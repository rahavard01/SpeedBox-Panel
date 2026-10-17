<?php

namespace App\Console\Commands;

use App\Services\CryptoTopupVerifier;
use Illuminate\Console\Command;

class VerifyCryptoTopups extends Command
{
    protected $signature = 'crypto:verify-topups';
    protected $description = 'Verify pending crypto wallet topups';

    public function handle(CryptoTopupVerifier $verifier): int
    {
        $verifier->run();

        $this->info('Crypto topups checked.');

        return self::SUCCESS;
    }
}