<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Link implements \JsonSerializable  
{
    private string $href;
    private string $rel;
    private string $method;
    
    public function __construct(string $href, string $rel, string $method) {
        $this->href = $href;
        $this->rel = $rel;
        $this->method = $method;
    }
    
    public function getHref(): string {
        return $this->href;
    }
    
    public function getRel(): string {
        return $this->rel;
    }
    
    public function getMethod(): string {
        return $this->method;
    }

    public function jsonSerialize(): mixed {
        return [
            'href' => $this->getHref(),
            'rel' => $this->getRel(),
            'method' => $this->getMethod(),
        ];
    }
}