<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingOrderItemResource extends JsonResource
{
     /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'price' => $this->formatted_price,
            'quantity' => $this->quantity,
            'thumbnail' => $this->thumbnail,
            'currency' => $this->currency,

            'subtotal' => $this->formatted_subtotal, // before tax & shipping
            'discount' => $this->formatted_discount,
            'tax' => $this->formatted_tax,
            'shipping' => $this->formatted_shipping,
            'total' => $this->formatted_total,

            'status' => $this->status,
            'billing_order_item_id' => $this->billing_order_item_id,
            'payment_status' => $this->payment_status,
            'delivery_status' => $this->delivery_status,
            'fulfillment_status' => $this->fulfillment_status,
            'billing_product' => $this->whenLoaded(
                'billing_product',
                fn () => BillingProductResource::make($this->billing_product)->resolve()
            ),
            'billing_order' => $this->whenLoaded(
                'billing_order',
                fn () => BillingOrderResource::make($this->billing_order)->resolve()
            ),
        ];
    }
}