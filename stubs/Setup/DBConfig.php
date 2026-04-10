<?php 

namespace App\Tests;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewirez\Billing\PaymentManager;
use Livewirez\Billing\Info\ProductItem;
use App\Actions\Payments\CompletePayment;
use App\Actions\Payments\InitiatePayment;
use Illuminate\Support\Facades\Artisan;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Models\BillingDiscountCode;
use Livewirez\Billing\Lib\Polar\Data\Products\ProductData;
use Livewirez\Billing\Lib\Polar\Data\Discounts\DiscountData;
use Livewirez\Billing\Enums\ApiProductTypeKey;

class DBConfig
{

    public static $testDataCallback;
    
    public static function createTestData(bool $returnTokenString = true)
    {
        DB::transaction(function () {
        
            $user = static::createUser();
            static::createProducts();
            static::insertPlans();
            static::insertPaymentMethods();

            // static::$testDataCallback = function (bool $returnTokenString = true) use ($user) {

            //     return DB::transaction(function () use ($user, $returnTokenString) {
            //         $token = $user->createToken('default', ['*']);

            //         return $returnTokenString ? $token->plainTextToken : $token;
            //     });
            // };

            static::$testDataCallback = fn (bool $returnTokenString = true) => DB::transaction(
                function () use ($user, $returnTokenString) {
                    $token = $user->createToken('default', ['*']);

                    return $returnTokenString ? $token->plainTextToken : $token;
                }
            );
        });

        static::syncApiData();

        // Make sure callback exists and is callable
        if (isset(static::$testDataCallback) && is_callable(static::$testDataCallback)) {
            return call_user_func(static::$testDataCallback, $returnTokenString);
        }
    }

    public static function syncApiData()
    {
        static::syncPaypalPlanMetadata();
        static::syncPolarPlanMetadata();
        static::syncPolarProductMetadata();
        static::syncPolarPlanDiscounts();
        static::syncPaddlePlanDiscounts();
        static::syncPaddleProductMetadata();
        static::syncPaddlePlanMetadata();
        static::fillCurrencyConversionRates();
    }

    public static function insertPaymentMethods()
    {
        DB::insert(<<<SQL
            INSERT INTO billable_addresses (id, created_at, updated_at, billable_type, billable_id, billable_key, first_name, last_name, email, line1, line2, city, state, postal_code, zip_code, country, phone, "type", is_default, hash) VALUES(1, '2025-09-07 15:19:25.000', '2025-09-07 15:19:25.000', 'App\Models\User', 1, 'App\Models\User:1', 'John', 'Doe', 'sb-qhpzz38936263@personal.example.com', '1 Main St', NULL, 'San Jose', 'CA', '95131', NULL, 'US', NULL, 'billing', false, '4bdb22e9491b2023f0c8b763633ada8711f5e46db8fc16da1e1c7d7a982df4de');
        SQL
        );

        DB::insert(<<<SQL
            INSERT INTO billable_payment_provider_information (id, created_at, updated_at, billable_type, billable_id, payment_provider, payment_provider_user_id, billing_address_id, metadata) VALUES(1, '2025-09-07 15:19:25.000', '2025-09-07 15:19:25.000', 'App\Models\User', 1, 'paypal', 'CgJlfrIZVb', 1, NULL);
        SQL
        );

        DB::insert(<<<SQL
            INSERT INTO billable_payment_methods (id, created_at, updated_at, billable_type, billable_id, billable_payment_provider_information_id, billable_payment_method_id, payment_provider, payment_provider_user_id, sub_payment_provider, billable_user_key, payment_provider_method_id, "token", brand, exp_month, exp_year, last4, funding, country, fingerprint, billing_name, billing_email, billing_phone, address_line1, address_line2, address_city, address_state, address_postal_code, address_zip_code, address_country, is_default, metadata) VALUES(1, '2025-09-07 15:19:25.000', '2025-09-07 15:19:25.000', 'App\Models\User', 1, 1, 'ea4d4158-8508-439c-b389-54beaf1110dc'::uuid, 'paypal', 'CgJlfrIZVb', NULL, 'App\Models\User:1:CgJlfrIZVb', NULL, 'eyJpdiI6IndCOEcra0RlK2Z4UGk4SUJJSWsySVE9PSIsInZhbHVlIjoia0ozSEROTWZIK2dnRkd4Z0IybGhwSzFtS010eGRPcXN2a0xPR0l6b0pBQT0iLCJtYWMiOiI4YjQyMWNiZTdmNzA1MDkzZmE0MWUzMWRmNmY4YmMxMTAxODBiNDgxZjU2MDI3NWMxNGZlYmY0MTRiZmMzNGM0IiwidGFnIjoiIn0=', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'John Doe', 'sb-qhpzz38936263@personal.example.com', NULL, '1 Main St', NULL, 'San Jose', 'CA', '95131', NULL, 'US', false, NULL);
        SQL
        );
    }

