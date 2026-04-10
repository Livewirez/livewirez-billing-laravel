<?php 

namespace Livewirez\Billing\Lib;

use Exception;
use LogicException;
use DomainException;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Illuminate\Support\Facades\Auth;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingCart;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingCartItem;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Interfaces\ProductInterface;

class BillingCartWrapper_ implements CartInterface
{
    public function __construct(public BillingCart $cart)
    {
        $this->loadRelations();
    }

    private function loadRelations()
    {
        $this->cart->loadMissing([
            'billing_cart_items' => [
                'billing_product'
            ]
        ]);
    }

    public function deleteCart()
    {
        return $this->cart->delete();
    }


    public function getCurrencyCode(): string
    {
        return $this->cart->currency;
    }

    public function setCurrencyCode(string $currency_code): static
    {
        $this->cart->update([
            'currency' => $currency_code
        ]);

       return $this;
    }

    public function getCurrencyType(): CurrencyType
    {
        if (CurrencyCode::from($this->getCurrencyCode())->isFiat()) 
            return CurrencyType::FIAT;

        if (CurrencyCode::from($this->getCurrencyCode())->isCrypto()) 
            return CurrencyType::CRYPTO;

        throw new DomainException('Unsupported Currency Type');
    }

    public static function fromCart(Billable $user, CartInterface $oldCart): static
    {
        $cart = $user->billing_cart()->firstOrCreate([
            'currency' => $oldCart->getCurrencyCode()
        ]);

        $cart->update(['currency' => $oldCart->getCurrencyCode()]);

        $instance = new static($cart);

        $instance->fill($oldCart->all());

        return $instance;
    } 

    public static function fromCartItem(CartItemInterface $cart_item): static 
    {
        throw new Exception(__METHOD__ . " is not supported");
    }

    public static function fromProduct(ProductInterface $product, int $quantity = 1): static 
    {
        throw new Exception(__METHOD__ . " is not supported");
    }

    public function getProducts(): array
    {
        return array_map(
            fn (CartItemInterface $cartItem) => $cartItem->getProduct(), 
            $this->cart->billing_cart_items->toArray()
        );
    }


    public function getTotalItemCount(): int
    {
        return array_reduce(
            array_map(fn (BillingCartItem $item) => $item->getQuantity(), $this->cart->billing_cart_items->toArray()), 
            fn (int $sum, int $quantity) => $sum + $quantity, 
            0
        );
    }

    public function add(CartItemInterface $item): static 
    {
        $item = $this->cart->billing_cart_items()->updateOrCreate([
            'billing_product_id' => $item->getProduct()->getId()
        ], [
            'quantity' => max(1, $item->getQuantity())
        ]);

        $this->cart->load(['billing_cart_items' => ['billing_product']]);

        $this->updateValues();

        return $this;
    }

    public function remove(CartItemInterface $item): static 
    {
        $this->cart->billing_cart_items()->where('billing_product_id', $item->getProduct()->getId())->delete();

        $this->cart->load(['billing_cart_items' => ['billing_product']]);

        $this->updateValues();

        return $this;
    }

    
    public function addProduct(ProductInterface $product, int $quantity = 1): static 
    {
        return $this->add(BillingCartItem::create([
            'quantity' => max(1, $quantity),
            'billing_product_id' => $product->getId(),
        ]));
    }

    public function removeProduct(ProductInterface $product): static 
    {
        return $this->remove(new CartItem($product));
    }

    public function updateQuantity(int $product_id, int $quantity): static 
    {
        foreach ($this->cart->billing_cart_items as $item) {
            if ($item->billing_product->getId() === $product_id) {
                $item->quantity = max(1, $quantity); // Ensure quantity is at least 1
                $item->save();
                break;
            }
        }

        $this->updateValues();

        return $this;
    }

    public function incrementQuantity(int $product_id, int $quantity = 1): static
    {
        foreach ($this->cart->billing_cart_items as $item) {
            if ($item->billing_product->getId() === $product_id) {
                $item->billing_product->increment('quantity', max(1, $quantity)); // Ensure quantity is at least 1
                break;
            }
        }

        $this->updateValues();

        return $this;
    }

    public function decrementQuantity(int $product_id, int $quantity = 1): static 
    {
        foreach ($this->cart->billing_cart_items as $item) {
            if ($item->billing_product->getId() === $product_id) {
                $item->quantity->decrement('quantity', max(1, $quantity)); // Ensure quantity is at least 1
                if ($item->quantity < 1) {
                    $item->quantity = 1; // Ensure quantity is at least 1
                    $item->save();
                }
                break;
            }
        }

        $this->updateValues();

        return $this;
    }

