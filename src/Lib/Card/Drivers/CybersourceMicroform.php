<?php 

namespace Livewirez\Billing\Lib\Card\Drivers;

use Closure;
use Exception;
use function is_string;
use Livewirez\Billing\Money;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Batch;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Livewirez\Billing\Enums\RequestMethod;
use Livewirez\Billing\Interfaces\Billable;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Lib\Cybersource\Headers;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Lib\Card\Enums\CardNetworks;

use Livewirez\Billing\Lib\Card\Interfaces\PaymentDriver;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;

class CybersourceMicroform implements PaymentDriver
{
    protected array $config = [];

    public function __construct()
    {
        $this->config = config('billing.providers.cybersource', []);
    }

    public function getSharedSecret(): string
    {
        return $this->config['rest_api_shared_secret'];
    }

    public function makeRequest(RequestMethod $method, string $uri, array $payload = []): Response | PromiseInterface
    {
        $request = Http::baseUrl(
            $this->config['base_url'][$this->config['environment']]
        )->withHeaders(
            $headers = new Headers($this->config)
            ->generate($method, $uri, $payload)
        )->retry(
            3,
            1000,
            function (Exception $e, PendingRequest $request) use ($uri) {
                \Illuminate\Support\Facades\Log::warning('Retry CybersourceMicroform::makeRequest: '. __METHOD__ . $uri, [
                    'error' => $e->getMessage(),
                    ...$e instanceof RequestException ? [
                        'status' => $e->response->status(),
                        'body' => $e->response->body()
                    ] : []
                ]);
                return $e instanceof ConnectionException || (
                    $e instanceof RequestException &&
                    in_array($e->response->status(), [404])
                ); // because it throws a 404 error sometimes 
            }
        )->throw(function (Response $r, RequestException $e) use ($uri) {
            \Illuminate\Support\Facades\Log::error('Throw CybersourceMicroform::makeRequest: '. __METHOD__ . $uri, [
                'response' => $r,
                'json_repsone' => $r->json(),
                'error' => $e->getMessage(),
                'status' => $r->status(),
                'body' => $r->body()  // Add this to see the full response body
            ]);
        })->truncateExceptionsAt(1500);

        return match ($method) {
            RequestMethod::Get => $request->get($uri, $payload),
            RequestMethod::Put => $request->put($uri, $payload),
            RequestMethod::Patch => $request->patch($uri, $payload),
            RequestMethod::Post => $request->post($uri, $payload),
            default => $request->post($uri, $payload),
        };
    }


    /**
     * @see https://developer.cybersource.com/library/documentation/dev_guides/REST_API/Getting_Started/html/index.html#page/REST_GS%2Fch_authentication.5.3.htm%23ww1126913
     * @see https://developer.cybersource.com/api-reference-assets/index.html#flex-microform_microform-integration_generate-capture-context_samplerequests-dropdown_generate-capture-context-card-opt-out-of-receiving-card-number-prefix_liveconsole-tab-request-headers
     * 
     * @brief jwt is the body $res->body();
     * @return Response
     */
    public function initializeCaptureContext(): Response
    {
        $payload = [
            'clientVersion' => 'v2.0',
            'targetOrigins' => [
                config('app.url')
            ],
            'allowedCardNetworks' => [
                CardNetworks::Visa->value, //'VISA',
                CardNetworks::Mastercard->value, //'MASTERCARD',
                CardNetworks::Amex->value, //'AMEX'
            ],
            'allowedPaymentTypes' => [
                'CARD'
            ]
        ];

        $uri = '/microform/v2/sessions';

        return $this->makeRequest(RequestMethod::Post, $uri, $payload);
    }

    public function constructPaymentPayload(string $token, CartInterface $cart, InitializeOrderRequest $request): array
    {
        $currency_code = $cart->getCurrencyCode();

        return [
            'clientReferenceInformation' => [
                'code' =>  $request->getOrderNumber()
            ],
            'processingInformation' => [
                'capture' => true,
                'commerceIndicator' => 'internet'
            ],
            'tokenInformation' => [
                'transientTokenJwt' => $token
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => (string) Money::formatAmountUsingCurrency(
                        $cart->getGrandTotal(), $currency_code
                    ),
                    'currency' => $currency_code
                ],
                'billTo' => [
                    'firstName' => $request->getUser()->getName(),
                    'lastName' => $request->getUser()->getName(Billable::LAST_NAME),
                    'address1' => $request->getBillingAddress()?->getAddressLine1() ?? '1Market St',
                    'address2' => $request->getBillingAddress()?->getAddressLine2() ?? 'Address 2',
                    'locality' =>  $request->getBillingAddress()?->getCity() ?? 'San Francisco',
                    'administrativeArea' =>  $request->getBillingAddress()?->getState() ?? 'CA',
                    'postalCode' => $request->getBillingAddress()?->getPostalCode() ?? '94105',
                    'country' => $request->getBillingAddress()?->getCountry() ?? 'US',
                    'email' => $request->getUser()->getEmail(),
                    'phoneNumber' => $request->getBillingAddress()?->getPhone() ?? '4158880000'
                ]
            ]
        ];
    }

    public function handlePayment(array $payload): Response
    {
        $uri = '/pts/v2/payments';

        return $this->makeRequest(RequestMethod::Post, $uri, $payload);
    }

    public function tryCapurePayment(string $transactionId, array $payload = []): Response
    {
        $uri = "/pefs/v1/followons/{$transactionId}/capture";

        return $this->makeRequest(RequestMethod::Post, $uri, [
            'settlementAmount' => $payload['amount'] ?? null,
            'settlementCurrency' => $payload['currency'] ?? null,
            'mddData' => $payload['mddData'] ?? null,
            'transactionType' => 'Capture',
            'merchantId' => $this->config['merchant_id'],
            'customerId' => $payload['customerId'] ?? ''
        ]);
    }

    public function capurePayment(string $transactionId, array $payload = []): Response
    {
        $uri = "/pts/v2/payments/{$transactionId}/captures";

        return $this->makeRequest(RequestMethod::Post, $uri, $payload);
    }

    public function getPaymentStatus(string $transactionId): Response
    {
        $uri = "/pts/v2/payments/{$transactionId}";

        return $this->makeRequest(RequestMethod::Get, $uri);
    }

    public function makeRequestFromLink(string $uri, RequestMethod|string $method, array $data = []): Response
    {
        return $this->makeRequest(
            is_string($method) ? RequestMethod::fromString($method) : $method, $uri, $data
        );
    }
}