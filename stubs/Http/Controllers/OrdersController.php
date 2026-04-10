<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\Lib\CartItem;
use App\Actions\Orders\CancelPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Enum;
use Livewirez\Billing\Models\Product;
use App\Services\Payments\CardHandler;
use App\Actions\Orders\CompletePayment;
use App\Services\Payments\PolarHandler;
use App\Services\Payments\PaddleHandler;
use App\Services\Payments\PayPalHandler;
use Illuminate\Support\Facades\Redirect;
use App\Actions\Orders\InitializePayment;
use App\Actions\Orders\SetupPaymentToken;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Lib\Card\CardManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Illuminate\Validation\ValidationException;
use App\Actions\Orders\CompletePaymentWithToken;

class OrdersController extends Controller
{

    public function createCyberSourceMicroformSession()
    {
        $response = CardManager::createSession('cybersource');

        return [
            'token' => $response->body()
        ];
    }

    public function setupPaymentToken(Request $request, SetupPaymentToken $action)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::PayPal => PayPalHandler::setupPaymentToken($request, $action),
        
            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }


    public function completePaymentWithToken(Request $request, CompletePaymentWithToken $action)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::completePaymentWithToken($request, $action),
            PaymentProvider::PayPal => PayPalHandler::completePaymentWithToken($request, $action),
        
            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }

    public function initializePayment(Request $request, InitializePayment $action)
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'provider' => [new Enum(PaymentProvider::class), 'required'],
            'products' => ['required', 'array'],
            'products.*' => ['required', 'array', 'required_array_keys:product,quantity'],
            'products.*.product' => ['required', 'numeric', 'exists:billing_products,id'],
            'products.*.quantity' =>  ['required', 'numeric']
        ]);

        return match (PaymentProvider::tryFrom($validated['provider'])) {
            PaymentProvider::Card => CardHandler::initializePayment($request, $action),
            PaymentProvider::PayPal => PayPalHandler::initializePayment($request, $action),
            PaymentProvider::Polar => PolarHandler::initializePayment($request, $action),
            PaymentProvider::Paddle => PaddleHandler::initializePayment($request, $action),

            default => throw ValidationException::withMessages([
                'provider' => 'Unsupported Payment Provider'
            ])
        };
    }

    public function completePayment(Request $request, CompletePayment $action)
    {
        if (! $provider = $request->query('provider')) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        return match (PaymentProvider::tryFrom($provider)) {
            PaymentProvider::Card => CardHandler::completePayment($request, $action),
            PaymentProvider::PayPal => PayPalHandler::completePayment($request, $action),
            PaymentProvider::Polar => PolarHandler::completePayment($request, $action),
            PaymentProvider::Paddle => PaddleHandler::completePayment($request, $action),
            default => Redirect::route('dashboard')
        };  
    }

    public function cancelPayment(Request $request, CancelPayment $action)
    {
        // capture_request_vars($request);

        if (! $provider = $request->query('provider')) {
            \Illuminate\Support\Facades\Log::warning('No Provider in cancel', [__METHOD__ . __LINE__]);
            return Redirect::route('dashboard');
        }

        return match (PaymentProvider::tryFrom($provider)) {
            PaymentProvider::Card => CardHandler::cancelPayment($request, $action),
            PaymentProvider::PayPal => PayPalHandler::cancelPayment($request, $action),
            PaymentProvider::Polar => PolarHandler::cancelPayment($request, $action),
            PaymentProvider::Paddle => PaddleHandler::cancelPayment($request, $action),
            default => Redirect::route('dashboard')
        };  
    }
}
