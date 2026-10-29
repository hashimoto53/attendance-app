<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(Vite::class, function () {
            return new class extends Vite
            {
                public function __invoke($entrypoints, $buildDirectory = null)
                {
                    return new HtmlString('');
                }
            };
        });
    }
}
