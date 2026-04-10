<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait; 

enum ActionType: string
{
    use ArchTechTrait;

    // 'create', 'refund', 'upgrade', 'downgrade', 'cancel'

    case CREATE = 'create';

    case REFUND = 'refund';

    case MODIFY = 'modify';

    case UPGRADE = 'upgrade';

    case DOWNGRADE = 'downgrade';

    case CANCEL = 'cancel';
}