<?php 

namespace Livewirez\Billing\Providers;

use Exception;
use Tekord\Result\Result;
use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;

use Livewirez\Billing\Enums\PaymentStatus;
use Illuminate\Http\Client\RequestException;
use function Livewirez\Billing\exception_info;
use function Pest\Laravel\call;

use Livewirez\Billing\Models\BillingPlanPrice;


use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Lib\Orders\CompleteOrderRequest;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;
use Livewirez\Billing\Interfaces\PaymentProviderInterface;
use Livewirez\Billing\Lib\Paddle\Exceptions\PaddleApiError;
use Livewirez\Billing\Lib\Card\Drivers\CybersourceMicroform;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Livewirez\Billing\Interfaces\TokenizedPaymentProviderInterface;

class CardProvider implements PaymentProviderInterface
{

    public function getTokenPaymentProvider(): TokenizedPaymentProviderInterface
    {
        throw new Exception('Unsupported');
    }

    public function initializePayment(CartInterface|ProductInterface $cart, InitializeOrderRequest $request): PaymentResult
    {
        if ($cart instanceof ProductInterface) {
            $cart = Cart::fromProduct($cart);
        }

        $totals = $cart->getItemTotals(false);

        $paymentData = [
            'billing_order_id' => $billingOrderId =  $request->getBillingOrderId(),
            'billing_payment_transaction_id' => $request->getBillingPaymenTransactionId(),
            'order_number' => $request->getOrderNumber(),
            'product_type' =>  $request->getProductType()->value,
            'billable_id' => $request->getUser()->getKey(),
            'billable_type' => $request->getUser()->getMorphClass(),
        ];

        $metadata = $request->getMetadata();

        \Illuminate\Support\Facades\Log::debug(__METHOD__ . ' Cybersource Data', [
            'payment_data' => $paymentData,
            'metadata' => $metadata,
        ]);


        switch ($metadata['card_gateway'] ?? 'cybersource') {
            case 'cybersource':
                if (! isset($metadata['token'])) throw new Exception('JWT Token Missing');

                $token = $metadata['token'];
                $driver = new CybersourceMicroform();

                $payload = $driver->constructPaymentPayload($token, $cart, $request);

                try {
                    $response = $driver->handlePayment($payload);

                    \Illuminate\Support\Facades\Log::debug(__METHOD__ . ' Cybersource', [
                        'response' => $response->json()
                    ]);

                    return match ($response->json('status')) {
                        'AUTHORIZED' =>  new PaymentResult(
                            true,
                            $billingOrderId,
                            PaymentStatus::PAID,
                            Result::success($response->json()),
                            null,
                            $response->json('id'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('id'),
                            'Payment Initialization Success',
                            [
                                'provider_class' => get_class($this),
                                ...$paymentData,
                                ...$response->json()
                            ]
                        ),
                        
                        'AUTHORIZED_PENDING_REVIEW' => new PaymentResult(
                            true,
                            $billingOrderId,
                            PaymentStatus::APPROVED,
                            Result::success($response->json()),
                            null,
                            $response->json('id'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('id'),
                            'Payment Initialization Success',
                            [
                                'provider_class' => get_class($this),
                                ...$paymentData,
                                ...$response->json()
                            ]
                        ),
                        default => new PaymentResult(
                            false,
                            $billingOrderId,
                            PaymentStatus::UNPAID,
                            Result::fail($response->json()),
                            null,
                            $response->json('id'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('processorInformation.transactionId'),
                            $response->json('id'),
                            'Payment Initialization Failure',
                            [
                                'provider_class' => get_class($this),
                                ...$paymentData,
                                ...$response->json()
                            ],
                            true
                        )
                    };
                } catch (RequestException $re) {

                    exception_info($re);
 
                    return new PaymentResult(
                            false,
                            $billingOrderId,
                            PaymentStatus::UNPAID,
                            Result::fail(
                                new ErrorInfo(
                                    "Payment Failure: " . $re->response->json('message', $re->getMessage()), 
                                    $re->response->status() ?? $re->getCode(),
                                    $re->response->json('message'),
                                    [
                                        'billingOrderId' => $billingOrderId,
                                        'billing_order_id' => $billingOrderId,
                                        'status' => $re->response->json(),
                                        'type' => $re->response->json('status'),
                                    ],
                                    error: $re
                                )
                            ),
                            null,
                            $re->response->json('id'),
                            message: 'Payment Initialization Failure',
                            metadata: [
                                'provider_class' => get_class($this),
                                ...$paymentData,
                                ...$re->response->json()
                            ],
                            throw: true
                        );
                }
            default:
                throw new Exception('Unsupported');
        }
    }

    // public function x() 
    // {
    //     $capturePayload = array_merge(array_filter(
    //         data_get($metadata, 'initiate_payment', []),
    //         fn (string $key) => in_array($key, [
    //             'clientReferenceInformation', 'orderInformation'
    //         ]),
    //         ARRAY_FILTER_USE_KEY
    //     ), [
    //             'clientReferenceInformation' => [
    //                 'code' => data_get($metadata, 'initiate_payment.clientReferenceInformation.code', $request->getOrderNumber()),
    //                 'partner' => [
    //                     'thirdPartyCertificationNumber' => $driver->getSharedSecret()
    //                 ]
    //             ],
    //             'orderInformation' => [
    //                 'amountDetails' => [
    //                     'totalAmount' => data_get($metadata, 'amount', data_get($metadata, 'total_amount', '0.00')),
    //                     'currency' => data_get($metadata, 'currency', 'USD')
    //                 ]
    //             ]
    //         ]
    //     );

    //     $response = $driver->capurePayment(
    //         $request->getProviderTransactionId(),
    //         $capturePayload
    //     );
    // }

    
    public function completePayment(CompleteOrderRequest $request): ?PaymentResult
    {
        $metadata = $request->getMetadata();

        switch ($metadata['card_gateway'] ?? 'cybersource') {
            case 'cybersource':
                $driver = new CybersourceMicroform();

                try {
                    $response = $driver->getPaymentStatus($request->getProviderTransactionId());

                    \Illuminate\Support\Facades\Log::debug(__METHOD__ . ' Cybersource CompletePayment', [
                        'response' => $response->json(),
                        // 'uri' => $uri,
                        // 'method' => $method
                    ]);  // response->json('statusInformation.reason') === 'Success'

                    if ($response->successful() && $response->json('statusInformation.reason') === 'Success') { 
                        return new PaymentResult(
                            true,
                            $request->getBillingOrderId(),
                            PaymentStatus::COMPLETED,
                            Result::success($response->json()),
                            null,
                            $request->getProviderTransactionId(),
                            $response->json('processorInformation.transactionId'),
                            $response->json('processorInformation.approvalCode'),
                            $request->getProviderTransactionId(),
                            'Payment Completion Success',
                            [
                                'provider_class' => get_class($this),
                                ...$response->json()
                            ]
                        );
                    } else {

                        \Illuminate\Support\Facades\Log::debug(__METHOD__ . ' Cybersource Transaction Unpaid Error', [
                            'response' => $response->json()
                        ]);


                        return new PaymentResult(
                            false,
                            $request->getBillingOrderId(),
                            PaymentStatus::UNPAID,
                            Result::success($response->json()),
                            null,
                            $request->getProviderTransactionId(),
                            $response->json('processorInformation.transactionId'),
                            $response->json('processorInformation.approvalCode'),
                            $request->getProviderTransactionId(),
                            'Payment Completion Unpaid',
                            [
                                'provider_class' => get_class($this),
                                ...$response->json()
                            ],
                            true
                        );
                    }
                } catch (RequestException $re) {

                    exception_info($re);

                    return new PaymentResult(
                        false,
                        $request->getBillingOrderId(),
                        PaymentStatus::FAILED,
                        Result::fail(
                            new ErrorInfo(
                                "Payment Failure: " . $re->response->json('message', $re->getMessage()), 
                                $re->response->status() ?? $re->getCode(),
                                $re->response->json('message'),
                                [
                                    'billingOrderId' => $request->getBillingOrderId(),
                                    'billing_order_id' => $request->getBillingOrderId(),
                                    'status' => $re->response->json(),
                                    'type' => $re->response->json('status'),
                                ],
                                error: $re
                            )
                        ),
                        null,
                        $request->getProviderTransactionId(),
                        $re->response->json('processorInformation.transactionId'),
                        $re->response->json('processorInformation.approvalCode'),
                        $request->getProviderTransactionId(),
                        'Payment Completion Failure',
                        [
                            'provider_class' => get_class($this),
                            ...$re->response->json()
                        ],
                        true
                    );
                }
            default:
                 throw new Exception('Unsupported Implementation');
        }
    }

    public function refundPayment(string $billingOrderId, string $providerOrderId): bool
    {
        throw new Exception('Unsupported');
    }
    
    public function getPaymentStatus(string $providerOrderId): PaymentStatus
    {
        throw new Exception('Unsupported');
    }

    public function initiateSubscription(BillingPlan $plan, BillingPlanPrice $planPrice, InitializeOrderRequest $request): SubscriptionResult
    {
        throw new Exception('Unsupported');
    }

    public function startSubscription(CompleteOrderRequest $request): SubscriptionResult
    {
        throw new Exception('Unsupported');
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
        throw new Exception('Unsupported');
    }

    public function listSubscriptions(): array
    {
        throw new Exception('Unsupported');
    }

    public function cancelSubscription(string $providerSubscriptionId): bool
    {
        throw new Exception('Unsupported');
    }

    public function pauseSubscription(string $providerSubscriptionId): bool
    {
        throw new Exception('Unsupported');
    }

    public function resumeSubscription(string $providerSubscriptionId): bool
    {
        throw new Exception('Unsupported');
    }

    public function getSubscriptionStatus(string $providerSubscriptionId): SubscriptionStatus
    {
        throw new Exception('Unsupported');
    }

    public function handleWebhook(Request $request): Response
    {
        throw new Exception('Unsupported'); 
    } 
}