    public static function fillCurrencyConversionRates()
    {
        Artisan::call('currency:save');
    }

    public static function createUser()
    {
        return User::create([
            'name' => 'Rocks Xebec',
            'email' => 'mirror892@gmail.com',
            'password' => '12345678',
        ]);
    }


    public static function createProducts()
    {
        $json = '
            [
                {
                    "id":1,
                    "created_at":"2025-03-19T03:06:44.000000Z",
                    "updated_at":"2025-03-19T03:06:44.000000Z",
                    "name":"Mustang GT Car Statue",
                    "description":"Mustang GT car pictured from a need for speed game",
                    "price":2000,
                    "currency":"USD",
                    "thumbnail":null,
                    "colour":"Black and Red",
                    "images":null,
                    "product_type":"physical",
                    "product_category":"PHYSICAL_GOODS",
                    "billing_product_id":"0684e04e-ae61-496c-8189-bec0fad30ad6",
                    "tax":0,
                    "tax_type":null,
                    "tax_model":"exclude",
                    "discount":0,
                    "discount_percentage":0,
                    "shipping":500,
                    "shipping_discount":0,
                    "handling":100,
                    "insurance":0,
                    "discount_expires_at":null,
                    "is_active":1,
                    "weight":null,
                    "brand":null,
                    "stock":0
                },
                {
                    "id":2,
                    "created_at":"2025-03-19T03:07:51.000000Z",
                    "updated_at":"2025-03-19T03:14:34.000000Z",
                    "name":"Lamborghini Huracan Statue",
                    "description":"Infamous Lamborghini Huracan pictured from a need for speed game",
                    "price":2500,
                    "currency":"USD",
                    "thumbnail":null,
                    "colour":"Black and Red",
                    "images":null,
                    "product_type":"physical",
                    "product_category":"PHYSICAL_GOODS",
                    "billing_product_id":"cd131e69-21ae-4b55-a3a5-2a9b854a2db6",
                    "tax":0,
                    "tax_type":null,
                    "tax_model":"exclude",
                    "discount":0,
                    "discount_percentage":0,
                    "shipping":300,
                    "shipping_discount":0,
                    "handling":100,
                    "insurance":0,
                    "discount_expires_at":null,
                    "is_active":1,
                    "weight":null,
                    "brand":null,
                    "stock":0
                }]
            ';

        $data = json_decode($json, true);

        $products = array_map(function ($d, $k) {

           $p = new BillingProduct($d);

           if ($k === 0) $p->thumbnail = 'https://mcusercontent.com/99aa7e1f00176548593fbfe81/images/84fdb19c-4c29-ad88-0349-2f6bb09cca17.jpg';
           if ($k === 1) $p->thumbnail = 'https://mcusercontent.com/99aa7e1f00176548593fbfe81/images/32561f47-907f-6138-5aa7-19f95d078f76.jpg';

            $p->save();

            return $p;
        },  $data, array_keys($data));

        return collect($products);
    }

