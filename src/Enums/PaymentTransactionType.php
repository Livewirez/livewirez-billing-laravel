<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum PaymentTransactionType: string
{
    use ArchTechTrait;

    case PAYMENT = 'PAYMENT';

    case SUBSCRIPTION = 'SUBSCRIPTION';
}