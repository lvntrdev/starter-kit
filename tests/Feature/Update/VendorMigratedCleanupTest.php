<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\UpdateCommand;
use Lvntr\StarterKit\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// The group-level filesystem scenarios below boot the testbench app so base_path()
// and config() resolve. tests/Feature/Update is not bound to a TestCase in
// Pest.php (its other tests are pure reflection), so bind it at file scope here.
// Harmless for the reflection tests in this file — they simply gain a booted app.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Task 4 (vendor-first behavior modules, v13.6.0)
|--------------------------------------------------------------------------
|
| The Files/Logs/ActivityLogs/ApiRoutes/Settings HTTP + UI layers moved to
| vendor. sk:update migrates the old published consumer copies under a
| registry-hash guard, decided per MODULE LAYER GROUP (controller+requests as
| one PHP group, the page tree as one Vue group): if any file in a group is
| modified/untracked the WHOLE group is preserved (group atomicity). Vue groups
| are only removed when app.ts ships the '@lvntr/pages' vendor fallback.
|
| The first block exercises the decision rule + manifest in isolation (hash-in /
| bool-out), matching the suite's reflection-test style. The second block boots
| the command against the testbench base_path to prove the GROUP-LEVEL behavior
| (atomicity, the app.ts Vue guard) end-to-end on a real filesystem.
|
*/

/**
 * @return list<string>
 */
function vendorMigratedPaths(): array
{
    $reflection = new ReflectionClassConstant(UpdateCommand::class, 'VENDOR_MIGRATED_PATHS');

    /** @var list<string> */
    return $reflection->getValue();
}

/**
 * @return array<string, array{php: list<string>, vue: list<string>}>
 */
function vendorMigratedModules(): array
{
    $reflection = new ReflectionClassConstant(UpdateCommand::class, 'VENDOR_MIGRATED_MODULES');

    /** @var array<string, array{php: list<string>, vue: list<string>}> */
    return $reflection->getValue();
}

function vendorMigratedCopyIsRemovable(string $currentHash, ?string $recordedHash, bool $force): bool
{
    $method = new ReflectionMethod(UpdateCommand::class, 'vendorMigratedCopyIsRemovable');

    return $method->invoke(new UpdateCommand, $currentHash, $recordedHash, $force);
}

// ── Filesystem harness ──────────────────────────────────────────────────────

/**
 * Build a fully-wired UpdateCommand: $files initialized, input bound (so
 * option('force') resolves), output + components set (so the warn/line calls in
 * removeVendorMigratedPaths land in a buffer we can assert on). Returns the
 * command plus the output buffer.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: UpdateCommand, 1: BufferedOutput}
 */
function bootUpdateCommand(array $options = []): array
{
    $command = new UpdateCommand;

    $filesProperty = new ReflectionProperty($command, 'files');
    $filesProperty->setValue($command, new Filesystem);

    $buffer = new BufferedOutput;
    $style = new OutputStyle(new ArrayInput($options, $command->getDefinition()), $buffer);
    $command->setInput(new ArrayInput($options, $command->getDefinition()));
    $command->setOutput($style);

    $componentsProperty = new ReflectionProperty($command, 'components');
    $componentsProperty->setValue($command, new ComponentsFactory($style));

    return [$command, $buffer];
}

/**
 * Run the private removeVendorMigratedPaths() and return the command's resulting
 * $removed / $preservedDeprecated arrays plus the captured output text.
 *
 * @param  array<string, mixed>  $options
 * @return array{removed: list<string>, preserved: list<string>, output: string}
 */
function runVendorMigration(array $options = []): array
{
    [$command, $buffer] = bootUpdateCommand($options);

    $method = new ReflectionMethod($command, 'removeVendorMigratedPaths');
    $method->invoke($command, false);

    $read = function (string $prop) use ($command): array {
        $p = new ReflectionProperty($command, $prop);

        /** @var list<string> */
        return $p->getValue($command);
    };

    return [
        'removed' => $read('removed'),
        'preserved' => $read('preservedDeprecated'),
        'output' => $buffer->fetch(),
    ];
}

