<?php

namespace App\Services\Hades;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HadesPluginCredentialIssuer
{
    /** @var list<string> */
    private const SCOPES = [
        'projects.read',
        'repositories.read',
        'policies.read',
        'runs.write',
        'wiki.write',
    ];

    /**
     * @param array{fingerprint_hash:string,name:string,platform_os:string,platform_arch:string,plugin_version:string} $device
     * @return array{project_id:string,token_id:string,token:string,device_id:string,device_secret:string,scopes:list<string>,expires_at:string}
     */
    public function issue(object $agent, array $device): array
    {
        $project = DB::table('projects')->where('id', $agent->project_id)->first();
        if (! $project) {
            throw new \RuntimeException('Hades project was not found while issuing Plugin credentials.');
        }

        $now = now();
        $deviceRow = DB::table('devices')
            ->where('user_id', $project->created_by_user_id)
            ->where('fingerprint_hash', $device['fingerprint_hash'])
            ->first();

        if ($deviceRow) {
            DB::table('devices')->where('id', $deviceRow->id)->update([
                'name' => $device['name'],
                'platform_os' => $device['platform_os'],
                'platform_arch' => $device['platform_arch'],
                'plugin_version' => $device['plugin_version'],
                'status' => 'active',
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
            $deviceId = (string) $deviceRow->id;
        } else {
            $deviceId = (string) Str::ulid();
            DB::table('devices')->insert([
                'id' => $deviceId,
                'user_id' => $project->created_by_user_id,
                'name' => $device['name'],
                'fingerprint_hash' => $device['fingerprint_hash'],
                'platform_os' => $device['platform_os'],
                'platform_arch' => $device['platform_arch'],
                'plugin_version' => $device['plugin_version'],
                'signing_secret_hash' => hash('sha256', Str::random(64)),
                'last_seen_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $id = (string) Str::ulid();
        $tokenSecret = Str::random(48);
        $deviceSecret = bin2hex(random_bytes(32));
        $prefix = 'devb_live_'.$id;
        $expiresAt = $now->copy()->addDays(90);

        DB::transaction(function () use ($agent, $now, $id, $tokenSecret, $deviceSecret, $prefix, $project, $deviceId, $expiresAt): void {
            DB::table('api_tokens')
                ->where('hades_agent_id', $agent->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            DB::table('api_tokens')->insert([
                'id' => $id,
                'token_prefix' => $prefix,
                'token_hash' => hash('sha256', $tokenSecret),
                'user_id' => $project->created_by_user_id,
                'device_id' => $deviceId,
                'project_id' => $agent->project_id,
                'hades_agent_id' => $agent->id,
                'device_signing_secret_hash' => hash('sha256', $deviceSecret),
                'name' => 'Hades Plugin token for '.$agent->external_agent_id,
                'scopes' => json_encode(self::SCOPES, JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'last_used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return [
            'project_id' => (string) $agent->project_id,
            'token_id' => $id,
            'token' => $prefix.'|'.$tokenSecret,
            'device_id' => $deviceId,
            'device_secret' => $deviceSecret,
            'scopes' => self::SCOPES,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }
}
