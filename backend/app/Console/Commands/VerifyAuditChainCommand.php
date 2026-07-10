<?php

namespace App\Console\Commands;

use App\Services\AuditChainVerifier;
use Illuminate\Console\Command;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify-chain';

    protected $description = 'Verify the serialized audit log hash chain';

    public function handle(AuditChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result->valid) {
            $this->info('Audit chain verified.');

            return self::SUCCESS;
        }

        foreach ($result->failures as $failure) {
            $this->error("Sequence {$failure->sequence}: {$failure->message}");
        }

        return self::FAILURE;
    }
}
