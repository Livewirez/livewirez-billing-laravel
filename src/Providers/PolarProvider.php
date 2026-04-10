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
use Livewirez\Billing\Lib\Polar\Polar;
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
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Lib\Polar\Traits\HandlesWebhooks;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Lib\Polar\Handlers\ProcessWebhook;
use Livewirez\Billing\Lib\Polar\Traits\ManagesCheckouts;
use Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

class PolarProvider implements PaymentProviderInterface
{
    use ManagesCheckouts, HandlesWebhooks;

    public function __construct(protected array $config = [])
    {
        $this->config = $config !== [] ? $config : config('billing.providers.polar');
    }

    protected function makeRequest(string $uri, array $data = [], array $headers = [], RequestMethod $method = RequestMethod::Post): Response
    {
        if (empty($token = $this->config['access_token'])) {
            throw new Exception('Polar API key not set.');
        }

        $client = Http::baseUrl($this->config['base_url'][$this->config['environment']])
                ->asJson()
                ->withToken($token)
                ->withHeaders($headers)
                ->retry(
                    3, 
                    100, 
                    fn(Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException
                )
                ->throw(function (Response $r, RequestException $e) use ($uri) {
                    \Illuminate\Support\Facades\Log::info(collect([
                        'response' => $r,
                        'json_response' => $r->json(),
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

            RequestMethod::Put => $client->put( $uri, $data),

            RequestMethod::Delete => $client->delete( $uri, $data),

            default => $client->post($uri, $data)
        };
    }

    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface
    {
        throw new DomainException('Tokenized Payments are unsupported by polar');
    }

    /**
     * @source https://developer.paypal.com/docs/api/orders/v2/#orders_create
     */
    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult
    {
        if ($cart instanceof ProductInterface) {
            $cart = Cart::fromProduct($cart);
        }

        $totals = $cart->getItemTotals(false);

        $paymentData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'product_type' =>  $request->getProductType()->value,
            'billable_id' => $request->getUser()->getKey(),
            'billable_type' => $request->getUser()->getMorphClass(),
        ];

        $metadata = $request->getMetadata();

        $checkout = $this->checkout(
            $request->getUser(),
            array_map(fn (BillingProduct $product) => $product->metadata[PaymentProvider::Polar->value]['id'],
                $cart->getProducts()
            ),
            options: [
                'amount' => $totals,
                'success_url' => $metadata['success_url'] ?? $data['success_url'] ?? config('billing.providers.polar.redirect_url'),
                'country' => $data['country'] ?? null,
            ],
            metadata: $paymentData
        );

        $checkoutSession = $checkout->checkoutSession();

        \Illuminate\Support\Facades\Log::debug(collect([
            'cart' => $cart,
            'totals' => $totals,
            'data' => $request,
            'checkout' => $checkoutSession
        ]), ['Polar Payment Initialization']);

        return new PaymentResult(
            true,
            $request->getBillingOrderId(),
            PaymentStatus::PENDING,
            Result::success($checkoutSession),
            $checkoutSession->url,
            null,
            null,
            $checkoutSession->id,
            null,
            'Payment Initialization Success',
            [
                'provider_class' => get_class($this),
                'checkout_id' => $checkoutSession->id,
                'checkout_url' => $checkoutSession->url,
                ...$paymentData,
                ...$checkoutSession->toArray()
            ]
        );

    }

    public function completePayment(CompleteOrderRequest $request): ?PaymentResult
    {
        $providerCheckoutId = $request->getProviderCheckoutId();
        $billingOrderId = $request->getBillingOrderId();

        try {
            $response = Polar::api('GET', "v1/checkouts/{$providerCheckoutId}");

            switch ($response->json('status')) {
                // open, expired, confirmed, succeeded, failed
                case 'succeeded':
                    return new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::PAID,
                        Result::success($response->json()),
                        null,
                        null,
                        null,
                        $response->json('id', $providerCheckoutId),
                        null,
                        'Payment Successful',
                        [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $billingOrderId, 
                            'billing_order_id' => $billingOrderId,
                            'checkout_id' => $response->json('id', $providerCheckoutId), 
                            ...$response->json()
                        ]
                    );
                case 'open':
                case 'confirmed':
                    return new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::PENDING,
                        Result::success($response->json()),
                        null,
                        null,
                        null,
                        $response->json('id', $providerCheckoutId),
                        null,
                        'Payment Success',
                        [ 
                            'provider_class' => get_class($this),
                            'billingOrderId' => $billingOrderId,
                            'billing_order_id' => $billingOrderId,
                            'checkout_id' => $response->json('id', $providerCheckoutId), 
                            ...$response->json()
                        ]
                    );
                case 'expired':
                    return new PaymentResult(
                        true,
                        $billingOrderId,
                        PaymentStatus::FAILED,
                        Result::success($response->json()),
                        null,
                        null,
                        null,
                        $response->json('id', $providerCheckoutId),
                        null,
                        'Payment Failed (Expired)',
                        [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $billingOrderId,
                            'billing_order_id' => $billingOrderId,
                            'checkout_id' => $response->json('id', $providerCheckoutId),
                            ...$response->json()
                        ]
                    );
                case 'failed':
                default:
                    return new PaymentResult(
                        false,
                        $billingOrderId,
                        PaymentStatus::FAILED,
                        Result::success($response->json()),
                        null,
                        null,
                        null,
                        $response->json('id', $providerCheckoutId),
                        null,
                        'Payment Activation Failed',
                        [
                            'provider_class' => get_class($this),
                            'billingOrderId' => $billingOrderId,
                            'billing_order_id' => $billingOrderId,
                            'checkout_id' => $response->json('id', $providerCheckoutId),
                            ...$response->json()
                        ],
                        true
                    );
            }

        } catch (RequestException $re) {
            return new PaymentResult(
                false,
                $billingOrderId,
                PaymentStatus::FAILED,
                Result::fail(
                new ErrorInfo(
                        "Payment Failure: " . $re->response->json('detail.0.msg'), 
                        $re->response->status() ?? $re->getCode(),
                        $re->response->json('detail.0.msg'),
                        [
                            'billingOrderId' => $billingOrderId,
                            'billing_order_id' => $billingOrderId,
                            'response' => $re->response->json(),
                            'type' => $re->response->json('detail.0.type'),
                        ],
                        error: $re
                    )
                ),
                null,
                null,
                null,
                $providerCheckoutId,
                null,
                'Payment Failure' .  $re->response->json('detail.0.msg'),
                [
                    'checkout_id' => $providerCheckoutId,
                    'error' => $re->getMessage(),
                    'response' => $re->response->json(),
                    'response_message' => $re->response->json('detail.0')
                ],
                true
            );
        }  catch (ConnectionException $ce) {
            return new PaymentResult(   
                false,
                $billingOrderId,
                PaymentStatus::PAYMENT_PROVIDER_UNAVAILABLE,
                Result::fail(
                    new ErrorInfo(
                        "Payment Failure", 
                        $ce->getCode(),
                        $ce->getMessage(),
                        [
                            'billingOrderId' => $billingOrderId,
                            'billing_order_id' => $billingOrderId,
                            'checkout_id' => $providerCheckoutId,
                        ],
                        $ce
                    )
                ),
                null,
                null,
                null,
                $providerCheckoutId,
                null,
                "Payment Failure: Polar is unavailable",
                [
                   'billingOrderId' => $billingOrderId,
                   'billing_order_id' => $billingOrderId,
                    'error' => 'Connection Exception, Polar unavailable'
                ],
                true
            );
        }
    }

    public function refundPayment(string $billingOrderId, string $providerCheckoutId): bool
    {
        throw new Exception('PolarProvider::refundPayment not implemented.');
    }

    public function getPaymentStatus(string $providerOrderId): PaymentStatus
    {
        $response = Polar::api('GET', "v1/orders/{$providerOrderId}");

        return match ($response->json('status')) {
            'pending' => PaymentStatus::PENDING, 
            'paid' => PaymentStatus::PAID, 
            'refunded' => PaymentStatus::REFUNDED, 
            'partially_refunded' => PaymentStatus::REFUNDED,
            default => PaymentStatus::FAILED, 
        };
    }

    public function initiateSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, InitializeOrderRequest $request): SubscriptionResult
    {
        $subscriptionData = [
            'billing_order_id' => $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'billing_subscription_id' => $billingSubscriptionId = $request->getBillingSubscriptionId(),
            'billing_subscription_transaction_id' => $billingSubscriptionTransactionId = $request->getBillingSubscriptionTransactionId(),
            'product_type' =>  $request->getProductType()->value,
            'billable_id' => $request->getUser()->getKey(),
            'billable_type' => $request->getUser()->getMorphClass(),
        ];

        $metadata = $request->getMetadata();

        $checkout =  $this->subscribe(
            $request->getUser(),
            $planPrice->billing_plan_payment_provider_information()->where([
                'billing_plan_id' => $plan->id,
                'payment_provider' => PaymentProvider::Polar
            ])->first()->payment_provider_plan_id,
            options: [
                'success_url' => $metadata['success_url'] ?? config('billing.providers.polar.subscription_redirect_url'),
                'country' => $metadata['country'] ?? null,
            ],
            metadata: $subscriptionData
        );

        $checkoutSession = $checkout->checkoutSession();

        // if (!  $checkoutSession->subscriptionId) throw new PolarApiError('Failed to create subscription checkout session');

        \Illuminate\Support\Facades\Log::debug('checkout session', $checkoutSession->toArray());

        return new SubscriptionResult(
            true,
            $billingSubscriptionId,
            PaymentStatus::PENDING,
            SubscriptionStatus::APPROVAL_PENDING,
            Result::success($checkoutSession),
            $checkoutSession->url,
            null,
            $checkoutSession->id,
            null,
            null,
            $checkoutSession->productId,
            'Subscription Initialization Success',
            [
                'checkout_id' => $checkoutSession->id,
                'checkout_url' => $checkoutSession->url,
                ...$checkoutSession->toArray()
            ]
        );
    }

    /**
     * @source https://docs.polar.sh/api-reference/checkouts/get-session
     *
     */
    public function startSubscription(CompleteOrderRequest $request): SubscriptionResult
    {
        $billingSubscriptionId = $request->getBillingSubscriptionId();
        $providerSubscriptionId = $request->getProviderCheckoutId() ?? $request->getProviderSubscriptionId();

        try {
            $response = Polar::api('GET', "v1/checkouts/{$providerSubscriptionId}");

            switch ($response->json('status')) {
                // open, expired, confirmed, succeeded, failed
                case 'succeeded':
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PAID,
                        SubscriptionStatus::ACTIVE,
                        Result::success($response->json()),
                        null,
                        null,
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('product_id'),
                        'Subscription Activation Successful',
                        ['checkout_id' => $response->json('id', $providerSubscriptionId), ...$response->json()]
                    );
                case 'open':
                case 'confirmed':
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::PENDING,
                        SubscriptionStatus::PENDING,
                        Result::success($response->json()),
                        null,
                        null,
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('product_id'),
                        'Subscription Initialization Success',
                        ['checkout_id' => $response->json('id', $providerSubscriptionId), ...$response->json()]
                    );
                case 'expired':
                    return new SubscriptionResult(
                        true,
                        $billingSubscriptionId,
                        PaymentStatus::EXPIRED,
                        SubscriptionStatus::EXPIRED,
                        Result::success($response->json()),
                        null,
                        null,
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('product_id'),
                        'Subscription Expired',
                        [
                            'checkout_id' => $response->json('id', $providerSubscriptionId),
                            ...$response->json()
                        ]
                    );
                case 'failed':
                default:
                    return new SubscriptionResult(
                        false,
                        $billingSubscriptionId,
                        PaymentStatus::FAILED,
                        SubscriptionStatus::FAILED,
                        Result::success($response->json()),
                        null,
                        null,
                        $response->json('id', $providerSubscriptionId),
                        null,
                        null,
                        $response->json('plan_id'),
                        'Subscription Activation Failed',
                        [
                            'checkout_id' => $response->json('id', $providerSubscriptionId),
                            ...$response->json()
                        ],
                        throw: true
                    );
            }

        } catch (RequestException $re) {
            return new SubscriptionResult(
                false,
                $billingSubscriptionId,
                PaymentStatus::FAILED,
                SubscriptionStatus::FAILED,
                Result::fail(
                new ErrorInfo(
                        "Subscription Activation Failure: " . $re->response->json('detail.0.msg'), 
                        $re->response->status() ?? $re->getCode(),
                        $re->response->json('detail.0.msg'),
                        [
                            'billingSubscriptionId' => $billingSubscriptionId,
                            'checkout_id' => $providerSubscriptionId,
                            'response' => $re->response->json(),
                            'type' => $re->response->json('detail.0.type'),
                        ],
                        error: $re
                    )
                ),
                null,
                null,
                $providerSubscriptionId,
                null,
                null,
                null,
                'Subscription Activation Failure' .  $re->response->json('detail.0.msg'),
                [
                    'billingSubscriptionId' => $billingSubscriptionId,
                    'checkout_id' => $providerSubscriptionId,
                    'error' => $re->getMessage(),
                    'response' => $re->response->json(),
                    'response_message' => $re->response->json('detail.0')
                ],
                throw: true
            );
        }  catch (ConnectionException $ce) {
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
                            'checkout_id' => $providerSubscriptionId,
                        ],
                        $ce
                    )
                ),
                null,
                null,
                providerCheckoutId :  $providerSubscriptionId,
                providerTransactionId :  null,
                providerPlanId:  null,
                message:  "Subscription Activation Failure: Polar is unavailable",
                metadata: [
                    'billingSubscriptionId' => $billingSubscriptionId,
                    'checkout_id' => $providerSubscriptionId,
                    'error' => 'Connection Exception, Polar unavailable'
                ],
                throw: true
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
        throw new Exception('PolarProvider::initiateSubscriptionModification not implemented.');
    }

    public function getSubscription(string $providerSubscriptionId): array
    {
        $response = Polar::api('GET', "v1/subscriptions/{$providerSubscriptionId}");

        return $response->json();
    }

    public function listSubscriptions(): array
    {
        $response = Polar::api('GET', "v1/subscriptions");

        return $response->json();
    }

    public function cancelSubscription(string $providerSubscriptionId): bool
    {
        try {
            $response = Polar::api('DELETE', "v1/subscriptions/{$providerSubscriptionId}");

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

    public function pauseSubscription(string $providerSubscriptionId): bool
    {
        throw new PolarApiError('Polar does no support pausing / resuming subscriptions');
    }

    public function resumeSubscription(string $providerSubscriptionId): bool
    {
        throw new PolarApiError('Polar does no support resuming / pausing subscriptions');
    }

    public function getSubscriptionStatus(string $providerSubscriptionId): SubscriptionStatus
    {
        $response = $this->getSubscription($providerSubscriptionId);

        // incomplete, incomplete_expired, trialing, active, past_due, canceled, unpaid
        switch($response['status'] ?? null) {
            case 'incomplete':
            case 'incomplete_expired':
            case 'unpaid':
                return SubscriptionStatus::INACTIVE;
            case 'trialing':
                return SubscriptionStatus::TRIALING;
            case 'pending':
                return SubscriptionStatus::PENDING;
            case 'active':
                return SubscriptionStatus::ACTIVE;
            case 'canceled':
                return SubscriptionStatus::CANCELED;
            case 'past_due':
                return SubscriptionStatus::PAST_DUE;
            default:
                return SubscriptionStatus::DEFAULT;
        }
    }
}