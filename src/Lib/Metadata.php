<?php

namespace Livewirez\Tollgate\Lib;

use ArrayAccess;
use ArrayIterator;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use RecursiveArrayIterator;
use Traversable;

#[\AllowDynamicProperties]
class Metadata implements ArrayAccess, JsonSerializable, Arrayable, IteratorAggregate
{
    public function __construct(
        public string $sessionId,
    ) {
    }

    public function getSessionId()
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function __call(string $name, array $arguments)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        if (str_starts_with($name, 'get')) {
            $property = lcfirst(substr($name, 3));
            if (property_exists($this, $property)) {
                return $this->$property;
            }
        }

        if (str_starts_with($name, 'set')) {
            $property = lcfirst(substr($name, 3));
            if (property_exists($this, $property)) {
                $this->$property = $arguments[0];
                return $this;
            }
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }

    public function offsetExists($offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet($offset): mixed
    {
        return $this->$offset;
    }

    public function offsetSet($offset, $value): void
    {
        $this->$offset = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->$offset);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function getIterator(): Traversable
    {
        return new RecursiveArrayIterator($this->toArray());
    }
}