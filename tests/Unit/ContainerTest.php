<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use App\Repositories\SettingsRepository;
use App\Services\Auth;
use App\Services\Gate;
use App\Services\ModerationService;
use Tests\TestCase;

/**
 * The services that are meant to be built once per request.
 *
 * Anything missing from the list in bootstrap.php still works, it is just
 * rebuilt on every resolution, which quietly throws away whatever it had
 * cached. That happened for several milestones without anything failing, which
 * is exactly why it is asserted here.
 */
final class ContainerTest extends TestCase
{
    private function container(): Container
    {
        return $this->kernel()->container();
    }

    public function testTheSignedInUserAndPermissionsAreResolvedOnce(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Auth::class), $container->get(Auth::class));
        $this->assertSame($container->get(Gate::class), $container->get(Gate::class));
    }

    public function testCachingRepositoriesAreResolvedOnce(): void
    {
        $container = $this->container();

        $this->assertSame(
            $container->get(SettingsRepository::class),
            $container->get(SettingsRepository::class),
            'SettingsRepository caches the settings table for the request.'
        );
    }

    public function testTheHeavyServicesAreResolvedOnce(): void
    {
        $container = $this->container();

        $this->assertSame(
            $container->get(ModerationService::class),
            $container->get(ModerationService::class)
        );
    }

    public function testEverythingInTheSharedListActuallyResolves(): void
    {
        $container = $this->container();
        $source = (string) file_get_contents(BASE_PATH . '/bootstrap.php');

        preg_match('/foreach \(\s*\[(.*?)\] as \$service/s', $source, $matches);
        preg_match_all('/([A-Za-z]+)::class/', $matches[1] ?? '', $names);

        $this->assertNotEmpty($names[1], 'The shared service list should not be empty.');

        foreach ($names[1] as $short) {
            $this->assertNotNull(
                $container->get($this->resolve($source, $short)),
                $short . ' is listed as shared but cannot be built.'
            );
        }
    }

    /** Turns a short class name back into the fully qualified one, from the imports. */
    private function resolve(string $source, string $short): string
    {
        preg_match('/^use ([^;]*\\\\' . preg_quote($short, '/') . ');$/m', $source, $found);

        $this->assertNotEmpty($found, 'No import for ' . $short);

        return $found[1];
    }
}
