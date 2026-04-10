<?php

namespace Livewirez\Billing\Lib\Card\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;
    

enum CardType: string 
{
    use ArchTechTrait;

    case Credit  = 'credit';

    case Debit   = 'debit';

    case Prepaid = 'prepaid';
    
    case Store   = 'store';

    case Unknown = 'unknown';
}