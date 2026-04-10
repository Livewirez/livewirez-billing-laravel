<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class SellerProtection implements \JsonSerializable 
{
    private string $status;
    private array $disputeCategories;
    
    public function __construct(string $status, array $disputeCategories) {
        $this->status = $status;
        $this->disputeCategories = $disputeCategories;
    }
    
    public function getStatus(): string {
        return $this->status;
    }
    
    public function getDisputeCategories(): array {
        return $this->disputeCategories;
    }
    
    public function isEligible(): bool {
        return $this->status === 'ELIGIBLE';
    }

    public function jsonSerialize(): mixed
    {
        return [
            'status' => $this->getStatus(),
            'dispute_categories' => $this->getDisputeCategories(),
        ];
    }
}