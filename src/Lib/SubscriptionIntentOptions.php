<?php

namespace Livewirez\Billing\Lib;

use DateTimeInterface;
use Livewirez\Billing\Interfaces\Billable;

class SubscriptionIntentOptions
{
    public function __construct(
        public Billable $user,
        public DateTimeInterface $start,
        public bool $recurring = true,
        public array $data = [],
        public ?string $customInterval = null
    ) {}
}