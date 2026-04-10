<?php

namespace Livewirez\Billing\Lib;

use Exception;
use DomainException;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Models\BillingCart;
use Livewirez\Billing\Models\BillingCartItem;
use Illuminate\Support\Facades\Log;

class BillingCartWrapper implements CartInterface
{
    public function __construct(public BillingCart $cart)
    {
        $this->loadRelations();
    }

    private function loadRelations(): void
    {
        $this->cart->loadMissing([
            'billing_cart_items.billing_product'
        ]);
    }

    private function syncAndRecalculate(int $extra_tax = 0): void
    {
        $this->cart->refresh()->loadMissing(['billing_cart_items.billing_product']);
        $this->updateValues($extra_tax);
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
        $this->cart->update(['currency' => $currency_code]);
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
        return $this->cart->billing_cart_items->map(
            fn(BillingCartItem $item) => $item->billing_product
        )->toArray();
    }

    public function getTotalItemCount(): int
    {
        return $this->cart->billing_cart_items->sum('quantity');
    }

    public function add(CartItemInterface $item): static
    {
        $this->cart->billing_cart_items()->updateOrCreate(
            ['billing_product_id' => $item->getProduct()->getId()],
            ['quantity' => max(1, $item->getQuantity())]
        );

        $this->syncAndRecalculate();
        return $this;
    }

    public function remove(CartItemInterface $item): static
    {
        $this->cart->billing_cart_items()
            ->where('billing_product_id', $item->getProduct()->getId())
            ->delete();

        $this->syncAndRecalculate();
        return $this;
    }

    public function addProduct(ProductInterface $product, int $quantity = 1): static
    {
        return $this->add(new CartItem($product, $quantity));
    }

    public function removeProduct(ProductInterface $product): static
    {
        return $this->remove(new CartItem($product));
    }

    public function updateQuantity(int $product_id, int $quantity): static
    {
        $item = $this->cart->billing_cart_items()
            ->where('billing_product_id', $product_id)
            ->first();

        if ($item) {
            $item->update(['quantity' => max(1, $quantity)]);
        }

        $this->syncAndRecalculate();
        return $this;
    }

    public function incrementQuantity(int $product_id, int $quantity = 1): static
    {
        $item = $this->cart->billing_cart_items()
            ->where('billing_product_id', $product_id)
            ->first();

        if ($item) {
            $item->increment('quantity', max(1, $quantity));
        }

        $this->syncAndRecalculate();
        return $this;
    }

    public function decrementQuantity(int $product_id, int $quantity = 1): static
    {
        $item = $this->cart->billing_cart_items()
            ->where('billing_product_id', $product_id)
            ->first();

        if ($item) {
            $newQty = max(1, $item->quantity - $quantity);
            $item->update(['quantity' => $newQty]);
        }

        $this->syncAndRecalculate();
        return $this;
    }

    public function clear(): static
    {
        $this->cart->billing_cart_items()->delete();
        $this->syncAndRecalculate();
        return $this;
    }

    public function replaceItems(array $items): static
    {
        $this->cart->billing_cart_items()->delete();

        foreach ($items as $value) {
            if ($value instanceof CartItem) {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value->getProduct()->getId(),
                    'quantity' => $value->getQuantity(),
                ]);
            } elseif ($value instanceof BillingCartItem) {
                $this->cart->billing_cart_items()->create(
                    $value->only(['billing_product_id', 'quantity'])
                );
            } else {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value['product_id'] ?? $value['billing_product_id'],
                    'quantity' => $value['quantity'],
                ]);
            }
        }

        $this->syncAndRecalculate();
        return $this;
    }

    public function get(int $product_id): ?CartItemInterface
    {
        return $this->cart->billing_cart_items()
            ->with('billing_product')
            ->where('billing_product_id', $product_id)
            ->first();
    }

    public function fill(iterable $attributes): static
    {
        $this->cart->billing_cart_items()->delete();

        foreach ($attributes as $value) {
            if ($value instanceof CartItem) {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value->getProduct()->getId(),
                    'quantity' => $value->getQuantity()
                ]);
            } elseif ($value instanceof BillingCartItem) {
                $this->cart->billing_cart_items()->create(
                    $value->only(['billing_product_id', 'quantity'])
                );
            } else {
                $this->cart->billing_cart_items()->create([
                    'billing_product_id' => $value['product_id'] ?? $value['billing_product_id'],
                    'quantity' => $value['quantity']
                ]);
            }
        }

        $this->syncAndRecalculate();
        return $this;
    }

    public function removeUsingProductId(int $product_id): static
    {
        $this->cart->billing_cart_items()
            ->where('billing_product_id', $product_id)
            ->delete();

        $this->syncAndRecalculate();
        return $this;
    }

    public function all(): array
    {
        return $this->cart->billing_cart_items->toArray();
    }

    public function getAttributes(): array
    {
        return $this->cart->billing_cart_items->map(fn(BillingCartItem $it) => [
            'product' => $it->billing_product,
            'quantity' => $it->quantity
        ])->toArray();
    }

    public function toArray(): array
    {
        return $this->getAttributes();
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson($options = 0): string|bool
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    // ---------- Totals Computation ---------- //

    public function getItemTotals(bool $use_discounts = true): int
    {
        return $this->cart->billing_cart_items->sum(function (BillingCartItem $item) use ($use_discounts) {
            $price = $item->billing_product->getPrice(false);
            if ($use_discounts) {
                $price -= $item->billing_product->getDiscount();
                $price -= $item->billing_product->getShippingDiscount();
            }
            return $price * $item->quantity;
        });
    }

    public function getItemTaxTotals(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getTax() * $item->quantity
        );
    }

    public function getItemExtraTaxTotals(int $extra_tax = 0): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => ($item->billing_product->getTax() + $extra_tax) * $item->quantity
        );
    }

    public function getDiscountTotal(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getDiscount() * $item->quantity
        );
    }

    public function getShippingTotal(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getShipping() * $item->quantity
        );
    }

    public function getShippingDiscountTotal(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getShippingDiscount() * $item->quantity
        );
    }

    public function getHandlingTotal(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getHandling() * $item->quantity
        );
    }

    public function getInsuranceTotal(): int
    {
        return $this->cart->billing_cart_items->sum(
            fn(BillingCartItem $item) => $item->billing_product->getInsurance() * $item->quantity
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

    // ---------- Core Fix ---------- //

    private function updateValues(int $extra_tax = 0): void
    {
        $values = [
            'subtotal' => $this->getItemTotals(false),
            'tax' => $this->getItemExtraTaxTotals($extra_tax),
            'discount' => $this->getDiscountTotal(),
            'shipping' => $this->getShippingTotal(),
            'shipping_discount' => $this->getShippingDiscountTotal(),
            'handling' => $this->getHandlingTotal(),
            'insurance' => $this->getInsuranceTotal(),
        ];

        $values['total'] = $this->getGrandTotalFromExtraTax(true, $extra_tax);

        Log::debug('BillingCartWrapper::updateValues', $values);

        $this->cart->forceFill($values)->save();
    }
}
