<?php

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class _01_RolePermissionSeeder extends Seeder
{
    /**
     * When true, existing roles are synced to match config exactly
     * (removing permissions not in config). Default is additive-only.
     */
    public bool $fresh = false;

    /**
     * Seed the default roles and permissions from config.
     *
     * Reads config/permission-resources.php to generate
     * resource-scoped permissions (e.g. users.create, roles.read).
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Track existing permissions before creating new ones
        $existingPermissions = Permission::pluck('name')->toArray();

        $allPermissions = $this->createPermissions();
        $subResourcePermissions = $this->createSubResourcePermissions();
        $customPermissions = $this->createCustomPermissions();
        $allPermissions = array_merge($allPermissions, $subResourcePermissions, $customPermissions);

        // Determine which permissions were just created
        $newPermissions = array_diff($allPermissions, $existingPermissions);

        // Remove orphaned permissions no longer in config
        $this->removeOrphanedPermissions($allPermissions);

        // Reset cached permissions so newly created ones are recognized
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->createRoles($allPermissions, array_values($newPermissions));
    }

    /**
     * Create permissions based on configured resources.
     *
     * @return string[]
     */
    private function createPermissions(): array
    {
        $resources = config('permission-resources.resources', []);
        $abilityDisplayNames = config('permission-resources.display_names.abilities', []);
        $resourceDisplayNames = config('permission-resources.display_names.resources', []);
        $allPermissions = [];

        foreach ($resources as $resource => $abilities) {
            $permissions = PermissionEnum::allFor($resource, $abilities);

            foreach ($permissions as $permissionName) {
                $permission = Permission::findOrCreate($permissionName, 'web');

                $parts = explode('.', $permissionName, 2);
                $resourceKey = $parts[0];
                $abilityKey = $parts[1] ?? $permissionName;

                $displayName = [];
                foreach ($abilityDisplayNames[$abilityKey] ?? [] as $locale => $abilityLabel) {
                    $resourceLabel = $resourceDisplayNames[$resourceKey][$locale] ?? ucfirst($resourceKey);
                    $displayName[$locale] = "{$resourceLabel} - {$abilityLabel}";
                }

                if ($displayName) {
                    $permission->update(['display_name' => $displayName]);
                }
            }

            $allPermissions = array_merge($allPermissions, $permissions);
        }

        return $allPermissions;
    }

    /**
     * Create sub-resource permissions (e.g. users:student.create).
     *
     * @return string[]
     */
    private function createSubResourcePermissions(): array
    {
        $subResources = config('permission-resources.sub_resources', []);
        $abilityDisplayNames = config('permission-resources.display_names.abilities', []);
        $resourceDisplayNames = config('permission-resources.display_names.resources', []);
        $allPermissions = [];

        foreach ($subResources as $parent => $types) {
            foreach ($types as $type => $abilities) {
                $scopedResource = "{$parent}:{$type}";
                $permissions = PermissionEnum::allFor($scopedResource, $abilities);

                foreach ($permissions as $permissionName) {
                    $permission = Permission::findOrCreate($permissionName, 'web');

                    $abilityKey = explode('.', $permissionName, 2)[1] ?? $permissionName;

                    $displayName = [];
                    foreach ($abilityDisplayNames[$abilityKey] ?? [] as $locale => $abilityLabel) {
                        $resourceLabel = $resourceDisplayNames[$scopedResource][$locale]
                            ?? ($resourceDisplayNames[$parent][$locale] ?? ucfirst($parent)).' → '.ucfirst($type);
                        $displayName[$locale] = "{$resourceLabel} - {$abilityLabel}";
                    }

                    if ($displayName) {
                        $permission->update(['display_name' => $displayName]);
                    }
                }

                $allPermissions = array_merge($allPermissions, $permissions);
            }
        }

        return $allPermissions;
    }

    /**
     * Create custom permissions not tied to resource controllers.
     *
     * @return string[]
     */
    private function createCustomPermissions(): array
    {
        $customPermissions = config('permission-resources.custom_permissions', []);
        $abilityDisplayNames = config('permission-resources.display_names.abilities', []);
        $resourceDisplayNames = config('permission-resources.display_names.resources', []);

        foreach ($customPermissions as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');

            $parts = explode('.', $permissionName, 2);
            $resourceKey = $parts[0];
            $abilityKey = $parts[1] ?? $permissionName;

            $displayName = [];
            foreach ($abilityDisplayNames[$abilityKey] ?? [] as $locale => $abilityLabel) {
                $resourceLabel = $resourceDisplayNames[$resourceKey][$locale] ?? ucfirst($resourceKey);
                $displayName[$locale] = "{$resourceLabel} - {$abilityLabel}";
            }

            if ($displayName) {
                $permission->update(['display_name' => $displayName]);
            }
        }

        return $customPermissions;
    }

    /**
     * Remove permissions from DB that are no longer defined in config.
     *
     * This handles resource renaming/removal safely:
     * - Detaches the orphan from all roles (role_has_permissions)
     * - Deletes the permission record
     *
     * @param  string[]  $configuredPermissions  All permissions currently in config
     */
    private function removeOrphanedPermissions(array $configuredPermissions): void
    {
        $orphans = Permission::where('guard_name', 'web')
            ->whereNotIn('name', $configuredPermissions)
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->roles()->detach();
            $orphan->delete();
        }

        if ($orphans->isNotEmpty()) {
            $this->command?->info('Removed '.count($orphans).' orphaned permission(s): '.$orphans->pluck('name')->join(', '));
        }
    }

    /**
     * Create roles and assign permissions based on config.
     *
     * - New roles: assign configured permissions (syncPermissions).
     * - Existing roles: add only newly configured default permissions, never remove existing ones.
     * - system_admin ('*'): always gets all permissions.
     *
     * @param  string[]  $allPermissions
     * @param  string[]  $newPermissionNames  Permissions created in this run
     */
    private function createRoles(array $allPermissions, array $newPermissionNames = []): void
    {
        $rolePermissions = config('permission-resources.role_permissions', []);
        $roleDisplayNames = config('permission-resources.display_names.roles', []);
        $roleGroups = config('permission-resources.role_groups', []);

        foreach (RoleEnum::cases() as $index => $roleEnum) {
            $isNew = ! Role::where('name', $roleEnum->value)->where('guard_name', 'web')->exists();
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $displayName = $roleDisplayNames[$roleEnum->value] ?? [];
            $group = $roleGroups[$roleEnum->value] ?? 'system';

            $updateData = [
                'group' => $group,
                'sort_order' => $index + 1,
            ];
            if ($displayName) {
                $updateData['display_name'] = $displayName;
            }
            $role->update($updateData);

            $configuredPermissions = $rolePermissions[$roleEnum->value] ?? [];

            $seededPermissions = $role->seeded_permissions ?? [];

            if ($isNew || $this->fresh) {
                // Brand new role or fresh mode: sync to match config exactly
                if ($configuredPermissions === '*') {
                    $role->syncPermissions($allPermissions);
                } elseif (is_array($configuredPermissions)) {
                    $role->syncPermissions($configuredPermissions);
                }
            } else {
                // Existing role: only backfill permissions newly added to config
                if ($configuredPermissions === '*') {
                    $newlyConfiguredPermissions = array_values(array_diff($allPermissions, $seededPermissions));

                    $role->givePermissionTo(
                        array_filter($newlyConfiguredPermissions, fn (string $p) => ! $role->hasPermissionTo($p))
                    );
                } elseif (is_array($configuredPermissions)) {
                    $newlyConfiguredPermissions = array_values(array_diff($configuredPermissions, $seededPermissions));
                    $toAdd = array_intersect($newlyConfiguredPermissions, $allPermissions);
                    $toAdd = array_filter($toAdd, fn (string $p) => ! $role->hasPermissionTo($p));
                    if ($toAdd) {
                        $role->givePermissionTo($toAdd);
                    }
                }
            }

            $role->forceFill([
                'seeded_permissions' => $configuredPermissions === '*' ? $allPermissions : $configuredPermissions,
            ])->save();
        }
    }
}
