<?php 

namespace Livewirez\Billing\Providers;

use Throwable;
use Psr\Log\LogLevel;
use Tekord\Result\Result;
use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\Lib\CartItem;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Item;
use Livewirez\Billing\PaymentResult;
use PaypalServerSdkLib\Models\Money;
use PaypalServerSdkLib\Models\Order;
use Livewirez\Billing\Info\ProductItem;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\Enums\PaymentStatus;
use PaypalServerSdkLib\Models\PaypalWallet;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use PaypalServerSdkLib\Models\PaymentSource;
use PaypalServerSdkLib\PaypalServerSdkClient;
use Livewirez\Billing\Models\BillingPlanPrice;
use PaypalServerSdkLib\Models\AmountBreakdown;
use PaypalServerSdkLib\Models\LinkDescription;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Interfaces\ProductInterface;
use PaypalServerSdkLib\Models\PurchaseUnitRequest;
use Livewirez\Billing\Interfaces\CartItemInterface;
use PaypalServerSdkLib\Models\Builders\ItemBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Livewirez\Billing\Info\PaypalPurchaseUnitOptions;
use Livewirez\Billing\Lib\PayPal\PayPalTokenProvider;
use Livewirez\Billing\Lib\PayPal\Transaction\Capture;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesWebhooks;
use Livewirez\Billing\Lib\PayPal\Enums\PayPalOrderStatus;
use function \Livewirez\Billing\formatAmountUsingCurrency;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Lib\PayPal\Transaction\PurchaseUnit;

use PaypalServerSdkLib\Logging\LoggingConfigurationBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesSubscriptions;
use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
use Livewirez\Billing\Lib\PayPal\Transaction\PayPalTransaction;
use PaypalServerSdkLib\Logging\RequestLoggingConfigurationBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;
use PaypalServerSdkLib\Logging\ResponseLoggingConfigurationBuilder;
use PaypalServerSdkLib\Models\Builders\OrderApplicationContextBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletExperienceContextBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;

// PayPal Provider
class PayPalProvider implements PaymentProviderInterface
{
    use HandlesWebhooks, HandlesSubscriptions {
        HandlesSubscriptions::__construct as __constructSubscriptionsManager;
    }

    public const string PROVIDER_TYPE = 'PACKAGE';

    protected PaypalServerSdkClient $client;


    public function __construct(array $config = [])
    {
        $this->__constructSubscriptionsManager();

        \Illuminate\Support\Facades\Log::info(collect($config), ['cONFIG']);
        
        $this->config = $config !== [] ? $config : config('billing.providers.paypal');

        $this->client = PaypalServerSdkClientBuilder::init()
        ->clientCredentialsAuthCredentials(
            ClientCredentialsAuthCredentialsBuilder::init(
                $this->config['client_id'],
                $this->config['client_secret']
            )
        )
        ->environment(strtoupper($this->config['environment']) === 'SANDBOX' ? Environment::SANDBOX : Environment::PRODUCTION)
        ->loggingConfiguration(
            LoggingConfigurationBuilder::init()
                ->level(LogLevel::INFO)
                ->requestConfiguration(RequestLoggingConfigurationBuilder::init()->body(true))
                ->responseConfiguration(ResponseLoggingConfigurationBuilder::init()->headers(true))
        )
        ->build();

        $this->initializeSubscriptionsManager();
    }

    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface
    {
        return new PayPalTokenProvider($this->config);
    }

