<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum PaymentProvider: string
{
    use ArchTechTrait;

    case Card = 'card';

    case Cybersource = 'cybersource';

    case PayPal = 'paypal';

    case Stripe = 'stripe';

    case Mpesa = 'mpesa';

    case LemonSqueezy = 'lemon_squeezy';

    case Polar = 'polar';

    case Paystack = 'paystack';

    case Pesapal = 'pesapal';

    case Paddle = 'paddle';

    case Anystack = 'anystack';

    case GooglePay = 'google_pay';

    case ApplePay = 'apple_pay';

    /** @see https://cryptomus.com/gateway */
    case Cryptomus = 'cryptomus';

    /**
     *  @see https://vicecart.io
     *  @see https://youtu.be/AEJqOkikvT0?list=PL-F5G7qNBao78WJhVm1Wh-hdvnttJzw9m
     * 
     */
    case ViceCart = 'vice_cart';


    

    public function getValue(): string
    {
        return $this->value;
    } 
}