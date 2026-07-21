<?php

namespace App\Services\Hades;

class HadesEvidencePolicy
{
    public const MAX_EVIDENCE_PAYLOAD_BYTES = 64000;

    public const MAX_SOURCE_SLICE_BYTES = 64000;

    public const MAX_DIAGNOSIS_PAYLOAD_BYTES = 32000;

    public const MAX_EVIDENCE_PACK_PAYLOAD_BYTES = 96000;

    /**
     * @return array{code: string, message: string}|null
     */
    public function validateBugEvidence(string $summary, array $payload): ?array
    {
        if ($this->encodedBytes($payload) > self::MAX_EVIDENCE_PAYLOAD_BYTES) {
            return $this->error('evidence_payload_too_large', 'Bug evidence payload exceeds the Hades diagnosis safety limit.');
        }

        return $this->rejectUnredactedSecret([$summary, $payload]);
    }

    /**
     * @return array{code: string, message: string}|null
     */
    public function validateSourceSlice(string $path, string $content, int $redactions): ?array
    {
        if (strlen($content) > self::MAX_SOURCE_SLICE_BYTES) {
            return $this->error('source_slice_too_large', 'Source slice content exceeds the Hades diagnosis safety limit.');
        }

        return $this->rejectUnredactedSecret([$path, $content, $redactions]);
    }

    /**
     * @return array{code: string, message: string}|null
     */
    public function validateDiagnosisReport(string $rootCause, ?string $mechanism, array $evidenceRefs, array $freshness, array $payload): ?array
    {
        if ($this->encodedBytes($payload) > self::MAX_DIAGNOSIS_PAYLOAD_BYTES) {
            return $this->error('diagnosis_payload_too_large', 'Diagnosis report payload exceeds the Hades diagnosis safety limit.');
        }

        return $this->rejectUnredactedSecret([$rootCause, $mechanism, $evidenceRefs, $freshness, $payload]);
    }

    /**
     * @return array{code: string, message: string}|null
     */
    public function validateEvidencePack(string $title, string $summary, array $evidenceRefs, array $graphRefs, array $sourceSliceIds, array $payload): ?array
    {
        $material = [
            'title' => $title,
            'summary' => $summary,
            'evidence_refs' => $evidenceRefs,
            'graph_refs' => $graphRefs,
            'source_slice_ids' => $sourceSliceIds,
            'payload' => $payload,
        ];

        if ($this->encodedBytes($material) > self::MAX_EVIDENCE_PACK_PAYLOAD_BYTES) {
            return $this->error('evidence_pack_payload_too_large', 'Evidence pack payload exceeds the Hades diagnosis safety limit.');
        }

        return $this->rejectUnredactedSecret([$title, $summary, $evidenceRefs, $graphRefs, $sourceSliceIds, $payload]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{code: string, message: string}|null
     */
    public function validateProjectLogbook(
        string $summary,
        ?string $narrative,
        array $references,
        ?string $correlationId,
        array $payload,
    ): ?array {
        return $this->rejectUnredactedSecret([$summary, $narrative, $references, $correlationId, $payload]);
    }

    /**
     * @return array{summary: string, payload: array<string, mixed>, redactions: int}
     */
    public function redactBugEvidenceMaterial(string $summary, array $payload): array
    {
        $redactions = 0;

        return [
            'summary' => $this->redactText($summary, $redactions),
            'payload' => $this->redactValue($payload, $redactions),
            'redactions' => $redactions,
        ];
    }

    /**
     * @return array{text: string, redactions: int}
     */
    public function redactTextMaterial(string $text): array
    {
        $redactions = 0;

        return [
            'text' => $this->redactText($text, $redactions),
            'redactions' => $redactions,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return array{code: string, message: string}|null
     */
    private function rejectUnredactedSecret(array $values): ?array
    {
        foreach ($values as $value) {
            if ($this->containsUnredactedSecret($this->stringify($value))) {
                return $this->error('unredacted_secret_detected', 'Payload appears to contain an unredacted token, credential, cookie, or secret.');
            }
        }

        return null;
    }

    private function encodedBytes(array $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function containsUnredactedSecret(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach ($this->secretPatterns() as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function secretPatterns(): array
    {
        return [
            '/\bBearer\s+(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{12,}/i',
            '/\b(?:api[_-]?key|access[_-]?token|auth[_-]?token|authorization|cookie|password|private[_-]?key|secret|token)["\']?\s*[:=]\s*["\']?(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{8,}/i',
            '/\b(?:sk|pk)-(?:live|test)-[A-Za-z0-9_\-]{8,}/i',
            '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/i',
        ];
    }

    private function redactText(string $text, int &$redactions): string
    {
        $patterns = [
            '/\bBearer\s+(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{12,}/i' => 'Bearer [redacted]',
            '/\b((?:api[_-]?key|access[_-]?token|auth[_-]?token|authorization|cookie|password|private[_-]?key|secret|token)["\']?\s*[:=]\s*)["\']?(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1[redacted]',
            '/\b(?:sk|pk)-(?:live|test)-[A-Za-z0-9_\-]{8,}/i' => '[redacted-token]',
            '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----.*?-----END (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/is' => '[redacted-private-key]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text, -1, $count) ?? $text;
            $redactions += $count;
        }

        return $text;
    }

    private function redactValue(mixed $value, int &$redactions): mixed
    {
        if (is_string($value)) {
            return $this->redactText($value, $redactions);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = $this->redactValue($item, $redactions);
        }

        return $redacted;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
