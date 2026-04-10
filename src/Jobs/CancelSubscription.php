<?php

namespace Livewirez\Billing\Jobs;

use Throwable;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class CancelSubscription implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $subscription_id)
    {
        
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $subscription = Billing::$billingSubscription::find($this->subscription_id);

        if ($subscription?->isActive() || ! $subscription?->isExpired()) {
            $subscription->cancel();
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error(__CLASS__ . ' Job Failed', [
            'error' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'exception_type' => class_basename($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
        ]);
    }
}
