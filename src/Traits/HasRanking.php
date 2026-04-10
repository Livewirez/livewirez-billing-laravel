<?php

namespace Livewirez\Billing\Traits;

use ReflectionClassConstant;
use Livewirez\Billing\Lib\PriceRanking;

trait HasRanking 
{
    public function ranking(): int
    {
        $attributes = (new ReflectionClassConstant(
            class: self::class,
            constant: $this->name
        ))->getAttributes(
            name: PriceRanking::class
        );

        return $attributes === [] ? 0 : (
            $attributes[0]->newInstance()->ranking ?? $attributes[0]->newInstance()::DEFAULT_RANK
        );
    }
}