/**
 * Point the hash registry at a fresh temp file for the current test and seed it
 * with the given path→hash map (v2 format). Returns the registry file path.
 *
 * @param  array<string, string>  $records
 */
function seedHashRegistry(array $records): string
{
    $fs = new Filesystem;
    $hashFile = sys_get_temp_dir().'/sk_vmc_hashes_'.uniqid('', true).'.json';
    $fs->put($hashFile, json_encode(['_format' => 'v2'] + $records));

    config(['starter-kit.published_hashes' => $hashFile]);

    return $hashFile;
}

/**
 * Write a consumer file under the testbench base_path, creating parent dirs.
 */
function seedAppFile(string $relativePath, string $contents): void
{
    $fs = new Filesystem;
    $target = base_path($relativePath);
    $fs->ensureDirectoryExists(dirname($target));
    $fs->put($target, $contents);
}

/**
 * Write a resources/js/app.ts under the testbench base_path. When $withFallback
 * is true the content carries the '@lvntr/pages' vendor-fallback marker.
 */
function seedAppTs(bool $withFallback): void
{
    $marker = $withFallback
        ? "import.meta.glob('@lvntr/pages/**/*.vue', { eager: true });"
        : '// customized resolver with no vendor fallback';

    seedAppFile('resources/js/app.ts', "// app.ts\n{$marker}\n");
}

/**
 * Remove every path this suite may have seeded under base_path so no test leaks
 * fixtures into the real testbench tree. Safe to call unconditionally.
 */
function cleanupVendorMigrationFixtures(): void
{
    $fs = new Filesystem;

    foreach ([
        'app/Http/Controllers/Admin/LogController.php',
        'app/Http/Controllers/Admin/ActivityLogController.php',
        'app/Http/Controllers/Admin/ApiRouteController.php',
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Requests/Admin/Log',
        'app/Http/Requests/Admin/Settings',
        // Faz 2 php-layer fixtures.
        'app/Http/Controllers/Admin/ApiClientController.php',
        'app/Http/Controllers/Admin/ApiTokenController.php',
        'app/Http/Controllers/Admin/SystemHealthController.php',
        'app/Http/Controllers/Admin/ContentLanguageController.php',
        'app/Http/Controllers/Api/DefinitionController.php',
        'app/Http/Controllers/Api/MediaUploadController.php',
        'app/Http/Controllers/Service/DefinitionServiceController.php',
        'app/Http/Requests/Admin/ApiClient',
        'app/Http/Requests/Admin/ApiToken',
        'app/Http/Requests/Admin/ContentLanguage',
        'app/Http/Resources/Admin/ApiClient',
        'app/Http/Resources/Admin/ApiToken',
        'app/Http/Resources/Admin/ContentLanguage',
        'app/Domain/ContentLanguage',
        'resources/js/pages/Admin/Files',
        'resources/js/pages/Admin/Logs',
        'resources/js/pages/Admin/ActivityLogs',
        'resources/js/pages/Admin/ApiRoutes',
        'resources/js/pages/Admin/Settings',
        'resources/js/app.ts',
    ] as $relative) {
        $target = base_path($relative);

        if ($fs->isDirectory($target)) {
            $fs->deleteDirectory($target);
        } elseif ($fs->exists($target)) {
            $fs->delete($target);
        }
    }
}

afterEach(function (): void {
    cleanupVendorMigrationFixtures();
});

