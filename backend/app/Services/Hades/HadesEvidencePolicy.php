<?php

namespace App\Services\Hades;

class HadesEvidencePolicy
{
    public const MAX_EVIDENCE_PAYLOAD_BYTES = 64000;

    public const MAX_SOURCE_SLICE_BYTES = 64000;

    public const MAX_DIAGNOSIS_PAYLOAD_BYTES = 32000;

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

        $patterns = [
            '/\bBearer\s+(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{12,}/i',
            '/\b(?:api[_-]?key|access[_-]?token|auth[_-]?token|authorization|cookie|password|private[_-]?key|secret|token)\s*[:=]\s*[\"\']?(?!\*{3,}|redacted|\[redacted\])[A-Za-z0-9._~+\/=\-]{8,}/i',
            '/\b(?:sk|pk)-(?:live|test)-[A-Za-z0-9_\-]{8,}/i',
            '/-----BEGIN (?:RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, message: string}
     */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
