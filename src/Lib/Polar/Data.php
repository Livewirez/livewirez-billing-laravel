<?php 

namespace Livewirez\Billing\Lib\Polar;

use ReflectionClass;
use JsonSerializable;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionIntersectionType;
use Illuminate\Support\Str;
use Livewirez\Billing\Lib\Polar\Traits\HasTransformedModel;

abstract class Data implements JsonSerializable
{
    use HasTransformedModel;
    
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(bool $useSnakeCase = true): array
    {
        $vars = get_object_vars($this);

        $arr = array_combine(
            array_map(fn(string $key): string => $useSnakeCase ? Str::snake($key) : $key, array_keys($vars)),
            array_values($vars)
        );

        array_walk_recursive($arr, function (mixed &$value): void {
            if ($value instanceof self) {
                $value = $value->toArray();  
            }

            if (is_array($value)) {
                array_walk_recursive($value, function (mixed &$subValue): void {
                    if ($subValue instanceof self) {
                        $subValue = $subValue->toArray();
                    }
                });
            }
        });

        return $arr;
    }

    public static function from(mixed ...$args): static
    {
        if (count($args) === 1 && is_array($args[0])) {
            return static::fromArray($args[0]);
        }

        $reflect = new ReflectionClass(static::class);
        return $reflect->newInstanceArgs($args);
    }

    public static function fromArray(array $data, bool $useSnakeCase = true): static
    {
        $reflect = new ReflectionClass(static::class);
        $constructor = $reflect->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        $args = [];

        foreach ($parameters as $param) {
            $name = $param->getName(); // e.g. 'customerName'
            $snake = $useSnakeCase ? Str::snake($name) : $name; // e.g. 'customer_name'

            if (array_key_exists($snake, $data)) {

                if ($param->getType() instanceof ReflectionUnionType) {

                    if ($param->allowsNull() && $data[$snake] === null) {
                        $args[] = null;
                        continue;
                    } else {
                    
                        foreach ($param->getType()->getTypes() as $type) {
                            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                                $class = $type->getName();  
                                if (is_subclass_of($class, self::class)) {
                                    $args[] = $class::fromArray($data[$snake] ?? []);
                                    continue 2; // Skip to the next parameter
                                }

                            }     
                        }  

                    }

                } elseif ($param->allowsNull() && $data[$snake] === null) {
                    $args[] = null;
                } elseif ($param->getType() instanceof ReflectionNamedType && !$param->getType()->isBuiltin() && is_subclass_of($param->getType()->getName(), self::class)) {

                    $class = $param->getType()->getName();

                    $args[] = $class::fromArray($data[$snake] ?? []);
                } else {
                    $args[] = $data[$snake];
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                $args[] = null; // or throw exception if required
            }
        }

        return $reflect->newInstanceArgs($args);
    }
}