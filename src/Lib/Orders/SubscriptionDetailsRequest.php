<?php 

namespace Livewirez\Billing\Lib\Orders;

use BadMethodCallException;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\ApiProductTypeKey;

#[\AllowDynamicProperties]
class SubscriptionDetailsRequest extends AbstractProviderOrderRequest
{

}