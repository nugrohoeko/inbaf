<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    private function report(): array
    {
        $this->assertSame(1, Artisan::call('inbaf:readiness', ['--json' => true]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_preflight_detects_insecure_configuration_without_disclosing_values(): void
    {
        $secret = 'base64:'.base64_encode(str_repeat('Q', 32));
        config(['app.env' => 'local', 'app.debug' => true, 'app.key' => $secret, 'app.url' => 'http://localhost', 'session.secure' => false, 'mail.default' => 'log']);
        $report = $this->report();
        $checks = collect($report['checks'])->keyBy('id');
        foreach (['app.environment', 'app.debug', 'app.https_url', 'app.production_host', 'session.secure', 'mail.transport'] as $id) {
            $this->assertSame('FAIL', $checks[$id]['status'], $id);
        }
        $this->assertSame('PASS', $checks['app.key_format']['status']);
        $this->assertStringNotContainsString($secret, Artisan::output());
        $this->assertStringNotContainsString(str_repeat('Q', 32), Artisan::output());
        $this->assertSame($secret, config('app.key'));
        $this->assertSame('local', config('app.env'));
    }

    public function test_valid_config_does_not_claim_unverified_infrastructure_is_ready(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://assessment.institution.edu', 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.driver' => 'database', 'cache.default' => 'database', 'mail.default' => 'smtp', 'mail.from.address' => 'assessment@institution.edu']);
        $report = $this->report();
        $checks = collect($report['checks'])->keyBy('id');
        foreach (['app.environment', 'app.debug', 'app.https_url', 'app.production_host', 'session.secure', 'session.http_only', 'session.same_site', 'session.persistent', 'cache.persistent', 'mail.transport', 'mail.sender'] as $id) {
            $this->assertSame('PASS', $checks[$id]['status'], $id);
        }
        $this->assertSame('NOT TESTED', $checks['operations.mail_delivery']['status']);
        $this->assertSame('NOT TESTED', $checks['infrastructure.tls_and_document_root']['status']);
        $this->assertSame('NOT READY FOR DEPLOYMENT', $report['decision']);
    }

    public function test_preflight_rejects_malformed_keys_example_hosts_and_logging_failover_mailers(): void
    {
        config(['app.key' => 'base64:not-valid', 'app.url' => 'https://example.com', 'mail.default' => 'failover']);
        $checks = collect($this->report()['checks'])->keyBy('id');
        foreach (['app.key_format', 'app.production_host', 'mail.transport'] as $id) $this->assertSame('FAIL', $checks[$id]['status'], $id);
    }
}
