<?php

namespace Livewirez\Billing\Exceptions;

class SubscriptionActivationException extends SubscriptionInitiationException
{
    public const string DEFAULT_MESSAGE = 'Subscription Activation Error';
}