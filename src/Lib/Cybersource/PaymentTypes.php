<?php

namespace Livewirez\Billing\Lib\Cybersource;

class PaymentTypes 
{
    private const ALLOWED_PAYMENT_TYPES = [
        "PANENTRY",
        "GOOGLEPAY",
        "CLICKTOPAY",
        "PAZE",
        "CHECK",
        [
            "type" => "GOOGLEPAY",
            "options" => [
                "allowedAuthMethods" => "PAN_ONLY"
            ]
        ],
        [
            "type" => "CLICKTOPAY",
            "options" => [
                "autoCheckEnrollment" => true
            ]
        ],

    ];

}