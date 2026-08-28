<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
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

    public function testSetOverridesAValue(): void
    {
        $config = $this->config();
        $config->set('app.name', 'Overridden');

        $this->assertSame('Overridden', $config->get('app.name'));
    }
}
