<?php 



namespace Livewirez\Billing\Lib\Card\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;
    

enum CardNetworks: string 
{
    use ArchTechTrait;

    case Visa = "VISA";
    case Mastercard = "MASTERCARD";
    case Amex = "AMEX";
    case Cartesbancaires = "CARTESBANCAIRES";
    case Carnet = "CARNET";
    case Cup = "CUP";
    case Dinersclub = "DINERSCLUB";
    case Discover = "DISCOVER";
    case Eftpos = "EFTPOS";
    case Elo = "ELO";
    case Jcb = "JCB";
    case Jcrew = "JCREW";
    case Mada = "MADA";
    case Maestro = "MAESTRO";
    case Meeza = "MEEZA";
    case Paypak = "PAYPAK";

}