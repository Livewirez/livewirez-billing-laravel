<?php

namespace Livewirez\Billing\Lib\PayPal\Enums;

enum ErrorMessageMode: int
{
    case ERROR_INFO_TITLE = 1;

    case ERROR_INFO_MESSAGE = 2;

    case RESULT_MESSAGE = 3;
}