it('registers all five migrated behavior modules (Vue + controllers + requests)', function (): void {
    $paths = vendorMigratedPaths();

    // Vue page trees (Task 1).
    expect($paths)->toContain(
        'resources/js/pages/Admin/Files/',
        'resources/js/pages/Admin/Logs/',
        'resources/js/pages/Admin/ActivityLogs/',
        'resources/js/pages/Admin/ApiRoutes/',
        'resources/js/pages/Admin/Settings/',
    );

    // Controllers (Task 2).
    expect($paths)->toContain(
        'app/Http/Controllers/Admin/LogController.php',
        'app/Http/Controllers/Admin/ActivityLogController.php',
        'app/Http/Controllers/Admin/ApiRouteController.php',
        'app/Http/Controllers/Admin/SettingsController.php',
    );

    // FormRequest trees (Task 2).
    expect($paths)->toContain(
        'app/Http/Requests/Admin/Log/',
        'app/Http/Requests/Admin/Settings/',
    );
});

it('registers the Faz 2 php-layer modules (ApiClient/ApiToken/SystemHealth/Definitions/MediaUpload/ContentLanguage)', function (): void {
    $paths = vendorMigratedPaths();

    // Tier 1 + Tier 2 controllers.
    expect($paths)->toContain(
        'app/Http/Controllers/Admin/ApiClientController.php',
        'app/Http/Controllers/Admin/ApiTokenController.php',
        'app/Http/Controllers/Admin/SystemHealthController.php',
        'app/Http/Controllers/Admin/ContentLanguageController.php',
        'app/Http/Controllers/Api/DefinitionController.php',
        'app/Http/Controllers/Api/MediaUploadController.php',
        'app/Http/Controllers/Service/DefinitionServiceController.php',
    );

    // Faz 2 request + resource + domain trees.
    expect($paths)->toContain(
        'app/Http/Requests/Admin/ApiClient/',
        'app/Http/Requests/Admin/ApiToken/',
        'app/Http/Requests/Admin/ContentLanguage/',
        'app/Http/Resources/Admin/ApiClient/',
        'app/Http/Resources/Admin/ApiToken/',
        'app/Http/Resources/Admin/ContentLanguage/',
        'app/Domain/ContentLanguage/',
    );
});

it('NEVER migrates an App\\Models\\* path (models stay app-owned)', function (): void {
    $paths = vendorMigratedPaths();

    foreach ($paths as $path) {
        expect(str_starts_with($path, 'app/Models/'))->toBeFalse(
            "VENDOR_MIGRATED_PATHS must not contain any app/Models/ path; found: {$path}"
        );
    }

    // Spot-check the three models the Faz 2 vendor code references by FQCN.
    expect($paths)->not->toContain(
        'app/Models/ContentLanguage.php',
        'app/Models/Media.php',
        'app/Models/Definition.php',
    );
});

it('does NOT migrate genuinely out-of-scope scaffold controllers (User/Role/Dashboard stay app-owned)', function (): void {
    $paths = vendorMigratedPaths();

    expect($paths)->not->toContain(
        'app/Http/Controllers/Admin/UserController.php',
        'app/Http/Controllers/Admin/RoleController.php',
        'app/Http/Controllers/Admin/DashboardController.php',
        'app/Http/Controllers/Service/RoleServiceController.php',
    );
});

it('deletes a copy whose on-disk hash matches its registry record (unmodified → vendor takes over)', function (): void {
    $hash = md5('the byte-for-byte content we shipped');

    expect(vendorMigratedCopyIsRemovable($hash, $hash, force: false))->toBeTrue();
});

it('preserves a copy whose content differs from its registry record (user-customized → keeps winning)', function (): void {
    expect(vendorMigratedCopyIsRemovable(
        md5('user edited this controller'),
        md5('what the kit originally shipped'),
        force: false,
    ))->toBeFalse();
});

it('preserves an untracked copy with no registry record (cannot prove unmodified)', function (): void {
    expect(vendorMigratedCopyIsRemovable(md5('anything'), null, force: false))->toBeFalse();
});

