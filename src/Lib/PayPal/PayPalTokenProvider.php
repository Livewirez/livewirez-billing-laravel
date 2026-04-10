<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Exception;
use Throwable;
use Psr\Log\LogLevel;
use DateTimeInterface;
use Tekord\Result\Result;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Livewirez\Billing\Money;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\Lib\Address;
use Livewirez\Billing\Lib\CartItem;
use Livewirez\Billing\Lib\Customer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewirez\Billing\PaymentResult;
use Illuminate\Support\Facades\Cache;
use Livewirez\Billing\Lib\PayPal\Utils;

use GuzzleHttp\Promise\PromiseInterface;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\RequestMethod;
use Livewirez\Billing\Interfaces\Billable;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillableAddress;
use Illuminate\Contracts\Encryption\Encrypter;
use Livewirez\Billing\Lib\PayPal\VaultManager;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionStatus;
use PaypalServerSdkLib\Models\VaultTokenRequest;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Lib\PayPal\PayPalTokenData;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Lib\PayPal\SubscriptionUtils;
use Livewirez\Billing\Models\BillablePaymentMethod;
use Livewirez\Billing\Lib\PayPal\Transaction\Capture;
use Livewirez\Billing\Lib\PayPal\SubscriptionsManager;
use function Livewirez\Billing\make_request_using_curl;
use Livewirez\Billing\Lib\PayPal\Enums\ErrorMessageMode;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesWebhooks;
use Livewirez\Billing\Lib\PayPal\Enums\PayPalOrderStatus;
use Livewirez\Billing\Lib\PayPal\Enums\VaultUsagePattern;
use function \Livewirez\Billing\formatAmountUsingCurrency;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Lib\PayPal\Traits\HandlesSubscriptions;
use Livewirez\Billing\Lib\PayPal\Transaction\PayPalTransaction;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

class PayPalTokenProvider implements TokenizedPaymentProviderInterface
{
    protected array $config = [];

    protected VaultManager $vaultManager;

    protected Encrypter $encrypter;

