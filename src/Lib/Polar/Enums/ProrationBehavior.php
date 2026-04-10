<?php

namespace Livewirez\Billing\Lib\Polar\Enums;

enum ProrationBehavior: string
{
    case Invoice = "invoice";
    case Prorate = "prorate";
}
