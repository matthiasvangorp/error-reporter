<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Tests;

use MatthiasVanGorp\ErrorReporter\ErrorReporterServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ErrorReporterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('error-reporter.enabled', true);
        $app['config']->set('error-reporter.endpoint', 'https://errors.test');
        $app['config']->set('error-reporter.token', 'testtoken');
        $app['config']->set('error-reporter.secret', 'testsecret');
        $app['config']->set('error-reporter.environment', 'testing');
        $app['config']->set('error-reporter.release', null);
        $app['config']->set('queue.default', 'sync');
    }
}