    protected function createPurchaseUnitRequestB(Cart $cart): PurchaseUnitRequest
    {
        $currency_code = $cart->getCurrencyCode();

        $extra_tax = (float) $this->config['extra_tax'] ?? 0;

        $transformAmount = fn (int $value) => formatAmountUsingCurrency($value, $currency_code);

        return PurchaseUnitRequestBuilder::init(
            AmountWithBreakdownBuilder::init(
                $currency_code, 
                $transformAmount($cart->getGrandTotalFromExtraTax(true, $extra_tax))
            )
            ->breakdown(
                AmountBreakdownBuilder::init()
                ->itemTotal(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getItemTotals(false)))->build()
                )
                ->taxTotal(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getItemExtraTaxTotals($extra_tax)))->build()
                )
                ->shipping(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getShippingTotal()))->build()
                )
                ->handling(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getHandlingTotal()))->build()
                )
                ->insurance(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getInsuranceTotal()))->build()
                )
                ->shippingDiscount(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getShippingDiscountTotal()))->build()
                )
                ->discount(
                    MoneyBuilder::init($currency_code, $transformAmount($cart->getDiscountTotal()))->build()
                )
                ->build()
            )
            ->build()
        )
        ->items(array_map(function (CartItemInterface $cartItem) use ($currency_code, $extra_tax, $transformAmount) {

            return  ItemBuilder::init(
                $cartItem->getProduct()->getName(), 
                MoneyBuilder::init($currency_code, $transformAmount($cartItem->getProduct()->getListedPrice()))->build(),
                $cartItem->getQuantity()
            )
            ->description($cartItem->getProduct()->getDescription())
            ->tax(MoneyBuilder::init($currency_code, $transformAmount($cartItem->getProduct()->getTax() + $extra_tax))->build())
            ->category($cartItem->getProduct()->getProductCategory()->value) 
            ->imageUrl($cartItem->getProduct()->getImageUrl())
            ->url($cartItem->getProduct()->getUrl())
            ->sku($cartItem->getProduct()->getSku())
            ->build();
        }, $cart->all()))
        ->build();
    }

    protected function createPurchaseUnitRequest(Cart $cart): PurchaseUnitRequest
    {
        $extra_tax = (int) $this->config['extra_tax'] ?? 0;

        $currency_code = $cart->getCurrencyCode();

        $transformAmount = fn (int $value) => formatAmountUsingCurrency($value, $currency_code);

        $breakdown = new AmountBreakdown();
        $breakdown->setItemTotal(new Money($currency_code,  $transformAmount($cart->getItemTotals(false))));
        $breakdown->setTaxTotal(new Money($currency_code,  $transformAmount($cart->getItemExtraTaxTotals($extra_tax))));
        $breakdown->setShipping(new Money($currency_code,  $transformAmount($cart->getShippingTotal())));
        $breakdown->setHandling(new Money($currency_code,  $transformAmount($cart->getHandlingTotal())));
        $breakdown->setInsurance(new Money($currency_code,  $transformAmount($cart->getInsuranceTotal())));
        $breakdown->setShippingDiscount(new Money($currency_code,  $transformAmount($cart->getShippingDiscountTotal())));
        $breakdown->setDiscount(new Money($currency_code,  $transformAmount($cart->getDiscountTotal())));

        $awb = AmountWithBreakdownBuilder::init(
            $currency_code,
            $transformAmount($cart->getGrandTotalFromExtraTax(true, $extra_tax))
        );

        $awb->breakdown($breakdown);

        $purchaseUnitRequest = PurchaseUnitRequestBuilder::init(
            $awb->build()
        )->build();

        $purchaseUnitRequest->setItems(array_map(function (CartItemInterface $cartItem) use ($extra_tax, $transformAmount) {
            $item = new Item(
                $cartItem->getProduct()->getName(), 
                new Money(
                    $cartItem->getProduct()->getCurrencyCode(), 
                    $transformAmount($cartItem->getProduct()->getListedPrice())
                ),
                $cartItem->getQuantity()
            );
            $item->setDescription($cartItem->getProduct()->getDescription());
            $item->setTax(new Money($cartItem->getProduct()->getCurrencyCode(), $transformAmount($cartItem->getProduct()->getTax() + $extra_tax)));
            $item->setCategory($cartItem->getProduct()->getProductCategory()->value);
            $item->setImageUrl($cartItem->getProduct()->getImageUrl());
            $item->setUrl($cartItem->getProduct()->getUrl());
            $item->setSku($cartItem->getProduct()->getSku());

            return $item;
        }, $cart->all()));

        return $purchaseUnitRequest;
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_create
     * 
     * @param \Livewirez\Billing\Lib\Cart|\Livewirez\Billing\Models\BillingProduct $cart
     */
    public function createOrder(Cart|BillingProduct $cart, ?string $email = null)
    {
        $ordersController = $this->client->getOrdersController();

        if ($cart instanceof BillingProduct) {
            $cart = Cart::fromProduct($cart);
        }

        /**
         * @source  https://developer.paypal.com/docs/checkout/standard/customize/pass-buyer-identifier/
         */
        $paymentSource = PaymentSourceBuilder::init()->paypal(
            PaypalWalletBuilder::init()->emailAddress($email)->experienceContext(
                PaypalWalletExperienceContextBuilder::init()
                ->paymentMethodPreference("IMMEDIATE_PAYMENT_REQUIRED")
                ->landingPage("LOGIN")
                ->shippingPreference("GET_FROM_FILE")
                ->userAction("PAY_NOW")
                ->returnUrl($this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']))
                ->cancelUrl($this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']))
                ->build()
            )->build()
        )->build();
        

        $order_options = [
            'body' => OrderRequestBuilder::init(
                CheckoutPaymentIntent::CAPTURE,
                array_map([$this, 'createPurchaseUnitRequest'], [$cart])
            )->paymentSource($paymentSource)->build(),
            'prefer' => 'return=minimal' // 'return=representation'
        ];

        ob_start(); // Start output buffering
        $response = $ordersController->createOrder($order_options);
        ob_end_clean(); // Discard the buffered output

        $result = $response->getResult();

        \Illuminate\Support\Facades\Log::info(collect($result), [__METHOD__]);

        return $result;
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_create
     * 
     * @param \Livewirez\Billing\Interfaces\CartInterface|\Livewirez\Billing\Interfaces\ProductInterface $cart
     * @param InitializeOrderRequest $request
     * @return PaymentResult
     */
    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult
    {
        try {
            $response = $this->createOrder($cart, $request->getUser()->getEmail());

            if ($response instanceof Order) {
                $responseData = json_decode(json_encode($response), true);

                $paymentData = [
                    'billing_order_id' => $request->getBillingOrderId(),
                    'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
                    'order_number' => $request->getOrderNumber(),
                    'product_type' =>  $request->getProductType()->value,
                ];

                switch (PayPalOrderStatus::tryFrom($response->getStatus())) {
                    case PayPalOrderStatus::CREATED:
                        $link = array_find(
                            $response->getLinks() ?? [], 
                            fn (LinkDescription $link) => $link->getRel() === 'approve'
                        );

                        return new PaymentResult(
                            true,
                            $request->getBillingOrderId(),
                            PaymentStatus::PENDING,
                            Result::success($response),
                            $link->getHref(),
                            $response->getId(),
                            null,
                            null,
                            null,
                            'Payment Initialization Success',
                            [
                                'provider_class' => get_class($this),
                                'checkout_url' => $link->getHref(),
                                ...$paymentData,
                                ...$responseData
                            ]
                        );
                    case PayPalOrderStatus::PAYER_ACTION_REQUIRED:
                        
                        $link = array_find(
                            $response->getLinks() ?? [], 
                            fn (LinkDescription $link) => $link->getRel() === 'payer-action'
                        );
        
                        return new PaymentResult(
                            true,
                            $request->getBillingOrderId(),
                            PaymentStatus::PENDING,
                            Result::success($response),
                            $link->getHref(),
                            $response->getId(),
                            null,
                            null,
                            null,
                            'Payment Initialization Success',
                            [
                                'provider_class' => get_class($this),
                                'checkout_url' => $link->getHref(),
                                ...$paymentData,
                                ...$responseData
                            ]
                        );
                    case PayPalOrderStatus::COMPLETED:
                        return new PaymentResult(
                            true,
                            $request->getBillingOrderId(),
                            PaymentStatus::COMPLETED,
                            Result::success($response),
                            null,
                            $response->getId(),
                            null,
                            null,
                            null,
                            'Payment Initialization Success',
                            [
                                'provider_class' => get_class($this),
                                ...$paymentData,
                                ...$responseData
                            ]
                        );
                    case PayPalOrderStatus::VOIDED:
                    default:
                        return  new PaymentResult(
                            success: false,
                            billingOrderId: $request->getBillingOrderId(),
                            status: PaymentStatus::FAILED,
                            result: Result::fail(
                                new ErrorInfo(
                                    "Payment Initialization Failure: ", 
                                    400,
                                    $response->getStatus(),
                                    [
                                        ...$paymentData,
                                        'provider_class' => get_class($this),
                                        'billingOrderId' => $request->getBillingOrderId(),
                                        'response' => $response
                                    ],
                                )
                            ),
                            message: 'Payment Initialization Failure',
                            throw: true
                        );
                }
            }

            if (is_array($response) && isset($response['details']) && count($response['details']) > 0) {

                return match ($response['details'][0]->issue) {
                    'AMOUNT_MISMATCH' => new PaymentResult(
                        success: false,
                        billingOrderId: $request->getBillingOrderId(),
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Completion Failure: " . $response['message'], 
                                422,
                                $response['details'][0]->issue,
                                [
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'result' => $response
                                ]
                            )
                        ),
                        message: "Payment Completion Failure: " . $response['details'][0]->issue . ' : '. $response['details'][0]->description,
                        throw: true
                    ),
                    default => new PaymentResult(
                        success: false,
                        billingOrderId: $request->getBillingOrderId(),
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Completion Failure: " . $response['message'], 
                                422,
                                $response['details'][0]->issue,
                                [
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'result' => $response
                                ]
                            )
                        ),
                        message: "Payment Completion Failure: " . $response['details'][0]->issue . ':'. $response['details'][0]->description,
                        throw: true
                    ),
                };
            }

            return new PaymentResult(
                success: false,
                billingOrderId: $request->getBillingOrderId(),
                status: PaymentStatus::FAILED,
                result: Result::fail(
                    new ErrorInfo(
                        "Payment Initialization Failure: " . $response['name'] ?? '', 
                        400,
                        $response['message'],
                        [
                            'billingOrderId' => $request->getBillingOrderId(),
                            'response' => $response
                        ],
                    )
                ),
                message: "Payment Initialization Failure: " . $response['message'],
                throw: true
            );

        } catch (\Exception $e) {
            return new PaymentResult(
                success: false,
                billingOrderId: $request->getBillingOrderId(),
                status: PaymentStatus::FAILED,
                result: Result::fail(
                    new ErrorInfo(
                        "Payment Initialization Failure", 
                        $e->getCode(),
                        $e->getMessage(),
                        [
                            'billingOrderId' => $request->getBillingOrderId(),
                        ],
                        $e
                    )
                ),
                message: "Payment Initialization Failure: " . $e->getMessage(),
                throw: true
            );
        }
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     * 
     * @param CompleteOrderRequest $request
     * @return PaymentResult
     */
    public function completePayment(CompleteOrderRequest $request): PaymentResult
    {
        return $this->capturePayment(
            $request->getBillingOrderId(),
            $request->getProviderOrderId(),
            $request->getMetadata()
        );
    }


    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     * 
     * @param string $billingOrderId
     * @param string $providerOrderId
     * @return PaymentResult
     */
    public function capturePayment(string $billingOrderId, string $providerOrderId, array $data = []): PaymentResult
    {
        $ordersController = $this->client->getOrdersController();

        $collect = [
            'id' => $providerOrderId,
            'body' => [
                'application_context' => OrderApplicationContextBuilder::init()
                ->returnUrl($this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']))
                ->cancelUrl($this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']))
                ->build()
            ],
            'prefer' => 'return=minimal'
        ];

        try {

            ob_start();
            $apiResponse = $ordersController->captureOrder($collect);
            ob_end_clean();

            $result = $apiResponse->getResult();

            if ($result instanceof Order) {
                return match($status = $result->getStatus()) {

                    /** @source https://developer.paypal.com/docs/api/orders/v2/#orders_capture */
                    'COMPLETED' => (function () use ($result, $billingOrderId, $providerOrderId): PaymentResult {

                        $data = json_decode(json_encode($result), true);

                        try {
                            $purchaseUnits = $data['purchase_units'];

                            $completedStatus = array_all(
                                $purchaseUnits, 
                                fn (array $purchaseUnit): bool => array_all(
                                    $purchaseUnit['payments']['captures'], 
                                    fn (array $capture): bool => $capture['status'] === 'COMPLETED'
                                )
                            );

                            return $completedStatus ? new PaymentResult(
                                $completedStatus,
                                $billingOrderId,
                                PaymentStatus::PAID,
                                Result::success($data),
                                null,
                                $providerOrderId,
                                null,
                                null,
                                null,
                                'Payment Completion Success',
                                [
                                    ...$data
                                ]
                            ) : new PaymentResult(
                                $completedStatus,
                                $billingOrderId,
                                PaymentStatus::FAILED,
                                Result::fail($data),
                                null,
                                $providerOrderId,
                                null,
                                null,
                                null,
                                'Payment Completion Failure',
                                [
                                    ...$data
                                ]
                            );
                            
                        } catch (Throwable $th) {
                            \Illuminate\Support\Facades\Log::error('Error trasforming COMPLETED status to Transaction Interface', ['Paypal::capturePayment']);

                            return new PaymentResult(
                                false,
                                $billingOrderId,
                                PaymentStatus::FAILED,
                                Result::fail($data),
                                null,
                                $providerOrderId,
                                null,
                                null,
                                null,
                                'Payment Completion Failure',
                                [
                                    ...$data
                                ]
                            );
                        }
                    })(),
                   
                    'APPROVED','CREATED' => (function () use ($result, $billingOrderId, $providerOrderId): PaymentResult {

                        $data = json_decode(json_encode($result), true);

                        return new PaymentResult(
                            false,
                            $billingOrderId,
                            PaymentStatus::PENDING,
                            Result::success([]),
                            $checkoutUrl = (function (array $links = []) {
                                    $link = array_find(
                                        $links, 
                                        fn (array $link) => $link['rel'] === 'payer-action'
                                    );

                                    return $link['href'] ?? null;
                                })($data['links'] ?? []),
                            $providerOrderId,
                            null,
                            null,
                            null,
                            'Payment Completion Pending',
                            [
                                'checkout_url' => $checkoutUrl,
                                ...$data
                            ]
                        );
                    })(),
                    
                    'PAYER_ACTION_REQUIRED' => (function () use ($result, $billingOrderId, $providerOrderId) {
                        $link = array_find(
                            $result->getLinks() ?? [], 
                            fn (LinkDescription $link) => $link->getRel() === 'payer-action'
                        );
        
                        return new PaymentResult(
                            true,
                            $billingOrderId,
                            PaymentStatus::PENDING,
                            Result::success($result),
                            $link->getHref(),
                            $result->getId() ?? $providerOrderId,
                            null,
                            null,
                            null,
                            'Payment Initialization Success',
                            [
                                'checkout_url' => $link->getHref(),
                                ...json_decode(json_encode($result, true))
                            ]
                        );
                    }),
                    'CANCELLED', 'VOIDED' => new PaymentResult(
                        false,
                        $billingOrderId,
                        PaymentStatus::CANCELED,
                        Result::success([]),
                        null,
                        $providerOrderId,
                        null,
                        null,
                        null,
                        'Payment Completion Success',
                        []
                    ),
                    default =>  new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::PENDING,
                        Result::success([]),
                        null,
                        $providerOrderId,
                        null,
                        null,
                        null,
                        'Payment Completion Success',
                        []
                    )
                };
            }

            if (is_array($result) && isset($result['details']) && count($result['details']) > 0) {

                return match ($result['details'][0]->issue) {
                    'ORDER_ALREADY_CAPTURED' => new PaymentResult(
                        false,
                        $billingOrderId,
                        PaymentStatus::DEFAULT,
                        Result::success([]),
                        null,
                        $providerOrderId,
                        null,
                        null,
                        null,
                        'Payment Completion Success',
                        []
                    ),
                    'ORDER_NOT_APPROVED' => new PaymentResult(
                        false,
                        $billingOrderId,
                        PaymentStatus::PENDING,
                        Result::success([]),
                        null,
                        $providerOrderId,
                        null,
                        null,
                        null,
                        'Payment Completion Success',
                        []
                    ),
                    'PAYER_ACTION_REQUIRED' => new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::PENDING,
                        Result::success($result),
                        $checkoutUrl = (function (array $links) {
                                $link = array_find(
                                    $links, 
                                    fn (object $link) => $link->rel === 'payer-action'
                                );

                                return $link['href'];
                            })($result['links']),
                        $providerOrderId,
                        null,
                        null,
                        null,
                        'Payment Completion Success',
                        [
                            'issue' => 'PAYER_ACTION_REQUIRED',
                            'checkout_url' => $checkoutUrl,
                            ...$result
                        ]
                    ),
                    default => new PaymentResult(
                        false,
                        $billingOrderId,
                        PaymentStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Payment Completion Failure", 
                                422,
                                $result['name'],
                                [
                                    'billingOrderId' => $billingOrderId,
                                    'result' => $result
                                ]
                            )
                        ),
                        null,
                        $providerOrderId,
                        null,
                        null,
                        "Payment Completion Failure: " . $result['name'],
                    )
                };
            }

            return  new PaymentResult(
                true,
                $billingOrderId,
                PaymentStatus::PENDING,
                Result::success([]),
                null,
                $providerOrderId,
                null,
                null,
                null,
                'Payment Completion Success',
                []
            );
        } catch (\Exception $e) {
            return new PaymentResult(
                true,
                $billingOrderId,
                PaymentStatus::PENDING,
                Result::success([]),
                null,
                $providerOrderId,
                null,
                null,
                null,
                'Payment Completion Success',
                []
            );
        }
    }


    public function refundPayment(string $billingOrderId, string $providerOrderId): bool
    {
        try {
            // $request = new \PayPalCheckoutSdk\Payments\CapturesRefundRequest($billingOrderId);
            // if ($amount) {
            //     $request->body = [
            //         'amount' => [
            //             'currency_code' => 'USD',
            //             'value' => $amount
            //         ]
            //     ];
            // }
            // $this->client->execute($request);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_get
     * 
     * @param string $providerOrderId
     * @return PaymentStatus
     */
    public function getPaymentStatus(string $providerOrderId): PaymentStatus
    {
        try {

            $ordersController = $this->client->getOrdersController();

            $collect = [
                'id' => $providerOrderId,
                'body' => [
                    'application_context' => OrderApplicationContextBuilder::init()
                    ->returnUrl($this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']))
                    ->cancelUrl($this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']))
                    ->build()
                ],
                'prefer' => 'return=minimal'
            ];

            ob_start();
            $apiResponse =$apiResponse = $ordersController->getOrder($collect);
            ob_end_clean();

            $result = $apiResponse->getResult();

            if ($result instanceof Order) {
                return match($status = $result->getStatus()) {
                    'COMPLETED' => PaymentStatus::COMPLETED,
                    'APPROVED' ,'CREATED', 'PAYER_ACTION_REQUIRED' => PaymentStatus::PENDING,
                    'CANCELLED', 'VOIDED' => PaymentStatus::FAILED,
                    default => PaymentStatus::DEFAULT
                };
            }

            if (is_array($result) && isset($result['details']) && count($result['details']) > 0) {

                return match ($result['details'][0]->issue) {
                    'ORDER_ALREADY_CAPTURED' => PaymentStatus::DEFAULT,
                    'PAYER_ACTION_REQUIRED', 'ORDER_NOT_APPROVED' => PaymentStatus::PENDING,
                    default => PaymentStatus::FAILED
                };
            }

            return  PaymentStatus::DEFAULT;
        } catch (\Exception $e) {
            return PaymentStatus::FAILED;
        }
    }

    public function getSubscriptionStatus(string $subscriptionId): SubscriptionStatus
    {
        try {
            // $request = new \PayPalSubscriptionsApi\Subscriptions\SubscriptionsGetRequest($subscriptionId);
            // $response = $this->client->execute($request);
            // return $this->mapPayPalStatus($response->result->status);

            return match($status = 'CREATED') {
                'APPROVED' => SubscriptionStatus::PENDING,
                'CREATED' => SubscriptionStatus::PENDING,
                'CANCELLED' => SubscriptionStatus::FAILED,
                'ACTIVE' => SubscriptionStatus::ACTIVE,
                'SUSPENDED' => SubscriptionStatus::PAST_DUE,
                default => SubscriptionStatus::from($status)
            };
        } catch (\Exception $e) {
            return SubscriptionStatus::FAILED;
        }
    }
}