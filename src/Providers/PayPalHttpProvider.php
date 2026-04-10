<?php 

namespace Livewirez\Billing\Providers;

use Exception;
use Throwable;
use Psr\Log\LogLevel;
use Tekord\Result\Result;
use Illuminate\Http\Request;
use Livewirez\Billing\Money;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\Lib\CartItem;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewirez\Billing\PaymentResult;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Lib\PayPal\Utils;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;

use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Lib\PayPal\PayPalTokenProvider;
use Livewirez\Billing\Lib\PayPal\Transaction\Capture;
use Livewirez\Billing\Lib\PayPal\SubscriptionsManager;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use function Livewirez\Billing\make_request_using_curl;
use Livewirez\Billing\Lib\PayPal\Enums\ErrorMessageMode;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesWebhooks;
use Livewirez\Billing\Lib\PayPal\Enums\PayPalOrderStatus;
use function \Livewirez\Billing\formatAmountUsingCurrency;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Lib\PayPal\Transaction\PurchaseUnit;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesSubscriptions;
use Livewirez\Billing\Lib\PayPal\Transaction\PayPalTransaction;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

class PaypalHttpProvider implements PaymentProviderInterface
{
    use HandlesSubscriptions, HandlesWebhooks;

    /**
     * Create a new PayPal provider instance.
     *
     * @param  array  $config The PayPal API configuration.
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->config = $config !== [] ? $config : config('billing.providers.paypal');

        $this->initializeSubscriptionsManager();
    }

    protected function getAccessToken(): string
    { 
        return Cache::remember('paypal_access_token', $this->config['expires_in'], function (): string {
            $response = Http::withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                ->asForm()
                ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw()
                ->post($this->config['base_url'][$this->config['environment']] . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            Cache::put('paypal_access_token', $response->json('access_token'), $response->json('expires_in'));
            
            return $response->json('access_token');
        });
    }

    public function createOrderFromCart(CartInterface $cart, ?string $email = null)
    {

        /**
         * {
            *  "intent": "CAPTURE",
            *    "payment_source": {
            *        "paypal": {
            *            "experience_context": {
            *                "payment_method_preference": "IMMEDIATE_PAYMENT_REQUIRED",
            *                "landing_page": "LOGIN",
            *                "shipping_preference": "GET_FROM_FILE",
            *                "user_action": "PAY_NOW",
            *                "return_url": "https://example.com/returnUrl",
            *                "cancel_url": "https://example.com/cancelUrl"
            *            }
            *        }
            *    },
            *    "purchase_units": [
            *        {
            *        "invoice_id": "90210",
            *        "amount": {
            *            "currency_code": "USD",
            *            "value": "230.00",
            *            "breakdown": {
            *                "item_total": {
            *                    "currency_code": "USD",
            *                    "value": "220.00"
            *                },
            *                "shipping": {
            *                    "currency_code": "USD",
            *                    "value": "10.00"
            *                }
            *            }
            *        },
            *        "items": [
            *            {
            *            "name": "T-Shirt",
            *            "description": "Super Fresh Shirt",
            *            "unit_amount": {
            *               "currency_code": "USD",
            *                "value": "20.00"
            *            },
            *            "quantity": "1",
            *            "category": "PHYSICAL_GOODS",
            *            "sku": "sku01",
            *            "image_url": "https://example.com/static/images/items/1/tshirt_green.jpg",
            *            "url": "https://example.com/url-to-the-item-being-purchased-1",
            *            "upc": {
            *                "type": "UPC-A",
            *                "code": "123456789012"
            *            }
            *            },
            *            {
            *            "name": "Shoes",
            *           "description": "Running, Size 10.5",
            *            "sku": "sku02",
            *            "unit_amount": {
            *                "currency_code": "USD",
            *                "value": "100.00"
            *            },
            *            "quantity": "2",
            *            "category": "PHYSICAL_GOODS",
            *            "image_url": "https://example.com/static/images/items/1/shoes_running.jpg",
            *            "url": "https://example.com/url-to-the-item-being-purchased-2",
            *            "upc": {
            *                "type": "UPC-A",
            *                "code": "987654321012"
            *            }
            *            }
            *        ]
            *        }
            *    ]
            *}
         * 
         * 
         * 
         */

        $extra_tax = (float) $this->config['extra_tax'] ?? 0;

        $currency_code = $cart->getCurrencyCode();

        $transformAmount = fn (int $value) => Money::formatAmountUsingCurrency($value, $currency_code);