it('removes any copy under --force regardless of modification', function (): void {
    expect(vendorMigratedCopyIsRemovable(md5('heavily customized'), md5('original'), force: true))->toBeTrue();
    expect(vendorMigratedCopyIsRemovable(md5('untracked'), null, force: true))->toBeTrue();
});

// ── Grouped manifest shape + drift guard ─────────────────────────────────────

it('groups every migrated path under a module split into php and vue layers', function (): void {
    $modules = vendorMigratedModules();

    expect($modules)->toHaveKeys([
        // Faz 1.
        'Files', 'Logs', 'ActivityLogs', 'ApiRoutes', 'Settings',
        // Faz 2.
        'ApiClient', 'ApiToken', 'SystemHealth', 'Definitions', 'MediaUpload', 'ContentLanguage',
    ]);

    foreach ($modules as $module => $layers) {
        expect($layers)->toHaveKeys(['php', 'vue'], "module {$module} must declare php + vue layers");
        expect($layers['php'])->toBeArray();
        expect($layers['vue'])->toBeArray();
    }

    // Settings keeps its controller AND request dir in ONE php group (atomicity
    // depends on them living together).
    expect($modules['Settings']['php'])->toContain(
        'app/Http/Controllers/Admin/SettingsController.php',
        'app/Http/Requests/Admin/Settings/',
    );

    // Files is Vue-only (its backend is the vendor FileManager runtime).
    expect($modules['Files']['php'])->toBe([]);
    expect($modules['Files']['vue'])->toContain('resources/js/pages/Admin/Files/');

    // Faz 2 groups carry an EMPTY vue layer — their Settings-tab Vue already shipped
    // vendor-first under the 'Settings' group's vue layer (no duplicate tree).
    foreach (['ApiClient', 'ApiToken', 'SystemHealth', 'Definitions', 'MediaUpload', 'ContentLanguage'] as $module) {
        expect($modules[$module]['vue'])->toBe([], "Faz 2 module {$module} must carry an empty vue layer.");
        expect($modules[$module]['php'])->not->toBe([], "Faz 2 module {$module} must declare a php layer.");
    }

    // ContentLanguage's php group bundles controller + request + resource + domain
    // (Tier 3 full vendorize) — they live together so atomicity pins them as one.
    expect($modules['ContentLanguage']['php'])->toContain(
        'app/Http/Controllers/Admin/ContentLanguageController.php',
        'app/Http/Requests/Admin/ContentLanguage/',
        'app/Http/Resources/Admin/ContentLanguage/',
        'app/Domain/ContentLanguage/',
    );

    // The ApiClient domain owns both Client + token flows: its php group bundles
    // the ApiClient AND ApiToken controller/request/resource sets are split across
    // two manifest groups (ApiClient / ApiToken) but each is fully self-contained.
    expect($modules['ApiClient']['php'])->toContain(
        'app/Http/Controllers/Admin/ApiClientController.php',
        'app/Http/Requests/Admin/ApiClient/',
        'app/Http/Resources/Admin/ApiClient/',
    );
});

it('keeps NO App\\Models\\* path in any module layer (models stay app-owned)', function (): void {
    foreach (vendorMigratedModules() as $module => $layers) {
        foreach (['php', 'vue'] as $layer) {
            foreach ($layers[$layer] as $path) {
                expect(str_starts_with($path, 'app/Models/'))->toBeFalse(
                    "module {$module} layer {$layer} must not migrate an app/Models/ path; found: {$path}"
                );
            }
        }
    }
});

it('keeps the flat VENDOR_MIGRATED_PATHS in sync with the grouped manifest (no drift)', function (): void {
    $flat = vendorMigratedPaths();

    $unionFromGroups = [];
    foreach (vendorMigratedModules() as $layers) {
        foreach (array_merge($layers['vue'], $layers['php']) as $entry) {
            $unionFromGroups[] = $entry;
        }
    }

    sort($flat);
    sort($unionFromGroups);

    expect($unionFromGroups)->toBe($flat);
});

