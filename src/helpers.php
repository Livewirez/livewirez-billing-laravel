<?php 

namespace Livewirez\Billing;

use Exception;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\Client\HttpClientException;


function formatAmount(int $amount, int $scale): string
{
    return Money::formatAmount($amount, $scale);
}


function formatAmountUsingCurrency(int $amount, string $currency): string 
{
    $scale = match (strtoupper($currency)) {
        'BTC' => 8,
        'ETH' => 18,
        'USDT' => 6,
        'USD', 'EUR', 'KES', 'KSH' => 2,
        default => 8,
    };

    return formatAmount($amount, $scale);
}

if (!function_exists('exception_info')) {
    function exception_info(\Throwable $th, array $where_thrown = [], array $include = [], array $exclude = ['trace', 'file', 'line', 'trace_as_string', 'previous'])
    {
        $info = [ 
            'where_thrown' => $where_thrown,
            'exception_type' => class_basename($th),
            'message' => $th->getMessage(),
            'code' => $th->getCode(),
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace(),
            'trace_as_string' => $th->getTraceAsString(),
            'previous' => $th->getPrevious(),
        ];

        Log::error(
            'Exception Info: ' . $info['message'],
            array_filter($info, fn(string $key) => !in_array($key, array_diff($exclude, $include)), ARRAY_FILTER_USE_KEY)
        );
    }
}

if(! function_exists('make_request_using_curl')) {
    function make_request_using_curl(string $url, array $data = [], array $curl_options = [], string $method = 'POST', bool $laravel_response = false): mixed
    {
        $curl = curl_init();
        $response_headers = [];
        $response_cookies = [];

        if (!$curl) throw new Exception('An Error occurred trying to initialize curl');

        $headers = array_unique(array_merge([
            "Content-Type: application/json",
           // "Authorization: Bearer $token",
            "Accept: application/json"
        ], $curl_options['headers'] ?? []));

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => php_uname('s') . ' ' . php_uname('v'),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true, // Enable SSL verification for security
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
           // CURLOPT_VERBOSE => true, // To get detailed info about the request/response
            CURLOPT_HEADERFUNCTION => function($curl, string $header) use (&$response_headers, &$response_cookies) {
                $len = strlen($header);

                if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $header, $cookie) === 1) $response_cookies[] = $cookie;

                $header = explode(':', $header, 2);
                if (count($header) < 2) return $len; // ignore invalid headers

                //$response_headers[strtolower(trim($header[0]))][] = trim($header[1]);
                $response_headers[trim($header[0])][] = trim($header[1]);
                
                return $len;
            }
        ];

        $client_options = array_filter(
            $curl_options['options'] ?? [], 
            fn(int $k) => ! in_array($k, [CURLOPT_URL, CURLOPT_RETURNTRANSFER, CURLOPT_USERAGENT, CURLOPT_HEADERFUNCTION, CURLOPT_VERBOSE]), 
            ARRAY_FILTER_USE_KEY
        );

        $options = $client_options + $options; // $options = array_replace($options, $client_options);

        // // Set the appropriate HTTP method
        // if ($method === 'POST') {
        //     $options[CURLOPT_POST] = true;
        //     $options[CURLOPT_POSTFIELDS] = json_encode($data);
        // } elseif ($method === 'PUT') {
        //     $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        //     $options[CURLOPT_POSTFIELDS] = json_encode($data);
        // } elseif ($method === 'PATCH') {
        //     $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        //     $options[CURLOPT_POSTFIELDS] = json_encode($data);
        // } elseif ($method === 'DELETE') {
        //     $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        // } elseif ($method === 'GET' && !empty($data)) {
        //     // For GET requests with parameters, append them to the URL
        //     $options[CURLOPT_URL] .= '?' . http_build_query($data);
        // }

        switch($method) {
            case 'GET':
                if (! empty($data)) {
                    // For GET requests with parameters, append them to the URL
                    $options[CURLOPT_URL] .= '?' . http_build_query($data);
                }
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
            case 'PATCH':
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
                break;
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            default:
                break;

        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($response === false) {
            Log::info(collect([
                'error' => $error,
                'http_code' => $httpCode,
                'headers' => $response_headers,
                'cookies' => $response_cookies,
                'body' => $response,
            ]), ['Response Failure Result Curl']);

            throw new HttpClientException($error);
        }

        // Handle API errors (4xx, 5xx responses)
        $responseData = json_decode($response, true);  

        Log::info(collect([
            'response' => $responseData,
            'http_code' => $httpCode,
            'headers' => $response_headers,
            'cookies' => $response_cookies,
            'body' => $response,
        ]), ['Response Result Curl']);

        if ($laravel_response) {
            $c_response = new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(
                    $httpCode,
                    $headers,
                    $response
                )
            );

            if ($httpCode >= 400) {
                Log::info(collect([
                    'response' => $responseData,
                    'http_code' => $httpCode,
                    'headers' => $response_headers,
                    'cookies' => $response_cookies,
                    'body' => $response,
                ]), [' Error Response Result Curl']);
                
                throw new \Illuminate\Http\Client\RequestException(
                    $c_response
                );
            }

            return $c_response;
        }

        $data = new class(
            $httpCode, $response_headers, $response_cookies, $responseData, $response
        ) {
            protected string $reasonPhrase = '';

            public function __construct(
                public int $statusCode = 200,
                public array $headers = [],
                public array $cookies = [],
                public ?array $data = [],
                public ?string $body = null,
                public string $version = '1.1',
                ?string $reason = null
            ) {
                if ($reason == '' && isset(\Symfony\Component\HttpFoundation\Response::$statusTexts[$this->statusCode])) {
                    $this->reasonPhrase = \Symfony\Component\HttpFoundation\Response::$statusTexts[$this->statusCode];
                } else {
                    $this->reasonPhrase = (string) $reason;
                }
            }

            public function getBody(): mixed
            {
                return $this->body;
            }

            public function getCookies(?string $key = null, mixed $default = null): mixed
            {
                return data_get($this->cookies, $key, $default);
            }

            public function getHeaders(?string $key = null, mixed $default = null): mixed
            {
                return data_get($this->headers, $key, $default); 
            }

            public function json(?string $key = null, mixed $default = null)
            {
                return data_get($this->data ?? [], $key, $default);
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            public function getReasonPhrase(): string
            {
                return $this->reasonPhrase;
            }

            public function successful()
            {
                return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
            }

            public function redirect()
            {
                return $this->getStatusCode() >= 300 && $this->getStatusCode() < 400;
            }

            public function failed()
            {
                return $this->serverError() || $this->clientError();
            }

            public function clientError()
            {
                return $this->getStatusCode() >= 400 && $this->getStatusCode() < 500;
            }

            public function serverError()
            {
                return $this->getStatusCode() >= 500;
            }
        };
        
        if ($httpCode >= 400) {
            if(isset($curl_options['onError']) && is_callable($curl_options['onError'])) {
                $curl_options['onError']($data);
            }
        }
 

        if ($httpCode >= 400) {
            Log::info(collect([
                'response' => $responseData,
                'http_code' => $httpCode,
                'headers' => $response_headers,
                'cookies' => $response_cookies,
                'body' => $response,
            ]), [' Error Response Result Curl']);

            throw new HttpClientException(
                $responseData['message'] ?? 'Unknown API error',
                $httpCode
            );
        }

        if(isset($curl_options['onSuccess']) && is_callable($curl_options['onSuccess'])) {
            $curl_options['onSuccess']($data);
        }

        return $data;
    }
}