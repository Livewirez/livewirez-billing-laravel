<?php

namespace Livewirez\Billing\Interfaces;

interface CartInterface extends CartItemContainerInterface
{
    public function getTotalItemCount(): int;
    
    /**
     * @return array<int, ProductInterface>
     */
    public function getProducts(): array;
}