<?php 

namespace Livewirez\Billing\Lib\Card;

use Illuminate\Http\Client\Response;
use Livewirez\Billing\Lib\Card\Drivers\CybersourceMicroform;

class CardManager 
{
    public static function createSession(string $gateway): Response 
    {
        switch($gateway) {
            case 'cybersource':
                $cm = new CybersourceMicroform();

                return $cm->initializeCaptureContext();
            default:
                throw new \Exception('Unsupported');
        }
    }

}