// ── (a) PHP group atomicity — one edited request pins the unmodified controller

it('preserves an unmodified controller when a sibling request in the same module was modified', function (): void {
    // Settings PHP group: an UNMODIFIED controller alongside a MODIFIED request.
    $controllerBody = '<?php // original SettingsController shipped by the kit';
    $requestRel = 'app/Http/Requests/Admin/Settings/UpdateStorageSettingsRequest.php';
    $controllerRel = 'app/Http/Controllers/Admin/SettingsController.php';

    seedAppFile($controllerRel, $controllerBody);
    seedAppFile($requestRel, '<?php // CONSUMER HARDENED validation + authorize()');

    seedHashRegistry([
        // Controller recorded hash == on-disk hash → unmodified on its own.
        $controllerRel => md5($controllerBody),
        // Request recorded as something else → on-disk differs → user-modified.
        $requestRel => md5('<?php // what the kit originally shipped'),
    ]);

    $result = runVendorMigration();

    // Atomicity: BOTH files preserved — the unmodified controller must NOT be
    // deleted while its hardened request stays on disk (vendor would type-hint
    // the vendor request and never call the consumer's authorize/validation).
    expect($result['removed'])->not->toContain($controllerRel, $requestRel);
    expect($result['preserved'])->toContain($controllerRel, $requestRel);

    expect(file_exists(base_path($controllerRel)))->toBeTrue();
    expect(file_exists(base_path($requestRel)))->toBeTrue();
});

it('still removes a fully-unmodified PHP group (control case for atomicity)', function (): void {
    seedAppTs(withFallback: true); // unrelated; PHP layer ignores app.ts anyway

    $ctrlBody = '<?php // original ActivityLogController';
    $ctrlRel = 'app/Http/Controllers/Admin/ActivityLogController.php';
    seedAppFile($ctrlRel, $ctrlBody);

    seedHashRegistry([$ctrlRel => md5($ctrlBody)]);

    $result = runVendorMigration();

    expect($result['removed'])->toContain($ctrlRel);
    expect($result['preserved'])->not->toContain($ctrlRel);
    expect(file_exists(base_path($ctrlRel)))->toBeFalse();
});

// ── (b) app.ts guard — Vue preserved without marker, removed with it ──────────

it('preserves all Vue groups and warns when app.ts has no vendor page fallback', function (): void {
    seedAppTs(withFallback: false);

    // An UNMODIFIED Logs Vue page that WOULD be removable on hash grounds.
    $vueBody = '<template><div>Logs Index</div></template>';
    $vueRel = 'resources/js/pages/Admin/Logs/Index.vue';
    seedAppFile($vueRel, $vueBody);

    seedHashRegistry([$vueRel => md5($vueBody)]);

    $result = runVendorMigration();

    // Vue group preserved purely because app.ts lacks the fallback resolver.
    expect($result['removed'])->not->toContain($vueRel);
    expect($result['preserved'])->toContain($vueRel);
    expect(file_exists(base_path($vueRel)))->toBeTrue();

    // Actionable warning naming app.ts + the fallback.
    expect($result['output'])->toContain('app.ts');
    expect($result['output'])->toContain('vendor page fallback');
});

it('removes an unmodified Vue group when app.ts ships the vendor page fallback', function (): void {
    seedAppTs(withFallback: true);

    $vueBody = '<template><div>Logs Index</div></template>';
    $vueRel = 'resources/js/pages/Admin/Logs/Index.vue';
    seedAppFile($vueRel, $vueBody);

    seedHashRegistry([$vueRel => md5($vueBody)]);

    $result = runVendorMigration();

    expect($result['removed'])->toContain($vueRel);
    expect($result['preserved'])->not->toContain($vueRel);
    expect(file_exists(base_path($vueRel)))->toBeFalse();
});

// ── (c) Vue group atomicity — one edited component pins the whole tree ────────

