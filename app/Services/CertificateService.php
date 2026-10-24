<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ExamAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CertificateService
{
    /** Issue once, from the immutable attempt snapshot and final result. */
    public function issue(ExamAttempt $attempt): ?Certificate
    {
        return DB::transaction(function () use ($attempt) {
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $existing = Certificate::query()->where('exam_attempt_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            $snapshot = $locked->snapshot ?? [];
            if (($snapshot['test_type'] ?? $snapshot['package']['test_type'] ?? $locked->test_type ?? 'certification') !== 'certification') return null;
            $policy = $snapshot['certificate_policy'] ?? [];
            $result = $locked->result ?? [];
            $score = (float) ($result['total_score'] ?? $locked->score ?? 0);
            $normalizedScore = (float) ($result['normalized_score'] ?? $score);
            if ($locked->status !== 'completed' || ($result['manual_pending'] ?? false)
                || ! ($policy['enabled'] ?? false) || ! ($result['passed'] ?? false)
                || $normalizedScore < (float) ($policy['pass_score'] ?? 60)) {
                return null;
            }

            $issued = CarbonImmutable::now()->utc();
            $months = max(0, min(120, (int) ($policy['expiry_months'] ?? 24)));
            $expires = $months ? $issued->addMonthsNoOverflow($months) : null;
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $number = 'INBAF-'.$issued->format('Ym').'-'.strtoupper(bin2hex(random_bytes(6)));
            $template = in_array($policy['template'] ?? null, ['modern', 'formal'], true)
                ? $policy['template'] : 'modern';
            $color = preg_match('/^#[0-9a-f]{6}$/i', $policy['primary_color'] ?? '')
                ? $policy['primary_color'] : '#101F43';
            $images = [];
            foreach (['logo', 'background', 'signature'] as $image) {
                $asset = $this->imageAsset($policy[$image.'_path'] ?? null);
                $images[$image.'_path'] = $asset['path'] ?? null;
                $images[$image.'_hash'] = $asset['hash'] ?? null;
            }

            $payload = [
                'version' => 1,
                'attempt_id' => (string) $locked->id,
                'test_type' => 'certification',
                'user_id' => (int) $locked->user_id,
                'holder_name' => $snapshot['participant_name'] ?? $locked->user->name,
                'number' => $number,
                'verification_token' => $token,
                'exam_package' => $snapshot['package']['name'] ?? $snapshot['package_name'] ?? 'INBAF Assessment',
                'test_date' => $locked->started_at->toDateString(),
                'issued_at' => $issued->toIso8601String(),
                'expires_at' => $expires?->toIso8601String(),
                'total_score' => $score,
                'score_max' => (float) ($snapshot['scoring']['scale_max'] ?? $snapshot['score_max'] ?? 100),
                'competency_scores' => $result['competency_scores'] ?? [],
                'level' => $locked->level ?? $result['level'] ?? 'A1',
                'qualification' => 'Internal proficiency classification issued by the INBAF institution. No government recognition, external accreditation, or equivalence to CEFR, UKBI, or TOEFL is claimed.',
                'institution' => $policy['institution'] ?? 'INBAF Language Assessment',
                'signer_name' => $policy['signer_name'] ?? 'INBAF Assessment Director',
                'signer_title' => $policy['signer_title'] ?? 'Assessment Director',
                'template' => $template,
                'primary_color' => $color,
                ...$images,
                'paper' => in_array($policy['paper'] ?? null, ['a4', 'letter'], true) ? $policy['paper'] : 'a4',
                'orientation' => in_array($policy['orientation'] ?? null, ['landscape', 'portrait'], true) ? $policy['orientation'] : 'landscape',
            ];

            $certificate = Certificate::query()->create([
                'exam_attempt_id' => $locked->id,
                'user_id' => $locked->user_id,
                'number' => $number,
                'token' => $token,
                'payload' => $payload,
                'signature' => $this->sign($payload),
                'status' => 'valid',
                'expires_at' => $expires,
                'issued_at' => $issued,
                'template' => $template,
            ]);

            AuditLog::query()->create([
                'user_id' => $locked->user_id,
                'event' => 'certificate.issued',
                'subject_type' => Certificate::class,
                'subject_id' => (string) $certificate->id,
                'data' => ['number' => $number, 'attempt_id' => $locked->id],
                'ip' => request()?->ip(),
            ]);

            return $certificate;
        });
    }

    public function signatureIsValid(Certificate $certificate): bool
    {
        $payload = $certificate->payload ?? [];

        return isset($payload['number'], $payload['verification_token'], $payload['attempt_id'], $payload['user_id'])
            && hash_equals((string) $certificate->signature, $this->sign($payload))
            && hash_equals((string) $certificate->number, (string) $payload['number'])
            && hash_equals((string) $certificate->token, (string) $payload['verification_token'])
            && (string) $certificate->exam_attempt_id === (string) $payload['attempt_id']
            && (int) $certificate->user_id === (int) $payload['user_id'];
    }

    public function status(Certificate $certificate): string
    {
        if (! $this->signatureIsValid($certificate)) {
            return 'not_found';
        }
        if ($certificate->status === 'revoked') {
            return 'revoked';
        }
        $expiry = $certificate->payload['expires_at'] ?? null;

        return $expiry && CarbonImmutable::parse($expiry)->isPast() ? 'expired' : 'valid';
    }

    /** Inspect actual local image bytes; never read remote, absolute or escaped paths. */
    public function imageAsset(?string $path): ?array
    {
        if (! $path || str_contains($path, '..') || str_contains($path, ':')
            || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return null;
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));
        if (! $root || ! $resolved || ! is_file($resolved)
            || ! str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(rtrim(str_replace('\\', '/', $root), '/')).'/')
            || filesize($resolved) > 10 * 1024 * 1024) {
            return null;
        }
        $bytes = file_get_contents($resolved);
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] * $info[1] > 24_000_000) {
            return null;
        }

        return ['path' => $path, 'hash' => hash('sha256', $bytes), 'mime' => $info['mime'], 'bytes' => $bytes];
    }

    private function sign(array $payload): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }
        if ($key === '') {
            throw new RuntimeException('APP_KEY is required for certificate signatures.');
        }

        return hash_hmac('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $key);
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
