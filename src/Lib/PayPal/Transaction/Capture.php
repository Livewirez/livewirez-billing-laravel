<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Capture implements \JsonSerializable  
{
    private string $id;
    private string $status;
    private Amount $amount;
    private bool $finalCapture;
    private SellerProtection $sellerProtection;
    private SellerReceivableBreakdown $sellerReceivableBreakdown;
    private array $links;
    private string $createTime;
    private string $updateTime;
    
    public function __construct(
        string $id,
        string $status,
        Amount $amount,
        bool $finalCapture,
        SellerProtection $sellerProtection,
        SellerReceivableBreakdown $sellerReceivableBreakdown,
        array $links,
        string $createTime,
        string $updateTime
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->amount = $amount;
        $this->finalCapture = $finalCapture;
        $this->sellerProtection = $sellerProtection;
        $this->sellerReceivableBreakdown = $sellerReceivableBreakdown;
        $this->links = $links;
        $this->createTime = $createTime;
        $this->updateTime = $updateTime;
    }
    
    public function getId(): string {
        return $this->id;
    }
    
    public function getStatus(): string {
        return $this->status;
    }
    
    public function getAmount(): Amount {
        return $this->amount;
    }
    
    public function isFinalCapture(): bool {
        return $this->finalCapture;
    }
    
    public function getSellerProtection(): SellerProtection {
        return $this->sellerProtection;
    }
    
    public function getSellerReceivableBreakdown(): SellerReceivableBreakdown {
        return $this->sellerReceivableBreakdown;
    }
    
    public function getLinks(): array {
        return $this->links;
    }
    
    public function getCreateTime(): string {
        return $this->createTime;
    }
    
    public function getUpdateTime(): string {
        return $this->updateTime;
    }

    public function jsonSerialize(): mixed {
        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'amount' => $this->getAmount(),
            'final_capture' => $this->isFinalCapture(),
            'seller_protection' => $this->getSellerProtection(),
            'seller_receivable_breakdown' => $this->getSellerReceivableBreakdown(),
            'links' => $this->getLinks(),
            'create_time' => $this->getCreateTime(),
            'update_time' => $this->getUpdateTime(),
        ]; 
    }
}
