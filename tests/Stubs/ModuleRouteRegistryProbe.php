<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Tests\Stubs;

use Closure;
use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Test probe for the vendor module route registry.
 *
 * registerRoutes() runs once during ServiceProvider boot, so the production
 * registry (file-manager) is already wired by the time a test body executes.
 * This probe lets the routing tests drive the registry loop on demand against
 * a fresh Router with controlled inputs:
 *
 *   - swap the registry for a custom descriptor set (override-stub paths,
 *     dummy modules, middleware tiers), and
 *   - invoke the otherwise-private registerRoutes() loop.
 *
 * It changes nothing about the production mount logic — it only opens a seam
 * to exercise the same code path with deterministic inputs.
 */
final class ModuleRouteRegistryProbe extends StarterKitServiceProvider
{
    /**
     * Optional registry override. When null, the production registry runs.
     *
     * @var array<int, array{name: string, overrideStubs: array<int, string>, middleware: array<int, string>, loader: Closure(): void}>|null
     */
    private ?array $registryOverride = null;

    /**
     * @param  array<int, array{name: string, overrideStubs: array<int, string>, middleware: array<int, string>, loader: Closure(): void}>  $registry
     */
    public function withRegistry(array $registry): self
    {
        $this->registryOverride = $registry;

        return $this;
    }

    /**
     * Expose the production registry descriptor list for inspection.
     *
     * @return array<int, array{name: string, overrideStubs: array<int, string>, middleware: array<int, string>, loader: Closure(): void}>
     */
    public function registryDescriptors(): array
    {
        return $this->moduleRouteRegistry();
    }

    /**
     * Run the real registerRoutes() loop on demand.
     */
    public function runRegisterRoutes(): void
    {
        $reflection = new \ReflectionMethod(StarterKitServiceProvider::class, 'registerRoutes');
        $reflection->invoke($this);
    }

    /**
     * {@inheritDoc}
     */
    protected function moduleRouteRegistry(): array
    {
        if ($this->registryOverride !== null) {
            return $this->registryOverride;
        }

        return parent::moduleRouteRegistry();
    }
}
