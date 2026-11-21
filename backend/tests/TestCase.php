<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Принудительно используем окружение testing
        $app[ \Illuminate\Contracts\Console\Kernel::class ];
        
        if (file_exists(__DIR__.'/../.env.testing')) {
            $app->loadEnvironmentFrom('.env.testing');
        }

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Явно переключаем соединение базы на тестовое
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'alchemy_of_dreams_test');

        return $app;
    }
}
