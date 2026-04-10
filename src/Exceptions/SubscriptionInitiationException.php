<?php 

namespace Livewirez\Billing\Exceptions;

use Livewirez\Billing\Enums\SubscriptionStatus;
use RuntimeException;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\SubscriptionResult;

class SubscriptionInitiationException extends RuntimeException
{
    public const string DEFAULT_MESSAGE = 'Subscription Initiation Error';

    protected ?ErrorInfo $errorInfo = null;

    public function __construct(protected SubscriptionResult $result) 
    {
        parent::__construct(
            $result->message ?? $result->result->getError()?->message ?? $result->result->getError()?->title ?? static::DEFAULT_MESSAGE,
            $result->result->getError()?->code ?? 0,
            $result->result->getError()?->error
        );

        $this->errorInfo = $result->result->getError();
    }

    public function getErrorInfo(): ?ErrorInfo
    {
        return $this->errorInfo;
    }

    public function getErrorInfoContext(): array
    {
        return $this->errorInfo?->context ?? [];
    }

    public function getErrorInfoMetadata(): array
    {
        return $this->getErrorInfoContext();
    }

    public function getSubscriptionStatus(): SubscriptionStatus
    {
        return $this->result->status;
    }

    public function getSubscriptionId(): string
    {
        return $this->result->billingSubscriptionId;
    }

    public function getProviderSubscriptionId(): ?string
    {
        return $this->result->billingSubscriptionId;
    }

    public function getProviderPlanId(): ?string
    {
        return $this->result->providerPlanId;
    }
}