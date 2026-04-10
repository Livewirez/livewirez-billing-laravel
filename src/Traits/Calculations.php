<?php 

namespace Livewirez\Billing\Traits;

use DateTimeInterface;
use Carbon\CarbonImmutable;


trait Calculations
{
    /**
     * Calculate proration for subscription changes.
     *
     * @param int $oldAmount The original subscription amount (in smallest currency unit).
     * @param int $newAmount The new subscription amount (in smallest currency unit).
     * @param DateTimeInterface $periodStart The start of the current billing period.
     * @param DateTimeInterface $periodEnd The end of the current billing period.
     * @return int The prorated amount (positive for upgrades, negative for downgrades) in smallest currency unit.
     */
    public function calculateProration(
        int $oldAmount,
        int $newAmount,
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd
    ): int {
        $now = new \DateTimeImmutable();

        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysRemaining = $now < $periodEnd ? $now->diff($periodEnd)->days : 0;

        if ($totalDays <= 0) {
            return 0; // Avoid division by zero or invalid periods
        }

        $remainingFraction = $daysRemaining / $totalDays;

        $proratedOld = $oldAmount * $remainingFraction;
        $proratedNew = $newAmount * $remainingFraction;

        return (int) round($proratedNew - $proratedOld); // Cast to int for smallest currency unit
    }

    /**
     * Calculate proration for subscription changes.
     *
     * @param float $oldAmount The original subscription amount.
     * @param float $newAmount The new subscription amount.
     * @param CarbonImmutable $periodStart The start of the current billing period.
     * @param CarbonImmutable $periodEnd The end of the current billing period.
     * @return float The prorated amount (positive for upgrades, negative for downgrades).
     */
    public function calculateProrationUsingCarbon(
        float $oldAmount, 
        float $newAmount, 
        CarbonImmutable $periodStart, 
        CarbonImmutable $periodEnd
    ): float
    {
        $totalDays = $periodStart->diffInDays($periodEnd);
        $daysRemaining = $periodStart->diffInDays(CarbonImmutable::now());

        if ($totalDays <= 0) {
            return 0.0; // Avoid division by zero
        }

        $usedDays = $totalDays - $daysRemaining;
        $usedFraction = $usedDays / $totalDays;
        $remainingFraction = $daysRemaining / $totalDays;

        // Calculate prorated amount: credit for unused portion of old plan, charge for new plan
        $proratedOld = $oldAmount * $remainingFraction;
        $proratedNew = $newAmount * $remainingFraction;

        return round($proratedNew - $proratedOld, 2); // Positive for upgrades, negative for downgrades
    }
}