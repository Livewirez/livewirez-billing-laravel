<?php

namespace Livewirez\Billing\Facades;

use Livewirez\Billing\BillingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Livewirez\Billing\BillingManager
 */
class Billing extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return BillingManager::class;
    }
}
