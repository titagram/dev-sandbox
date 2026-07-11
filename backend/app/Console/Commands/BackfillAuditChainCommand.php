<?php

namespace App\Console\Commands;

use App\Services\AuditCanonicalizer;
use App\Services\AuditChainVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAuditChainCommand extends Command
{
    protected $signature = 'audit:chain-backfill {--dry-run : Report legacy rows without mutating audit chain metadata} {--force : Backfill outside maintenance mode}';

    protected $description = 'Backfill serialized audit chain metadata for existing audit rows';

    public function handle(AuditCanonicalizer $canonicalizer, AuditChainVerifier $verifier): int
    {
        if (! $this->option('dry-run') && ! $this->option('force') && ! app()->isDownForMaintenance()) {
            $this->error('Audit chain backfill requires maintenance mode or --force.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = DB::table('audit_logs')
                ->whereNull('sequence')
                ->orWhereNull('chain_version')
                ->orWhereNull('row_hash')
                ->count();

            $this->info("Would backfill {$count} audit row(s).");

            return self::SUCCESS;
        }

        $count = DB::transaction(function () use ($canonicalizer): int {
            $head = DB::table('audit_chain_heads')
                ->where('chain_key', 'global')
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                DB::table('audit_chain_heads')->insert([
                    'chain_key' => 'global',
                    'last_sequence' => 0,
                    'last_hash' => null,
                    'updated_at' => now(),
                ]);

                DB::table('audit_chain_heads')->where('chain_key', 'global')->lockForUpdate()->first();
            }

            $sequence = 0;
            $previousHash = null;
            $rows = DB::table('audit_logs')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

            foreach ($rows as $row) {
                $sequence++;
                $updates = [
                    'sequence' => $sequence,
                    'chain_version' => 1,
                    'actor_user_ref' => $row->actor_user_id === null ? null : 'user:'.$row->actor_user_id,
                    'actor_device_ref' => $row->actor_device_id === null ? null : 'device:'.$row->actor_device_id,
                    'prev_hash' => $previousHash,
                ];

                $hash = $canonicalizer->hash(array_merge((array) $row, $updates));
                $updates['row_hash'] = $hash;

                DB::table('audit_logs')->where('id', $row->id)->update($updates);
                $previousHash = $hash;
            }

            DB::table('audit_chain_heads')->where('chain_key', 'global')->update([
                'last_sequence' => $sequence,
                'last_hash' => $previousHash,
                'updated_at' => now(),
            ]);

            return $sequence;
        });

        $this->info("Backfilled {$count} audit row(s).");

        $result = $verifier->verify();
        if (! $result->valid) {
            foreach ($result->failures as $failure) {
                $this->error("Sequence {$failure->sequence}: {$failure->message}");
            }

            return self::FAILURE;
        }

        $this->info('Audit chain verified.');

        return self::SUCCESS;
    }
}
