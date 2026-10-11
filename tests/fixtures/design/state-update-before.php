<?php

declare(strict_types=1);

namespace StateUpdateBefore;

final class Wallet
{
    private int $balance = 100;

    public function balance(): int
    {
        return $this->balance;
    }

    public function setBalance(int $value): void
    {
        $this->balance = $value;
    }
}

function checkout(Wallet $wallet, int $amount): void
{
    $wallet->setBalance($wallet->balance() - $amount);
}

function renewal(Wallet $wallet, int $amount): void
{
    $wallet->setBalance($wallet->balance() - $amount);
}

function withdrawal(Wallet $wallet, int $amount): void
{
    $wallet->setBalance($wallet->balance() - $amount);
}
