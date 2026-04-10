<?php 

namespace Livewirez\Billing\Exceptions;

use Livewirez\Billing\Enums\PaymentStatus;
use RuntimeException;
use Livewirez\Billing\ErrorInfo;
use Livewirez\Billing\PaymentResult;

class PaymentInitiationException extends RuntimeException
{

    protected ?ErrorInfo $errorInfo = null;

    public function __construct(protected PaymentResult $result) 
    {
        parent::__construct(
            $result->message ?? $result->result->getError()?->message ?? $result->result->getError()?->title ?? 'Payment Initiation Error',
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

    public function getPaymentStatus(): PaymentStatus
    {
        return $this->result->status;
    }

    public function getOrderId(): string
    {
        return $this->result->billingOrderId;
    }
    public function getProviderOrderId(): ?string
    {
        return $this->result->providerOrderId;
    }   
}