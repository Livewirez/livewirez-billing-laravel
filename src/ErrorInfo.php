<?php 

namespace Livewirez\Billing;

use Throwable;

class ErrorInfo 
{
    public function __construct(
        public string $title,
        public int $code, 
        public string $message, 
        public array $context = [], 
        public ?Throwable $error = null
    ) {
    }
}