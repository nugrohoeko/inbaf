<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class CheckProductionReadiness extends Command
{
    protected $signature = 'inbaf:readiness {--json : Output machine-readable checks without configuration values}';
    protected $description = 'Inspect production configuration without changing configuration, data, or secrets.';

    public function handle(): int
    {
        $checks = [];
        $check = function (string $id, bool $passed, string $message) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'message' => $message];
        };
        $check('app.environment', config('app.env') === 'production', 'The application environment must be production.');
        $check('app.debug', config('app.debug') === false, 'Detailed debugging must be disabled.');
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check('app.key_format', is_string($decoded) && Encrypter::supported($decoded, config('app.cipher')), 'An application key of the configured cipher length must exist; preserve the original certificate-signing key.');
        $url = parse_url((string) config('app.url'));
        $host = strtolower($url['host'] ?? '');
        $check('app.https_url', ($url['scheme'] ?? '') === 'https' && $host !== '' && empty($url['user']) && empty($url['pass']), 'The base URL must use HTTPS without embedded credentials.');
        $check('app.production_host', $this->productionHost($host), 'Use the actual production hostname instead of a loopback or example hostname.');
        $check('session.secure', config('session.secure') === true, 'Session cookies must require HTTPS.');
        $check('session.http_only', config('session.http_only') === true, 'Session cookies must be inaccessible to scripts.');
        $check('session.same_site', in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookies must have a same-site policy.');
        $check('session.persistent', ! in_array(config('session.driver'), ['array', 'null', null], true), 'Sessions need a persistent driver shared as required by the deployment topology.');
        $check('cache.persistent', ! in_array(config('cache.default'), ['array', 'null', null], true), 'Rate limiting and scheduler locks need a persistent cache.');
        $check('mail.transport', $this->realMailer((string) config('mail.default')), 'Credential links require a delivery transport without log or array fallbacks.');
        $from = (string) config('mail.from.address');
        $check('mail.sender', filter_var($from, FILTER_VALIDATE_EMAIL) !== false && $this->productionHost(strtolower(substr(strrchr($from, '@') ?: '', 1))), 'Configure a real sender address; successful delivery must be verified separately.');
        $check('php.compatibility', PHP_VERSION_ID >= 80300, 'PHP 8.3 or later is required by the application.');
        // Baseline from the PHP security release of 21 October 2026. This is
        // deliberately not a substitute for a current runtime advisory scan.
        $minimum = ['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'][PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION] ?? null;
        $check('php.security_baseline_2026_10_21', $minimum !== null && version_compare(PHP_VERSION, $minimum, '>='), 'Verify a patched PHP runtime for CVE-2026-109618 and CVE-2026-109619, or obtain vendor backport evidence.');
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip'] as $extension) {
            $check('php.extension.'.$extension, extension_loaded($extension), 'Required PHP extension: '.$extension.'.');
        }
        $driver = config('database.default');
        $pdo = ['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'][$driver] ?? null;
        $check('database.driver_extension', $pdo !== null && extension_loaded($pdo), 'The selected database driver must be supported and installed.');
        foreach (['composer.lock', 'package-lock.json', 'vendor/autoload.php', 'public/build/manifest.json'] as $file) {
            $check('artifact.'.$file, is_file(base_path($file)), 'Required release artifact: '.$file.'.');
        }
        $check('assets.no_hot_marker', ! is_file(public_path('hot')), 'Production must serve compiled assets without a development-server marker.');
        foreach (['storage/app/private', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $directory) {
            $check('writable.'.$directory, is_dir(base_path($directory)) && is_writable(base_path($directory)), 'The runtime must be able to write '.$directory.'.');
        }
        $private = realpath((string) config('filesystems.disks.local.root'));
        $public = realpath(public_path());
        $normalize = fn (string $path) => strtolower(str_replace('\\', '/', rtrim($path, '/\\'))).'/';
        $check('storage.private_outside_public', $private !== false && $public !== false && ! str_starts_with($normalize($private), $normalize($public)), 'Private media and backups must remain outside the public document root.');
        foreach ([
            'infrastructure.web_runtime' => 'Verify the web-server PHP build, extensions, security patches, and upload limits; CLI settings alone do not establish this.',
            'infrastructure.tls_and_document_root' => 'Verify real TLS, proxy trust, public document root, and blocked access to environment and repository files.',
            'infrastructure.database_and_migrations' => 'Verify production database access, migration status, constraints, and concurrency on the target engine.',
            'operations.mail_delivery' => 'Deliver an authorized test message through the production transport and verify its destination and TLS.',
            'operations.worker_and_scheduler' => 'Verify scheduler heartbeat, queue worker supervision, retries, and failed-job monitoring where required.',
            'operations.backup_restore_and_rollback' => 'Verify encrypted offsite database/media backups, original secrets, recovery rehearsal, and rollback on target infrastructure.',
            'operations.capacity_and_monitoring' => 'Set production capacity targets, measure load on matching infrastructure, and verify monitoring and alerts.',
            'dependencies.current_advisories' => 'Complete current Composer, npm, and runtime security audits; release artifacts alone do not establish vulnerability status.',
        ] as $id => $message) {
            $checks[] = ['id' => $id, 'status' => 'NOT TESTED', 'message' => $message];
        }
        $report = ['decision' => 'NOT READY FOR DEPLOYMENT', 'scope' => 'Read-only configuration preflight; external checks require independent evidence.', 'counts' => array_count_values(array_column($checks, 'status')), 'checks' => $checks];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Status', 'Requirement'], array_map(fn ($row) => [$row['id'], $row['status'], $row['message']], $checks));
            $this->warn($report['decision'].' — complete failed and independently unverified requirements before release.');
        }

        return self::FAILURE;
    }

    private function productionHost(string $host): bool
    {
        $host = rtrim($host, '.');
        if ($host === '' || ! str_contains($host, '.') || preg_match('/(?:^|\.)example\.(?:com|org|net)$/', $host)
            || preg_match('/\.(?:localhost|test|invalid|example)$/', $host)) return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function realMailer(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) return false;
        $visited[] = $mailer;
        $settings = config('mail.mailers.'.$mailer, []);
        $transport = $settings['transport'] ?? null;
        if (! $transport || in_array($transport, ['log', 'array'], true)) return false;
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $settings['mailers'] ?? [];
            return count($children) > 0 && collect($children)->every(fn ($child) => $this->realMailer($child, $visited));
        }

        return true;
    }
}
