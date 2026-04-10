<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'billing_order_id' => $this->billing_order_id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'currency' => $this->currency,

            'subtotal' => $this->formatted_subtotal, // before tax & shipping
            'discount' => $this->formatted_discount,
            'tax' => $this->formatted_tax,
            'shipping' => $this->formatted_shipping,
            'total' => $this->formatted_total,

            'payment_status' => $this->payment_status,
            'payment_provider' => $this->payment_provider,
            'billing_order_items' => $this->whenLoaded(
                'billing_order_items',
                fn () => BillingOrderItemResource::collection($this->billing_order_items)->resolve()
            )
        ];
    }
}
