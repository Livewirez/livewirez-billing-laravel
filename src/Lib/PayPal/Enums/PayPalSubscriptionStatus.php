<?php 

namespace Livewirez\Billing\Lib\PayPal\Enums;

enum PayPalSubscriptionStatus: string 
{
    case SUSPENDED = 'SUSPENDED';	
    case ACTIVE = 'ACTIVE';		
    case APPROVED = 'APPROVED';		
    case CANCELLED = 'CANCELLED';	
    case APPROVAL_PENDING = 'APPROVAL_PENDING';
    case EXPIRED = 'EXPIRED';
}