<?php 

namespace App\Services\Payments;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Redirect;
use Inertia\Response as InertiaResponse;
use Livewirez\Billing\Actions\CancelPayment;
use Livewirez\Billing\Actions\CompletePayment;
use Livewirez\Billing\Actions\InitializePayment;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;
use Livewirez\Billing\Actions\SetupPaymentToken;
use Livewirez\Billing\Actions\StartSubscription;
use Livewirez\Billing\Actions\UpdateSubscription;
use Livewirez\Billing\Actions\InitializeSubscription;
use Livewirez\Billing\Actions\SetupSubscriptionPaymentToken;
use Livewirez\Billing\Actions\CompletePaymentWithToken;
use Livewirez\Billing\Actions\StartSubscriptionWithToken;

abstract class PaymentProviderHandler
{
    public static function setupPaymentToken(Request $request, SetupPaymentToken $action): Response
    {
        return Redirect::route('dashboard');
    }

    public static function setupSubscriptionPaymentToken(Request $request, SetupSubscriptionPaymentToken $action): Response
    {
        return Redirect::route('dashboard');
    }

    public static function completePaymentWithToken(Request $request, CompletePaymentWithToken $action): Response | Responsable
    {
        return Redirect::route('dashboard');
    }

    public static function startSubscriptionWithToken(Request $request, StartSubscriptionWithToken $action): Response | Responsable 
    {
        return Redirect::route('dashboard');
    }

    abstract public static function initializePayment(Request $request, InitializePayment $action): Response;

    abstract public static function completePayment(Request $request, CompletePayment $action): Response | Responsable;

    abstract public static function cancelPayment(Request $request, CancelPayment $action): Response;

    abstract public static function initializeSubscription(Request $request, InitializeSubscription $action): Response;

    abstract public static function updateSubscription(Request $request, UpdateSubscription $action): Response;

    abstract public static function startSubscription(Request $request, StartSubscription $action): Response | Responsable;
}