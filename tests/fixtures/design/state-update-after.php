<?php

declare(strict_types=1);

namespace StateUpdateAfter;

final class Wallet
{
    private int $balance = 100;

    public function balance(): int
    {
        return $this->balance;
    }

    public function debit(int $amount): void
    {
        $this->balance -= $amount;
    }
}

function checkout(Wallet $wallet, int $amount): void
{
    $wallet->debit($amount);
}

function renewal(Wallet $wallet, int $amount): void
{
    $wallet->debit($amount);
}

function withdrawal(Wallet $wallet, int $amount): void
{
    $wallet->debit($amount);
}
