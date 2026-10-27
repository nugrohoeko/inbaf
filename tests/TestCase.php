<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($expected = getenv('INBAF_QA_EXPECTED_DATABASE')) {
            $connection = $app['config']->get('database.connections.pgsql');
            if ($app->configurationIsCached() || ! $app->environment('testing')
                || ! preg_match('/^inbaf_qa_pg_[a-f0-9]{12}$/D', $expected)
                || $app['config']->get('database.default') !== 'pgsql'
                || $connection['database'] !== $expected || $connection['host'] !== '127.0.0.1'
                || ! empty($connection['url'])) {
                throw new \RuntimeException('Refusing an unisolated PostgreSQL test target.');
            }
            $private = getenv('INBAF_QA_PRIVATE_ROOT');
            if (! $private || ! is_dir($private)) {
                throw new \RuntimeException('Isolated private storage is required.');
            }
            $app['config']->set('filesystems.disks.local.root', $private);
            $app['config']->set('logging.default', 'stderr');
        }

        return $app;
    }
}
