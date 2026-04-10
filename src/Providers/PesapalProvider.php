<?php 

namespace Livewirez\Billing\Providers;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

class PesapalProvider
{
    public function __construct(protected array $config = [])
    {
        $this->config ??= config('billing.providers.pesapal');
    }

    public function getAccessToken(): string
    { 
        return Cache::remember('pesapal_access_token', 300, function (): string {
            $response = Http::acceptJson()
                ->retry(
                    2, 
                    5, 
                    fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException
                )
                ->throw()
                ->post(
                    $this->config['base_url'][$this->config['environment']] . '/api/Auth/RequestToken', 
                    ['consumer_key' => $this->config['consumer_key'], 'consumer_secret' => $this->config['consumer_secret']]
                );

            $expiryDate = \Carbon\Carbon::parse($response->json('expiryDate'));

            $seconds = $expiryDate->diffInSeconds(now());

            Cache::put('pesapal_access_token', $response->json('token'), (int) abs($seconds));
            
            return $response->json('token');
        }); 
    }

    private function buildCallbackUrl(string $route_name): string
    {
        $callback_url = $this->config['webhook_domain'] . parse_url(route($route_name), PHP_URL_PATH);

        $callback_url .= '?' . http_build_query([
            'signature' => hash_hmac('sha256', $this->config['webhook_secret_value'], sha1($this->config['webhook_secret_key']))
        ]);

        return $callback_url;
    }

    public function registerIpns()
    {
        return Cache::remember('pesapal_ipn_id', 300, function (): string {
            $token = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => "Bearer $token"
            ])
            ->acceptJson()
            ->retry(2, 100, fn (Exception $exception, PendingRequest $request) => $exception instanceof ConnectionException)
            ->throw()
            ->post(
                $this->config['base_url'][$this->config['environment']] . '/api/URLSetup/RegisterIPN', 
                [
                    'url' => $this->buildCallbackUrl('webhooks.api.payment.pesapal.webhooks.ipn_callback'), 
                    'ipn_notification_type' => 'POST'
                ]
            );

            return $response->json('ipn_id');
        });
    }

    /**
     * Random Reference Generator used to generate unique IDs
     * @param mixed $prefix
     * @param mixed $length
     * @return string
     */
    public function random_reference($prefix = 'PESAPAL', $length = 10)
    {
        $keyspace = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $str = '';
        $max = mb_strlen($keyspace, '8bit') - 1;
        $prefix ??= 'PESAPAL';
        // Generate a random string of the desired length
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[random_int(0, $max)];
        }

        // Append the current timestamp in milliseconds for uniqueness
        $timestamp = round(microtime(true) * 1000);

        return $prefix . '-' . $timestamp . '-' . $str;
    }
}