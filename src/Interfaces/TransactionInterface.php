<?php

namespace Livewirez\Billing\Interfaces;

interface TransactionInterface
{
    public function getId(): int|string;

    public function getStatus(): string;

    public function getTransactionType(): string;
}