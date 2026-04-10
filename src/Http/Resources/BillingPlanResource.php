<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingPlanResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features,
            'billing_prices' => $this->whenLoaded(
                'billing_prices',
                fn () => BillingPlanPriceResource::collection($this->billing_prices)->resolve($request)
            ),
            'billing_plan_prices' => $this->whenLoaded(
                'billing_plan_prices',
                fn () => BillingPlanPriceResource::collection($this->billing_plan_prices)->resolve($request)
            ),
            'billing_plan_payment_provider_information' => $this->whenLoaded(
                'billing_plan_payment_provider_information',
                fn () => BillingPlanPaymentProviderInformationResource::collection($this->billing_plan_payment_provider_information)->resolve($request)
            )
        ];
    }
}