    public function clear()
    {
        $this->cart->billing_cart_items()->delete();

        $this->cart->load(['billing_cart_items']);

        $this->updateValues();

        return $this;
    }

    public function replaceItems(array $items): static 
    {
        foreach ($this->cart->billing_cart_items as $old) {
            $new = collect($items)->firstWhere('id', $old->id); // match by id or another field
            if ($new) {
                $old->fill($new->toArray())->save();
            }
        }

        $this->updateValues();

        return $this;
    }

    public function get(int $product_id): ?CartItemInterface
    {
        return $this->cart->billing_cart_items()
        ->with(['billing_product'])->where('billing_product_id', $product_id)
        ->first();
    }

    /**
     * Fill the fluent instance with an array of attributes.
     *
     * @param  iterable<int, BillingCartItem|CartItem>  $attributes
     * @return $this
     */
    public function fill(iterable $attributes): static 
    {
        $this->cart->billing_cart_items()->delete();

        foreach ($attributes as $key => $value) {
            if ($value instanceof CartItem) {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value->getProduct()->getId(), 
                    'quantity' => $value->getQuantity()
                ]);
            } else if ($value instanceof BillingCartItem) {
                $this->cart->billing_cart_items()->create($value->only(['billing_product_id', 'quantity']));
            } else {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value['product_id'] ?? $value['billing_product_id'], 
                    'quantity' => $value['quantity']
                ]);
            }
        }

        $this->updateValues();

        return $this;
    }

    public function removeUsingProductId(int $product_id): static 
    {
        $this->cart->billing_cart_items->firstWhere(fn (BillingCartItem $item) => $item->billing_product_id === $product_id)?->delete();

        $this->updateValues();

        return $this;
    }

    /**
     * Get all of the attributes from the fluent instance.
     *
     * @param  array|mixed|null  $keys
     * @return array
     */
    public function all(): array
    {
        return $this->cart->billing_cart_items->toArray();
    }

    public function getAttributes(): array
    {
        return $this->cart->billing_cart_items->map(fn (BillingCartItem $it) => [
            'product' => $it->billing_product, 
            'quantity' => $it->getQuantity()
        ])->toArray();
    }


    public function toArray(): array
    {
        return $this->cart->billing_cart_items->map(fn (BillingCartItem $it) => [
            'product' => $it->billing_product, 
            'quantity' => $it->getQuantity()
        ])->toArray();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the fluent instance to JSON.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0): bool|string
    {
        return json_encode($this->jsonSerialize(), $options);
    }


    public function getItemTotals(bool $use_discounts = true): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(), 
            fn (int $sum, BillingCartItem $item) => 
            $use_discounts ? $sum + (($item->billing_product->getPrice(false) - $item->billing_product->getDiscount() - $item->billing_product->getShippingDiscount()) * $item->quantity)
            : $sum + (($item->billing_product->getPrice(false)) * $item->quantity), 
            0
        );
    }

    
    public function getItemTaxTotals(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(), 
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getTax() * $item->quantity, 
            0
        );
    }

    public function getItemExtraTaxTotals(int $extra_tax = 0): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(), 
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getTax() + $extra_tax * $item->quantity, 
            0
        );
    }

    public function getDiscountTotal(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(),
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getDiscount() * $item->quantity,
            0
        );
    }

    public function getShippingTotal(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(),
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getShipping() * $item->quantity,
            0
        );
    }

    public function getShippingDiscountTotal(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(),
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getShippingDiscount() * $item->quantity,
            0
        );
    }

    public function getHandlingTotal(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(),
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getHandling() * $item->quantity,
            0
        );
    }

    public function getInsuranceTotal(): int
    {
        return array_reduce(
            $this->cart->billing_cart_items->all(),
            fn (int $sum, BillingCartItem $item) => $sum + $item->billing_product->getInsurance() * $item->quantity,
            0
        );
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

    private function updateValues(int $extra_tax = 0): void
    {
        $this->loadRelations();

        $values = [
            'total' => $this->getGrandTotalFromExtraTax(true, $extra_tax),
            'subtotal' => $this->getItemTotals(false),
            'tax' => $this->getItemExtraTaxTotals($extra_tax), 
            'discount' => $this->getDiscountTotal(),
            'shipping' => $this->getShippingTotal(),
            'shipping_discount' => $this->getShippingDiscountTotal(),
            'handling' => $this->getHandlingTotal(),
            'insurance' => $this->getInsuranceTotal(),
        ];

        \Illuminate\Support\Facades\Log::debug(__METHOD__, $values);


        $this->cart->update($values);        
    }
}