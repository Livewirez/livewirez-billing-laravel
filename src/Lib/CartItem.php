<?php

namespace Livewirez\Billing\Lib;

use DomainException;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\ProductCategory;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Interfaces\OrderCalculatableInterface;


class CartItem implements OrderCalculatableInterface, CartItemInterface
{
    public function __construct(protected ProductInterface $product, protected int $quantity = 1)
    {
        $this->quantity = max(1, $quantity);

        // Digital goods should always have a quantity of 1
        if ($product->getProductCategory() === ProductCategory::DIGITAL_GOODS) {
            $this->quantity = 1;
        }
    }


    public function getProduct(): ProductInterface
    {
        return $this->product;
    }

    public function setProduct(ProductInterface $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = max(1, $quantity);

        // Digital goods should always have a quantity of 1
        if ($this->product->getProductCategory() === ProductCategory::DIGITAL_GOODS) {
            $this->quantity = 1;
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity,
            'product' => [
                'id'               => $this->product->getId(),
                'currency_code'    => $this->product->getCurrencyCode(),
                'product_type'     => $this->product->getProductType(),
                'product_category' => $this->product->getProductCategory(),
                'name'             =>  $this->product->getName(),
                'description'      =>  $this->product->getDescription(),
                'sku'              => $this->product->getSku(),
                'url'              => $this->product->getUrl(),
                'image_url'        => $this->product->getImageUrl(),
                'upc'              => $this->product->getUpc(),
                'price'            => $this->product->getPrice(true),
                'listed_price'     => $this->product->getListedPrice(),

            ]
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public static function fromArray(array $data): static
    {
        return new static($data['product'], $data['quantity']);
    }

    public function getCurrencyCode(): string
    {
        return $this->product->getCurrencyCode();
    }

    public function getCurrencyType(): CurrencyType
    {
        if (CurrencyCode::from($this->getCurrencyCode())->isFiat()) 
            return CurrencyType::FIAT;

        if (CurrencyCode::from($this->getCurrencyCode())->isCrypto()) 
            return CurrencyType::CRYPTO;

        throw new DomainException('Unsupported Currency Type');
    }

    public function getItemTotals(bool $use_discounts = true): int
    {
        return $use_discounts ? (($this->product->getPrice(false) - $this->product->getDiscount() - $this->product->getShippingDiscount()) * $this->quantity)
            : (($this->product->getPrice(false)) * $this->quantity);
    }

    
    public function getItemTaxTotals(): int
    {
        return $this->product->getTax() * $this->quantity;
    }

    public function getItemExtraTaxTotals(int $extra_tax = 0): int
    {
        return $this->product->getTax() + $extra_tax * $this->quantity;
    }

    public function getDiscountTotal(): int
    {
        return $this->product->getDiscount() * $this->quantity;
    }

    public function getShippingTotal(): int
    {
        return $this->product->getShipping() * $this->quantity;
    }

    public function getShippingDiscountTotal(): int
    {
        return $this->product->getShippingDiscount() * $this->quantity;
    }

    public function getHandlingTotal(): int
    {
        return $this->product->getHandling() * $this->quantity;
    }

    public function getInsuranceTotal(): int
    {
        return $this->product->getInsurance() * $this->quantity;
    }

    public function getGrandTotal(bool $use_discounts = true): int
    {
        return $this->getItemTotals($use_discounts) 
        + $this->getItemTaxTotals() 
        + $this->getShippingTotal()
        + $this->getHandlingTotal()
        + $this->getInsuranceTotal();
    }

    public function getGrandTotalFromExtraTax(bool $use_discounts = true, int $extra_tax = 0): int
    {
        return $this->getItemTotals($use_discounts) 
        + $this->getItemExtraTaxTotals($extra_tax) 
        + $this->getShippingTotal()
        + $this->getHandlingTotal()
        + $this->getInsuranceTotal();
    }
}