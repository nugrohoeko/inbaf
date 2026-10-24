<?php

// This CLI-only helper creates isolated QA users and broker tokens. Its stdout
// is consumed privately by execFileSync; it must never be served as an API.
if (PHP_SAPI !== 'cli') exit(1);
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite') {
    throw new RuntimeException('Credential fixtures require the isolated testing environment.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = realpath(base_path('tmp/browser.sqlite'));
$database = config('database.connections.sqlite.database');
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! $expected || realpath((string) $database) !== $expected) {
    throw new RuntimeException('Credential fixtures require the isolated tmp/browser.sqlite database.');
}
$key = $argv[1] ?? '';
if (! in_array($key, ['activation-participant', 'activation-superadmin', 'reset-participant', 'reset-superadmin'], true)) {
    throw new InvalidArgumentException('Unknown credential fixture.');
}
[$purpose, $role] = explode('-', $key, 2);
$pending = $purpose === 'activation';
$initialPassword = 'CredentialStart#2026';
$email = 'qa-credential-'.$key.'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'@browser-qa.example.test';
$user = \Illuminate\Support\Facades\DB::transaction(function () use ($pending, $role, $email, $initialPassword) {
    $user = new \App\Models\User;
    $user->forceFill([
        'name' => 'QA Credential '.ucfirst($role), 'email' => $email,
        'password' => $initialPassword, 'role' => $role, 'active' => ! $pending,
        'activation_pending' => $pending, 'must_change_password' => false,
        'text_size' => 'normal', 'preferred_language' => $role === 'participant' ? 'en' : null,
        'email_verified_at' => $pending ? null : now(),
    ])->save();
    if ($role === 'participant') $user->profile()->create();
    return $user;
});
// Only the hash is stored by Laravel's broker; no notification is sent or logged.
$token = \Illuminate\Support\Facades\Password::broker()->createToken($user);
echo json_encode(['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $role, 'password' => $initialPassword, 'token' => $token], JSON_THROW_ON_ERROR);
