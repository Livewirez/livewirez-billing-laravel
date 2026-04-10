<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingPlanPaymentProviderInformationResource extends JsonResource
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
            'billing_plan_id' => $this->billing_plan_id,
            'payment_provider' => $this->payment_provider,
            'payment_provider_plan_id' => $this->payment_provider_plan_id,
            'status' => $this->status,
            'billing_plan_price' => $this->whenLoaded(
                'billing_plan_price',
                fn () => BillingPlanPriceResource::collection($this->billing_plan_price)->resolve()
            )
        ];
    }
}
