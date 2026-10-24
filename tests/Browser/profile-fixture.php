<?php

// Dedicated accounts for the browser suite; this helper must never touch MySQL.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = realpath(base_path('tmp/browser.sqlite'));
$database = config('database.connections.sqlite.database');
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! $expected || realpath((string) $database) !== $expected) {
    throw new RuntimeException('Profile fixtures require the isolated tmp/browser.sqlite test database.');
}
$key = $argv[1] ?? '';
if (! in_array($key, ['profile-edit', 'profile-password', 'profile-logout', 'profile-responsive', 'participant-edit', 'participant-password', 'participant-logout', 'participant-responsive', 'participant-certificate'], true)) {
    throw new InvalidArgumentException('Unknown profile fixture.');
}
$email = $key.'@browser-qa.example.test';
$participant = str_starts_with($key, 'participant-');
$user = \App\Models\User::firstOrNew(['email' => $email]);
$oldPhoto = $user->avatar_path;
$user->forceFill([
    'name' => $participant ? 'QA Profile Participant' : 'QA Profile Administrator', 'email' => $email,
    'password' => 'ProfileStart123!', 'role' => $participant ? 'participant' : 'superadmin', 'active' => true,
    'text_size' => 'normal', 'avatar_path' => null, 'avatar_version' => null,
    'preferred_language' => $participant ? 'en' : null,
])->save();
if ($participant) $user->profile()->updateOrCreate([], ['nationality' => null, 'country' => null, 'native_language' => null, 'institution' => null, 'phone' => null, 'date_of_birth' => null, 'gender' => null]);
if ($oldPhoto) app(\App\Services\ProfilePhotoService::class)->delete($oldPhoto, $user->id);
$output = ['id' => $user->id, 'email' => $email, 'password' => 'ProfileStart123!', 'name' => $user->name];
if ($key === 'participant-certificate') {
    // The browser regression needs a genuine signed certificate. Assessment
    // completion/scoring itself is covered by ParticipantProfileIntegrityTest.
    $package = \App\Models\ExamPackage::where('code', 'INBAF-ACADEMIC')->firstOrFail();
    $attempt = $user->attempts()->where('exam_package_id', $package->id)->where('status', 'completed')->first();
    if (! $attempt) $attempt = \App\Models\ExamAttempt::create([
        'user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'certification',
        'status' => 'completed', 'started_at' => now()->subDay(), 'expires_at' => now()->subDay()->addHour(),
        'submitted_at' => now()->subDay()->addMinutes(30), 'score' => 91, 'level' => 'B2',
        'result' => ['total_score' => 91, 'normalized_score' => 91, 'score_max' => 100, 'manual_pending' => 0, 'passed' => true, 'level' => 'B2', 'competency_scores' => []],
        'snapshot' => [
            'participant_name' => $user->name, 'test_type' => 'certification',
            'package' => ['id' => $package->id, 'name' => $package->name, 'test_type' => 'certification'],
            'scoring' => ['scale_max' => 100], 'sections' => [],
            'certificate_policy' => ['enabled' => true, 'pass_score' => 60, 'expiry_months' => 24],
        ],
    ]);
    $certificate = app(\App\Services\CertificateService::class)->issue($attempt);
    if (! $certificate || ! app(\App\Services\CertificateService::class)->signatureIsValid($certificate)) {
        throw new RuntimeException('The isolated certificate fixture could not be issued correctly.');
    }
    $output['certificate'] = ['id' => $certificate->id, 'number' => $certificate->number, 'token' => $certificate->token, 'holder_name' => $certificate->payload['holder_name'], 'attempt_id' => $attempt->id];
}
echo json_encode($output, JSON_THROW_ON_ERROR);
