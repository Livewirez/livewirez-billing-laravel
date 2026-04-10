<?php

namespace Livewirez\Billing\Lib;

use Closure;
use Livewirez\Billing\Interfaces\Billable;

class Customer
{
    protected ?Closure $addressResolver = null;

    public function __construct(
        public string $email,
        public string $name,
        public ?string $phone = null,
        public ?Address $billingAddress = null
    ) {
        $this->addressResolver = fn (): ?Address => $this->billingAddress;
    }

    public static function fromBillable(Billable $billable, ?Address $billingAddress = null, ?Closure $addressResolver = null): self
    {
        $customer = new self(
            email: $billable->getEmail(),
            name: $billable->getName(),
            phone: $billable->getMobileNumber(),
            billingAddress: $billingAddress
        );

        if ($addressResolver) {
            return $customer->setAddressResolver($addressResolver);
        }

        return $customer;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBillingAddress(): ?Address
    {
        return $this->addressResolver ? call_user_func($this->addressResolver) : $this->billingAddress;
    }

    public function setBillingAddress(?Address $billingAddress): static
    {
        $this->billingAddress = $billingAddress;

        return $this;
    }

    /**
     * Get the route resolver callback.
     *
     * @return \Closure
     */
    public function getAddressResolver(): Closure
    {
        return $this->addressResolver ?: fn (): ?Address => null;
    }

    /**
     * Set the address resolver callback.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function setAddressResolver(Closure $callback): static
    {
        $this->addressResolver = $callback;

        return $this;
    }
}