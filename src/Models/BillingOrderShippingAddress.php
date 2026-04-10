<?php 

namespace Livewirez\Billing\Models;

use InvalidArgumentException;
use Livewirez\Billing\Billing;
use Livewirez\Billing\Lib\Address;
use Livewirez\Billing\Lib\Customer;
use Illuminate\Database\Eloquent\Model;
use Livewirez\Billing\Interfaces\Billable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillableAddress extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'zip_code',
        'country',
        'phone',
        'type',
        'hash'
    ];

    protected $casts = [
        
    ];

    public static function hash(string $line1, string $city, string $country): string
    {
        $data = "{$line1}-{$city}-{$country}";


        return hash('sha256', $data);
    }

    public static function hashFromAddress(Billable $billable, Address $address): string
    {
        return static::hash($address->line1, $address->city, $address->country);
    }

    public static function attributesFromAddress(Address $address): array
    {
        $names = explode(' ', $address->name, 2);

        return [
            'first_name' => $names[0],
            'last_name' => $names[1] ?? null,
            'phone' => $address->phone,
            'email' => $address->email,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'zip_code' => $address->zip_code,
            'country' => $address->country,
        ];
    }

    public function billing_orders(): HasMany
    {
        return $this->hasMany(Billing::$billingOrder, 'shipping_address_id');
    }
}