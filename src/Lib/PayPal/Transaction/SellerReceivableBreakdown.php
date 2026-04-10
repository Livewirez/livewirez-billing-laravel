<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class SellerReceivableBreakdown implements \JsonSerializable 
{
    private Amount $grossAmount;
    private Amount $paypalFee;
    private Amount $netAmount;
    
    public function __construct(Amount $grossAmount, Amount $paypalFee, Amount $netAmount) {
        $this->grossAmount = $grossAmount;
        $this->paypalFee = $paypalFee;
        $this->netAmount = $netAmount;
    }
    
    public function getGrossAmount(): Amount {
        return $this->grossAmount;
    }
    
    public function getPaypalFee(): Amount {
        return $this->paypalFee;
    }
    
    public function getNetAmount(): Amount {
        return $this->netAmount;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'gross_amount' => $this->getGrossAmount(),
            'paypal_fee' => $this->getPaypalFee(),
            'net_amount' => $this->getNetAmount(),
        ];
    }
}