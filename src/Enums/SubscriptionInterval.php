<?php 

namespace Livewirez\Billing\Enums;

use DateInterval;
use DateTimeInterface;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Livewirez\Billing\Lib\PriceRanking;
use Livewirez\Billing\Traits\HasRanking;
use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionInterval: string 
{
    use ArchTechTrait, HasRanking;

    #[PriceRanking(1)]
    case DAILY = 'DAILY';

    #[PriceRanking(2)]
    case WEEKLY = 'WEEKLY';

    #[PriceRanking(3)]
    case MONTHLY = 'MONTHLY';

    #[PriceRanking(4)]
    case YEARLY = 'YEARLY';

    #[PriceRanking(-1)]
    case CUSTOM = 'CUSTOM';  // every 3 years, 5 years, Bi-annually 

    /**
     * Calculate the next billing date
     */
    public function calculateNextInterval(
        DateTimeInterface $start, 
        int $count = 1, 
        ?string $unit = null
    ): DateTimeInterface {
        // Handle Carbon instances for better performance and immutability
        if ($start instanceof CarbonInterface) {
            return $this->addCarbonInterval($start->copy(), $count, $unit);
        }

        // For DateTime instances, clone and add interval
        $next = clone $start;
        return $next->add($this->toDateInterval($count, $unit));
    }

    /**
     * Create a custom DateInterval based on unit
     */
    private function createCustomInterval(int $amount, ?string $unit = null): DateInterval
    {
        if ($unit === null && $this === static::CUSTOM) {
            throw new InvalidArgumentException("Unit is required for CUSTOM interval");
        }

        return match (strtolower($unit)) {
            'second', 'seconds' => new DateInterval("PT{$amount}S"),
            'minute', 'minutes' => new DateInterval("PT{$amount}M"),
            'hour', 'hours' => new DateInterval("PT{$amount}H"),
            'day', 'days' => new DateInterval("P{$amount}D"),
            'week', 'weeks' => new DateInterval("P{$amount}W"),
            'month', 'months' => new DateInterval("P{$amount}M"),
            'year', 'years' => new DateInterval("P{$amount}Y"),
            default => throw new InvalidArgumentException("Invalid time unit: {$unit}. Allowed: day, week, month, year"),
        };
    }

    /**
     * Add interval to Carbon instance using native methods for better performance
     */
    private function addCarbonInterval(CarbonInterface $carbon, int $count, ?string $unit): CarbonInterface
    {
        return match ($this) {
            self::DAILY => $carbon->addDays($count),
            self::WEEKLY => $carbon->addWeeks($count),
            self::MONTHLY => $carbon->addMonths($count),
            self::YEARLY => $carbon->addYears($count),
            self::CUSTOM => $this->addCustomCarbonInterval($carbon, $count, $unit),
        };
    }

     /**
     * Add custom interval to Carbon instance
     */
    private function addCustomCarbonInterval(CarbonInterface $carbon, int $count, ?string $unit): CarbonInterface
    {
        if ($unit === null && $this === static::CUSTOM) {
            throw new InvalidArgumentException("Unit is required for CUSTOM interval");
        }

        return match (strtolower($unit)) {
            'second', 'seconds' => $carbon->addSeconds($count),
            'minute', 'minutes' => $carbon->addMinutes($count),
            'hour', 'hours' => $carbon->addHours($count),
            'day', 'days' => $carbon->addDays($count),
            'week', 'weeks' => $carbon->addWeeks($count),
            'month', 'months' => $carbon->addMonths($count),
            'year', 'years' => $carbon->addYears($count),
            default => throw new InvalidArgumentException("Invalid time unit: {$unit}. Allowed: day, week, month, year"),
        };
    }

    /**
     * Create a DateInterval based on the enum type and amount
     */
    public function toDateInterval(int $amount = 1, ?string $unit = null): DateInterval 
    {
        return match($this) {
            self::DAILY => new DateInterval("P{$amount}D"),
            self::WEEKLY => new DateInterval("P{$amount}D"), // Convert weeks to days
            self::MONTHLY => new DateInterval("P{$amount}M"),
            self::YEARLY => new DateInterval("P{$amount}Y"),
            self::CUSTOM => $this->createCustomInterval($amount, $unit),
        };
    }

    public static function fromDays(int $days): DateInterval 
    {
        return new DateInterval("P{$days}D");
    }

    /**
     * @see https://www.php.net/manual/en/dateinterval.construct.php
     */
    public function fromUnit(int $amount, ?string $unit = null): DateInterval 
    {
        // Accept only Y, M, D, W and return a constructed interval
        return match($this) {
            static::DAILY => new DateInterval("P{$amount}D"),
            static::WEEKLY  => new DateInterval("P{$amount}W"),
            static::MONTHLY  => new DateInterval("P{$amount}M"),
            static::YEARLY  => new DateInterval("P{$amount}Y"),
            static::CUSTOM  => match ($unit) {
                'second', 'seconds' => new DateInterval("PT{$amount}S"),
                'minute', 'minutes' => new DateInterval("PT{$amount}M"),
                'hour', 'hours' => new DateInterval("PT{$amount}H"),
                'day', 'days' => new DateInterval("P{$amount}D"),
                'week', 'weeks' => new DateInterval("P{$amount}W"),
                'month', 'months' => new DateInterval("P{$amount}M"),
                'year', 'years' => new DateInterval("P{$amount}Y"),
                default => throw new InvalidArgumentException("Invalid time unit: {$unit}"),
            },
        };
    }

    /**
     * Get a human-readable description of the interval
     */
    public function getDescription(int $amount = 1, ?string $unit = null): string
    {
        return match ($this) {
            self::DAILY => $amount === 1 ? 'Daily' : "Every {$amount} days",
            self::WEEKLY => $amount === 1 ? 'Weekly' : "Every {$amount} weeks",
            self::MONTHLY => $amount === 1 ? 'Monthly' : "Every {$amount} months",
            self::YEARLY => $amount === 1 ? 'Yearly' : "Every {$amount} years",
            self::CUSTOM => $unit ? "Every {$amount} " . ($amount === 1 ? rtrim($unit, 's') : $unit) : 'Custom interval',
        };
    }

    /**
     * Check if this is a standard interval (not custom)
     */
    public function isStandard(): bool
    {
        return $this !== self::CUSTOM;
    }

    /**
     * Get all valid units for custom intervals
     */
    public static function getValidCustomUnits(): array
    {
        return [
            'second',
            'seconds',
            'minute',
            'minutes',
            'hour', 
            'hours', 
            'day', 
            'days', 
            'week', 
            'weeks', 
            'month', 
            'months', 
            'year', 
            'years'
        ];
    }
}