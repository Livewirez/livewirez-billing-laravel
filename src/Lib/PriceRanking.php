<?php 

namespace Livewirez\Billing\Lib;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class PriceRanking 
{
    public const int DEFAULT_RANK = 0;
    
    public function __construct(
        public int $ranking
    ) {}
}