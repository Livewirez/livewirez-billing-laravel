<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Enum;
use App\Services\Payments\CardHandler;
use App\Services\Payments\PolarHandler;
use Livewirez\Billing\Models\PlanPrice;
use App\Services\Payments\PaddleHandler;
use App\Services\Payments\PayPalHandler;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rules\RequiredIf;
use Livewirez\Billing\Enums\PaymentProvider;
use Illuminate\Validation\ValidationException;
use Livewirez\Billing\Enums\SubscriptionStatus;
use App\Actions\Subscriptions\StartSubscription;
use App\Actions\Subscriptions\UpdateSubscription;
use App\Actions\Subscriptions\InitializeSubscription;
use App\Actions\Subscriptions\StartSubscriptionWithToken;
use App\Actions\Subscriptions\SetupSubscriptionPaymentToken;

class SubscriptionsController extends Controller
{
    public function setupSubscriptionPaymentToken(Request $request, SetupSubscriptionPaymentToken $action)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::setupSubscriptionPaymentToken($request, $action),
            PaymentProvider::PayPal => PayPalHandler::setupSubscriptionPaymentToken($request, $action),
        
            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }

    
    public function startSubscriptionWithToken(Request $request, StartSubscriptionWithToken $action)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::startSubscriptionWithToken($request, $action),
            PaymentProvider::PayPal => PayPalHandler::startSubscriptionWithToken($request, $action),
        
            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }

    public function initializeSubscription(Request $request, InitializeSubscription $action)
    {
        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
            'plan_price' => ['required', 'numeric', 'exists:billing_plan_prices,id']
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::initializeSubscription($request, $action),
            PaymentProvider::PayPal => PayPalHandler::initializeSubscription($request, $action),
            PaymentProvider::Polar => PolarHandler::initializeSubscription($request, $action),
            PaymentProvider::Paddle => PaddleHandler::initializeSubscription($request, $action),

            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }


    public function startSubscription(Request $request, StartSubscription $action)
    {

        capture_request_vars($request);

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
            //'subscription_id' => [new RequiredIf(fn () => $request->provider === 'paypal'), /*Rule::requiredIf()*/],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::startSubscription($request, $action),
            PaymentProvider::PayPal => PayPalHandler::startSubscription($request, $action),
            PaymentProvider::Polar => PolarHandler::startSubscription($request, $action),
            PaymentProvider::Paddle => PaddleHandler::startSubscription($request, $action),

            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }

    public function updateSubscription(Request $request, UpdateSubscription $action)
    {

        capture_request_vars($request);

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
            'subscription_id' => ['required'],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::updateSubscription($request, $action),
            PaymentProvider::PayPal => PaypalHandler::updateSubscription($request, $action),
            PaymentProvider::Polar => PaypalHandler::updateSubscription($request, $action),
            PaymentProvider::Paddle => PaypalHandler::updateSubscription($request, $action),

            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }



    public function testCacheLock(Request $request)
    {
        $userId = $request->user()->id;

        // Create a unique lock per user or transaction
        $lock = Cache::lock("cache-lock-test:user:{$userId}", 15); // 10 seconds timeout

        if ($lock->get()) {
            try {
                Sleep::sleep(5);

                return response()->json(['status' => 'success']);

            } finally {
                $lock->release();
            }
        } else {
            return response()->json(['status' => 'locked', 'message' => 'Action in progress.'], 429);
        }
    }

    public function testRateLimit(Request $request)
    {
        Sleep::sleep(5);

        return response()->json(['status' => 'success']);

    }
}
