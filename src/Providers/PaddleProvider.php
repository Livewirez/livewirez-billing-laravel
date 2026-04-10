<?php 

namespace Livewirez\Billing\Providers;

use Exception;
use DomainException;
use Tekord\Result\Result;
use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\ErrorInfo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\Lib\Paddle\Paddle;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Enums\RequestMethod;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Models\BillingPlanPrice;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Lib\Paddle\Traits\HandlesWebhooks;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Lib\Paddle\Exceptions\PaddleApiError;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

use function Livewirez\Billing\exception_info;

class PaddleProvider implements PaymentProviderInterface
{
    use HandlesWebhooks;

    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface
    {
        throw new DomainException('Tokenized Payments are unsupported by paddle');
    }

    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult
    {
        $paymentData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'product_type' =>  $request->getProductType()->value,
            'billable_id' => $request->getUser()->getKey(),
            'billable_type' => $request->getUser()->getMorphClass(),
        ];

        if ($cart instanceof BillingProduct) {
            $cart = Cart::fromProduct($cart);
        }

        foreach($cart->getProducts() as $product) {

            if ($product instanceof BillingProduct) {
                $product->loadMissing([
                    'billing_product_payment_provider_information' => fn ($query) => $query->where('payment_provider', PaymentProvider::Paddle),
                ]);
            }
        }

