<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Base test case.
 *
 * Tests run against a DISPOSABLE PostgreSQL + PostGIS database (docker-compose.testing.yml
 * locally, a service container in CI), never against the shared operations DB (Aiven).
 * The schema comes from database/migrations-testing, which mirrors the real one.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as protected baseMigrateFreshUsing;
    }

    /**
     * Refuse to run if the connection does not point to the local testing database:
     * RefreshDatabase runs `migrate:fresh`, which DROPS EVERY TABLE.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $config = $app['config']->get("database.connections.{$connection}");

        $host = (string) ($config['host'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if (! empty($config['url'])
            || ! in_array($host, ['127.0.0.1', 'localhost'], true)
            || ! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to run the test suite against [{$host}/{$database}]: tests must use the local "
                .'testing database (see phpunit.xml and docker-compose.testing.yml).'
            );
        }

        return $app;
    }

    /**
     * Build the schema from the test-only migrations, not from database/migrations.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--path' => 'database/migrations-testing',
        ]);
    }
}
