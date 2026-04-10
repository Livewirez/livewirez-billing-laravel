<?php

namespace Livewirez\Billing\Lib\PayPal\Traits;

use Exception;
use Throwable;
use Tekord\Result\Result;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\Lib\PayPal\Utils;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Traits\Calculations;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use function Livewirez\Billing\exception_info;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;

use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Lib\PayPal\SubscriptionsManager;
use Livewirez\Billing\Lib\PayPal\Enums\ErrorMessageMode;
use Livewirez\Billing\Lib\PayPal\Enums\PayPalSubscriptionStatus;


trait HandlesSubscriptions
{
    use Calculations;

    protected array $config = [];
    
    protected SubscriptionsManager $subscriptionsManager;

    public function __construct() 
    {
        $this->subscriptionsManager = new SubscriptionsManager($this->config);
    }

    public function initializeSubscriptionsManager()
    {
        $this->subscriptionsManager ??= new SubscriptionsManager;
    }

    public function initiateSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, InitializeOrderRequest $request): SubscriptionResult
    {
        $subscriptionData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'billing_subscription_id' => $billingSubscriptionId = $request->getBillingSubscriptionId(),
            'product_type' =>  $request->getProductType()->value,
        ];

        try {
            $response = $this->subscriptionsManager->createSubscription(
                $plan, $planPrice, $request->getSubscriptionStart()
            );

            \Illuminate\Support\Facades\Log::info(collect([
                'response' => $response->json(),
                'headers' => $response->headers(),
                'type' => 'product'
            ]), ['Initialize Subscription']);

            switch (PayPalSubscriptionStatus::tryFrom($response->json('status'))) {
                case PayPalSubscriptionStatus::APPROVAL_PENDING:
                    $link = array_find(
                        $response->json('links'), 
                        fn (array $link) => $link['rel'] === 'approve'
                    );

                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::APPROVAL_PENDING,
                        Result::success($response->json()),
                        $link['href'],
                        $response->json('id'),
                        $response->json('id'),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Initialization Success',
                        [
                            'checkout_url' => $link['href'],
                            ...$response->json()
                        ]
                    );
                case PayPalSubscriptionStatus::APPROVED:
                   $providerSubscriptionId = $response->json('id');
                    try{
                        $activationResponse = $this->subscriptionsManager->activateSubscription($providerSubscriptionId);

                        if ($activationResponse->successful()) {
        
                            return new SubscriptionResult(
                                true,
                                $billingSubscriptionId,
                                PaymentStatus::PENDING,
                                SubscriptionStatus::ACTIVE,
                                Result::success($response->json()),
                                null,
                                $response->json('id', $providerSubscriptionId),
                                $response->json('id', $providerSubscriptionId),
                                null,
                                null,
                                $response->json('plan_id'),
                                'Subscription Activation Successful',
                                [
                                    
                                ]
                            );
                        }

                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::PENDING,
                            SubscriptionStatus::PENDING,
                            Result::success($response->json()),
                            null,
                            $response->json('id', $providerSubscriptionId),
                            $response->json('id', $providerSubscriptionId),
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Activation Pending',
                            [
                                
                            ],
                            throw: false
                        );
                    } catch (RequestException $re) {
                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::FAILED,
                            SubscriptionStatus::FAILED,
                            Result::fail(
                            new ErrorInfo(
                                    "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_TITLE), 
                                    $re->response->status() ?? $re->getCode(),
                                    Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                    [
                                        'billingSubscriptionId' => $providerSubscriptionId,
                                        'response' => $re->response->json(),
                                        'name' => $re->response->json('name'),
                                    ],
                                    error: $re
                                )
                            ),
                            null,
                            $response->json('id', $providerSubscriptionId),
                            $response->json('id', $providerSubscriptionId),
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Activation Failure' .  Utils::formatErrorInfoMessages($re, ErrorMessageMode::RESULT_MESSAGE),
                            [
                                'billingSubscriptionId' => $providerSubscriptionId,
                                'error' => $re->getMessage(),
                                'response' => $re->response->json(),
                                'response_message' => [
                                    'details_issue' => $re->response->json('details.0.issue'),
                                    'details_description' => $re->response->json('details.0.description'),
                                    'message' => $re->response->json('message'),
                                ]
                            ],
                            throw: true
                        );

                    } catch (ConnectionException $ce) {
                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                            SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                            Result::success($response->json()),
                            null,
                            $response->json('id', $providerSubscriptionId),
                            $response->json('id', $providerSubscriptionId),
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Provider Unavailable',
                            [
                                'error' => $ce->getMessage(),
                                'code' => $ce->getCode()
                            ],
                            throw: true
                        );
                    } catch (Throwable $th) {
                        exception_info($th, ['Initiate Subscription']);

                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::FAILED,
                            SubscriptionStatus::FAILED,
                            Result::fail(new ErrorInfo(
                                    "Subscription Initiation Failure: " . $th->getMessage(), 
                                    $th->getCode(),
                                    $th->getMessage(),
                                    [
                                        'billingSubscriptionId' => $billingSubscriptionId,
                                    ],
                                    error: $th
                                )
                            ),
                            null,
                            $providerSubscriptionId,
                            $providerSubscriptionId,
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Provider Unavailable',
                            [
                                
                            ],
                            throw: true
                        );
                    }
                case PayPalSubscriptionStatus::ACTIVE:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PAID,
                        SubscriptionStatus::ACTIVE,
                        Result::success($response->json()),
                        null,
                        $response->json('id'),
                        $response->json('id'),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Active',
                        [
                            ...$response->json()
                        ]
                    );
                case SubscriptionStatus::SUSPENDED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::SUSPENDED,
                        Result::success($response->json()),
                        null,
                        $response->json('id'),
                        $response->json('id'),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Suspended',
                        [
                            ...$response->json()
                        ]
                    );
                case SubscriptionStatus::CANCELLED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::CANCELLED,
                        Result::success($response->json()),
                        null,
                        $response->json('id'),
                        $response->json('id'),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Canceled',
                        [
                            ...$response->json()
                        ]
                    );
                case SubscriptionStatus::EXPIRED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::EXPIRED,
                        Result::success($response->json()),
                        null,
                        $response->json('id'),
                        $response->json('id'),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Expired',
                        [
                            ...$response->json()
                        ]
                    );
                default:
                    return new SubscriptionResult(
                        success: false,
                        billingSubscriptionId: $billingSubscriptionId,
                        paymentStatus: PaymentStatus::FAILED,
                        status: SubscriptionStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Subscription Initialization Failure: " . $response->json('name') ?? '', 
                                400,
                                $response->json('message'),
                                [
                                    'billingSubscriptionId' => $billingSubscriptionId,
                                    'response' => $response->json()
                                ],
                            )
                        ),
                        providerSubscriptionId: $response->json('id'),
                        providerCheckoutId: $response->json('id'),
                        providerPlanId: $response->json('plan_id'),
                        message: "Payment Initialization Failure: " . $response->json('message'),
                        throw: true
                    );
            }
        } catch (RequestException $e) {
            exception_info($e, [__METHOD__, __LINE__]);
            return match ($status = $e->response->status()) {
                422 => new SubscriptionResult(
                        success: false,
                        billingSubscriptionId: $billingSubscriptionId,
                        paymentStatus: PaymentStatus::FAILED,
                        status: SubscriptionStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Subscription Initialization Failure: " .  $e->response->json('message'), 
                                422,
                                $e->response->json('details.0.issue'),
                                [
                                    'billingSubscriptionId' => $billingSubscriptionId,
                                    'response' => $response->json()
                                ],
                                error: $e
                            )
                        ),
                        message: "Subscription Initialization Failure: " . $e->response->json('details.0.issue') . ' : ' . $e->response->json('details.0.description'),
                        metadata: [
                            'billingSubscriptionId' => $billingSubscriptionId,
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
                429 => new SubscriptionResult(   
                    false,
                    $billingSubscriptionId,
                    PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                    SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                    Result::fail(
                        new ErrorInfo(
                            "Subscription Initialization Failure", 
                            $e->getCode(),
                            $e->getMessage(),
                            [
                                'billingSubscriptionId' => $billingSubscriptionId,
                            ],
                            $e
                        )
                    ),
                    message:  "Subscription Initialization Failure: Paypal is unavailable",
                    metadata: [
                        'error' => 'Connection Exception, Paypal unavailable',
                        'billingSubscriptionId' => $billingSubscriptionId,
                    ],
                    throw: true
                ),
                default => new SubscriptionResult(   
                        false,
                        $billingSubscriptionId,
                        PaymentStatus::FAILED,
                        SubscriptionStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Subscription Initialization Failure", 
                                $status,
                                $e->response->json('message') ?? $e->response->json('details.0.issue') ?? $e->response->json('details.0.issue') ?? $e->getMessage() ,
                                [
                                    'billingSubscriptionId' => $billingSubscriptionId,
                                ],
                                $e
                            )
                        ),
                        message:  "Subscription Initialization Failure: " . $e->response->json('message') ?? $e->getMessage(),
                        metadata: [
                            'billingSubscriptionId' => $billingSubscriptionId,
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
        } catch (ConnectionException $ce) {
            exception_info($ce, [__METHOD__, __LINE__]);
            return new SubscriptionResult(   
                false,
                $billingSubscriptionId,
                PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                Result::fail(
                    new ErrorInfo(
                        "Subscription Initialization Failure", 
                        $ce->getCode(),
                        $ce->getMessage(),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                        ],
                        $ce
                    )
                ),
                message:  "Subscription Initialization Failure: Paypal is unavailable",
                metadata: [
                    'error' => 'Connection Exception, Paypal unavailable',
                    'billingSubscriptionId' => $billingSubscriptionId,
                ],
                throw: true
            );
        } catch (Throwable $th) {
            exception_info($th, [__METHOD__, __LINE__]);
            return new SubscriptionResult(   
                false,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::fail(
                    new ErrorInfo(
                        "Subscription Initialization Failure", 
                        $th->getCode(),
                        $th->getMessage(),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                        ],
                        $th
                    )
                ),
                message:  "Subscription Initialization Failure: " . $th->getMessage(),
                metadata: [
                    'error' =>  $th->getMessage(),
                ],
                throw: true
            );
        }
    }

    
    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_capture
     * 
     */
    public function startSubscription(CompleteOrderRequest $request): SubscriptionResult
    {
        $billingSubscriptionId = $request->getBillingSubscriptionId();
        $providerSubscriptionId = $request->getProviderSubscriptionId();

        try {
            $response = $this->subscriptionsManager->getSubscription($providerSubscriptionId);
    
            \Illuminate\Support\Facades\Log::info(collect([
                'response' => $response->json(),
                'headers' => $response->headers(),
                'type' => 'product'
            ]), ['Initialize Oder']);
    
            switch (PayPalSubscriptionStatus::tryFrom($response->json('status'))) {
                case PayPalSubscriptionStatus::APPROVAL_PENDING:
                    $link = array_find(
                        $response->json('links'), 
                        fn (array $link) => $link['rel'] === 'approve'
                    );
    
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::APPROVAL_PENDING,
                        Result::success($response->json()),
                        $link['href'],
                        $response->json('id', $providerSubscriptionId),
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Activation Success',
                        [
                            'checkout_url' => $link['href'],
                            ...$response->json()
                        ]
                    );
                case PayPalSubscriptionStatus::APPROVED:

                    try {
                        $response = $this->subscriptionsManager->activateSubscription($providerSubscriptionId);

                        if ($response->successful()) {
        
                            return new SubscriptionResult(
                                true,
                                $billingSubscriptionId,
                                PaymentStatus::PENDING,
                                SubscriptionStatus::ACTIVE,
                                Result::success($response->json()),
                                null,
                                $response->json('id', $providerSubscriptionId),
                                $response->json('id', $providerSubscriptionId),
                                null,
                                null,
                                $response->json('plan_id'),
                                'Subscription Activation Successful',
                                [
                                    
                                ]
                            );
                        }

                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::PENDING,
                            SubscriptionStatus::PENDING,
                            Result::success($response->json()),
                            null,
                            $response->json('id', $providerSubscriptionId),
                            $response->json('id', $providerSubscriptionId),
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Activation Failed',
                            [
                                
                            ],
                            throw: true
                        );
                    } catch (RequestException $re) {

                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::FAILED,
                            SubscriptionStatus::FAILED,
                            Result::fail(
                            new ErrorInfo(
                                    "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_TITLE), 
                                    $re->response->status() ?? $re->getCode(),
                                    Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_MESSAGE),
                                    [
                                        'billingSubscriptionId' => $billingSubscriptionId,
                                        'providerSubscriptionId' => $providerSubscriptionId,
                                        'response' => $re->response->json(),
                                        'name' => $re->response->json('name'),
                                    ],
                                    error: $re
                                )
                            ),
                            null,
                            $response->json('id', $providerSubscriptionId),
                            $response->json('id', $providerSubscriptionId),
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Activation Failure' .  Utils::formatErrorInfoMessages($re, ErrorMessageMode::RESULT_MESSAGE),
                            [
                                'billingSubscriptionId' => $billingSubscriptionId,
                                'providerSubscriptionId' => $providerSubscriptionId,
                                'error' => $re->getMessage(),
                                'response' => $re->response->json(),
                                'response_message' => [
                                    'details_issue' => $re->response->json('details.0.issue'),
                                    'details_description' => $re->response->json('details.0.description'),
                                    'message' => $re->response->json('message'),
                                ]
                            ],
                            throw: true
                        );
                    } catch (ConnectionException) {
                        return new SubscriptionResult(
                            false,
                            $billingSubscriptionId,
                            PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                            SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                            Result::success($response->json()),
                            null,
                            $providerSubscriptionId,
                            $providerSubscriptionId,
                            null,
                            null,
                            $response->json('plan_id'),
                            'Subscription Provider Unavailable',
                            [
                                'billingSubscriptionId' => $billingSubscriptionId,
                                'providerSubscriptionId' => $providerSubscriptionId,
                            ],
                            throw: true
                        );
                    }
    
                case PayPalSubscriptionStatus::ACTIVE:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PAID,
                        SubscriptionStatus::ACTIVE,
                        Result::success($response->json()),
                        null,
                        $response->json('id', $providerSubscriptionId),
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Active',
                        [
                            ...$response->json()
                        ]
                    );
                case PayPalSubscriptionStatus::SUSPENDED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::SUSPENDED,
                        Result::success($response->json()),
                        null,
                        $response->json('id', $providerSubscriptionId),
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Suspended',
                        [
                            ...$response->json()
                        ]
                    );
                case PayPalSubscriptionStatus::CANCELLED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::CANCELLED,
                        Result::success($response->json()),
                        null,
                        $response->json('id', $providerSubscriptionId),
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Canceled',
                        [
                            ...$response->json()
                        ]
                    );
                case PayPalSubscriptionStatus::EXPIRED:
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::EXPIRED,
                        Result::success($response->json()),
                        null,
                        $response->json('id', $providerSubscriptionId),
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Expired',
                        [
                            ...$response->json()
                        ]
                    );
                default:
                    return new SubscriptionResult(
                        success: false,
                        billingSubscriptionId: $billingSubscriptionId,
                        paymentStatus: PaymentStatus::FAILED,
                        status: SubscriptionStatus::FAILED,
                        result: Result::fail(
                            new ErrorInfo(
                                "Subscription Get Failure: " . $response->json('name') ?? '', 
                                400,
                                $response->json('message'),
                                [
                                    'billingSubscriptionId' => $billingSubscriptionId,
                                    'providerSubscriptionId' => $providerSubscriptionId,
                                    'response' => $response->json()
                                ],
                            )
                        ),
                        providerSubscriptionId: $response->json('id',  $providerSubscriptionId),
                        providerPlanId: $response->json('plan_id'),
                        message: "Payment Get Failure: " . $response->json('message'),
                        throw: true
                    );
            }
        } catch (RequestException $e) {
            return match ($status = $e->response->status()) {
                422 => new SubscriptionResult(
                    success: false,
                    billingSubscriptionId: $billingSubscriptionId,
                    paymentStatus: PaymentStatus::FAILED,
                    status: SubscriptionStatus::FAILED,
                    result: Result::fail(
                        new ErrorInfo(
                            "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                            422,
                            Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                            [
                                'billingSubscriptionId' => $billingSubscriptionId,
                                'providerSubscriptionId' => $providerSubscriptionId,
                                'response' => $response->json()
                            ],
                            error: $e
                        )
                    ),
                    providerSubscriptionId:  $providerSubscriptionId,
                    providerCheckoutId:  $providerSubscriptionId,
                    message: "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                    metadata: [
                        'billingSubscriptionId' => $billingSubscriptionId,
                        'providerSubscriptionId' => $providerSubscriptionId,
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
                default => new SubscriptionResult(   
                    false,
                    $billingSubscriptionId,
                    PaymentStatus::FAILED,
                    SubscriptionStatus::FAILED,
                    Result::fail(
                        new ErrorInfo(
                            "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_TITLE), 
                            $status,
                            Utils::formatErrorInfoMessages($e, ErrorMessageMode::ERROR_INFO_MESSAGE),
                            [
                                'billingSubscriptionId' => $billingSubscriptionId,
                                'providerSubscriptionId' => $providerSubscriptionId,
                            ],
                            $e
                        )
                    ),
                    providerSubscriptionId:  $providerSubscriptionId,
                    providerCheckoutId:$providerSubscriptionId,
                    providerOrderId: null,
                    providerTransactionId: null,
                    providerPlanId: null,
                    message:  "Subscription Activation Failure: " . Utils::formatErrorInfoMessages($e, ErrorMessageMode::RESULT_MESSAGE),
                    metadata: [
                        'billingSubscriptionId' => $billingSubscriptionId,
                        'providerSubscriptionId' => $providerSubscriptionId,
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
        } catch (ConnectionException $ce) {
            return new SubscriptionResult(   
                false,
                $billingSubscriptionId,
                PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                Result::fail(
                    new ErrorInfo(
                        "Subscription Activation Failure", 
                        $ce->getCode(),
                        $ce->getMessage(),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                            'providerSubscriptionId' => $providerSubscriptionId,
                        ],
                        $ce
                    )
                ),
                providerSubscriptionId:  $providerSubscriptionId,
                providerOrderId: null,
                providerCheckoutId: null,
                providerTransactionId: null,
                providerPlanId: null,
                message:  "Subscription Activation Failure: Paypal is unavailable",
                metadata: [
                    'billingSubscriptionId' => $billingSubscriptionId,
                    'providerSubscriptionId' => $providerSubscriptionId,
                    'error' => 'Connection Exception, Paypal unavailable'
                ],
                throw: true
            );
        } catch (Throwable $th) {
            return new SubscriptionResult(   
                false,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::fail(
                    new ErrorInfo(
                        "Subscription Get Failure", 
                        $th->getCode(),
                        $th->getMessage(),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                            'providerSubscriptionId' => $providerSubscriptionId,
                        ],
                        $th
                    )
                ),
                providerSubscriptionId:  $providerSubscriptionId,
                providerOrderId: null,
                providerCheckoutId: null,
                providerTransactionId: null,
                providerPlanId: null,
                message:  "Subscription Activation Failure: " . $th->getMessage(),
                metadata: [
                    'billingSubscriptionId' => $billingSubscriptionId,
                    'providerSubscriptionId' => $providerSubscriptionId,
                    'error' =>  $th->getMessage(),
                ],
                throw: true
            );
        }
    }

     /**
     * Modify an existing subscription (e.g., change plan, quantity, or billing details).
     *
     * @param string $billingSubscriptionId The local subscription ID.
     * @param string $providerSubscriptionId The provider subscription ID.
     * @param BillingPlan $newPlan The new plan to switch to.
     * @param array $data Additional data for the modification (e.g., quantity, start time).
     * @return SubscriptionResult
     */
    public function updateSubscription(
        string $billingSubscriptionId, 
        string $providerSubscriptionId, 
        BillingPlanPrice $newPlanPrice, 
        array $data = []
    ): SubscriptionResult
    {
        try {
            $response = $this->subscriptionsManager->reviseSubscription(
                $providerSubscriptionId,
                $newPlanPrice
            );

            if ($response->successful()) {

                $link = array_find(
                    $response->json('links'), 
                    fn (array $link) => $link['rel'] === 'approve'
                );

                return new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::PENDING,
                    SubscriptionStatus::APPROVAL_PENDING,
                    Result::success($response->json()),
                    $link['href'],
                    $response->json('id', $providerSubscriptionId),
                    $response->json('id', $providerSubscriptionId),
                    null,
                    null,
                    $response->json('plan_id'),
                    'Subscription Modification Successful',
                    [
                        'checkout_url' => $link['href'],
                        ...$response->json()
                    ]
                );
            }

            return new SubscriptionResult(
                false,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::success($response->json()),
                null,
                $response->json('id', $providerSubscriptionId),
                $response->json('id', $providerSubscriptionId),
                null,
                null,
                $response->json('plan_id'),
                'Subscription Modification Failed',
                [
                    
                ],
                throw: true
            );
        } catch (RequestException $re) {

            return new SubscriptionResult(
                false,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::fail(
                new ErrorInfo(
                        "Subscription Modification Failure: " . Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_TITLE), 
                        $re->response->status() ?? $re->getCode(),
                        Utils::formatErrorInfoMessages($re, ErrorMessageMode::ERROR_INFO_MESSAGE),
                        [
                            'billingSubscriptionId' => $providerSubscriptionId,
                            'response' => $re->response->json(),
                            'name' => $re->response->json('name'),
                        ],
                        error: $re
                    )
                ),
                null,
                $providerSubscriptionId,
                $providerSubscriptionId,
                null,
                null,
                null,
                'Subscription Modification Failure' .  Utils::formatErrorInfoMessages($re, ErrorMessageMode::RESULT_MESSAGE),
                [
                    'billingSubscriptionId' => $providerSubscriptionId,
                    'error' => $re->getMessage(),
                    'response' => $re->response->json(),
                    'response_message' => [
                        'details_issue' => $re->response->json('details.0.issue'),
                        'details_description' => $re->response->json('details.0.description'),
                        'message' => $re->response->json('message'),
                    ]
                ],
                throw: true
            );
        } catch (ConnectionException $ce) {
            return new SubscriptionResult(
                false,
                $billingSubscriptionId,
                PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                SubscriptionStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                Result::fail(
                    new ErrorInfo(
                        "Subscription Modification Failure", 
                        $ce->getCode(),
                        $ce->getMessage(),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                            'providerSubscriptionId' => $providerSubscriptionId,
                        ],
                        $ce
                    )
                ),
                providerSubscriptionId:  $providerSubscriptionId,
                providerCheckoutId: null,
                providerOrderId: null,
                providerTransactionId: null,
                providerPlanId: null,
                message:  "Subscription Modification Failure: Paypal is unavailable",
                metadata: [
                    'billingSubscriptionId' => $billingSubscriptionId,
                    'providerSubscriptionId' => $providerSubscriptionId,
                    'error' => 'Connection Exception, Paypal unavailable'
                ],
                throw: true
            );
        }
    }

    /**
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_get
     */
    public function getSubscription(string $providerSubscriptionId): array
    {
        return $this->subscriptionsManager->getSubscription($providerSubscriptionId)->json();
    }

    /**
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_list
     */
    public function listSubscriptions(): array
    {
        return $this->subscriptionsManager->listSubscriptions()->json('subscriptions');
    }

    /**
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_activate
     */
    public function activateSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = $this->subscriptionsManager->activateSubscription($providerSubscriptionId);

            return $response->successful();
        } catch (RequestException $re) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' => $re,
                'message' => $re->getMessage(),
                'response' => $re->response->json(),
                'headers' => $re->response->headers(),
                'type' => 'subscription'
            ]), ['Activate Subscription']);

            return false;
        }  catch (ConnectionException $ce) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $ce,
                'message' =>  $ce->getMessage() . 'Paypal Unavailable',
                'type' => 'subscription'
            ]), ['Activate Subscription']);

            return false;
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $e,
                'message' =>  $e->getMessage(),
                'type' => 'subscription'
            ]), ['Activate Subscription']);
            
            return false;
        }
    }

    /**
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_cancel
     */
    public function cancelSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = $this->subscriptionsManager->cancelSubscription($providerSubscriptionId);

            return $response->successful();
        } catch (RequestException $re) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' => $re,
                'message' => $re->getMessage(),
                'response' => $re->response->json(),
                'headers' => $re->response->headers(),
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            return false;
        }  catch (ConnectionException $ce) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $ce,
                'message' =>  $ce->getMessage() . 'Paypal Unavailable',
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            return false;
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $e,
                'message' =>  $e->getMessage(),
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            
            return false;
        }
    }

    public function resumeSubscription(string $providerSubscriptionId): bool
    {
        return $this->activateSubscription($providerSubscriptionId);
    }

    /**
     * @see https://developer.paypal.com/docs/api/subscriptions/v1/#subscriptions_suspend
     */
    public function pauseSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = $this->subscriptionsManager->suspendSubscription($providerSubscriptionId);

            return $response->successful();
        } catch (RequestException $re) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' => $re,
                'message' => $re->getMessage(),
                'response' => $re->response->json(),
                'headers' => $re->response->headers(),
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            return false;
        }  catch (ConnectionException $ce) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $ce,
                'message' =>  $ce->getMessage() . 'Paypal Unavailable',
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            return false;
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' =>  $e,
                'message' =>  $e->getMessage(),
                'type' => 'subscription'
            ]), ['Cancel Subscription']);
            
            return false;
        }
    }

    public function getSubscriptionStatus(string $providerSubscriptionId): SubscriptionStatus
    {
        try {
            $response = $this->subscriptionsManager->getSubscription($providerSubscriptionId);

           return SubscriptionStatus::from($response->json('status'));
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error(collect([
                'error' => $e,
                'error_class' => get_class($e),
                'message' => $e->getMessage(),
                'response' => $e instanceof RequestException ? $e->response->json() : get_class($e) .': ' . $e->getMessage(),
                'headers' =>  $e instanceof RequestException ? $e->response->headers() : get_class($e) .': No Heasers Available',
                'error_code' => $e->getCode(),
                'status' => $e instanceof RequestException ?  $e->response->status() : get_class($e) .': No Status Code',
                'type' => 'subscription'
            ]), ['Get Subscription Status']);

            return SubscriptionStatus::FAILED;
        }
    }
}