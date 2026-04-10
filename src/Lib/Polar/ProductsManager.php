<?php 

namespace Livewirez\Billing\Lib\Polar;

use CURLFile;
use Exception;
use Throwable;
use CURLStringFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Models\BillingPlan;
use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use function Livewirez\Billing\exception_info;
use Livewirez\Billing\Enums\ApiProductTypeKey;
use Livewirez\Billing\Models\BillingPlanPrice;
use Livewirez\Billing\Enums\SubscriptionInterval;
use Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductData;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductMediaData;
use Livewirez\Billing\Lib\Polar\Data\Products\DownloadableMediaData;

use Livewirez\Billing\Lib\Polar\Data\Products\ProductPriceFixedData;
use Livewirez\Billing\Lib\Polar\Data\Products\OrganizationAvatarMediaData;

class ProductsManager
{
    // This class is responsible for managing products in the Polar billing system.


    /**
     * Summary of createProduct
     * 
     * @param \Livewirez\Billing\Models\BillingProduct $billingProduct
     * @param (callable(string $path):string) | null $pathResolver
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @throws \Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError
     * @return ProductData
     */
    public static function createProduct(BillingProduct $billingProduct, ?callable $pathResolver = null): ProductData
    {
        if ($billingProduct->type === ProductType::SERVICE) throw new Exception(
            'Please use the ProductsManager::createSubscriptionProduct method for subscription products.'
        );

        $uploadedFileData = [];

        if ($billingProduct->thumbnail) {

            if (! $path = filter_var($billingProduct->thumbnail, FILTER_VALIDATE_URL)) {

                if (!is_callable($pathResolver)) {
                    throw new InvalidArgumentException('Invalid path resolver');
                }

                $path = $pathResolver($billingProduct->thumbnail);
            }

            try {
               $uploadedFileData = static::createFile($path);
            } catch (Throwable $th) {
                throw new PolarApiError($th->getMessage(), 400);
            }
        }

        $payload = [
            'name' => $billingProduct->name,
            'description' => $billingProduct->description,
            'recurring_interval' => null,
            'is_recurring' => $billingProduct->type === ProductType::SERVICE,
            'is_archived' => false,
            // 'organization_id' => '', //config('billing.providers.polar.organization_id')
            'prices' => [
                [
                    'amount_type' => 'fixed',
                    'price_amount' => $billingProduct->price,
                    'price_currency' => mb_strtolower($billingProduct->currency),
                ]
            ],
            'benefits' => [],
            'medias' => [
                $uploadedFileData['id'] ?? null
            ],
            'metadata' => [
                'billing_product_name' => $billingProduct->name,
                'billing_product_id' => $billingProduct->billing_product_id,
                'billing_product' => $billingProduct->id,
                'product_type' => ApiProductTypeKey::ONE_TIME->value
            ],
        ];

        try {
            $response = Polar::api("POST", "v1/products", $payload);

            $data = ProductData::from($response->json());

            static::updateBillingProductMetadata(
                $billingProduct, $data
            );

            return $data;
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function getProduct(BillingProduct $product): ProductData
    {
        $product->loadMissing(
            [
                'billing_product_payment_provider_information' => function ($query) {
                    $query->where('payment_provider', PaymentProvider::Polar);
                }
            ]
        );

        $id = $product->billing_product_payment_provider_information->first()->payment_provider_product_id;

        // if (! isset($product->metadata[PaymentProvider::Polar->value]['id'])) {
        //     throw new PolarApiError('Product not found', 404);
        // }

        // $id = $product->metadata[PaymentProvider::Polar->value]['id'];

        try {
            $response = Polar::api("GET", "v1/products/{$id}");

            return ProductData::from($response->json());       
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function getProducts(): array
    {
        try {
            $response = Polar::api("GET", "v1/products", [
                'is_archived' => false,
                'metadata[product_type]' => ApiProductTypeKey::ONE_TIME->value
            ]);

            $items = array_filter(
                $response->json('items') ?? [],
                fn (array $item): bool =>
                isset($item['metadata']) && !array_key_exists('billing_plan_id', $item['metadata'])
            );

            return array_map(
                fn (array $item): ProductData =>
                    ProductData::from($item),
                $items
            );       
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function getSubscriptionProduct(BillingPlanPrice $plan_price): ProductData
    {
        $plan_price->loadMissing(
            [
                'billing_plan_payment_provider_information' => function ($query) {
                    $query->where('payment_provider', PaymentProvider::Polar);
                }
            ]
        );

        $id = $plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id;

        try {
            $response = Polar::api("GET", "v1/products/{$id}");

            return ProductData::from($response->json());       
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    /**
     * @see https://docs.polar.sh/api-reference/products/list
     * @throws \Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError
     * @return ProductData[]
     */
    public static function getSubscriptionProducts(): array
    {
        try {
            $response = Polar::api("GET", "v1/products", [
                'is_recurring' => true,
                'is_archived' => false,
                'metadata[product_type]' => ApiProductTypeKey::SUBSCRIPTION->value
            ]);

            $items = array_filter(
                $response->json('items') ?? [],
                fn  (array $item): bool  =>
                isset($item['metadata']) && isset($item['metadata']['billing_plan_id']) && isset($item['metadata']['billing_plan_price_id'])
            );

            return array_map(
                fn (array $item): ProductData =>
                    ProductData::from($item),
                $items
            );       
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    }

    public static function createSubscriptionProduct(BillingPlanPrice $plan_price): ProductData
    {
        $plan_price->loadMissing('billing_plan.billing_product');

        $payload = [
            'name' => $plan_price->billing_plan->name . ' - ' . ucfirst(mb_strtolower($plan_price->interval->name)),
            'description' => $plan_price->billing_plan->description,
            'recurring_interval' => match ($plan_price->interval) {
                SubscriptionInterval::MONTHLY => 'month',
                SubscriptionInterval::YEARLY => 'year',
                default => null,
            },
            'is_recurring' => true,
            'is_archived' => false,
            // 'organization_id' => '', //config('billing.providers.polar.organization_id')
            'prices' => [
                [
                    'amount_type' => 'fixed',
                    'price_amount' => $plan_price->amount,
                    'price_currency' => mb_strtolower($plan_price->currency),
                ]
            ],
            'benefits' => [],
            'metadata' => [
                'billing_plan_name' => $plan_price->billing_plan->name,
                'billing_plan' => $plan_price->billing_plan->id,
                'billing_plan_id' => $plan_price->billing_plan->billing_plan_id,
                'billing_product' => $plan_price->billing_plan->billing_product->id,
                'billing_product_id' => $plan_price->billing_plan->billing_product->billing_product_id,
                'billing_plan_price_id' => $plan_price->billing_plan_price_id,
                'billing_plan_price' => $plan_price->id,
                'product_type' => ApiProductTypeKey::SUBSCRIPTION->value
            ],
        ];
       

        try {
            $response = Polar::api("POST", "v1/products", $payload);

            $data = ProductData::from($response->json());
            
            return $data->setModel(
                static::updateBillingPlanMetadata($plan_price, $data)
            );
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    } 


    /**
     * Summary of updateSubscriptionProduct
     * 
     * curl --request PATCH \
     *   --url https://api.polar.sh/v1/products/{id} \
     *   --header 'Authorization: Bearer <token>' \
     *  --header 'Content-Type: application/json' \
     *   --data '{
     *   "metadata": {},
     *   "name": "<string>",
     *   "description": "<string>",
     *   "recurring_interval": "month",
     *   "is_archived": true,
     *   "prices": [
     *       {
     *       "id": "<string>"
     *       }
     *   ],
     *   "medias": [
     *       "<string>"
     *   ],
     *   "attached_custom_fields": [
     *       {
     *           "custom_field_id": "<string>",
     *           "required": true
     *       }
     *   ]
     *   }'
     * 
     * 
     * 
     * @param \Livewirez\Billing\Models\BillingPlanPrice $plan_price
     * @param array $updates
     * @throws \Livewirez\Billing\Lib\Polar\Exceptions\PolarApiError
     * @return ProductData
     */
    public static function updateSubscriptionProduct(BillingPlanPrice $plan_price, array $updates): ProductData
    {
        $plan_price->loadMissing([
            'billing_plan' => ['billing_product'],
            'billing_plan_payment_provider_information' => function ($query) {
                $query->where('payment_provider', PaymentProvider::Polar);
            }
        ]);

        throw_if(
            $plan_price->billing_plan_payment_provider_information->billing_plan_price_id === null,
            PolarApiError::class, 
            'Billing plan price id not found for Polar.'
        );
       
        $id = $plan_price->billing_plan_payment_provider_information->first()->payment_provider_plan_id;

        try {
            $response = Polar::api("PATCH", "v1/products/{$id}", $updates);

            $data = ProductData::from($response->json());
            
            return $data->setModel(
                static::updateBillingPlanMetadata($plan_price, $data)
            );
        } catch (PolarApiError $e) {
            throw new PolarApiError($e->getMessage(), 400);
        }
    } 


    public static function updateBillingPlanMetadata(BillingPlanPrice $plan_price, ProductData $response)
    {
        $plan_price->loadMissing('billing_plan');

        return DB::transaction(fn () => $plan_price->billing_plan_payment_provider_information()->updateOrCreate([
            'payment_provider' => PaymentProvider::Polar,
            'billing_plan_id' => $plan_price->billing_plan->id,
            'billing_plan_price_id' => $plan_price->id,
            'payment_provider_plan_id' => $response->id,
        ],[
            'is_active' => true,
            'status' => 'ACTIVE',
            'metadata' => $response->toArray()
        ]));
    }

    public static function updateBillingProductMetadata(BillingProduct $product, ProductData $response)
    {
        DB::transaction(function () use ($product, $response) {
            
            $product->update([
                'metadata' => array_merge(
                    $product->metadata ?? [],
                    [
                        PaymentProvider::Polar->value => $response->toArray(),
                    ]
                )
            ]);

            $product->billing_product_payment_provider_information()->updateOrCreate([
                'payment_provider' => PaymentProvider::Polar,
                'payment_provider_product_id' => $response->id,
                'payment_provider_price_id' => isset($response->prices[0]) && is_array($response->prices[0]) ? $response->prices[0]['id'] : $response->prices[0]?->id,
            ],[
                'is_active' => !$response->isArchived,
                'is_archived' => $response->isArchived,
                'payment_provider_media_id' => isset($response->medias[0]) && is_array($response->medias[0]) ? $response->medias[0]['id'] : $response->medias[0]?->id,
                'payment_provider_price_ids' => array_map(fn ($p) => is_array($p) ? $p['id'] : $p->id, $response->prices ?? []),
                'payment_provider_media_ids' => array_map(fn (ProductMediaData|array $m) => is_array($m) ? $m['id'] : $m->id, $response->medias ?? []),
                'metadata' => $response->toArray()
            ]);
        });

        return $product;
    }

    public static function getFileData(string $pathOrUrl)
    {
        if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {

            // Optional—but recommended: ensure it's HTTP(s)
            $parsed = parse_url($pathOrUrl);
            if (
                !isset($parsed['scheme'])
                || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
            ) {
                throw new InvalidArgumentException('Thumbnail URL must be HTTP or HTTPS');
            }

            
            try {
                $response = Http::get($pathOrUrl);
                $stream = $response->toPsrResponse()->getBody();

                $contents = '';

                while(! $stream->eof()) { 
                    $contents .= $stream->read(1024 * 8); 
                }

                $temp = tmpfile();
                if (! $temp) {
                    throw new Exception('Unable to create temporary file');
                }
                fwrite($temp, $contents);
                rewind($temp);
                $meta = stream_get_meta_data($temp);
                $filesize = filesize($tmp_name = $meta['uri']); 
                $mimeType = $response->header('Content-Type') ?: mime_content_type($tmp_name);

            
                return [
                    'mime_type' => $mimeType,
                    'size' => $filesize,
                    'tmp_name' => $tmp_name,
                    'file_handle' => $temp,
                    'name' => $name = basename(parse_url($pathOrUrl, PHP_URL_PATH)) ?: Str::random(10),
                    'curl_file' => new CURLFile($tmp_name, $mimeType),
                    'is_tmp_file' => true
                ];

            } catch (Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error fetching file from URL', [
                    'url' => $pathOrUrl,
                    'error' => $e->getMessage()
                ]);

                throw $e;
            }
            // ...
        } else {
            // Assume it's a local storage path
            if (file_exists($pathOrUrl)) {
                
                $mimeType = mime_content_type($pathOrUrl);

                if (! $mimeType) {
                    $mimeType = 'application/octet-stream';
                }

                $filesize = filesize($pathOrUrl);

                return [
                    'mime_type' => $mimeType,
                    'size' => $filesize,
                    'tmp_name' => $pathOrUrl,
                    'file_handle' => $stream = fopen($pathOrUrl, 'r'),
                    'name' => $name = pathinfo($pathOrUrl, PATHINFO_BASENAME),
                    'curl_file' => new CURLFile($pathOrUrl, $mimeType, $pathOrUrl),
                    'is_tmp_file' => false
                ];
            } else {
                throw new Exception('File not found');
            }
        }
    }

    /**
     * Summary of createFile
     * @param string $pathOrUrl
     * @param string $service { 'product_media' | 'downloadable' | 'organization_avatar' }
     * @return array
     */
    public static function createFile(string $pathOrUrl, string $service = 'product_media'): array
    {
        try {
            $fileData = self::getFileData($pathOrUrl);

            // Read full file content
            rewind($fileData['file_handle']);
            $data = stream_get_contents($fileData['file_handle']);
            $size = strlen($data);
            $checksumBytes = hash('sha256', $data, true);
            $checksumBase64 = base64_encode($checksumBytes);

            // Step 1: Create file entry
            $createPayload = [
                'size' => $size,
                'mime_type' => $fileData['mime_type'],
                'name' => $fileData['name'],
                'service' => $service,
                'checksum_sha256_base64' => $checksumBase64,
                'upload' => [
                    'parts' => [
                        [
                            'number' => 1,
                            'chunk_start' => 0,
                            'chunk_end' => $size - 1,
                            'checksum_sha256_base64' => $checksumBase64,
                        ]
                    ]
                ]
            ];

            $response = Polar::api("POST", "v1/files", $createPayload);

            $uploadInfo = $response->json('upload');
            $partInfo = $uploadInfo['parts'][0];

            /** Step 2: Upload file part
            * @see https://laracasts.com/discuss/channels/laravel/sending-uploaded-file-to-external-api-using-the-http-client
            * @see https://laravel.com/docs/12.x/http-client#sending-a-raw-request-body
            */ 
            $uploadResponse = Http::withBody($data, $fileData['mime_type'] ?? 'application/octet-stream')
                ->withHeaders($partInfo['headers'])
                ->put($partInfo['url'])
                ->throw();

            $etag = $uploadResponse->header('ETag');

            // Step 3: Complete upload
            $completePayload = [
                'id' => $uploadInfo['id'],
                'path' => $uploadInfo['path'],
                'parts' => [
                    [
                        'number' => $partInfo['number'],
                        'checksum_etag' => $etag,
                        'checksum_sha256_base64' => $checksumBase64,
                    ]
                ]
            ];

            return Polar::api("POST", "v1/files/{$response->json('id')}/uploaded", $completePayload)
                ->throw()->json();

        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error uploading file to Polar', [
                'url' => $pathOrUrl,
                'error' => $e->getMessage(),
            ]);

            exception_info($e, include: ['code',
                'file',
                'line',
                'trace',
                'trace_as_string'
            ]);

            throw $e;
        } finally {
            if (isset($fileData['file_handle']) && is_resource($fileData['file_handle'])) {
                fclose($fileData['file_handle']);
            }
        }  
    }

    /**
     * @return array
     */
    public static function listFiles(bool $asDataClass = false, ?string $service = null): array
    {
        try {
            $response = Polar::api("GET", "v1/files")
                ->throw();
                
            $files = $response->json('items');

            if ($service) {
                $files = array_filter($files, fn(array $item) => $item['service'] === $service);
            }

            return $asDataClass ? array_map(fn(array $item) => match($item['service']) {
                'product_media' => ProductMediaData::fromArray($item),
                'downloadable' => DownloadableMediaData::fromArray($item),
                'organization_avatar' => OrganizationAvatarMediaData::fromArray($item),
                default => throw new InvalidArgumentException('Unknown service type'),
            }, $files) : $files;
        } catch (PolarApiError $e) {
           throw new PolarApiError($e->getMessage(), 400);
        }  
    }

    /**
     * Summary of updateFile
     * @param string $fileId
     * @param array{name: string | null, version: string | null} $updates
     * @return array
     */
    public static function updateFile(string $fileId, array $updates): array
    {
        try {
            return Polar::api("PATCH", "v1/files/{$fileId}", $updates)
                ->throw()->json();
        } catch (PolarApiError $e) {
           throw new PolarApiError($e->getMessage(), 400);
        }  
    }

    public static function deleteFile(string $fileId): array
    {
        try {
            return Polar::api("DELETE", "v1/files/{$fileId}")
                ->throw()->json();
        } catch (PolarApiError $e) {
           throw new PolarApiError($e->getMessage(), 400);
        }  
    }

}