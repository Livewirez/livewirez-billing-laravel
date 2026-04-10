<?php

namespace Livewirez\Billing\Listeners;

use Throwable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Jobs\ExpireSubscription;
use Livewirez\Billing\Events\SubscriptionRenewed;

class HandleSubscriptionRenewed implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(SubscriptionRenewed $event): void
    {
        ExpireSubscription::dispatch($subscription = $event->subscription)
            ->delay(CarbonImmutable::parse($subscription->ends_at))
            ->afterCommit();

    }

    /**
     * Handle a job failure.
     */
    public function failed(SubscriptionRenewed $event, Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error(__CLASS__ . ' Listener Failed', [
            'event' => $event,
            'error' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'exception_type' => class_basename($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
        ]);
    }
}
