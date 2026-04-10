<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Traits\AsLaravelEvent;

class BenefitGrantUpdated
{
    use AsLaravelEvent;

    /**
     * Create a new event instance.
     */
    public function __construct(
        /**
         * The billable entity.
         */
        public Billable $billable,
        /**
         * The payload array.
         *
         * @var array<string, mixed>
         */
        public array $payload,
    ) {}
}