it('preserves the entire module Vue tree when a single component was modified', function (): void {
    seedAppTs(withFallback: true); // fallback present, so only atomicity can block

    // Settings Vue tree: an UNMODIFIED Index.vue + a MODIFIED tab component.
    $indexBody = '<template><div>Settings</div></template>';
    $indexRel = 'resources/js/pages/Admin/Settings/Index.vue';
    $tabRel = 'resources/js/pages/Admin/Settings/components/StorageTab.vue';

    seedAppFile($indexRel, $indexBody);
    seedAppFile($tabRel, '<template><!-- consumer-customized tab --></template>');

    seedHashRegistry([
        $indexRel => md5($indexBody),                       // unmodified
        $tabRel => md5('<template><!-- original --></template>'), // differs → modified
    ]);

    $result = runVendorMigration();

    // The whole Settings Vue tree stays — a partial delete would break the build
    // (vendor pages import sibling components by relative path).
    expect($result['removed'])->not->toContain($indexRel, $tabRel);
    expect($result['preserved'])->toContain($indexRel, $tabRel);

    expect(file_exists(base_path($indexRel)))->toBeTrue();
    expect(file_exists(base_path($tabRel)))->toBeTrue();
});

// ── Independence — a blocked Vue group does not block the PHP group ───────────

it('migrates the PHP group even when the Vue group of the same module is preserved', function (): void {
    seedAppTs(withFallback: true);

    // Logs PHP: fully unmodified (removable). Logs Vue: one modified file (blocked).
    $ctrlBody = '<?php // original LogController';
    $ctrlRel = 'app/Http/Controllers/Admin/LogController.php';
    seedAppFile($ctrlRel, $ctrlBody);

    $vueRel = 'resources/js/pages/Admin/Logs/Index.vue';
    seedAppFile($vueRel, '<template><!-- consumer edited --></template>');

    seedHashRegistry([
        $ctrlRel => md5($ctrlBody),                  // unmodified → PHP group removable
        $vueRel => md5('<template>original</template>'), // differs → Vue group preserved
    ]);

    $result = runVendorMigration();

    // PHP layer is evaluated independently of the (blocked) Vue layer.
    expect($result['removed'])->toContain($ctrlRel);
    expect($result['preserved'])->toContain($vueRel);
    expect($result['preserved'])->not->toContain($ctrlRel);

    expect(file_exists(base_path($ctrlRel)))->toBeFalse();
    expect(file_exists(base_path($vueRel)))->toBeTrue();
});

// ══════════════════════════════════════════════════════════════════════════
// Faz 2 — php-layer group atomicity (controller + request + resource + domain)
// ══════════════════════════════════════════════════════════════════════════

// ── ContentLanguage: one edited domain Action pins controller+request+resource

it('preserves the whole ContentLanguage php group when one domain Action was modified', function (): void {
    // Tier 3 group: controller + request + resource + domain travel as ONE atomic
    // php unit. An UNMODIFIED controller alongside a MODIFIED domain Action.
    $ctrlBody = '<?php // original ContentLanguageController shipped by the kit';
    $ctrlRel = 'app/Http/Controllers/Admin/ContentLanguageController.php';
    $actionRel = 'app/Domain/ContentLanguage/Actions/CreateContentLanguageAction.php';

    seedAppFile($ctrlRel, $ctrlBody);
    seedAppFile($actionRel, '<?php // CONSUMER customized create logic');

    seedHashRegistry([
        $ctrlRel => md5($ctrlBody),                              // unmodified on its own
        $actionRel => md5('<?php // what the kit originally shipped'), // differs → modified
    ]);

    $result = runVendorMigration();

    // Atomicity: the unmodified controller must NOT be deleted while the consumer's
    // customized domain Action stays on disk — the vendor controller would bind the
    // vendor Action (App→vendor alias) and never run the consumer's logic.
    expect($result['removed'])->not->toContain($ctrlRel, $actionRel);
    expect($result['preserved'])->toContain($ctrlRel, $actionRel);

    expect(file_exists(base_path($ctrlRel)))->toBeTrue();
    expect(file_exists(base_path($actionRel)))->toBeTrue();
});

