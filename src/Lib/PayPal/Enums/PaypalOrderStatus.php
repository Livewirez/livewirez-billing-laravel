<?php 

namespace Livewirez\Billing\Lib\PayPal\Enums;

enum PayPalOrderStatus: string 
{
    case CREATED = 'CREATED';	
    case SAVED = 'SAVED';		
    case APPROVED = 'APPROVED';		
    case VOIDED = 'VOIDED';	
    case COMPLETED = 'COMPLETED';		
    case APPROVAL_PENDING = 'APPROVAL_PENDING';
    case PAYER_ACTION_REQUIRED = 'PAYER_ACTION_REQUIRED';	
}