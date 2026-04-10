<?php 


return [
    'default' => env('PAYMENT_PROVIDER', 'paypal'), // paypal, stripe, mpesa

    'default_currency' => env('PAYMENT_CURRENCY', 'USD'), // USD, GBP, BTC, USDT

    'default_card_gateway' => env('CARD_GATEWAY', 'cybersource'),

    'providers' => [

        // https://developer.cybersource.com/library/documentation/dev_guides/REST_API/Getting_Started/html/index.html#page/REST_GS%2Fch_authentication.5.3.htm%23ww1120375
        'cybersource' => [
            'environment' => env('CYBERSOURCE_ENVIRONMENT', "sandbox"),
            'base_url' => [
                'sandbox' => 'https://apitest.cybersource.com',
                'production' => env('CYBERSOURCE_BASE_URL', 'https://api.cybersource.com')
            ],
            'host' => [
                'sandbox' => env('CYBERSOURCE_HOST', 'apitest.cybersource.com'),
                'production' => env('CYBERSOURCE_HOST', 'api.cybersource.com'),
            ],
            'key' => env('CYBERSOURCE_KEY'),
            'shared_secret' => env('CYBERSOURCE_SHARED_SECRET'),
            'organization_id' => env('CYBERSOURCE_ORGANIZATION_ID'),
            'merchant_id' => env('CYBERSOURCE_MERCHANT_ID'),
            'rest_api_shared_secret' => env('CYBERSOURCE_REST_API_SHARED_SECRET')
        ],

        'paypal' => [
            'provider' => 'paypal',
            'default_paypal_provider' => env('PAYPAL_DEFAULT_PROVIDER', 'paypal_http'),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'environment' => env('PAYPAL_ENVIRONMENT', "SANDBOX"),
            'base_url' => [ 
                // https://developer.paypal.com/api/rest/requests/
                'SANDBOX' => 'https://api-m.sandbox.paypal.com', //*,
                'PRODUCTION' => env('PAYPAL_BASE_URL', 'https://api-m.paypal.com'),
            ],
            'expires_in' => 32000,
            'extra_tax' => env('PAYPAL_EXTRA_TAX', 0.0), // 0.33
            'payment_return_url' => env('PAYPAL_PAYMENT_RETURN_URL', env('APP_URL')),
            'payment_cancel_url' => env('PAYPAL_PAYMENT_CANCEL_URL', env('APP_URL')),
            'subscription_return_url' => env('PAYPAL_SUBSCRIPTION_RETURN_URL', env('APP_URL')),
            'subscription_cancel_url' => env('PAYPAL_SUBSCRIPTION_CANCEL_URL', env('APP_URL')),
            'payment_return_url_name' => env('PAYPAL_PAYMENT_RETURN_URL_NAME', 'paypal.payment.success'),
            'payment_cancel_url_name' => env('PAYPAL_PAYMENT_CANCEL_URL_NAME', 'paypal.payment.cancel'),
            'subscription_return_url_name' => env('PAYPAL_SUBSCRIPTION_RETURN_URL_NAME', 'paypal.subscription.success'),
            'subscription_cancel_url_name' => env('PAYPAL_SUBSCRIPTION_CANCEL_URL_NAME', 'paypal.subscription.cancel'),
            'paypal_webhook_secret_value' => env('PAYPAL_WEBHOOK_SECRET_VALUE'),
            'paypal_webhook_secret_key' => env('PAYPAL_WEBHOOK_SECRET_KEY'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'subscriptions' => [
                'user_action' => env('PAYPAL_SUBSCRIPTION_USER_ACTION', 'CONTINUE'), // 'CONTIUNE' or 'SUBSCRIBE_NOW'
                'payment_method_payee_preferred' => env(
                    'PAYPAL_SUBSCRIPTION_PAYMENT_METHOD_PAYEE_PREFERRED',
                    'IMMEDIATE_PAYMENT_REQUIRED'
                ) // 'IMMEDIATE_PAYMENT_REQUIRED' or 'UNRESTRICTED'
            ]
        ],
    
        'mpesa' => [
            'provider' => 'mpesa',
            'environment' => env('MPESA_ENVIRONMENT', 'sandbox'), 
            'base_url' => [
                'sandbox' => 'https://sandbox.safaricom.co.ke',
                'production' => env('MPESA_BASE_URL', 'https://api.safaricom.co.ke')
            ],
            'business_name' => env('MPESA_BUSINESS_NAME'),
            'consumer_key' =>  env('MPESA_CONSUMER_KEY'),
            'consumer_secret' =>  env('MPESA_CONSUMER_SECRET'),
            'shortcode_type' => env('MPESA_SHORTCODE_TYPE', 'CustomerPayBillOnline'),
            'shortcode' => env('MPESA_SHORTCODE'),
            'business_shortcode' =>  env('MPESA_BUSINESS_SHORTCODE'),
            'till_number' =>  env('MPESA_TILL_NUMBER'),
            'test_msisdn' =>  env('MPESA_TEST_MSISDN'),
            'callback_domain' =>  env('MPESA_CALLBACK_DOMAIN'),
            'passkey' =>  env('MPESA_PASSKEY'),
            'b2c_password' =>  env('MPESA_B2C_PASSWORD'),
            'b2c_security_credential' => env('MPESA_B2C_SECURITY_CREDENTIAL'),
            'b2c_initiator' => env('MPESA_B2C_INITIATOR'),
            'callback_secret_key' => env('MPESA_CALLBACK_SECRET_KEY'),
            'callback_secret_value' => env('MPESA_CALLBACK_SECRET_VALUE'),
            'expires_in' => 3000
        ],
    
        'pesapal' => [
            'provider' => 'pesapal',
            'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),
            'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
            'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
            'base_url' => [
                // 'sandbox' => 'https://cybqa.pesapal.com',
                // 'production' => env('PESAPAL_BASE_URL', 'https://pay.pesapal.com'),
    
                'sandbox' => 'https://cybqa.pesapal.com/pesapalv3',
                'production' => env('PESAPAL_BASE_URL', 'https://pay.pesapal.com/v3'),
            ],
            'webhook_domain' =>  env('PESAPAL_WEBHOOK_DOMAIN'),
            'webhook_secret_value' => env('PESAPAL_WEBHOOK_SECRET_VALUE'),
            'webhook_secret_key' => env('PESAPAL_WEBHOOK_SECRET_KEY'),
        ],

        'polar' => [
            'provider' => 'polar',
            'environment' => env('POLAR_ENVIRONMENT', 'sandbox'),
            'base_url' => [
                'sandbox' => 'https://sandbox-api.polar.sh',
                'production' => env('POLAR_BASE_URL', 'https://api.polar.sh'),
            ],
            /*
            |--------------------------------------------------------------------------
            | Polar Access Token
            |--------------------------------------------------------------------------
            |
            | The Polar access token is used to authenticate with the Polar API.
            | You can find your access token in the Polar dashboard > Settings
            | under the "Developers" section.
            |
            */
            'access_token' => env('POLAR_API_KEY'),
            'api_key' => env('POLAR_API_KEY'),

            'organization_id' => env('POLAR_ORGANIZATION_ID'),

            /*
            |--------------------------------------------------------------------------
            | Polar Webhook Secret
            |--------------------------------------------------------------------------
            |
            | The Polar webhook secret is used to verify that the webhook requests
            | are coming from Polar. You can find your webhook secret in the Polar
            | dashboard > Settings > Webhooks on each registered webhook.
            |
            | We (the developers) recommend using a single webhook for all your
            | integrations. This way you can use the same secret for all your
            | integrations and you don't have to manage multiple webhooks.
            |
            */
            'webhook_secret' => env('POLAR_WEBHOOK_SECRET'),

            'webhook_secret_key' => env('POLAR_WEBHOOK_SECRET_KEY'),
            'webhook_secret_value' => env('POLAR_WEBHOOK_SECRET_VALUE'),

            /*
            |--------------------------------------------------------------------------
            | Polar Url Path
            |--------------------------------------------------------------------------
            |
            | This is the base URI where routes from Polar will be served
            | from. The URL built into Polar is used by default; however,
            | you can modify this path as you see fit for your application.
            |
            */
            'path' => env('POLAR_PATH', 'polar'),

            /*
            |--------------------------------------------------------------------------
            | Default Redirect URL
            |--------------------------------------------------------------------------
            |
            | This is the default redirect URL that will be used when a customer
            | is redirected back to your application after completing a purchase
            | from a checkout session in your Polar account.
            |
            */
            'redirect_url' => env('POLAR_REDIRECT_URL', env('APP_URL')),

            'subscription_redirect_url' => env('POLAR_SUBSCRIPTION_REDIRECT_URL', env('APP_URL')),

            /*
            |--------------------------------------------------------------------------
            | Currency Locale
            |--------------------------------------------------------------------------
            |
            | This is the default locale in which your money values are formatted in
            | for display. To utilize other locales besides the default "en" locale
            | verify you have to have the "intl" PHP extension installed on the system.
            |
            */
            'currency_locale' => env('POLAR_CURRENCY_LOCALE', 'en'),
        ],

        'cryptomus' => [
            'provider' => 'cryptomus',
            'api_key' => env('CRYPTOMUS_API_KEY'),
            'merchant_id' => env('CRYPTOMUS_MERCHANT_ID') 
        ],

        'paddle' => [
            'provider' => 'paddle',
            'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
            'api_key' => env('PADDLE_API_KEY'),
            'base_url' => [
                'sandbox' => 'https://sandbox-api.paddle.com',
                'production' => env('PADDLE_BASE_URL', 'https://api.paddle.com'),
            ],
            'webhook_secret' => env('PADDLE_WEHBOOK_SECRET'),
            'webhook_secret_value' => env('PADDLE_WEBHOOK_SECRET_VALUE'),
            'webhook_secret_key' => env('PADDLE_WEBHOOK_SECRET_KEY'),

            'payment_return_url' => env('PADDLE_PAYMENT_RETURN_URL', env('APP_URL')),
            'subscription_return_url' => env('PADDLE_SUBSCRIPTION_RETURN_URL', env('APP_URL')),
        ]
    ],

 
    'currencies' => [

        'active_currency_api' => env('ACTIVE_CURRENCY_API','era1'),

        'era1' => [
            /** @see https://www.exchangerate-api.com/docs/php-currency-api */
            'base_url' => env('EXCHANGE_RATE_API_URL', 'https://v6.exchangerate-api.com/v6'),
            'default_url' => 'https://api.exchangerate-api.com/v4',

            /**
             * GET https://v6.exchangerate-api.com/v6/YOUR-API-KEY/latest/USD
             * 
             * GET https://v6.exchangerate-api.com/v6/latest/USD & Authorization: Bearer YOUR-API-KEY
             */
            'api_key' => env('EXCHANGE_RATE_API_API_KEY')
        ],

        'era2' => [
            /** @see https://exchangeratesapi.io/documentation/ */
            'base_url' => env('EXCHANGE_RATES_API_IO_API_URL', 'https://api.exchangeratesapi.io/v1'),

            /**
             * https://api.exchangeratesapi.io/v1/latest?access_key = API_KEY
             * 
             * 
             * https://api.exchangeratesapi.io/v1/latest? access_key = API_KEY& base = USD& symbols = GBP,JPY,EUR
             */
            'api_key' => env('EXCHANGE_RATES_API_IO_API_KEY')
        ]
    ]
];