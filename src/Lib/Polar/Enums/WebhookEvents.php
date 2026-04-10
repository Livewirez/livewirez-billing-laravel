<?php

namespace Livewirez\Billing\Lib\Polar\Enums;

enum WebhookEvents: string
{
    // Checkout events
    case Checkout_Created = 'checkout.created';
    case Checkout_Updated = 'checkout.updated';

    // Customer events
    case Customer_Created = 'customer.created';
    case Customer_Updated = 'customer.updated';
    case Customer_Deleted = 'customer.deleted';
    case Customer_State_Changed = 'customer.state_changed';

    // Order events
    case Order_Created = 'order.created';
    case Order_Updated = 'order.updated';
    case Order_Paid = 'order.paid';
    case Order_Refunded = 'order.refunded';

    // Subscription events
    case Subscription_Created = 'subscription.created';
    case Subscription_Updated = 'subscription.updated';
    case Subscription_Active = 'subscription.active';
    case Subscription_Canceled = 'subscription.canceled';
    case Subscription_Uncanceled = 'subscription.uncanceled';
    case Subscription_Revoked = 'subscription.revoked';

    // Refund events
    case Refund_Created = 'refund.created';
    case Refund_Updated = 'refund.updated';

    // Product events
    case Product_Created = 'product.created';
    case Product_Updated = 'product.updated';

    // Benefit events
    case Benefit_Created = 'benefit.created';
    case Benefit_Updated = 'benefit.updated';

    // Benefit Grant events
    case Benefit_Grant_Created = 'benefit_grant.created';
    case Benefit_Grant_Cycled = 'benefit_grant.cycled';
    case Benefit_Grant_Updated = 'benefit_grant.updated';
    case Benefit_Grant_Revoked = 'benefit_grant.revoked';

    // Organization events
    case Organization_Updated = 'organization.updated';
}
