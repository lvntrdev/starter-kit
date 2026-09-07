<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use BackedEnum;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Throwable;

/**
 * Has the consumer's permission matrix fallen behind the package's?
 *
 * `config/permission-resources.php` sits in `UpdateCommand::NEVER_UPDATE_PATHS`
 * — deliberately, because it is the one file where the consumer declares their
 * OWN resources, and an updater that merged into it would eventually overwrite
 * a project's authorization model. The cost of that choice is silence in the
 * other direction: when the kit adds a resource or an ability (the FileManager
 * `files.create` / `files.update` / `files.delete` split is the case that
 * prompted this check), nothing tells an existing installation that its matrix
 * no longer covers what the shipped routes now require.
 *
 * The failure mode is not a security hole — `CheckResourcePermission` denies an
 * unmapped route in production — but a feature that quietly stops working, with
 * a 403 whose cause is a config file nobody was told to edit. This check is the
 * missing signal: it diffs the package's shipped matrix against the one the
 * application actually has loaded, and reports what the consumer is missing.
 *
 * Deliberately one-directional. A resource the consumer added and the package
 * never shipped is the normal case, not drift, and is never reported. Only
 * package-shipped entries absent from the application count.
 *
 * A WARN rather than a FAIL: nothing is broken until a route for the missing
 * ability is actually hit, and the fix (edit the config, re-seed) is a
 * deliberate act the operator has to schedule.
 */
class PermissionResourcesDriftCheck implements DoctorCheck
{
    /**
     * How many missing entries are named in the message before it truncates.
     *
     * A drift after a long-skipped upgrade can run to dozens of entries; a
     * doctor row that becomes a wall of text stops being read.
     */
    private const MAX_REPORTED = 8;

    /**
     * The abilities the PACKAGE ships, used to expand a `null` (= all
     * abilities) declaration on the package side of the comparison.
     *
     * Deliberately NOT read from `App\Enums\PermissionEnum`. That enum is
     * app-owned and extensible — the consumer is expected to add project
     * abilities to it — so reading it here would make the package's own
     * expectation depend on the consumer's edits, in both wrong directions: a
     * consumer who added `case Approve = 'approve';` and narrowed a
     * package-`null` resource to an explicit list would be told they are
     * missing `users.approve`, a permission the package never shipped; and a
     * consumer whose enum still predates a new package ability would have that
     * real drift hidden. The expected set has to come from the package.
     *
     * Kept honest by `PermissionResourcesDriftCheckTest`, which parses the
     * cases out of `stubs/app/Enums/PermissionEnum.php` and fails if this list
     * drifts from what the kit actually ships.
     *
     * @var list<string>
     */
    private const PACKAGE_ABILITIES = ['create', 'read', 'update', 'delete', 'import', 'export'];

    public function name(): string
    {
        return (string) __('sk-doctor.permission_resources_drift.name');
    }

