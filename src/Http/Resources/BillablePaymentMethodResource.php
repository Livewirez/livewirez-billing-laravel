<?php 

namespace Livewirez\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillablePaymentMethodResource extends JsonResource
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
            'billable_payment_method_id' => $this->billable_payment_method_id,
            'payment_provider' => $this->payment_provider,
            'billing_email' => $this->billing_email,
            'is_active' => $this->is_active,
        ];
    }
}
