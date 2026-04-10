<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class PayPal implements \JsonSerializable 
{
    private string $emailAddress;
    private string $accountId;
    private string $accountStatus;
    private Name $name;
    private Address $address;
    private bool $appSwitchEligibility;
    
    public function __construct(string $emailAddress, string $accountId, string $accountStatus, Name $name, Address $address, bool $appSwitchEligibility) {
        $this->emailAddress = $emailAddress;
        $this->accountId = $accountId;
        $this->accountStatus = $accountStatus;
        $this->name = $name;
        $this->address = $address;
        $this->appSwitchEligibility = $appSwitchEligibility;
    }
    
    public function getEmailAddress(): string {
        return $this->emailAddress;
    }
    
    public function getAccountId(): string {
        return $this->accountId;
    }
    
    public function getAccountStatus(): string {
        return $this->accountStatus;
    }
    
    public function getName(): Name {
        return $this->name;
    }
    
    public function getAddress(): Address {
        return $this->address;
    }
    
    public function isAppSwitchEligible(): bool {
        return $this->appSwitchEligibility;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'email_address' => $this->getEmailAddress(),
            'account_id' => $this->getAccountId(),
            'account_status' => $this->getAccountStatus(),
            'name' => $this->getName(),
            'address' => $this->getAddress(),
            'app_switch_eligibility' => $this->isAppSwitchEligible(),
        ];
    }
}