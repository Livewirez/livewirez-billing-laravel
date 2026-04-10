<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingProductResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'formatted_price' => $this->formatted_price,
            'currency' => $this->currency,
            'colour' => $this->colour,
            'url' => $this->url,
            'thumbnail' => $this->thumbnail,
            'images' => $this->images,
            'billing_product_id' => $this->billing_product_id,
            'shipping' => $this->shipping,
            'handling' => $this->handling,
            'tax' => $this->tax,
            'insurance' => $this->insurance,
            'discount' => $this->discount,
            'shipping_discount' => $this->shipping_discount,
        ];
    }
}