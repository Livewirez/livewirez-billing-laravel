<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;


enum EntityType: string
{
    use ArchTechTrait;
    
    // 'payment', 'subscription'
    case PAYMENT = 'payment';
    case SUBSCRIPTION = 'subscription';
}