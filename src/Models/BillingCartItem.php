<?php 

namespace Livewirez\Billing\Models;

use DomainException;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Illuminate\Support\Str;
use Livewirez\Billing\Money;
use Illuminate\Support\Carbon;
use Livewirez\Billing\Billing;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Lib\CurrencyCode;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\PaymentStatus;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Enums\ProductCategory;
use Livewirez\Billing\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Livewirez\Billing\Interfaces\ProductInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Livewirez\Billing\Interfaces\OrderCalculatableInterface;

class BillingCartItem extends Model implements CartItemInterface, OrderCalculatableInterface
{
    public $timestamps = false;

    protected $fillable = [
        'quantity',
        'billing_product_id',
    ];

    public function billing_cart(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingCart, 'billing_cart_id');
    }
    
    public function billing_product(): BelongsTo 
    {
        return $this->belongsTo(Billing::$billingProduct, 'billing_product_id');
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


    public function getProduct(): ProductInterface
    {
        $this->loadMissing(['billing_product']);

        return $this->billing_product;
    }

    public function setProduct(ProductInterface $product): static
    {
        $this->billing_product()->associate($product->getId());
        $this->save();

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->loadMissing(['billing_product']);

        $this->quantity = max(1, $quantity);

        // Digital goods should always have a quantity of 1
        if ($this->billing_product->getProductCategory() === ProductCategory::DIGITAL_GOODS) {
            $this->quantity = 1;
        }

        $this->save();

        return $this;
    }

    public function getItemTotals(bool $use_discounts = true): int
    {
        return $use_discounts ? (($this->getProduct()->getPrice(false) - $this->getProduct()->getDiscount() - $this->getProduct()->getShippingDiscount()) * $this->quantity)
            : (($this->getProduct()->getPrice(false)) * $this->quantity);
    }

    
    public function getItemTaxTotals(): int
    {
        return $this->getProduct()->getTax() * $this->quantity;
    }

    public function getItemExtraTaxTotals(int $extra_tax = 0): int
    {
        return $this->getProduct()->getTax() + $extra_tax * $this->quantity;
    }

    public function getDiscountTotal(): int
    {
        return $this->getProduct()->getDiscount() * $this->quantity;
    }

    public function getShippingTotal(): int
    {
        return $this->getProduct()->getShipping() * $this->quantity;
    }

    public function getShippingDiscountTotal(): int
    {
        return $this->getProduct()->getShippingDiscount() * $this->quantity;
    }

    public function getHandlingTotal(): int
    {
        return $this->getProduct()->getHandling() * $this->quantity;
    }

    public function getInsuranceTotal(): int
    {
        return $this->getProduct()->getInsurance() * $this->quantity;
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