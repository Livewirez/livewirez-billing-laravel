<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    Route::post(
        '/subscriptions/initiatesubscription',
        [App\Http\Controllers\SubscriptionsController::class, 'initializeSubscription']
    )->name('api.subscriptions.initiate_subscription');


    Route::post('/subscriptions/setupsubscriptionpaymenttoken', [App\Http\Controllers\SubscriptionsController::class, 'setupSubscriptionPaymentToken'])
    ->name('api.subscriptions.setup_subscription_payment_token');

    

    Route::get(
        'testcachelock',
        [App\Http\Controllers\SubscriptionsController::class, 'testCacheLock']
    )->name('app.test_cache_lock');

    Route::get('testquery', function (Request $request) {

        $request->validate([
            'provider' => [new Illuminate\Validation\Rules\Enum(Livewirez\Billing\Enums\PaymentProvider::class), 'required', function(string $attribute, mixed $value, \Closure $fail) {
                if (Livewirez\Billing\Enums\PaymentProvider::tryFrom($value) !== Livewirez\Billing\Enums\PaymentProvider::PayPal) $fail('Unsupported Method');
            }],
            'subscription_id' => ['required'],
        ]);
    })->name('api.test_query');


    Route::get(
        'testratelimit',
        [App\Http\Controllers\SubscriptionsController::class, 'testRateLimit']
    )->middleware('throttle:1,0.25')->name('app.test_rate_limit'); // 0.25 minutes = 15 seconds
});