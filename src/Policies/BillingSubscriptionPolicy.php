<?php 

namespace Livewirez\Billing\Policies;

use Illuminate\Auth\Access\Response;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingSubscription;

class BillingSubscriptionPolicy
{
 /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Billable $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Billable $user, BillingSubscription $billingSubscription): bool
    {
        return $billingSubscription->billable()->is($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Billable $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Billable $user, BillingSubscription $billingSubscription): bool
    {
        return $billingSubscription->billable()->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(Billable $user, BillingSubscription $billingSubscription): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Billable $user, BillingSubscription $billingSubscription): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Billable $user, BillingSubscription $billingSubscription): bool
    {
        return false;
    }
}