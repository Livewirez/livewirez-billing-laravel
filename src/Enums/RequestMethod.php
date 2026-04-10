<?php 

namespace Livewirez\Billing\Enums;

use InvalidArgumentException;
use Livewirez\Billing\Traits\ArchTechTrait;

enum RequestMethod: string
{
    use ArchTechTrait;

    case Get     = 'GET';

    case Post    = 'POST';

    case Put     = 'PUT';

    case Patch   = 'PATCH';

    case Delete  = 'DELETE';

    case Head    = 'HEAD';

    case Options = 'OPTIONS';

    case Trace   = 'TRACE';

    case Query   = 'QUERY';   

    case Connect = 'CONNECT';   

    case Purge   = 'PURGE';    


    public function toMethod(): string
    {
        return strtolower($this->value);
    }

    public static function fromString(string $method): static 
    {
        return match (strtoupper($method)) {
            'GET' => self::Get,
            'PUT' => self::Put,
            'PATCH' => self::Patch,
            'POST' => self::Post,
            'DELETE' => self::Delete,
            'HEAD' => self::Head,
            'OPTIONS' => self::Options,
            'TRACE' => self::Trace,
            'QUERY' => self::Query,
            'CONNECT' => self::Connect,
            'PURGE' => self::Purge,
            default => throw new InvalidArgumentException('Unsupported Request Method')
        };
    }
}