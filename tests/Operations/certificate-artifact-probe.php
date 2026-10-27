<?php

// Real PDF output for independent renderer/QR-decoder inspection. Never uses the local database.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
    fwrite(STDERR, "This probe requires APP_ENV=testing and SQLite :memory:.\n");
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || ! app()->environment('testing')) {
    throw new RuntimeException('The certificate probe refuses a non-memory database.');
}
config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32)), 'app.url' => 'https://inbaf.example']);
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$user = \App\Models\User::factory()->create(['name' => 'INBAF QA Certificate Holder', 'role' => 'participant', 'active' => true]);
$service = app(\App\Services\CertificateService::class);
$directory = base_path('tmp/qa/certificate-artifacts');
if (! is_dir($directory)) mkdir($directory, 0777, true);
$artifacts = [];
foreach (['modern', 'formal'] as $template) foreach (['a4', 'letter'] as $paper) foreach (['landscape', 'portrait'] as $orientation) {
    $name = implode('-', [$template, $paper, $orientation]);
    $package = \App\Models\ExamPackage::create(['code' => 'ARTIFACT-'.strtoupper($name), 'name' => 'QA Reference Assessment']);
    $competencies = [];
    foreach (['Grammar', 'Vocabulary', 'Reading', 'Listening', 'Writing', 'Speaking', 'Communication'] as $index => $label) {
        $competencies[] = ['competency_id' => $index + 1, 'name' => $label, 'score' => 92.55];
    }
    $attempt = \App\Models\ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'status' => 'completed', 'test_type' => 'certification', 'started_at' => now()->subHour(), 'expires_at' => now(), 'submitted_at' => now(), 'score' => 92.55, 'level' => 'C2', 'result' => ['total_score' => 92.55, 'normalized_score' => 92.55, 'score_max' => 100, 'passed' => true, 'manual_pending' => 0, 'competency_scores' => $competencies], 'snapshot' => ['test_type' => 'certification', 'participant_name' => $user->name, 'package' => ['name' => $package->name], 'scoring' => ['scale_max' => 100], 'certificate_policy' => ['enabled' => true, 'pass_score' => 60, 'expiry_months' => 24, 'template' => $template, 'paper' => $paper, 'orientation' => $orientation]]]);
    $certificate = $service->issue($attempt);
    if (! $certificate || ! $service->signatureIsValid($certificate)) throw new RuntimeException('The synthetic certificate could not be signed.');
    $request = \Illuminate\Http\Request::create('/api/certificates/'.$certificate->id.'/pdf');
    $request->setUserResolver(fn () => $user);
    $response = app(\App\Http\Controllers\CertificateController::class)->pdf($request, $certificate, $service);
    $bytes = $response->getContent();
    if (! str_starts_with($bytes, '%PDF-') || ! str_contains($bytes, '%%EOF')) throw new RuntimeException('Invalid PDF framing.');
    file_put_contents($directory.'/'.$name.'.pdf', $bytes);
    $artifacts[] = ['name' => $name, 'bytes' => strlen($bytes), 'verification_url_sha256' => hash('sha256', 'https://inbaf.example/verify/'.$certificate->token), 'official_score' => 92.55, 'signature_valid' => true];
}
file_put_contents($directory.'/manifest.json', json_encode(['synthetic_data' => true, 'database' => 'sqlite::memory:', 'artifacts' => $artifacts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['artifacts' => count($artifacts), 'output' => 'tmp/qa/certificate-artifacts', 'database' => 'sqlite::memory:'], JSON_THROW_ON_ERROR)."\n";