        /**'items' =>  [
                    {
                        "quantity": 20,
                        "price_id": "pri_01gsz91wy9k1yn7kx82aafwvea"
                    },
                    {
                        "quantity": 1,
                        "price_id": "pri_01gsz96z29d88jrmsf2ztbfgjg"
                    },
                    {
                        "quantity": 1,
                        "price_id": "pri_01gsz98e27ak2tyhexptwc58yk"
                    }
                ] */
        try {
            $response = Paddle::api("POST", "transactions", [
                "items" => array_map(function (CartItemInterface $item) {

                    return [
                        'quantity' => $item->getQuantity(),
                        'price_id' => $item->getProduct()->billing_product_payment_provider_information->first()->payment_provider_price_id
                    ];
                }, $cart->all()),
                "currency_code" => $cart->getCurrencyCode(),
                "collection_mode" => "automatic",
                'custom_data' => [
                    'metadata' => $paymentData
                ]
            ]);

            return new PaymentResult(
                true,
                $request->getBillingOrderId(),
                PaymentStatus::PENDING,
                Result::success($response->json()),
                null,
                null,
                null,
                null,
                $response->json('data.id'),
                'Payment Initialization Success',
                [
                    'request_id' => $response->json('meta.request_id'),
                    'provider_class' => get_class($this),
                    'transaction_id' => $response->json('data.id'),
                    ...$paymentData,
                    ...$response->json('data')
                ]
            );
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    /** @see https://developer.paddle.com/api-reference/transactions/get-transaction#get-a-transaction */ 
    public function completePayment(CompleteOrderRequest $request): PaymentResult
    {
        $transactionId = $request->getProviderTransactionId();

        try {
            $response = Paddle::api("GET", "transactions/{$transactionId}");

            return match ($response->json('data.status')) {
                'ready',
                'draft' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::PENDING,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                'billed' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::APPROVED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                'paid' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::PAID,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                'completed' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::COMPLETED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                'canceled' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::CANCELED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                'past_due' => new PaymentResult(
                    true,
                    $request->getBillingOrderId(),
                    PaymentStatus::PAST_DUE,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
                default => new PaymentResult(
                    false,
                    $request->getBillingOrderId(),
                    PaymentStatus::FAILED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $transactionId),
                    'Payment Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id', $transactionId),
                        ...$response->json('data')
                    ]
                ),
            };
        } catch (PaddleApiError $e) {

            exception_info($e, [__METHOD__.__LINE__], ['trace']);

            return new PaymentResult(
                false,
                $request->getBillingOrderId(),
                PaymentStatus::FAILED,
                Result::fail(
                    new ErrorInfo(
                    "Payment Completion Failure: " . $e->getMessage(), 
                    400,
                    $e->getMessage(),
                    [
                        'billingOrderId' => $request->getBillingOrderId(),
                        'paymentProviderTransactionId' => $request->getProviderTransactionId(),
                    ],
                    $e
                )),
                null,
                null,
                null,
                null,
                $transactionId,
                'Payment Completion Failure',
                [
                    'provider_class' => get_class($this),
                    'transaction_id' => $transactionId,
                ],
                true
            );
        }
    }

    public function refundPayment(string $billingOrderId, string $providerOrderId): bool
    {
        throw new Exception('Unsupported');
    }
    
    public function getPaymentStatus(string $providerOrderId): PaymentStatus
    {
        $response = Paddle::api("GET", "transactions/{$providerOrderId}");

        return match ($response->json('data.status')) {
            'ready',
            'draft'     => PaymentStatus::PENDING,
            'billed'    => PaymentStatus::APPROVED,
            'paid'      => PaymentStatus::PAID,
            'completed' => PaymentStatus::COMPLETED,
            'canceled'  => PaymentStatus::CANCELED,
            'past_due' => PaymentStatus::PAST_DUE,
            default => PaymentStatus::FAILED,
        };
    }

    public function initiateSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, InitializeOrderRequest $request): SubscriptionResult
    {
        $paymentData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'product_type' =>  $request->getProductType()->value,
            'billing_subscription_id' => $billingSubscriptionId = $request->getBillingSubscriptionId(),
            'billing_subscription_transaction_id' => $billingSubscriptionTransactionId = $request->getBillingSubscriptionTransactionId(),
            'billable_id' => $request->getUser()->getKey(),
            'billable_type' => $request->getUser()->getMorphClass(),
        ];

        $start = $request->getSubscriptionStart();
        $end = $request->getSubscriptionEnd();

        try {
            $response = Paddle::api("POST", "transactions", [
                "items" => [
                    [
                        'quantity' => 1,
                        'price_id' => $planPrice->billing_plan_price_payment_provider_information()->where([
                            'billing_plan_id' => $plan->id,
                            'payment_provider' => PaymentProvider::Paddle,
                            'is_active' => true
                        ])->first()->payment_provider_plan_price_id,
                    ]
                ],
                "currency_code" => $planPrice->currency,
                "collection_mode" => "automatic",
                'billing_period' => [
                    "starts_at" => $start->format(DATE_RFC3339),
                    "ends_at" => $end->format(DATE_RFC3339),
                ],
                'custom_data' => [
                    'metadata' => $paymentData
                ]
            ]);

            return new SubscriptionResult(
                true,
                $request->getBillingSubscriptionId(),
                PaymentStatus::PENDING,
                SubscriptionStatus::APPROVAL_PENDING,
                Result::success($response->json()),
                null,
                null,
                null,
                null,
                $response->json('data.id'),
                $response->json('data.items.0.price.id'),
                'Subscription Initialization Success',
                [
                    'request_id' => $response->json('meta.request_id'),
                    'provider_class' => get_class($this),
                    'transaction_id' => $response->json('data.id'),
                    ...$paymentData,
                    ...$response->json('data')
                ]
            );
        } catch (PaddleApiError $e) {
            throw new PaddleApiError($e->getMessage(), 400);
        }
    }

    public function startSubscription(CompleteOrderRequest $request): SubscriptionResult
    {
        $billingSubscriptionId = $request->getBillingSubscriptionId();
        $providerTransactionId = $request->getProviderTransactionId() ?? $request->getProviderSubscriptionId();

        try {
            $response = Paddle::api("GET", "transactions/{$providerTransactionId}");

            return match ($response->json('data.status')) {
                'ready',
                'draft' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::PENDING,
                    SubscriptionStatus::APPROVAL_PENDING,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id'),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                'billed' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::APPROVED,
                    SubscriptionStatus::PENDING,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                'paid' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::PAID,
                    SubscriptionStatus::ACTIVE,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                'completed' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::COMPLETED,
                    SubscriptionStatus::ACTIVE,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                'canceled' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::CANCELED,
                    SubscriptionStatus::CANCELED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                'past_due' => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::PAST_DUE,
                    SubscriptionStatus::PAST_DUE,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                ),
                default => new SubscriptionResult(
                    true,
                    $billingSubscriptionId,
                    PaymentStatus::FAILED,
                    SubscriptionStatus::FAILED,
                    Result::success($response->json()),
                    null,
                    null,
                    null,
                    null,
                    $response->json('data.id', $providerTransactionId),
                    $response->json('data.items.0.price.id'),
                    'Subscription Completion Success',
                    [
                        'request_id' => $response->json('meta.request_id'),
                        'provider_class' => get_class($this),
                        'transaction_id' => $response->json('data.id'),
                        ...$response->json('data')
                    ]
                )
            };
        } catch (PaddleApiError $e) {

            exception_info($e, [__METHOD__.__LINE__], ['trace']);

            return new SubscriptionResult(
                true,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::fail(
                    new ErrorInfo(
                    "Subscription Payment Completion Failure: " . $e->getMessage(), 
                    400,
                    $e->getMessage(),
                    [
                        'billingOrderId' => $request->getBillingOrderId(),
                        'paymentProviderTransactionId' => $request->getProviderTransactionId(),
                    ],
                    $e
                )),
                null,
                null,
                null,
                null,
                $providerTransactionId,
                null,
                'Subscription Payment Completion Failure',
                [
                    'provider_class' => get_class($this),
                    'transaction_id' => $request->getBillingPaymenTransactionId(),
                    'provider_transaction_id' => $providerTransactionId,
                ],
                true
            );
        }
    }

    public function updateSubscription(
        string $billingSubscriptionId, 
        string $providerSubscriptionId, 
        BillingPlanPrice $newPlanPrice, 
        array $data = []
    ): SubscriptionResult
    {
        throw new Exception('Unsupported');
    }

    public function getSubscription(string $providerSubscriptionId): array
    {
        $response = Paddle::api("GET", "subscriptions/{$providerSubscriptionId}");

        return $response->json('data');
    }

    public function listSubscriptions(): array
    {
        $response = Paddle::api("GET", "subscriptions");

        return $response->json('data');
    }

    /** @see https://developer.paddle.com/api-reference/subscriptions/cancel-subscription#cancel-a-subscription */
    public function cancelSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = Paddle::api("POST", "subscriptions/{$providerSubscriptionId}/cancel", [
                'effective_from' => 'next_billing_period' // next_billing_period, immediately
            ]);

            return $response->successful();
        } catch (PaddleApiError $e) {
            exception_info($e, [__METHOD__.__LINE__], ['trace']);

            return false;
        }
    }

    /**
     * @see https://developer.paddle.com/api-reference/subscriptions/pause-subscription#pause-a-subscription
     */
    public function pauseSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = Paddle::api("POST", "subscriptions/{$providerSubscriptionId}/resume", [
                /**
                 *  RFC 3339 datetime string or immediately
                 */
                'effective_from' => 'next_billing_period', // next_billing_period, immediately
                'on_resume' => 'start_new_billing_period' // continue_existing_billing_period, start_new_billing_period
            ]);

            return $response->successful();
        } catch (PaddleApiError $e) {
            exception_info($e, [__METHOD__.__LINE__], ['trace']);

            return false;
        }
    }

     /**
     * @see https://developer.paddle.com/api-reference/subscriptions/resume-subscription#resume-a-paused-subscription
     */
    public function resumeSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = Paddle::api("POST", "subscriptions/{$providerSubscriptionId}/resume", [
                /**
                 *  RFC 3339 datetime string or immediately
                 */
                'effective_from' => 'immediately',
                'on_resume' => 'start_new_billing_period' // continue_existing_billing_period, start_new_billing_period
            ]);

            return $response->successful();
        } catch (PaddleApiError $e) {
            exception_info($e, [__METHOD__.__LINE__], ['trace']);

            return false;
        }
    }

    public function getSubscriptionStatus(string $providerSubscriptionId): SubscriptionStatus
    {
        $response = Paddle::api("GET", "subscriptions/{$providerSubscriptionId}");

        return match ($response->json('data.status')) {
            'ready',
            'draft' => SubscriptionStatus::APPROVAL_PENDING,
            'billed' => SubscriptionStatus::PENDING,
            'paid',
            'completed' => SubscriptionStatus::ACTIVE,
            'canceled' => SubscriptionStatus::CANCELED,
            'past_due' => SubscriptionStatus::PAST_DUE,
            default => SubscriptionStatus::FAILED,
        };
    }
}