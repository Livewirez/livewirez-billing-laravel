<?php

namespace Livewirez\Billing\Lib;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillableAddress;


class Address
{
    public function __construct(
        public string $email,
        public string $name,
        public ?string $phone = null,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postal_code = null,
        public ?string $zip_code = null,
        public ?string $country = null,
    ) {}

    public static function make(
        string $email,
        string $name,
        ?string $phone = null,
        ?string $line1 = null,
        ?string $line2 = null,
        ?string $city = null,
        ?string $state = null,
        ?string $postal_code = null,
        ?string $zip_code = null,
        ?string $country = null,
    ): self 
    {
        return new self(
            $email,
            $name,
            $phone,
            $line1,
            $line2,
            $city,
            $state,
            $postal_code,
            $zip_code,
            $country
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email_address'] ?? $data['email'],
            name: $data['name'],
            phone: $data['mobile_number'] ?? $data['phone_number'] ?? $data['phone'],
            line1: $data['address_line_1'] ?? $data['line1'] ?? null,
            line2: $data['address_line_2'] ?? $data['line2'] ?? null,
            city: $data['admin_area_2'] ?? $data['address_city'] ?? $data['city'] ?? null,
            state: $data['admin_area_1'] ?? $data['address_state'] ?? $data['state'] ?? null,
            postal_code: $data['address_postal_code'] ?? $data['postal_code'] ?? null,
            zip_code: $data['address_zip_code'] ?? $data['zip_code'] ?? null,
            country: $data['address_country']  ?? $data['country_code'] ?? $data['country'] ?? null
        );
    }

    public function fromBillableUserAddress(BillableAddress $address, ?Billable $user = null): static
    {
        $user ??= $address->loadMissing('billable')->billable;

        return static::make(
            email: $address->email,
            name: trim(($address->first_name ?? $user->getName()) . ' ' . ($address->last_name ?? '')),
            phone: $address->phone,
            line1: $address->line1,
            line2: $address->line2,
            city: $address->city,
            state: $address->state,
            postal_code: $address->postal_code,
            zip_code: $address->zip_code,
            country: $address->country,
        );
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getAddressLine1(): ?string
    {
        return $this->line1;
    }

    public function getAddressLine2(): ?string
    {
        return $this->line2;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getPostalCode(): ?string
    {
        return $this->postal_code;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }
}