    public function run(): DoctorReport
    {
        $expected = $this->packageMatrix();

        if ($expected === null) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.permission_resources_drift.package_matrix_unreadable'),
                (string) __('sk-doctor.permission_resources_drift.package_matrix_unreadable_hint')
            );
        }

        $actual = config('permission-resources');

        if (! is_array($actual) || $actual === []) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.permission_resources_drift.app_matrix_missing'),
                (string) __('sk-doctor.permission_resources_drift.app_matrix_missing_hint')
            );
        }

        $missing = array_merge(
            $this->missingResources($expected, $actual),
            $this->missingSubResources($expected, $actual),
            $this->missingCustomPermissions($expected, $actual),
        );

        if ($missing === []) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.permission_resources_drift.covered')
            );
        }

        $shown = array_slice($missing, 0, self::MAX_REPORTED);
        $suffix = count($missing) > self::MAX_REPORTED
            ? ' (+'.(count($missing) - self::MAX_REPORTED).' more)'
            : '';

        return DoctorReport::warn(
            $this->name(),
            (string) __('sk-doctor.permission_resources_drift.missing', [
                'count' => count($missing),
                'items' => implode(', ', $shown),
                'suffix' => $suffix,
            ]),
            (string) __('sk-doctor.permission_resources_drift.missing_hint')
        );
    }

    /**
     * The matrix as the package ships it, read straight from the stub.
     *
     * `require` (not `require_once`): doctor may run more than once in one
     * process — a queue worker, a test — and the second call must still get the
     * array back rather than `true`.
     *
     * @return array<string, mixed>|null
     */
    private function packageMatrix(): ?array
    {
        try {
            $path = StarterKitServiceProvider::stubsPath('config/permission-resources.php');

            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $data = require $path;

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<string>
     */
    private function missingResources(array $expected, array $actual): array
    {
        $expectedResources = is_array($expected['resources'] ?? null) ? $expected['resources'] : [];
        $actualResources = is_array($actual['resources'] ?? null) ? $actual['resources'] : [];

        $missing = [];

        foreach ($expectedResources as $resource => $abilities) {
            if (! array_key_exists($resource, $actualResources)) {
                $missing[] = (string) $resource.'.* (resource not declared)';

                continue;
            }

            foreach ($this->missingAbilities($abilities, $actualResources[$resource]) as $ability) {
                $missing[] = (string) $resource.'.'.$ability;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<string>
     */
    private function missingSubResources(array $expected, array $actual): array
    {
        $expectedSubs = is_array($expected['sub_resources'] ?? null) ? $expected['sub_resources'] : [];
        $actualSubs = is_array($actual['sub_resources'] ?? null) ? $actual['sub_resources'] : [];

        $missing = [];

        foreach ($expectedSubs as $parent => $types) {
            if (! is_array($types)) {
                continue;
            }

            $actualTypes = is_array($actualSubs[$parent] ?? null) ? $actualSubs[$parent] : [];

            foreach ($types as $type => $abilities) {
                if (! array_key_exists($type, $actualTypes)) {
                    $missing[] = (string) $parent.':'.(string) $type.'.* (sub-resource not declared)';

                    continue;
                }

                foreach ($this->missingAbilities($abilities, $actualTypes[$type]) as $ability) {
                    $missing[] = (string) $parent.':'.(string) $type.'.'.$ability;
                }
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return list<string>
     */
    private function missingCustomPermissions(array $expected, array $actual): array
    {
        $expectedCustom = is_array($expected['custom_permissions'] ?? null) ? $expected['custom_permissions'] : [];
        $actualCustom = is_array($actual['custom_permissions'] ?? null) ? $actual['custom_permissions'] : [];

        $have = array_map(fn (mixed $p): string => $this->normalizeAbility($p), $actualCustom);

        $missing = [];

        foreach ($expectedCustom as $permission) {
            $name = $this->normalizeAbility($permission);

            if ($name !== '' && ! in_array($name, $have, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Which abilities the package declares that the application does not.
     *
     * `null` means "all abilities" on either side, and the two nulls are NOT
     * symmetric: a `null` on the application side covers everything the package
     * could ask for, so it can never drift; a `null` on the package side has to
     * be expanded — from `PACKAGE_ABILITIES`, never from the consumer's own
     * enum — before it can be compared against an explicit list.
     *
     * @return list<string>
     */
    private function missingAbilities(mixed $expected, mixed $actual): array
    {
        if ($actual === null) {
            return [];
        }

        $want = $expected === null ? $this->allAbilities() : $this->normalizeAbilities($expected);
        $have = $this->normalizeAbilities($actual);

        return array_values(array_diff($want, $have));
    }

    /**
     * @return list<string>
     */
    private function normalizeAbilities(mixed $abilities): array
    {
        if (! is_array($abilities)) {
            return [];
        }

        $out = [];

        foreach ($abilities as $ability) {
            $name = $this->normalizeAbility($ability);

            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Both sides may write an ability as a plain string or as a PermissionEnum
     * case — the config's own docblock offers the enum — so the comparison is
     * done on the backing value, folded to lower case.
     */
    private function normalizeAbility(mixed $ability): string
    {
        if ($ability instanceof BackedEnum) {
            return mb_strtolower((string) $ability->value);
        }

        if (is_string($ability)) {
            return mb_strtolower($ability);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function allAbilities(): array
    {
        return self::PACKAGE_ABILITIES;
    }
}
