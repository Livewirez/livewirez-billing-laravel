<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum ApiProductTypeKey: string
{
    use ArchTechTrait;

    case ONE_TIME = 'one-time';

    case SUBSCRIPTION = 'subscription';

}