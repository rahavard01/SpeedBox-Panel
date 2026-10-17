<?php

namespace App\Console\Commands;

use App\Services\UserBotCryptoOrderVerifier;
use Illuminate\Console\Command;

class VerifyUserBotCryptoOrders extends Command
{
    protected $signature = 'userbot:verify-crypto-orders';
    protected $description = 'Verify pending user bot crypto orders';

    public function handle(UserBotCryptoOrderVerifier $verifier): int
    {
        $verifier->run();

        $this->info('User bot crypto orders checked.');

        return self::SUCCESS;
    }
}