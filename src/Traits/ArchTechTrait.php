<?php

namespace Livewirez\Billing\Traits;

use ArchTech\Enums\Names;
use ArchTech\Enums\Values;
use ArchTech\Enums\InvokableCases;

trait ArchTechTrait
{
    use Names, Values, InvokableCases;

    public static function sorted_values(string $direction = 'ASC'): array
    {
        $values = static::values();

        match (strtolower($direction)) {
            'asc' => sort($values),
            'desc' => rsort($values),
            default => null
        };

        return $values;
    }
}