<?php

namespace Livewirez\Billing\Traits;

use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\ProductCategory;

trait BillingProductTrait
{
    use ProductPricingTrait;

    public function getId(): int
    {
        return $this->getKey();
    }

    public function getCurrencyCode(): string
    {
        return $this->currency ?? config('billing.currency_code');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getImageUrl(): ?string
    {
        return $this->thumbnail;
    }

    public function getUpc(): ?string
    {
        return $this->name;
    }

    public function getProductType(): ProductType
    {
        return $this->product_type;
    }

    public function getProductCategory(): ProductCategory
    {
        return $this->product_category;
    }
}