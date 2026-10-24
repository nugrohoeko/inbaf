<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateVerification;
use App\Services\CertificateService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CertificateController extends Controller
{
    public function verify(Request $request, string $token, CertificateService $service): JsonResponse
    {
        $certificate = strlen($token) <= 128 ? Certificate::query()->where('token', $token)->first() : null;
        $status = $certificate ? $service->status($certificate) : 'not_found';
        CertificateVerification::query()->create([
            'certificate_id' => $certificate?->id,
            'token' => mb_substr($token, 0, 128),
            'ip' => $request->ip(),
            'valid' => $status === 'valid',
        ]);
        if ($status === 'not_found') {
            return response()->json(['status' => 'not_found', 'certificate' => null], 404);
        }
        $payload = $certificate->payload;

        return response()->json([
            'status' => $status,
            'certificate' => [
                'holder_name' => $payload['holder_name'],
                'number' => $payload['number'],
                'exam_package' => $payload['exam_package'],
                'total_score' => $payload['total_score'],
                'score_max' => $payload['score_max'],
                'level' => $payload['level'],
                'issued_at' => $payload['issued_at'],
                'expires_at' => $payload['expires_at'],
                'institution' => $payload['institution'],
                'qualification' => $payload['qualification'],
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function pdf(Request $request, Certificate $certificate, CertificateService $service): Response
    {
        abort_unless($request->user()->role === 'superadmin' || $certificate->user_id === $request->user()->id, 403);
        abort_unless($service->signatureIsValid($certificate), 409, 'Certificate integrity check failed.');
        $payload = $certificate->payload;
        $verificationUrl = rtrim(config('app.url'), '/').'/verify/'.$certificate->token;
        $qr = new QrCode(data: $verificationUrl, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 320, margin: 24);
        $qrDataUri = (new PngWriter)->write($qr)->getDataUri();
        $logoDataUri = $this->logoDataUri($payload['logo_path'] ?? null, $payload['logo_hash'] ?? null, $service);
        $backgroundDataUri = $this->imageDataUri($payload['background_path'] ?? null, $payload['background_hash'] ?? null, $service);
        $signatureDataUri = $this->imageDataUri($payload['signature_path'] ?? null, $payload['signature_hash'] ?? null, $service);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [public_path(), storage_path('app')]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('certificates.'.($payload['template'] === 'formal' ? 'formal' : 'modern'), [
            'certificate' => $certificate,
            'payload' => $payload,
            'qrDataUri' => $qrDataUri,
            'logoDataUri' => $logoDataUri,
            'backgroundDataUri' => $backgroundDataUri,
            'signatureDataUri' => $signatureDataUri,
            'verificationUrl' => $verificationUrl,
            'status' => $service->status($certificate),
        ])->render());
        $dompdf->setPaper($payload['paper'] ?? 'a4', $payload['orientation'] ?? 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$certificate->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function revoke(Request $request, Certificate $certificate): JsonResponse
    {
        abort_unless($request->user()->role === 'superadmin', 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        DB::transaction(function () use ($request, $certificate, $data) {
            $locked = Certificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($locked->status === 'revoked') {
                return;
            }
            $locked->update(['status' => 'revoked', 'revocation_reason' => $data['reason']]);
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'event' => 'certificate.revoked',
                'subject_type' => Certificate::class,
                'subject_id' => (string) $locked->id,
                'data' => ['reason' => $data['reason'], 'number' => $locked->number],
                'ip' => $request->ip(),
            ]);
        });

        return response()->json(['message' => 'Certificate revoked.', 'certificate' => $certificate->fresh()]);
    }

    private function imageDataUri(?string $path, ?string $expectedHash, CertificateService $service): ?string
    {
        if (! $path) {
            return null;
        }
        $asset = $service->imageAsset($path);
        abort_unless($asset && (! $expectedHash || hash_equals($expectedHash, $asset['hash'])), 409, 'Certificate image asset integrity check failed. Restore the original image from backup.');

        return 'data:'.$asset['mime'].';base64,'.base64_encode($asset['bytes']);
    }

    private function logoDataUri(?string $path, ?string $expectedHash, CertificateService $service): ?string
    {
        if ($path) {
            return $this->imageDataUri($path, $expectedHash, $service);
        }
        $printMark = resource_path('views/certificates/mark.svg');
        if (is_file($printMark)) {
            return 'data:image/svg+xml;base64,'.base64_encode(file_get_contents($printMark));
        }
        foreach (['brand/inbaf-mark-print.svg', 'brand/inbaf-mark.svg', 'brand/logomark.svg', 'logo.svg'] as $candidate) {
            if (is_file(public_path($candidate))) {
                return 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(public_path($candidate)));
            }
        }

        return null;
    }
}