    public static function insertPlans()
    {
        $products = [
            [
                'created_at' => now(),
                'updated_at' => now(),
                'name' => 'Car Parts Marketplace Seller Subscription',
                'description' => 'Subscription service allowing sellers to list and manage car parts on the marketplace',
                'price' => 0,
                'currency' => 'USD',
                'url' => 'https://packages.test/payments',
                'thumbnail' => 'https://mcusercontent.com/99aa7e1f00176548593fbfe81/images/32561f47-907f-6138-5aa7-19f95d078f76.jpg',
                'colour' => 'NONE',
                'images' => null,
                'product_type' => 'service',
                'product_category' => 'MARKETPLACE',
                'billing_product_id' => $productUuid = 'f51235b9-f277-4b4b-98a6-7cfa3cccba48',
                'tax' => 16,
                'tax_type' => 'percent',
                'tax_model' => 'include',
                'discount' => 0,
                'discount_percentage' => 0,
                'shipping' => 0,
                'shipping_discount' => 0,
                'handling' => 0,
                'insurance' => 0,
                'discount_expires_at' => null,
                'is_active' => 1,
                'weight' => null,
                'brand' => null,
                'stock' => 0
            ]
        ];

        DB::table('billing_products')->insert($products);

            $discounts = [
            [
                'billing_discount_code_id' => $welcome = Str::uuid(),
                'code' => 'WELCOME50',
                'name' => 'Welcome 50% Off',
                'billing_type' => ApiProductTypeKey::SUBSCRIPTION->value,
                'type' => 'percentage',
                'value' => 50,
                'currency' => config('billing.default_currency', 'USD'),
                'max_uses' => 1000,
                'used_count' => 0,
                'max_uses_per_customer' => 1,
                'starts_at' => now(),
                'expires_at' => now()->addMonths(3),
                'extends_trial_days' => 7,
                'is_active' => true,
            ],
            [
                'billing_discount_code_id' => $student = Str::uuid(),
                'code' => 'STUDENT20',
                'name' => 'Student Discount',
                'billing_type' => ApiProductTypeKey::SUBSCRIPTION->value, 
                'type' => 'percentage',
                'value' => 20,
                'currency' => config('billing.default_currency', 'USD'),
                'max_uses' => null, // Unlimited
                'used_count' => 0,
                'max_uses_per_customer' => 1,
                'starts_at' => now(),
                'expires_at' => null, // No expiry
                'extends_trial_days' => 0,
                'is_active' => true,
            ],
        ];

        DB::table('billing_discount_codes')->insert($discounts);


        $productId = DB::table('billing_products')->where('billing_product_id', $productUuid)->value('id');


        $plans = [
            [
                'billing_plan_id' => $free = Str::uuid(),
                'billing_product_id' => $productId,
                'name' => 'Free',
                'ranking' => 0,
                'type' => 'recurring',
                'is_active' => true,
                'features' => json_encode([
                    "List up to 15 parts",
                    "Basic store profile",
                    "Standard search visibility",
                    "Email support",
                    "Order notifications",
                    "Basic analytics",
                ]),
            ],
            [
                'billing_plan_id' =>  $bronze = Str::uuid(),
                'billing_product_id' => $productId,
                'name' => 'Bronze',
                'ranking' => 2,
                'type' => 'recurring',
                'is_active' => true,
                'features' => json_encode([
                    "List up to 30 parts",
                    "Basic store profile",
                    "Premium search visibility",
                    "Email support",
                    "Order notifications",
                    "Advanced analytics",
                ]),
            ],
            [
                'billing_plan_id' =>  $silver = Str::uuid(),
                'billing_product_id' => $productId,
                'name' => 'Silver',
                'ranking' => 3,
                'type' => 'recurring',
                'is_active' => true,
                'features' => json_encode([
                    "Everything in Bronze",
                    "List up to 60 parts",
                    "Featured store profile",
                    "Priority search placement",
                    "Phone & email support",
                    "Real-time order tracking",
                    "Advanced analytics",
                    "Promotional banners",
                    "Bulk upload tool",
                ]),
            ],
            [
                'billing_plan_id' =>  $gold = Str::uuid(),
                'billing_product_id' => $productId,
                'name' => 'Gold',
                'ranking' => 4,
                'type' => 'recurring',
                'is_active' => true,
                'features' => json_encode([
                    "Everything in Silver", // ['text' => "Everything in Silver", 'included' => true]
                    "100 parts listing",
                    "Premium store profile",
                    "Top search placement",
                    "24/7 dedicated support",
                    "Integration APIs",
                    "Comprehensive analytics",
                    "Custom reporting",
                    "Multiple user accounts",
                    "Priority fulfillment badge",
                    "Marketing assistance",
                ]),
            ],
        ];


        foreach ($plans as $plan) {
            DB::table('billing_plans')->insert(array_merge($plan, ['created_at' => now(), 'updated_at' => now()]));
        }

        $freeId = DB::table('billing_plans')->where('billing_plan_id', $free)->value('id');
        $bronzeId = DB::table('billing_plans')->where('billing_plan_id', $bronze)->value('id');
        $silverId = DB::table('billing_plans')->where('billing_plan_id', $silver)->value('id');
        $goldId = DB::table('billing_plans')->where('billing_plan_id', $gold)->value('id');

        $welcomeDiscountCodeId = DB::table('billing_discount_codes')->where('billing_discount_code_id', $welcome)
        ->value('id');
        $studentDiscountCodeId =  DB::table('billing_discount_codes')->where('billing_discount_code_id', $student)
        ->value('id');

        $prices = [
            // Free (no price)
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $freeId,
                'interval' => 'MONTHLY',
                'amount' => 0,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $freeId,
                'interval' => 'MONTHLY',
                'amount' => 0,
                'currency' => 'USD',
            ],
            // Bronze
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $bronzeId,
                'interval' => 'MONTHLY',
                'amount' => 50000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $bronzeId,
                'interval' => 'MONTHLY',
                'amount' => 400,
                'tax' => 62,
                'currency' => 'USD',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $bronzeId,
                'interval' => 'YEARLY',
                'amount' => 480000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => null,
                'billing_plan_id' => $bronzeId,
                'interval' => 'YEARLY',
                'amount' => 3717,
                'tax' => 595,
                'currency' => 'USD',
            ],
            // Silver
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $studentDiscountCodeId,
                'billing_plan_id' => $silverId,
                'interval' => 'MONTHLY',
                'amount' => 80000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $studentDiscountCodeId,
                'billing_plan_id' => $silverId,
                'interval' => 'MONTHLY',
                'amount' => 620,
                'tax' => 100,
                'currency' => 'USD',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $studentDiscountCodeId,
                'billing_plan_id' => $silverId,
                'interval' => 'YEARLY',
                'amount' => 960000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $studentDiscountCodeId,
                'billing_plan_id' => $silverId,
                'interval' => 'YEARLY',
                'amount' => 7434,
                'tax' => 1200,
                'currency' => 'USD',
            ],
            // Gold
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $welcomeDiscountCodeId,
                'billing_plan_id' => $goldId,
                'interval' => 'MONTHLY',
                'amount' => 120000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $welcomeDiscountCodeId,
                'billing_plan_id' => $goldId,
                'interval' => 'MONTHLY',
                'amount' => 930,
                'tax' => 150,
                'currency' => 'USD',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $welcomeDiscountCodeId,
                'billing_plan_id' => $goldId,
                'interval' => 'YEARLY',
                'amount' => 1440000,
                'currency' => 'KES',
            ],
            [
                'billing_plan_price_id' => Str::uuid(),
                'billing_discount_code_id' => $welcomeDiscountCodeId,
                'billing_plan_id' => $goldId,
                'interval' => 'YEARLY',
                'amount' => 11200,
                'tax' => 1800,
                'currency' => 'USD',
            ],
        ];

        foreach ($prices as &$price) {
            $price['custom_interval_count'] = null;
            $price['tax'] = 0;
            $price['tax_type'] = null;
            $price['tax_model'] = 'exclude';
            $price['created_at'] = now();
            $price['updated_at'] = now();
        }
        unset($price);

        DB::table('billing_plan_prices')->insert($prices);

        return \Livewirez\Billing\Models\BillingPlan::with('billing_plan_prices')->get();
        
    }

    public static function syncPaypalPlanMetadata()
    {
        $subscriptionPlansManager = new \Livewirez\Billing\Lib\PayPal\SubscriptionPlansManager;

        $product = BillingProduct::find(3);

        $databasePlans = $product->billing_plans()->get();

        $plans = collect(collect($subscriptionPlansManager->listPlans())->get('plans'));

        return $databasePlans->map(function (\Livewirez\Billing\Models\BillingPlan $plan) use ($product, $plans, $subscriptionPlansManager) {

            $data = $plans->firstWhere('name', $plan->name);

            if ($data) {
                return (fn () => $this->updatePlanWithProviderMetadata($plan, $product, $data))->call($subscriptionPlansManager);
            }

            return null;
        });
    }

    public static function syncPolarPlanMetadata()
    {
        $plans = \Livewirez\Billing\Lib\Polar\ProductsManager::getSubscriptionProducts();

        foreach ($plans as $plan) {
            \Livewirez\Billing\Lib\Polar\ProductsManager::updateBillingPlanMetadata(
                $plan_price = \Livewirez\Billing\Models\BillingPlanPrice::find($plan->metadata['billing_plan_price_id']),
                $plan
            );
        }
    }


    public static function syncPolarProductMetadata()
    {
        $products = array_filter(
            \Livewirez\Billing\Lib\Polar\ProductsManager::getProducts(),
            fn (ProductData $product) => isset($product->metadata['billing_product'])
        );

        foreach ($products as $product) {
            \Livewirez\Billing\Lib\Polar\ProductsManager::updateBillingProductMetadata(
                $product_price = BillingProduct::find((int) $product->metadata['billing_product']),
                $product
            );
        }
    }

    public static function syncPolarPlanDiscounts()
    {
        $discounts = array_filter(
            \Livewirez\Billing\Lib\Polar\DiscountsManager::getSubscriptionDiscounts(),
            fn (DiscountData $discount) => isset($discount->metadata['billing_discount_code']) 
        );

        foreach ($discounts as $discount) {
            \Livewirez\Billing\Lib\Polar\DiscountsManager::updateBillingDiscountCodeMetadata(
                $discount_code = BillingDiscountCode::find((int) $discount->metadata['billing_discount_code']),
                $discount
            );
        }
    }

    public static function syncPaddlePlanDiscounts()
    {
        $discounts = array_filter(
            \Livewirez\Billing\Lib\Paddle\DiscountsManager::getDiscounts([
                'code' => BillingDiscountCode::where('is_active', true)->get()->pluck('code')->all()
            ]),
            fn (array $discount) => isset(
                $data['custom_data'],
                $data['custom_data']['metadata'],
                $discount['custom_data']['metadata']['billing_discount_code']
            ) 
        );

        foreach ($discounts as $discount) {
            \Livewirez\Billing\Lib\Paddle\DiscountsManager::updateBillingDiscountCodeMetadata(
                $discount_code = BillingDiscountCode::find((int) $discount['custom_data']['metadata']['billing_discount_code']),
                $discount
            );
        }
    }


    public static function syncPaddleProductMetadata()
    {
        $products = \Livewirez\Billing\Lib\Paddle\ProductsManager::getProducts([
            'include' => 'prices'
        ]);

        $products = array_filter(
            $products['data'] ?? [], 
            fn (array $data) => isset(
                $data['custom_data'],
                $data['custom_data']['metadata'],
                $data['custom_data']['metadata']['product_type'],
            ) && $data['custom_data']['metadata']['product_type'] === ApiProductTypeKey::ONE_TIME->value
        );

        foreach ($products as $product) {
            if (isset($product['prices'])) {
                \Livewirez\Billing\Lib\Paddle\ProductsManager::updateBillingProductMetadata(
                    $b_product = BillingProduct::find($product['custom_data']['metadata']['billing_product']),
                    $product,
                    $product['prices']
                );
            }
        }
    }

    public static function syncPaddlePlanMetadata()
    {
        $plans = \Livewirez\Billing\Lib\Paddle\ProductsManager::getSubscriptionProducts([
            'include' => 'prices'
        ]);

        $plans = array_filter(
            $plans['data'] ?? [], 
            fn (array $data) => isset(
                $data['custom_data'],
                $data['custom_data']['metadata'],
                $data['custom_data']['metadata']['billing_plan_price'],
            )
        );

        foreach ($plans as $plan) {
            \Livewirez\Billing\Lib\Paddle\ProductsManager::updateBillingPlanMetadataList(
                $plan_price = \Livewirez\Billing\Models\BillingPlanPrice::find($plan['custom_data']['metadata']['billing_plan_price']),
                $plan
            );

            $prices = isset($plan['prices']) &&  is_array($plan['prices']) ? $plan['prices'] : [];

            foreach ($prices as $price) {
                \Livewirez\Billing\Lib\Paddle\ProductsManager::updateBillingPlanPriceMetadataList(
                    $plan_price,
                    $price
                );
            }
        }

    }
}