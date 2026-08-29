<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function config(): Config
    {
        return new Config(BASE_PATH . '/config');
    }

    public function testReadsAValueByDotPath(): void
    {
        $this->assertIsString($this->config()->get('app.name'));
        $this->assertSame('mysql', $this->config()->get('database.driver'));
    }

    public function testReturnsTheDefaultForAMissingKey(): void
    {
        $this->assertSame('fallback', $this->config()->get('app.nothing_here', 'fallback'));
        $this->assertNull($this->config()->get('nosuchfile.key'));
    }

    public function testStoragePathsAreOutsideThePublicDirectory(): void
    {
        foreach (['library', 'quarantine', 'covers'] as $name) {
            $path = (string) $this->config()->get('storage.' . $name);

            $this->assertStringNotContainsString('/public/', $path, "storage.{$name} must not be web reachable.");
        }
    }

    public function testAnEnvOverrideSurvivesReloadingTheFile(): void
    {
        // The test suite sets STORAGE_ROOT once and then boots a kernel per
        // request, and every boot re-reads .env. If load() could undo the
        // override, uploads in tests would land in the developer's own storage
        // tree, which is exactly what used to happen.
        Env::set('STORAGE_ROOT', '/tmp/somewhere-else');
        Env::load(BASE_PATH . '/.env');

        $this->assertSame('/tmp/somewhere-else', Env::get('STORAGE_ROOT'));
        $this->assertSame('/tmp/somewhere-else', (new Config(BASE_PATH . '/config'))->get('storage.root'));
    }

    public function testSetOverridesAValue(): void
    {
        $config = $this->config();
        $config->set('app.name', 'Overridden');

        $this->assertSame('Overridden', $config->get('app.name'));
    }
}
