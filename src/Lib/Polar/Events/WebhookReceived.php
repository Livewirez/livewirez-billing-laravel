<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Traits\AsLaravelEvent;

class WebhookReceived
{
    use AsLaravelEvent;

    public function __construct(
        /**
         * The payload array.
         *
         * @var array<string, mixed>
         */
        public array $payload,
    ) {}
}
