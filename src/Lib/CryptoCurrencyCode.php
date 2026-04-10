<?php

namespace Livewirez\Billing\Lib;

use Livewirez\Billing\Traits\ArchTechTrait; 
use Livewirez\Billing\Interfaces\CurrencyType;
use Livewirez\Billing\Traits\CurrencyTypeTrait;

/**
 * @see https://www.bankrate.com/investing/types-of-cryptocurrency/
 */
enum CryptoCurrencyCode: string implements CurrencyType
{
    use ArchTechTrait, CurrencyTypeTrait; 

    case BTC  = 'BTC'; // Bitcoin
    case ETH  = 'ETH'; // Ethereum
    case USDT = 'USDT'; // Tether

    case BNB  = 'BNB'; // Binance Coin
    case XRP  = 'XRP'; // Ripple
    case LTC  = 'LTC'; // Litecoin
    case BCH  = 'BCH'; // Bitcoin Cash
    case DOT  = 'DOT'; // Polkadot
    case LINK = 'LINK'; // Chainlink
    case ADA  = 'ADA'; // Cardano
    case SOL  = 'SOL'; // Solana
    case DOGE = 'DOGE'; // Dogecoin

    case TRX  = 'TRX'; // Tron

    case HYPE = 'HYPE'; // Hyperliquid

}