<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class ShippingAddress implements \JsonSerializable 
{
    private string $addressLine1;
    private string $adminArea2;
    private string $adminArea1;
    private string $postalCode;
    private string $countryCode;
    
    public function __construct(string $addressLine1, string $adminArea2, string $adminArea1, string $postalCode, string $countryCode) {
        $this->addressLine1 = $addressLine1;
        $this->adminArea2 = $adminArea2;
        $this->adminArea1 = $adminArea1;
        $this->postalCode = $postalCode;
        $this->countryCode = $countryCode;
    }
    
    public function getAddressLine1(): string {
        return $this->addressLine1;
    }
    
    public function getAdminArea2(): string {
        return $this->adminArea2;
    }
    
    public function getAdminArea1(): string {
        return $this->adminArea1;
    }
    
    public function getPostalCode(): string {
        return $this->postalCode;
    }
    
    public function getCountryCode(): string {
        return $this->countryCode;
    }
    
    public function getFormattedAddress(): string {
        return $this->addressLine1 . ', ' . $this->adminArea2 . ', ' . $this->adminArea1 . ' ' . $this->postalCode . ', ' . $this->countryCode;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'address_line_1' => $this->getAddressLine1(),
            'admin_area_2' => $this->getAdminArea2(),
            'admin_area_1' => $this->getAdminArea1(),
            'postal_code' => $this->getPostalCode(),
            'country_code' => $this->getCountryCode(),
        ];
    }
}
