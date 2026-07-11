<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditChainVerifier
{
    public function __construct(private readonly AuditCanonicalizer $canonicalizer) {}

    public function verify(): object
    {
        $rows = DB::table('audit_logs')->orderBy('sequence')->orderBy('created_at')->orderBy('id')->get();
        $result = $this->verifyRows($rows);

        $head = DB::table('audit_chain_heads')->where('chain_key', 'global')->first();

        if ($head === null) {
            $result->failures[] = (object) [
                'sequence' => $result->lastSequence,
                'message' => 'Missing global audit chain head.',
            ];
        } elseif ((int) $head->last_sequence !== $result->lastSequence || $head->last_hash !== $result->lastHash) {
            $result->failures[] = (object) [
                'sequence' => $result->lastSequence,
                'message' => 'Global audit chain head does not match the final row.',
            ];
        }

        $result->valid = $result->failures === [];

        return $result;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $rows
     */
    public function verifyRows(iterable $rows): object
    {
        $failures = [];
        $previousHash = null;
        $expectedSequence = 1;
        $rows = Collection::make($rows)
            ->sort(function (array|object $left, array|object $right): int {
                $left = (array) $left;
                $right = (array) $right;

                return [
                    (int) ($left['sequence'] ?? 0),
                    (string) ($left['created_at'] ?? ''),
                    (string) ($left['id'] ?? ''),
                ] <=> [
                    (int) ($right['sequence'] ?? 0),
                    (string) ($right['created_at'] ?? ''),
                    (string) ($right['id'] ?? ''),
                ];
            })
            ->values();

        foreach ($rows as $row) {
            $rowArray = (array) $row;
            $sequence = ($rowArray['sequence'] ?? null) === null ? null : (int) $rowArray['sequence'];

            if ($sequence !== $expectedSequence) {
                $failures[] = (object) [
                    'sequence' => $expectedSequence,
                    'message' => "Expected audit sequence {$expectedSequence}, found ".($sequence ?? 'null').'.',
                ];
                break;
            }

            if (($rowArray['prev_hash'] ?? null) !== $previousHash) {
                $failures[] = (object) [
                    'sequence' => $sequence,
                    'message' => 'Previous hash does not match the prior row hash.',
                ];
                break;
            }

            $hash = $this->canonicalizer->hash($row);

            if (($rowArray['row_hash'] ?? null) !== $hash) {
                $failures[] = (object) [
                    'sequence' => $sequence,
                    'message' => 'Row hash does not match the canonical audit row.',
                ];
                break;
            }

            $previousHash = $hash;
            $expectedSequence++;
        }
        $lastSequence = $expectedSequence - 1;

        return (object) [
            'valid' => $failures === [],
            'failures' => $failures,
            'lastSequence' => $lastSequence,
            'lastHash' => $previousHash,
        ];
    }
}