    /**
     * Create a new PayPal provider instance.
     *
     * @param  array  $config The PayPal API configuration.
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->config = $config !== [] ? $config : config('billing.providers.paypal');

        $this->setupVaultManager();
    }

    public function setEncrypter(Encrypter $encrypter): static
    {
        $this->encrypter = $encrypter;

        return $this;
    }

    public function getEncrypter(): Encrypter
    {
        return $this->encrypter;
    }

    protected function setupVaultManager(): VaultManager
    {
        return $this->vaultManager = new VaultManager($this->getAccessToken(...), $this->config);
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

    protected function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response | PromiseInterface
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

    public function saveToken(Billable $user, PayPalTokenData $tokenData): void
    {
        $billablePaymentMethod = $user->billable_payment_methods()->create([
            'payment_provider' => PaymentProvider::PayPal,
            'payment_provider_user_id' => $tokenData->token_customer_id ?? $tokenData->payer_id,
            'token' => $tokenData->vault_id,
            'provider_payment_method_id' => $tokenData->token,
            'billing_email' => $tokenData->address->email,
            'billing_name' => $tokenData->address->name,
            'billing_phone' => $tokenData->address->phone,
            'address_line1' => $tokenData->address->line1,
            'address_line2' => $tokenData->address->line2,
            'address_city' => $tokenData->address->city,
            'address_state' => $tokenData->address->state,
            'address_postal_code' => $tokenData->address->postal_code,
            'address_country' => $tokenData->address->country,
        ]);

        $address = $user->billable_addresses()->firstOrCreate([
            'hash' => BillableAddress::hashFromAddress($user, $tokenData->address)
        ], BillableAddress::attributesFromAddress($tokenData->address));

        $billablePaymentInformation = $user->billable_payment_provider_information()->firstOrCreate([
            'payment_provider' => PaymentProvider::PayPal,
            'payment_provider_user_id' => $tokenData->token_customer_id ?? $tokenData->payer_id
        ], []);

        $billablePaymentInformation->billable_address()->associate($address);
        $billablePaymentInformation->save();

        $billablePaymentMethod->billable_payment_provider_information()->associate($billablePaymentInformation);
        $billablePaymentMethod->save();

    }

    public function createOrderFromCart(Cart $cart, #[\SensitiveParameter] string $vault_id, ?string $email = null): array
    {

        /**
         * https://developer.paypal.com/docs/api/orders/v2/#orders_create
         * {
            *  "intent": "CAPTURE",
            *    "payment_source": {
            *        "paypal": {
            *            "vault_id": "PAYMENT-TOKEN-ID",
            *            "stored_credential": {
            *                "payment_initiator": "MERCHANT",
            *                "usage": "DERIVED", // DERIVED | SUBSEQUENT | FIRST
            *                "usage_pattern": "IMMEDIATE",
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
                    'vault_id' => $vault_id,
                    'stored_credential' => [
                        'payment_initiator' => "MERCHANT",
                        'usage' => "DERIVED",
                        'usage_pattern' => "IMMEDIATE",
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

    public function setupPaymentToken(array $data = []): array
    {
        return $this->createVaultPaymentSetupToken(VaultUsagePattern::IMMEDIATE, data: $data);
    }

    public function setupSubscriptionPaymentToken(BillingPlanPrice $planPrice, array $data = []): array
    {
        return $this->createVaultPaymentSetupToken(
            VaultUsagePattern::SUBSCRIPTION_PREPAID, $planPrice->billing_plan()->first(), $data
        );
    }

    public function createVaultPaymentSetupToken(
        VaultUsagePattern $vaultUsagePattern = VaultUsagePattern::IMMEDIATE,
        ?BillingPlan $plan = null,
        array $data = []
    ): array
    {
        try {
            $vaultResponse = $this->vaultManager->createVaultSetupToken($vaultUsagePattern, $plan , $data);

            \Illuminate\Support\Facades\Log::debug('Create Vault Token: ' . $vaultUsagePattern->value, [
               'response' => $vaultResponse->json(),
            ]);

            switch (PayPalOrderStatus::tryFrom($vaultResponse->json('status'))) {
                case PayPalOrderStatus::PAYER_ACTION_REQUIRED:
                    
                    $link = array_find(
                        $vaultResponse->json('links'), 
                        fn (array $link) => $link['rel'] === 'approve'
                    );
    
                    return [
                        'provider_class' => get_class($this),
                        'checkout_url' => $link['href'],
                        ...$vaultResponse->json()
                    ];
                default:
                    \Illuminate\Support\Facades\Log::error(
                        'Error creating vault setup token: ' . __METHOD__ .': '.__LINE__, 
                        [
                        'response' => $vaultResponse->json()
                    ]);
                    throw new \RuntimeException('Failed to create vault setup token');
            }
        } catch (RequestException $e) {
            \Illuminate\Support\Facades\Log::error(
                'Error creating vault setup token: ' . $e->getMessage() . ': ' . $e->response->json('message'). ': '. $e->response->json('details.0.issue') , 
                [
                'message' => $e->getMessage(),
                'response' => $e->response->json()
            ]);

            throw new \RuntimeException('Failed to create vault setup token');

        } catch (Throwable $th) {
            \Illuminate\Support\Facades\Log::error('Error creating vault setup token: ' . $th->getMessage(), [
                'message' => $th->getMessage(),
            ]);
            throw new \RuntimeException('Failed to create vault setup token');
        }
    }


    public function completePayment(
        Cart $cart, #[\SensitiveParameter] string $vault_id, array $data = []
    ): PaymentResult
    {
        $paymentData = [
            'billing_order_id' => $data['billing_order_id'],
            'billing_payment_transaction_id' => $data['billing_payment_transaction_id']
        ];

        try {
            $checkoutResponse = $this->makeRequest(
                '/v2/checkout/orders', 
                $this->createOrderFromCart(
                    $cart,
                    $vault_id,
                    $data['email'] ?? null
                ),
                ['PayPal-Request-ID' =>  Str::random(32)]
            );

            \Illuminate\Support\Facades\Log::debug('Complete Payment With Token: Checkout Token Response', [
               'response' => $checkoutResponse->json(),
            ]);

            switch (PayPalOrderStatus::tryFrom($checkoutResponse->json('status'))) {
                case PayPalOrderStatus::CREATED:
                    $link = array_find(
                        $checkoutResponse->json('links'), 
                        fn (array $link) => $link['rel'] === 'approve'
                    );

                    return new PaymentResult(
                        true,
                        $data['billing_order_id'],
                        PaymentStatus::PENDING,
                        Result::success($checkoutResponse->json()),
                        $link['href'],
                        $checkoutResponse->json('id'),
                        null,
                        null,
                        null,
                        'Payment Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            ...$paymentData,
                            ...$checkoutResponse->json()
                        ]
                    );
                case PayPalOrderStatus::PAYER_ACTION_REQUIRED:
                    
                    $link = array_find(
                        $checkoutResponse->json('links'), 
                        fn (array $link) => $link['rel'] === 'payer-action'
                    );
    
                    return new PaymentResult(
                        true,
                        $data['billing_order_id'],
                        PaymentStatus::PENDING,
                        Result::success($checkoutResponse->json()),
                        $link['href'],
                        $checkoutResponse->json('id'),
                        null,
                        null,
                        null,
                        'Payment Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            ...$paymentData,
                            ...$checkoutResponse->json()
                        ]
                    );
                case PayPalOrderStatus::COMPLETED:
                    try {
                        $purchaseUnits = $checkoutResponse->json('purchase_units');

                        $completedStatus = array_all(
                            $purchaseUnits, 
                            fn (array $purchaseUnit): bool => array_all(
                                $purchaseUnit['payments']['captures'], 
                                fn (array $capture): bool => $capture['status'] === 'COMPLETED'
                            )
                        );

                        return $completedStatus ? new PaymentResult(
                            $completedStatus,
                            $data['billing_order_id'],
                            PaymentStatus::PAID,
                            Result::success($checkoutResponse->json()),
                            null,
                            $checkoutResponse->json('id'),
                            null,
                            null,
                            null,
                            'Payment Completion Success',
                            [
                                'provider_class' => get_class($this),
                                ...$checkoutResponse->json()
                            ]
                        ) : new PaymentResult(
                            $completedStatus,
                            $data['billing_order_id'],
                            PaymentStatus::FAILED,
                            Result::fail($checkoutResponse->json()),
                            null,
                            $checkoutResponse->json('id'),
                            null,
                            null,
                            null,
                            'Payment Completion Failure',
                            [
                                'provider_class' => get_class($this),
                                ...$checkoutResponse->json()
                            ]
                        );
                    } catch (Throwable $th) {
                        \Illuminate\Support\Facades\Log::error('Error trasforming COMPLETED status to Transaction Interface', ['PaypalHttp::capturePayment']);

                        return new PaymentResult(
                            false,
                            $data['billing_order_id'],
                            PaymentStatus::FAILED,
                            Result::fail($checkoutResponse->json()),
                            null,
                            $checkoutResponse->json('id'),
                            null,
                            null,
                            null,
                            'Payment Completion Failure',
                            [
                                'provider_class' => get_class($this),
                                ...$checkoutResponse->json()
                            ]
                        );
                    }
                case PayPalOrderStatus::VOIDED:
                default:
                    return new PaymentResult(
                        success: false,
                        billingOrderId: $data['billing_order_id'],
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Initialization Failure: " . $checkoutResponse->json('name') ?? '', 
                                400,
                                $checkoutResponse->json('message'),
                                [
                                    ...$paymentData,
                                    'billingOrderId' => $data['billing_order_id'],
                                    'response' => $checkoutResponse->json()
                                ],
                            )
                        ),
                        message: "Payment Initialization Failure: " . $checkoutResponse->json('message'),
                        throw: true
                    );
            }
        } catch (RequestException $e) {
            return match ($status = $e->response->status()) {
                422 => match ($e->response->json('details.0.issue')) {

                    'AMOUNT_MISMATCH' => new PaymentResult(
                        success: false,
                        billingOrderId: $data['billing_order_id'],
                        status: PaymentStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Payment Initialization Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                                $status,
                                Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                [
                                    'billingOrderId' => $data['billing_order_id'],
                                    'result' => $e->response->json()
                                ],
                                $e
                            )
                        ),
                        message: "Payment Initialization Failure: AMOUNT_MISMATCH: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                        metadata: [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $data['billing_order_id'],
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
                        $data['billing_order_id'],
                        PaymentStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Payment Completion Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                                $status,
                                Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                [
                                    'billingOrderId' => $data['billing_order_id'],
                                    'reposne' => $e->response->json()
                                ],
                                $e
                            )
                        ),
                        message: "Payment Completion Failure: " .  Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                        metadata: [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $data['billing_order_id'],
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
                    $data['billing_order_id'],
                    PaymentStatus::FAILED,
                    Result::fail(
                        new ErrorInfo(
                            "Payment Completion Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                            $status,
                            Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                            [
                                'billingOrderId' => $data['billing_order_id'],
                                'reposne' => $e->response->json()
                            ],
                            $e
                        )
                    ),
                    message: "Payment Completion Failure: " .  Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                    metadata: [
                        'provider_class' => get_class($this),
                        'billingOrderId' => $data['billing_order_id'],
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
                billingOrderId: $data['billing_order_id'],
                status: PaymentStatus::FAILED,
                result: Result::fail(
                    new ErrorInfo(
                        "Payment Initialization Failure", 
                        $e->getCode(),
                        $e->getMessage(),
                        [
                            'billingOrderId' => $data['billing_order_id'],
                        ],
                        $e
                    )
                ),
                message: "Payment Initialization Failure: Paypal is unavailable",
                throw: true 
            );
        }
    }

    public function completePaymentWithToken(
       Cart $cart, string $token, array $data = [] 
    ): PaymentResult
    {
        
        $paymentTokenResponse =  $this->makeRequest('/v3/vault/payment-tokens', [
            'payment_source' => [
                'token' => [
                    'id' => $token,
                    'type' => 'SETUP_TOKEN'
                ]
            ]
        ]);

        \Illuminate\Support\Facades\Log::debug('Complete Payment With Token: Payment Token Response', [
            'response' => $paymentTokenResponse->json(),
        ]);

        $vault_id = $paymentTokenResponse->json('id');

        if (isset($data['save_token']) && $data['save_token']) {

            $this->saveToken( 
                $data['user'],
                new PayPalTokenData(
                    $vault_id,
                    $token,
                    Address::make(
                        $paymentTokenResponse->json('payment_source.paypal.email_address'),
                        $paymentTokenResponse->json('payment_source.paypal.name.full_name'),
                        null,
                        $paymentTokenResponse->json('payment_source.paypal.shipping.address.address_line_1'),
                        $paymentTokenResponse->json('payment_source.paypal.shipping.address.address_line_2'),
                        $paymentTokenResponse->json('payment_source.paypal.shipping.address.admin_area_2'),
                        $paymentTokenResponse->json('payment_source.paypal.shipping.address.admin_area_1'),
                        $paymentTokenResponse->json('payment_source.paypal.shipping.address.postal_code'),
                        null,
                        trim($paymentTokenResponse->json('payment_source.paypal.shipping.address.country_code')),
                    ),
                    $paymentTokenResponse->json('payment_source.paypal.payer_id'),
                    $paymentTokenResponse->json('customer.id'),
                )
            );
        }

        return $this->completePayment(
            $cart,
            $vault_id,
            $data
        );
    }

    public function completePaymentWithSavedToken(
       Cart $cart, BillablePaymentMethod $paymentMethod, array $data = [] 
    ): PaymentResult
    {
        return $this->completePayment(
            $cart,
            $paymentMethod->token,
            $data
        );
    }

    /**
     * @see https://developer.paypal.com/docs/api/orders/v2/#orders_create
     */
    public function startSubscription(
        BillingPlan $plan,
        BillingPlanPrice $planPrice,
        #[\SensitiveParameter] string $vault_id,
        array $data = []
    ): SubscriptionResult
    {
        $subscriptionData = [
            'billing_subscription_id' => $billingSubscriptionId = $data['billing_subscription_id'],
        ];

        $planProviderMetadata = $planPrice->billing_plan_payment_provider_information()->where([
            'payment_provider' => PaymentProvider::PayPal
        ])->firstOr(fn () => $plan->billing_plan_payment_provider_information()->where([
            'payment_provider' => PaymentProvider::PayPal
        ])->firstOrFail());

        try {
            $subscriptionResponse = $this->makeRequest(
                '/v2/checkout/orders',
                $this->createSubscriptionOrder(
                    $plan,
                    $planPrice,
                    $vault_id,
                    $data['email'] ?? null
                ),
                ['PayPal-Request-Id' => Str::random(32)],
                RequestMethod::Post
            );

            \Illuminate\Support\Facades\Log::debug('Start Subscription With Token: Subscription Response', [
                'response' => $subscriptionResponse->json(),
            ]);

            switch (PayPalOrderStatus::tryFrom($status = $subscriptionResponse->json('status'))) {
                case PayPalOrderStatus::PAYER_ACTION_REQUIRED:
                    
                    $link = array_find(
                        $subscriptionResponse->json('links'), 
                        fn (array $link) => $link['rel'] === 'payer-action'
                    );
    
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::PENDING,
                        Result::success($subscriptionResponse->json()),
                        $link['href'],
                        null,
                        null,
                        $subscriptionResponse->json('id'), 
                        null,
                        $planProviderMetadata->payment_provider_plan_id,
                        'Subscription Initialization Success',
                        [
                            'provider_class' => get_class($this),
                            'checkout_url' => $link['href'],
                            'checkout_id' => $subscriptionResponse->json('id'),
                            ...$subscriptionData,
                            ...$subscriptionResponse->json()
                        ]
                    );
                case PayPalOrderStatus::COMPLETED:
                    try {
                        $purchaseUnits = $subscriptionResponse->json('purchase_units');

                        $completedStatus = array_all(
                            $purchaseUnits, 
                            fn (array $purchaseUnit): bool => array_all(
                                $purchaseUnit['payments']['captures'], 
                                fn (array $capture): bool => $capture['status'] === 'COMPLETED'
                            )
                        );

                        return $completedStatus ? new SubscriptionResult(
                            $completedStatus,
                            $billingSubscriptionId,
                            PaymentStatus::PAID,
                            SubscriptionStatus::ACTIVE,
                            Result::success($subscriptionResponse->json()),
                            null,
                            null,
                            null,
                            $subscriptionResponse->json('id'),
                            null,
                            $planProviderMetadata->payment_provider_plan_id,
                            'Subscription Payment Compled and is now Active',
                            [
                                'provider_class' => get_class($this),
                                'checkout_id' => $subscriptionResponse->json('id'),
                                ...$subscriptionData,
                                ...$subscriptionResponse->json()
                            ]
                        ) : new SubscriptionResult(
                            $completedStatus,
                            $billingSubscriptionId,
                            PaymentStatus::FAILED,
                            SubscriptionStatus::FAILED,
                            Result::fail($subscriptionResponse->json()),
                            null,
                            null,
                            null,
                            $subscriptionResponse->json('id'),
                            null,
                            $planProviderMetadata->payment_provider_plan_id,
                            'Subscription Payment Failure',
                            [
                                'provider_class' => get_class($this),
                                'checkout_id' => $subscriptionResponse->json('id'),
                                ...$subscriptionData,
                                ...$subscriptionResponse->json()
                            ],
                            true
                        );
                    } catch (Throwable $th) {
                        \Illuminate\Support\Facades\Log::error('Error trasforming COMPLETED status to Transaction Interface', ['PaypalHttp::capturePayment']);

                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::FAILED,
                            SubscriptionStatus::FAILED,
                            Result::fail(Result::fail(
                                new ErrorInfo(
                                    'Subscription Activation Failure',
                                    $th->getCode(),
                                    $th->getMessage(),
                                    [
                                        'billing_subscription_id' => $billingSubscriptionId,
                                        ...$subscriptionResponse->json()
                                    ],
                                    $th
                                )
                            )),
                            null,
                            null,
                            null,
                            null,
                            null,
                            $planProviderMetadata->payment_provider_plan_id,
                            'Subscription Payment Failure',
                            [
                                'provider_class' => get_class($this),
                                'checkout_id' => $subscriptionResponse->json('id'),
                                ...$subscriptionData,
                                ...$subscriptionResponse->json()
                            ],
                            true
                        );
                    }
                default:
                    return new SubscriptionResult(
                        false,
                        $billingSubscriptionId,
                        PaymentStatus::FAILED,
                        SubscriptionStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Subscription Activation Failure: " . $subscriptionResponse->json('name') ?? '', 
                                400,
                                $subscriptionResponse->json('message'),
                                [
                                    ...$subscriptionData,
                                    'response' => $subscriptionResponse->json()
                                ],
                            )
                        ),
                        "Subscription Activation Failure: " . $subscriptionResponse->json('message'),
                        true
                    );
            }
        } catch (RequestException $e) {
            \Illuminate\Support\Facades\Log::error('Failed Subscription With Token: Subscription Response', [
                'response' => $e->response->json(),
                'error' => $e->getMessage()
            ]);

            return new SubscriptionResult(
                success: false,
                billingSubscriptionId: $billingSubscriptionId,
                paymentStatus: PaymentStatus::FAILED,
                status: SubscriptionStatus::FAILED,
                result: Result::fail(
                    new ErrorInfo(
                        'Subscription Activation Failure',
                        $e->response->status(),
                        Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                        [
                            'billing_subscription_id' => $billingSubscriptionId,
                            'response' => $e->response->json()
                        ],
                        $e
                    )
                ),
                providerSubscriptionId: null,
                message: 'Subscription Activation Failure: ' . Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                metadata: [
                    'provider_class' => get_class($this),
                    'billing_subscription_id' => $billingSubscriptionId,
                    'error' => $e->getMessage(),
                    'response' => $e->response->json()
                ],
                throw: true
            );
        } catch (ConnectionException $ce) {
            \Illuminate\Support\Facades\Log::error('Failed Subscription With Token: Connection Error', [
                'error' => $ce->getMessage()
            ]);

            return new SubscriptionResult(
                success: false,
                billingSubscriptionId: $billingSubscriptionId,
                paymentStatus: PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                status: SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                result: Result::fail(
                    new ErrorInfo(
                        'Subscription Activation Failure',
                        $ce->getCode(),
                        $ce->getMessage(),
                        [
                            'billing_subscription_id' => $billingSubscriptionId,
                        ],
                        $ce
                    )
                ),
                providerSubscriptionId: null,
                message: 'Subscription Activation Failure: PayPal is unavailable',
                metadata: [
                    'provider_class' => get_class($this),
                    'billing_subscription_id' => $billingSubscriptionId,
                    'error' => $ce->getMessage()
                ],
                throw: true
            );
        }
    }

    public function startSubscriptionWithSavedToken(
        BillingPlan $plan, BillingPlanPrice $planPrice, BillablePaymentMethod $paymentMethod, array $data = []
    ): SubscriptionResult
    {
        return $this->startSubscription(
            $plan,
            $planPrice,
            $paymentMethod->token,
            $data
        );
    }

    public function startSubscriptionWithToken(
       BillingPlan $plan, BillingPlanPrice $planPrice, string $token, array $data = [] 
    ): SubscriptionResult
    {
         $paymentTokenResponse =  $this->makeRequest('/v3/vault/payment-tokens', [
            'payment_source' => [
                'token' => [
                    'id' => $token,
                    'type' => 'SETUP_TOKEN'
                ]
            ]
        ]);

        \Illuminate\Support\Facades\Log::debug('Complete Payment With Token: Payment Subscription Token Response', [
            'response' => $paymentTokenResponse->json(),
        ]);

        $vault_id = $paymentTokenResponse->json('id');

        if (isset($data['save_token']) && $data['save_token']) {

            $this->saveToken(
                $data['user'],
                new PayPalTokenData(
                    $vault_id,
                    $token,
                    Address::make(
                            $paymentTokenResponse->json('payment_source.paypal.email_address'),
                            $paymentTokenResponse->json('payment_source.paypal.name.full_name'),
                            null,
                            $paymentTokenResponse->json('payment_source.paypal.shipping.address.address_line_1'),
                            $paymentTokenResponse->json('payment_source.paypal.shipping.address.address_line_2'),
                            $paymentTokenResponse->json('payment_source.paypal.shipping.address.admin_area_2'),
                            $paymentTokenResponse->json('payment_source.paypal.shipping.address.admin_area_1'),
                            $paymentTokenResponse->json('payment_source.paypal.shipping.address.postal_code'),
                            null,
                            trim($paymentTokenResponse->json('payment_source.paypal.shipping.address.country_code')),
                        ) ,
                    $paymentTokenResponse->json('payment_source.paypal.payer_id'),
                    $paymentTokenResponse->json('customer.id'),
                )
            );
        }

        return $this->startSubscription(
            $plan,
            $planPrice,
            $vault_id,
            $data
        );
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

    /**
     * @see https://developer.paypal.com/docs/checkout/standard/customize/save-payment-methods-for-recurring-payments/
     */

    public function createSubscriptionOrder(
        BillingPlan $plan,
        BillingPlanPrice $planPrice,
        string $vault_id,
        ?string $email = null
    ): array
    {
        $currency_code = $planPrice->currency;
        $amount = Money::formatAmountUsingCurrency($planPrice->amount, $currency_code);

        \Illuminate\Support\Facades\Log::debug(__METHOD__, ['vault_id' => $vault_id]);

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'value' => $amount,
                        'currency_code' => $currency_code,
                        'breakdown' => [
                            'item_total' => [
                                'value' => $amount,
                                'currency_code' => $currency_code,
                            ]
                        ]
                    ],
                ]
            ],
            'payment_source' => [
                'paypal' => [
                    'vault_id' => $vault_id,
                    'email_address' => $email,
                    'stored_credential' => [
                        'payment_initiator' => 'MERCHANT',
                        'usage' => 'SUBSEQUENT',
                        'usage_pattern' => 'SUBSCRIPTION_PREPAID'
                    ]
                ]
            ],
        ];
    }
}