        return [
            'intent' => 'CAPTURE',
            'payment_source' => [
                'paypal' => [
                    'email_address' => $email,
                    'experience_context' => [
                        "payment_method_preference" => "IMMEDIATE_PAYMENT_REQUIRED",
                        "landing_page" => "LOGIN",
                        "shipping_preference" => "GET_FROM_FILE", // SET_PROVIDED_ADDRESS
                        "user_action" => "PAY_NOW",
                        "return_url" => $this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']),
                        "cancel_url" => $this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']),
                    ]
                ]
            ],
            'purchase_units' => [
                [
                    'items' => array_map(function (CartItemInterface $sci) use ($extra_tax, $transformAmount) {
                        return [
                            'name' => $sci->getProduct()->getName(),
                            'description' => $sci->getProduct()->getDescription(),
                            'quantity' => $sci->getQuantity(),
                            'unit_amount' => [
                                'currency_code' => $sci->getProduct()->getCurrencyCode(),
                                'value' => $transformAmount($sci->getProduct()->getListedPrice())
                            ],
                            'tax' => [
                                'currency_code' => $sci->getProduct()->getCurrencyCode(),
                                'value' => $transformAmount($sci->getProduct()->getTax() + $extra_tax)
                            ],
                            'url' => $sci->getProduct()->getUrl(),
                            'category' => $sci->getProduct()->getProductCategory()->value,
                            'image_url' => $sci->getProduct()->getImageUrl(),
                            'sku' => $sci->getProduct()->getSku()
                        ];
                    }, $cart->all()),

                    'amount' => [
                        'currency_code' => $cart->getCurrencyCode(),
                        'value' => $transformAmount($cart->getGrandTotalFromExtraTax(true, $extra_tax)),
                        'breakdown' => [
                            'item_total' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getItemTotals(false))
                            ],
                            'tax_total' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getItemExtraTaxTotals($extra_tax))
                            ],
                            'shipping' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getShippingTotal())
                            ],
                            'handling' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getHandlingTotal())
                            ],
                            'insurance' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getInsuranceTotal())
                            ],
                            'shipping_discount' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getShippingDiscountTotal())
                            ],
                            'discount' => [
                                'currency_code' => $cart->getCurrencyCode(),
                                'value' => $transformAmount($cart->getDiscountTotal())
                            ],
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_create
     */
    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult
    {
        $paymentData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'product_type' =>  $request->getProductType()->value,
        ];

        try {
            $response = $this->makeRequest(
                '/v2/checkout/orders', 
                $this->createOrderFromCart(
                    $cart, 
                    $request->getUser()->getEmail()
                )
            );

            \Illuminate\Support\Facades\Log::debug(collect([
                'response' => $response->json(),
                'headers' => $response->headers(),
                'type' => $request->getProductType()
            ]), ['Paypal: Initialize Order']);

            switch (PayPalOrderStatus::tryFrom($response->json('status'))) {
                case PayPalOrderStatus::CREATED:
                    $link = array_find(
                        $response->json('links'), 
                        fn (array $link) => $link['rel'] === 'approve'
                    );

                    return new PaymentResult(
                        true,
                        $request->getBillingOrderId(),
                        PaymentStatus::PENDING,
                        Result::success($response->json()),
                        $link['href'],
                        $response->json('id'),
                        null,
                        null,
                        null,
                        'Payment Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            ...$paymentData,
                            ...$response->json()
                        ]
                    );
                case PayPalOrderStatus::PAYER_ACTION_REQUIRED:
                    
                    $link = array_find(
                        $response->json('links'), 
                        fn (array $link) => $link['rel'] === 'payer-action'
                    );
    
                    return new PaymentResult(
                        true,
                        $request->getBillingOrderId(),
                        PaymentStatus::PENDING,
                        Result::success($response->json()),
                        $link['href'],
                        $response->json('id'),
                        null,
                        null,
                        null,
                        'Payment Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            ...$paymentData,
                            ...$response->json()
                        ]
                    );
                case PayPalOrderStatus::COMPLETED:
                    return new PaymentResult(
                        true,
                        $request->getBillingOrderId(),
                        PaymentStatus::COMPLETED,
                        Result::success($response->json()),
                        null,
                        $response->json('id'),
                        null,
                        null,
                        null,
                        'Payment Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            ...$paymentData,
                            ...$response->json()
                        ]
                    );
                case PayPalOrderStatus::VOIDED:
                default:
                    return new PaymentResult(
                        success: false,
                        billingOrderId: $request->getBillingOrderId(),
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Initialization Failure: " . $response->json('name') ?? '', 
                                400,
                                $response->json('message'),
                                [
                                    ...$paymentData,
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'response' => $response->json()
                                ],
                            )
                        ),
                        message: "Payment Initialization Failure: " . $response->json('message'),
                        throw: true
                    );
            }
        } catch (RequestException $e) {
            return match ($status = $e->response->status()) {
                422 => match ($e->response->json('details.0.issue')) {

                    'AMOUNT_MISMATCH' => new PaymentResult(
                        success: false,
                        billingOrderId: $request->getBillingOrderId(),
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Initialization Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                                $status,
                                Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                [
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'result' => $e->response->json()
                                ],
                                $e
                            )
                        ),
                        message: "Payment Initialization Failure: AMOUNT_MISMATCH: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                        metadata: [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $request->getBillingOrderId(),
                            'error' => $e->getMessage(),
                            'response' => $e->response->json(),
                            'response_message' => [
                                'details_issue' => $e->response->json('details.0.issue'),
                                'details_description' => $e->response->json('details.0.description'),
                                'message' => $e->response->json('message'),
                            ]
                        ],
                        throw: true
                    ),

                    default => new PaymentResult(   
                        false,
                        $request->getBillingOrderId(),
                        PaymentStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Payment Completion Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                                $status,
                                Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                [
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'reposne' => $e->response->json()
                                ],
                                $e
                            )
                        ),
                        message: "Payment Completion Failure: " .  Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                        metadata: [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $request->getBillingOrderId(),
                            'error' => $e->getMessage(),
                            'response' => $e->response->json(),
                            'response_message' => [
                                'details_issue' => $e->response->json('details.0.issue'),
                                'details_description' => $e->response->json('details.0.description'),
                                'message' => $e->response->json('message'),
                            ]
                        ],
                        throw: true
                    )
                },
                default => new PaymentResult(   
                    false,
                    $request->getBillingOrderId(),
                    PaymentStatus::FAILED,
                    Result::fail(
                        new ErrorInfo(
                            "Payment Completion Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                            $status,
                            Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                            [
                                'billingOrderId' => $request->getBillingOrderId(),
                                'reposne' => $e->response->json()
                            ],
                            $e
                        )
                    ),
                    message: "Payment Completion Failure: " .  Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                    metadata: [
                        'provider_class' => get_class($this),
                        'billingOrderId' => $request->getBillingOrderId(),
                        'error' => $e->getMessage(),
                        'response' => $e->response->json(),
                        'response_message' => [
                            'details_issue' => $e->response->json('details.0.issue'),
                            'details_description' => $e->response->json('details.0.description'),
                            'message' => $e->response->json('message'),
                        ]
                    ],
                    throw: true
                )
            };
        } catch (ConnectionException $e) {
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
                message: "Payment Initialization Failure: Paypal is unavailable",
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
        } catch (Exception $e) {
            return false;
        }
    }

    public function capturePayment(string $billingOrderId, string $providerOrderId, array $data = []): PaymentResult
    {
        try {
            $response = $this->makeRequest("/v2/checkout/orders/{$providerOrderId}/capture", [
                'application_context' => [
                    "return_url" => $this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']),
                    "cancel_url" => $this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']),
                ]
            ]);

            return match($response->json('status')) {

                /** @source https://developer.paypal.com/docs/api/orders/v2/#orders_capture */
                'COMPLETED' => (function () use ($response, $billingOrderId, $providerOrderId): PaymentResult {

                    try {
                        $purchaseUnits = $response->json('purchase_units');

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
                            Result::success($response->json()),
                            null,
                            $providerOrderId,
                            null,
                            null,
                            null,
                            'Payment Completion Success',
                            [
                                'provider_class' => get_class($this),
                                ...$response->json()
                            ]
                        ) : new PaymentResult(
                            $completedStatus,
                            $billingOrderId,
                            PaymentStatus::FAILED,
                            Result::fail($response->json()),
                            null,
                            $providerOrderId,
                            null, 
                            null,
                            null,
                            'Payment Completion Failure',
                            [
                                'provider_class' => get_class($this),
                                ...$response->json()
                            ]
                        );
                    } catch (Throwable $th) {
                        \Illuminate\Support\Facades\Log::error('Error trasforming COMPLETED status to Transaction Interface', ['PaypalHttp::capturePayment']);

                        return new PaymentResult(
                            false,
                            $billingOrderId,
                            PaymentStatus::FAILED,
                            Result::fail($response->json()),
                            null,
                            $providerOrderId,
                            null,
                            null,
                            null,
                            'Payment Completion Failure',
                            [
                                'provider_class' => get_class($this),
                                ...$response->json()
                            ]
                        );
                    }
                })(),
                'APPROVED',
                'CREATED' => new PaymentResult(
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
                        })($response->json()),
                    $providerOrderId,
                    null,
                    null,
                    null,
                    'Payment Completion Pending',
                    [
                        'provider_class' => get_class($this),
                        'checkout_url' => $checkoutUrl,
                        ...$response->json()
                    ]
                ),
                'PAYER_ACTION_REQUIRED' => (function () use ($response, $billingOrderId, $providerOrderId) {
                    $link = array_find(
                        $response->json('links'), 
                        fn (array $link) => $link['rel'] === 'payer-action'
                    );
    
                    return new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::PENDING,
                        Result::success($response->json()),
                        $link['href'],
                        $response->json('id', $providerOrderId),
                        null,
                        null,
                        null,
                        'Payment Completion Pending',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            ...$response->json()
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
                    ['provider_class' => get_class($this),]
                ),
                default => new PaymentResult(
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
                    ['provider_class' => get_class($this),]
                )
            };
        } catch (RequestException $e) {
            return match ($e->response->json('details.0.issue')) {
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
                    Result::success($e->response->json()),
                    $checkoutUrl = (function (array $links) {
                            $link = array_find(
                                $links, 
                                fn (array $link) => $link['rel'] === 'payer-action'
                            );

                            return $link['href'];
                        })($e->response->json('links')),
                    $providerOrderId,
                    null,
                    null,
                    null,
                    'Payment Completion Success',
                    [
                        'issue' => 'PAYER_ACTION_REQUIRED',
                        'provider_class' => get_class($this),
                        'checkout_url' => $checkoutUrl,
                        ...$e->response->json()
                    ]
                ),
                default => new PaymentResult(
                    success: false,
                    billingOrderId: $billingOrderId,
                    status: PaymentStatus::FAILED,
                    result: Result::fail(
                        new ErrorInfo(
                            "Payment Completion Failure", 
                            $e->getCode(),
                            $e->getMessage(),
                            [
                                'billingOrderId' => $billingOrderId,
                            ],
                            $e
                        )
                    ),
                    providerOrderId: $providerOrderId,
                    message: "Payment Completion Failure: " . $e->getMessage(),
                )
            };
        } catch (ConnectionException) {
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
                [
                    'error' => 'Connection Exception, Paypal unavailable'
                ],
                true
            );
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
            $response = $this->makeRequest("/v2/checkout/orders/{$providerOrderId}", [
                'application_context' => [
                    "return_url" => $this->config['payment_return_url'] ?? route($this->config['payment_return_url_name']),
                    "cancel_url" => $this->config['payment_cancel_url'] ?? route($this->config['payment_cancel_url_name']),
                ]
            ], method: RequestMethod::Get);

            return match($response->json('status')) {
                'COMPLETED' => PaymentStatus::COMPLETED,
                'APPROVED' => PaymentStatus::PENDING,
                'CREATED' => PaymentStatus::PENDING,
                'CANCELLED', 'VOIDED' => PaymentStatus::CANCELED,
                default => PaymentStatus::DEFAULT
            };
        } catch (RequestException $e) {
            return match ($e->response->json('details.0.issue')) {
                'ORDER_ALREADY_CAPTURED' => PaymentStatus::DEFAULT,
                'PAYER_ACTION_REQUIRED', 'ORDER_NOT_APPROVED' => PaymentStatus::PENDING,
                default => PaymentStatus::FAILED
            };
        } catch (ConnectionException) {
            return PaymentStatus::PENDING;
        }
    }


    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface
    {
        return new PayPalTokenProvider($this->config);
    }

    protected function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response
    {
        $token = $this->getAccessToken();

        $client = Http::baseUrl($this->config['base_url'][$this->config['environment']])
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->withHeader('prefer', 'return=minimal') // 'return=representation'
                ->retry(3, 5, fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
                ->throw(function (Response $r, RequestException $e) use ($uri) {
                    \Illuminate\Support\Facades\Log::info(collect([
                        'response' => $r,
                        'json_repsone' => $r->json(),
                        'error' => $e->getMessage(),
                        'status' => $r->status(),
                        'body' => $r->body()  // Add this to see the full response body
                    ]), [__METHOD__, $uri]);
                })
                ->truncateExceptionsAt(1500);

        return match ($method) {

            RequestMethod::Get => $client->get( $uri, $data),
        
            RequestMethod::Patch => $client->patch($uri, $data),

            RequestMethod::Post => $client->post( $uri, $data),

            default => $client->post($uri, $data)
        };
    }
    
    protected function makeRequestUsingCurl(string $uri, array $data, string $method = 'POST'): mixed
    {
        $token = $this->getAccessToken();

        return make_request_using_curl(
            $this->config['base_url'][$this->config['environment']] . $uri,
            $data,
            [
                'headers' => [
                    "User-Agent: PayPal REST API PHP SDK, Version: 0.6.1, on " . php_uname('s'),
                    "Prefer: return=minimal", // 'return=representation'
                    "Authorization: Bearer $token",
                ],
                'options' => [
                    CURLOPT_USERAGENT => 'PayPal REST API PHP SDK, Version: 0.6.1, on ' . php_uname('s'),
                ]
            ],
            $method
        );
    }
}