// ── ContentLanguage: fully-unmodified php group is removed (control case) ─────

it('removes the whole ContentLanguage php group when every file is unmodified', function (): void {
    $ctrlBody = '<?php // original ContentLanguageController';
    $ctrlRel = 'app/Http/Controllers/Admin/ContentLanguageController.php';
    $reqBody = '<?php // original StoreContentLanguageRequest';
    $reqRel = 'app/Http/Requests/Admin/ContentLanguage/StoreContentLanguageRequest.php';
    $resBody = '<?php // original ContentLanguageResource';
    $resRel = 'app/Http/Resources/Admin/ContentLanguage/ContentLanguageResource.php';
    $domBody = '<?php // original ContentLanguageDTO';
    $domRel = 'app/Domain/ContentLanguage/DTOs/ContentLanguageDTO.php';

    seedAppFile($ctrlRel, $ctrlBody);
    seedAppFile($reqRel, $reqBody);
    seedAppFile($resRel, $resBody);
    seedAppFile($domRel, $domBody);

    seedHashRegistry([
        $ctrlRel => md5($ctrlBody),
        $reqRel => md5($reqBody),
        $resRel => md5($resBody),
        $domRel => md5($domBody),
    ]);

    $result = runVendorMigration();

    expect($result['removed'])->toContain($ctrlRel, $reqRel, $resRel, $domRel);
    expect($result['preserved'])->not->toContain($ctrlRel, $reqRel, $resRel, $domRel);

    expect(file_exists(base_path($ctrlRel)))->toBeFalse();
    expect(file_exists(base_path($reqRel)))->toBeFalse();
    expect(file_exists(base_path($resRel)))->toBeFalse();
    expect(file_exists(base_path($domRel)))->toBeFalse();
});

// ── Definitions: the Api + Service controllers form ONE atomic php group ──────

it('preserves both Definition controllers when only one was modified (group atomicity)', function (): void {
    $apiBody = '<?php // original Api/DefinitionController';
    $apiRel = 'app/Http/Controllers/Api/DefinitionController.php';
    $svcRel = 'app/Http/Controllers/Service/DefinitionServiceController.php';

    seedAppFile($apiRel, $apiBody);
    seedAppFile($svcRel, '<?php // CONSUMER customized service controller');

    seedHashRegistry([
        $apiRel => md5($apiBody),                                  // unmodified
        $svcRel => md5('<?php // what the kit originally shipped'), // differs → modified
    ]);

    $result = runVendorMigration();

    expect($result['removed'])->not->toContain($apiRel, $svcRel);
    expect($result['preserved'])->toContain($apiRel, $svcRel);

    expect(file_exists(base_path($apiRel)))->toBeTrue();
    expect(file_exists(base_path($svcRel)))->toBeTrue();
});

// ── SystemHealth: controller-only php group still removed when unmodified ─────

it('removes the SystemHealth controller-only php group when unmodified (no app.ts dependency)', function (): void {
    $ctrlBody = '<?php // original SystemHealthController';
    $ctrlRel = 'app/Http/Controllers/Admin/SystemHealthController.php';
    seedAppFile($ctrlRel, $ctrlBody);

    seedHashRegistry([$ctrlRel => md5($ctrlBody)]);

    $result = runVendorMigration();

    // Faz 2 modules carry an empty vue layer, so the app.ts vendor-fallback guard
    // never applies — the php group resolves purely on the registry hash.
    expect($result['removed'])->toContain($ctrlRel);
    expect($result['preserved'])->not->toContain($ctrlRel);
    expect(file_exists(base_path($ctrlRel)))->toBeFalse();
});
