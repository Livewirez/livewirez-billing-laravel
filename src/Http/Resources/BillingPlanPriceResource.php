<?php

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingPlanPriceResource extends JsonResource
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
            'interval' => $this->interval,
            'amount' => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'currency' => $this->currency,
            'tax' => $this->tax,
            'tax_type' => $this->tax_type,
            'discount' => $this->discount,
            'billing_plan_payment_provider_information' => $this->whenLoaded(
                'billing_plan_payment_provider_information',
                fn () => BillingPlanPaymentProviderInformationResource::collection($this->billing_plan_payment_provider_information)->resolve()
            )
        ];
    }
}
