<?php

namespace App\Models;

use Livewirez\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $base_code
 * @property string $target_code
 * @property float $rate
 * @property int|null $timestamp
 * @property string|null $date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BillingCurrencyConversionRate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'base_code',
        'target_code',
        'rate',
        'timestamp',
        'date',
        'url'
    ];

    protected $casts = [
        'date' => 'timestamp'
    ];

    public function convertCurrency(int $amount): string
    {
        $convertedAmount = $amount * $this->rate;

        $scale = match (strtoupper($this->target_code)) {
            'BTC' => 8,
            'ETH' => 18,
            'USDT' => 6,
            'USD', 'EUR', 'KES', 'KSH' => 2,
            default => 2,
        };

        return is_int($convertedAmount) ? Money::formatAmount($convertedAmount, $scale) 
                : Money::formatMajorAmount($convertedAmount, $scale);

    } 
}
