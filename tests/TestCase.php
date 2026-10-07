<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a *_testing database. With a cached
     * config (php artisan config:cache / optimize) phpunit.xml's
     * DB_DATABASE override is ignored, and RefreshDatabase would wipe the
     * real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! str_ends_with($database, '_testing') && $database !== ':memory:') {
            fwrite(STDERR, "\nRefusing to run tests against database \"{$database}\". "
                ."Run `php artisan optimize:clear` (a cached config ignores phpunit.xml).\n");

            exit(1);
        }

        return $app;
    }
}
