<?php

namespace App\Assistants;

final class SystemProviderHostResolver implements ProviderHostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        try {
            $aRecords = @dns_get_record($host, DNS_A);
            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($aRecords)) {
            $aRecords = [];
        }
        if (! is_array($aaaaRecords)) {
            $aaaaRecords = [];
        }

        foreach ($aRecords as $record) {
            if (isset($record['ip']) && is_string($record['ip']) && $record['ip'] !== '') {
                $addresses[] = $record['ip'];
            }
        }

        foreach ($aaaaRecords as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6']) && $record['ipv6'] !== '') {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}