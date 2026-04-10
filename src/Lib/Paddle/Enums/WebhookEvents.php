<?php

namespace Livewirez\Billing\Lib\Paddle\Enums;

enum WebhookEvents: string
{
    // 🔹 Subscription Events
    case SUBSCRIPTION_ACTIVATED   = 'subscription.activated';
    case SUBSCRIPTION_CANCELED    = 'subscription.canceled';
    case SUBSCRIPTION_CREATED     = 'subscription.created';
    case SUBSCRIPTION_PAST_DUE    = 'subscription.past_due';
    case SUBSCRIPTION_PAUSED      = 'subscription.paused';
    case SUBSCRIPTION_RESUMED     = 'subscription.resumed';
    case SUBSCRIPTION_TRIALING    = 'subscription.trialing';
    case SUBSCRIPTION_UPDATED     = 'subscription.updated';
    case SUBSCRIPTION_IMPORTED    = 'subscription.imported';

    // Address Events
    case ADDRESS_CREATED          = 'address.created';
    case ADDRESS_UPDATED          = 'address.updated';
    case ADDRESS_IMPORTED         = 'address.imported';


    // 🔹 Transaction Events
    case TRANSACTION_BILLED        = 'transaction.billed';
    case TRANSACTION_CANCELED      = 'transaction.canceled';
    case TRANSACTION_COMPLETED     = 'transaction.completed';
    case TRANSACTION_CREATED       = 'transaction.created';
    case TRANSACTION_PAID          = 'transaction.paid';
    case TRANSACTION_PAST_DUE      = 'transaction.past_due';
    case TRANSACTION_PAYMENT_FAILED = 'transaction.payment_failed';
    case TRANSACTION_READY         = 'transaction.ready';
    case TRANSACTION_UPDATED       = 'transaction.updated';
    case TRANSACTION_REVISED       = 'transaction.revised';

    // 🔹 Payment Method Events
    case PAYMENT_METHOD_SAVED   = 'payment_method.saved';
    case PAYMENT_METHOD_DELETED = 'payment_method.deleted';
}
