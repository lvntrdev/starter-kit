<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\StarterKitServiceProvider;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

class UpdateCommand extends Command
{
    protected $signature = 'sk:update
        {--force : Overwrite all files including user-modified ones}
        {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update Starter Kit files while preserving user modifications';

    private Filesystem $files;

    /**
     * Files/directories that are always safe to update (not user-customized).
     * These are core package files the user should not modify.
     *
     * @var list<string>
     */
    private const SAFE_UPDATE_PATHS = [
        // Shared domain base classes (excluding Services — user may customize those)
        'app/Domain/Shared/Actions/',
        'app/Domain/Shared/Contracts/',
        'app/Domain/Shared/DTOs/',
        'app/Domain/Shared/Pipelines/',

        // Base enums and contracts from package
        'app/Enums/PermissionEnum.php',

        // Middleware from package (excluding CheckResourcePermission — user may customize)
        'app/Http/Middleware/SecurityHeaders.php',
        'app/Http/Middleware/AssignTraceId.php',

        // API Response builder
        'app/Http/Responses/ApiResponse.php',

        // Global helpers (to_api, format_date, definition, definitionLabel)
        'app/Helpers/sk-helpers.php',

        // Traits from package
        'app/Traits/',

        // Exception handler
        'app/Exceptions/ApiExceptionHandler.php',
    ];

    /**
     * Files that are NEVER updated — only installed once.
     * These are user-customizable config/template files.
     *
     * @var list<string>
     */
    private const NEVER_UPDATE_PATHS = [
        'config/permission-resources.php',
    ];

    /**
     * Files/directories removed from the package that should be cleaned up.
     * These are deleted during update if they exist in the application.
     *
     * @var list<string>
     */
    private const DEPRECATED_PATHS = [
        'app/Enums/EnumRegistry.php',
        'app/Enums/Contracts/HasDefinition.php',
        'app/Enums/Attributes/InertiaShared.php',
        'app/Enums/Contracts/',
        'app/Enums/Attributes/',
        'app/Enums/IdentityType.php',
        'app/Enums/YesNo.php',
        'app/Traits/HasEnumAccessors.php',
        'resources/js/composables/useEnum.ts',
    ];

    /** @var list<string> */
    private array $updated = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $added = [];

    /** @var list<string> Files with no hash record (untracked) */
    private array $untracked = [];

    /** @var list<string> */
    private array $removed = [];

    public function handle(): int
    {
        $this->files = new Filesystem;

        $this->newLine();
        $this->components->info('Updating Lvntr Starter Kit...');
        $this->newLine();

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        // 1. Always update safe paths (core files)
        $this->updateSafePaths($dryRun);

        // 1b. Remove deprecated files
        $this->removeDeprecatedPaths($dryRun);

        // 2. Update user-modifiable files only if not modified
        $this->updateModifiableFiles($force, $dryRun);

        // 3. Handle untracked files (no hash record — ask user)
        if (! $force && ! empty($this->untracked)) {
            $this->resolveUntrackedFiles($dryRun);
        }

        // 4. Add new files that don't exist yet
        $this->addNewFiles($dryRun);

        // 4b. Inject filesystem config if missing (added in later versions)
        if (! $dryRun) {
            $this->injectFilesystemsConfig();
            $this->injectMediaLibraryConfig();
            $this->migrateLegacyHelpersFile();
            $this->rewriteHelpersAutoload();
        }

        // 4c. Merge stub package.json into the consumer's package.json so newly added
        // npm dependencies (e.g. the @tiptap/* set added with EditorInput in v13.4.x)
        // land automatically on `sk:update` instead of forcing every consumer to copy
        // them by hand. Stub versions win for shared keys; user extras are preserved.
        if (! $dryRun) {
            $this->mergePackageJson();
        }

        // 5. Run new migrations
        if (! $dryRun && ! empty($this->added) && $this->hasNewMigrations()) {
            if (confirm('New migrations found. Run them now?', default: true)) {
                spin(function () {
                    return $this->callSilently('migrate', ['--force' => true]) === 0;
                }, 'Running new migrations...');
                $this->components->info('Migrations completed.');
            }
        }

        // 6. Update hash registry
        if (! $dryRun) {
            $this->updateHashRegistry();
        }

        // Summary
        $this->newLine();
        $this->printSummary($dryRun);

        return self::SUCCESS;
    }

    /**
     * Update files that are always safe to overwrite.
     */
    private function updateSafePaths(bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();

        foreach (self::SAFE_UPDATE_PATHS as $safePath) {
            $stubSource = $stubsPath.DIRECTORY_SEPARATOR.$safePath;
            $appTarget = base_path($safePath);

            if (str_ends_with($safePath, '/')) {
                // Directory
                if (! $this->files->isDirectory($stubSource)) {
                    continue;
                }

                foreach ($this->files->allFiles($stubSource, true) as $file) {
                    $relativePath = $safePath.$file->getRelativePathname();
                    $targetPath = base_path($relativePath);

                    if ($this->filesAreIdentical($file->getPathname(), $targetPath)) {
                        continue;
                    }

                    if (! $dryRun) {
                        $this->ensureDirectoryExists(dirname($targetPath));
                        $this->files->copy($file->getPathname(), $targetPath);
                    }

                    $this->updated[] = $relativePath;
                }
            } else {
                // Single file
                if (! $this->files->exists($stubSource)) {
                    continue;
                }

                if ($this->filesAreIdentical($stubSource, $appTarget)) {
                    continue;
                }

                if (! $dryRun) {
                    $this->ensureDirectoryExists(dirname($appTarget));
                    $this->files->copy($stubSource, $appTarget);
                }

                $this->updated[] = $safePath;
            }
        }
    }

    /**
     * Remove deprecated files/directories that are no longer part of the package.
     */
    private function removeDeprecatedPaths(bool $dryRun): void
    {
        foreach (self::DEPRECATED_PATHS as $path) {
            $target = base_path($path);

            if (str_ends_with($path, '/')) {
                // Directory — remove only if empty
                if ($this->files->isDirectory($target) && empty($this->files->files($target))) {
                    if (! $dryRun) {
                        $this->files->deleteDirectory($target);
                    }
                    $this->removed[] = $path;
                }
            } else {
                // File
                if ($this->files->exists($target)) {
                    if (! $dryRun) {
                        $this->files->delete($target);
                    }
                    $this->removed[] = $path;
                }
            }
        }
    }

    /**
     * Update files that the user may have modified.
     * Only updates if the file hasn't been modified since last install/update.
     */
    private function updateModifiableFiles(bool $force, bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $hashes = $this->loadHashRegistry();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            // Skip if it's in safe paths (already handled)
            if ($this->isInSafePaths($relativePath)) {
                continue;
            }

            // Skip files that should never be updated
            if ($this->isNeverUpdate($relativePath)) {
                continue;
            }

            // Skip if target doesn't exist (handled in addNewFiles)
            if (! $this->files->exists($targetPath)) {
                continue;
            }

            // Skip if files are identical
            if ($this->filesAreIdentical($file->getPathname(), $targetPath)) {
                continue;
            }

            // Check if user has modified the file
            $currentHash = md5_file($targetPath);
            $originalHash = $hashes[$relativePath] ?? null;

            if (! $force) {
                if ($originalHash === null) {
                    // No hash record — collect for interactive prompt
                    $this->untracked[] = $relativePath;

                    continue;
                }

                if ($currentHash !== $originalHash) {
                    // User has modified this file — skip it
                    $this->skipped[] = $relativePath;

                    continue;
                }
            }

            if (! $dryRun) {
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($file->getPathname(), $targetPath);
            }

            $this->updated[] = $relativePath;
        }
    }

    /**
     * Handle untracked files — no hash record exists.
     * Ask the user which ones to update via multiselect.
     */
    private function resolveUntrackedFiles(bool $dryRun): void
    {
        if (empty($this->untracked)) {
            return;
        }

        if ($dryRun) {
            // In dry-run mode, just report them — no interactive prompt
            return;
        }

        $this->newLine();
        $this->components->warn('The following files have no tracking history and differ from the package version.');
        $this->line('  <fg=gray>This usually happens after a package update that changed these files.</>');
        $this->newLine();

        $options = [];
        foreach ($this->untracked as $path) {
            $options[$path] = $path;
        }

        $toUpdate = multiselect(
            label: 'Which files should be updated? (select only files you did NOT modify)',
            options: $options,
        );

        $stubsPath = StarterKitServiceProvider::stubsPath();

        foreach ($this->untracked as $path) {
            if (in_array($path, $toUpdate)) {
                $stubPath = $stubsPath.DIRECTORY_SEPARATOR.$path;
                $targetPath = base_path($path);
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($stubPath, $targetPath);

                $this->updated[] = $path;
            } else {
                $this->skipped[] = $path;
            }
        }
    }

    /**
     * Add new stub files that don't exist in the application yet.
     */
    private function addNewFiles(bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $hashes = $this->loadHashRegistry();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if ($this->files->exists($targetPath)) {
                continue;
            }

            // Skip if already handled
            if (in_array($relativePath, $this->updated)) {
                continue;
            }

            // If hash registry has an entry, the file was previously installed
            // but the user deleted it — respect that decision, don't re-add.
            if (isset($hashes[$relativePath])) {
                continue;
            }

            if (! $dryRun) {
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($file->getPathname(), $targetPath);
            }

            $this->added[] = $relativePath;
        }
    }

    /**
     * Check if newly added files include migrations.
     */
    private function hasNewMigrations(): bool
    {
        foreach ($this->added as $path) {
            if (str_starts_with($path, 'database/migrations/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a relative path falls within safe update paths.
     */
    private function isInSafePaths(string $relativePath): bool
    {
        foreach (self::SAFE_UPDATE_PATHS as $safePath) {
            if (str_ends_with($safePath, '/')) {
                if (str_starts_with($relativePath, $safePath)) {
                    return true;
                }
            } else {
                if ($relativePath === $safePath) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a file should never be updated (user-customizable files).
     */
    private function isNeverUpdate(string $relativePath): bool
    {
        foreach (self::NEVER_UPDATE_PATHS as $path) {
            if (str_ends_with($path, '/')) {
                if (str_starts_with($relativePath, $path)) {
                    return true;
                }
            } elseif ($relativePath === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two files have identical content.
     */
    private function filesAreIdentical(string $source, string $target): bool
    {
        if (! $this->files->exists($target)) {
            return false;
        }

        return md5_file($source) === md5_file($target);
    }

    /**
     * Load the hash registry from storage.
     * Automatically migrates old format (target hashes) to new format (stub hashes).
     *
     * @return array<string, string>
     */
    private function loadHashRegistry(): array
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        if (! $this->files->exists($hashFile)) {
            return [];
        }

        $data = json_decode($this->files->get($hashFile), true) ?: [];

        // Already migrated to v2 format (stores stub hashes)
        if (($data['_format'] ?? null) === 'v2') {
            unset($data['_format']);

            return $data;
        }

        // Migrate from old format: old registry stored TARGET hashes which is unreliable.
        // Re-derive by comparing each target against its stub.
        return $this->migrateHashRegistry($data);
    }

    /**
     * Migrate old hash registry (target hashes) to new format (stub hashes).
     *
     * For each stub file:
     * - If target === stub → store stub hash (user hasn't modified)
     * - If target !== stub → don't store (assume user modified, will be skipped)
     *
     * @param  array<string, string>  $oldHashes
     * @return array<string, string>
     */
    private function migrateHashRegistry(array $oldHashes): array
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();
        $newHashes = [];

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if (! $this->files->exists($targetPath)) {
                // File was deleted by user or never installed.
                // If it was in old registry, keep the entry so addNewFiles knows it existed.
                if (isset($oldHashes[$relativePath])) {
                    $newHashes[$relativePath] = '__deleted__';
                }

                continue;
            }

            $stubHash = md5_file($file->getPathname());
            $targetHash = md5_file($targetPath);

            if ($stubHash === $targetHash) {
                // Target matches current stub — user hasn't modified this file
                $newHashes[$relativePath] = $stubHash;
            } elseif (isset($oldHashes[$relativePath]) && $targetHash === $oldHashes[$relativePath]) {
                // Target matches what was installed (old v1 hash) — user hasn't modified,
                // but the STUB has changed since last install. Store target hash so update
                // will detect it's safe to overwrite.
                $newHashes[$relativePath] = $targetHash;
            }

            // If neither match, user modified the file — don't store → skip (safe).
        }

        // Save migrated registry immediately
        $this->saveHashRegistry($newHashes);

        $this->components->info('Migrated hash registry to v2 format.');

        return $newHashes;
    }

    /**
     * Update the hash registry with STUB hashes for files that were actually updated or added.
     */
    private function updateHashRegistry(): void
    {
        $hashes = $this->loadHashRegistry();
        $stubsPath = StarterKitServiceProvider::stubsPath();

        $changedFiles = array_merge($this->updated, $this->added);

        foreach ($changedFiles as $relativePath) {
            // Skip descriptive entries like "config/filesystems.php (injected DO Spaces disk)"
            if (str_contains($relativePath, ' ')) {
                continue;
            }

            $stubPath = $stubsPath.DIRECTORY_SEPARATOR.$relativePath;
            if ($this->files->exists($stubPath)) {
                // Store the STUB hash, not the target hash
                $hashes[$relativePath] = md5_file($stubPath);
            }
        }

        $this->saveHashRegistry($hashes);
    }

    /**
     * Persist the hash registry to disk.
     *
     * @param  array<string, string>  $hashes
     */
    private function saveHashRegistry(array $hashes): void
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        $dir = dirname($hashFile);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $hashes['_format'] = 'v2';

        $this->files->put($hashFile, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Inject DigitalOcean Spaces disk into config/filesystems.php if not already present.
     */
    private function injectFilesystemsConfig(): void
    {
        $configPath = config_path('filesystems.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $content = $this->files->get($configPath);

        // Check if 'do' disk is already inside the 'disks' section
        $disksPos = strpos($content, "'disks'");
        if ($disksPos === false) {
            return;
        }

        $disksClosingPos = strpos($content, "\n    ],", $disksPos);
        if ($disksClosingPos === false) {
            return;
        }

        $disksSection = substr($content, $disksPos, $disksClosingPos - $disksPos);
        if (str_contains($disksSection, "'do'")) {
            return;
        }

        $diskConfig = <<<'PHP'

        'do' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'url' => env('DO_SPACES_URL'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],
PHP;

        $content = substr_replace($content, $diskConfig."\n\n    ],", $disksClosingPos + 1, strlen('    ],'));

        $this->files->put($configPath, $content);

        $this->updated[] = 'config/filesystems.php (injected DO Spaces disk)';
    }

    /**
     * Set the custom path generator in config/media-library.php if not already configured.
     */
    private function injectMediaLibraryConfig(): void
    {
        $configPath = config_path('media-library.php');

        // Publish the config if it doesn't exist yet
        if (! $this->files->exists($configPath)) {
            $vendorConfig = base_path('vendor/spatie/laravel-medialibrary/config/media-library.php');
            if ($this->files->exists($vendorConfig)) {
                $this->files->copy($vendorConfig, $configPath);
            } else {
                return;
            }
        }

        $content = $this->files->get($configPath);

        if (str_contains($content, 'MediaPathGenerator')) {
            return;
        }

        $content = str_replace(
            'Spatie\\MediaLibrary\\Support\\PathGenerator\\DefaultPathGenerator::class',
            'App\\Support\\MediaPathGenerator::class',
            $content,
        );

        $content = str_replace(
            'DefaultPathGenerator::class',
            'MediaPathGenerator::class',
            $content,
        );

        if (str_contains($content, 'MediaPathGenerator::class') && ! str_contains($content, 'use App\\Support\\MediaPathGenerator')) {
            $content = str_replace(
                "<?php\n",
                "<?php\n\nuse App\\Support\\MediaPathGenerator;\n",
                $content,
            );
        }

        $this->files->put($configPath, $content);

        $this->updated[] = 'config/media-library.php (set custom path generator)';
    }

    /**
     * Rewrite composer.json autoload `files` entry: legacy `app/helpers.php`
     * becomes `app/Helpers/custom.php`. The package now ships `to_api()` and
     * `format_date()` from vendor — the app file is purely user-owned.
     */
    /**
     * Known historical md5 hashes of stock `app/helpers.php` shipped by the
     * package. If the user's file matches any of these, we know they have not
     * customized it and it is safe to delete during the migration to
     * `app/Helpers/custom.php`.
     *
     * @var list<string>
     */
    private const LEGACY_HELPERS_STOCK_HASHES = [
        '34375d826bb4ea188ab738cc12bcb096', // initial package release
    ];

    /**
     * One-time migration: when the legacy `app/helpers.php` exists, decide
     * whether it is safe to remove (matches a known stock hash) or whether
     * the user has customized it (warn and leave the file in place so their
     * code is not destroyed).
     */
    private function migrateLegacyHelpersFile(): void
    {
        $legacyPath = base_path('app/helpers.php');

        if (! $this->files->exists($legacyPath)) {
            return;
        }

        $hash = md5_file($legacyPath);

        if (in_array($hash, self::LEGACY_HELPERS_STOCK_HASHES, true)) {
            $this->files->delete($legacyPath);
            $this->removed[] = 'app/helpers.php';

            return;
        }

        $this->newLine();
        $this->components->warn('app/helpers.php contains custom code — left in place.');
        $this->line('  <fg=gray>Move your helpers to app/Helpers/custom.php and delete app/helpers.php manually.</>');
        $this->line('  <fg=gray>to_api() and format_date() are now provided by the package — drop them from your file.</>');
        $this->newLine();
    }

    /**
     * Merge the stub package.json into the application's package.json.
     *
     * Mirrors the strategy used at install time: stub version wins for shared
     * dependency versions, user-added dependencies (and any extra root-level
     * keys) are preserved. We only record an "updated" entry when the merge
     * actually changes the file on disk — re-running `sk:update` is otherwise
     * a no-op for users whose package.json is already in sync.
     */
    private function mergePackageJson(): void
    {
        $stubPath = StarterKitServiceProvider::stubsPath('package.json');
        $targetPath = base_path('package.json');

        if (! $this->files->exists($stubPath)) {
            return;
        }

        if (! $this->files->exists($targetPath)) {
            $this->files->copy($stubPath, $targetPath);
            $this->added[] = 'package.json';

            return;
        }

        /** @var array<string, mixed>|null $stub */
        $stub = json_decode($this->files->get($stubPath), true);
        /** @var array<string, mixed>|null $current */
        $current = json_decode($this->files->get($targetPath), true);

        if (! is_array($stub) || ! is_array($current)) {
            // Malformed JSON — fall back to stub to guarantee a working build.
            $this->files->copy($stubPath, $targetPath);
            $this->updated[] = 'package.json';

            return;
        }

        // Stub keys win at the root level; user-added extra keys are preserved.
        $merged = array_merge($current, $stub);

        // For dependency sections, union the two maps so user extras survive
        // while stub versions override any shared dependency versions.
        foreach (['dependencies', 'devDependencies'] as $section) {
            $stubSection = $stub[$section] ?? [];
            $currentSection = $current[$section] ?? [];

            if (! is_array($stubSection) || ! is_array($currentSection)) {
                continue;
            }

            $mergedSection = array_merge($currentSection, $stubSection);
            ksort($mergedSection);
            $merged[$section] = $mergedSection;
        }

        $rendered = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if ($rendered === $this->files->get($targetPath)) {
            return;
        }

        $this->files->put($targetPath, $rendered);
        $this->updated[] = 'package.json (merged stub dependencies — run npm install)';
    }

    private function rewriteHelpersAutoload(): void
    {
        $composerPath = base_path('composer.json');

        if (! $this->files->exists($composerPath)) {
            return;
        }

        $data = json_decode($this->files->get($composerPath), true);

        if (! is_array($data)) {
            return;
        }

        $files = $data['autoload']['files'] ?? [];
        $hasLegacyEntry = in_array('app/helpers.php', $files, true);
        $hasCustomEntry = in_array('app/Helpers/custom.php', $files, true);
        $legacyFileStillPresent = $this->files->exists(base_path('app/helpers.php'));

        // Only drop the legacy autoload entry when the file is gone too —
        // otherwise the user's custom code in app/helpers.php would silently
        // stop loading. migrateLegacyHelpersFile() handles deletion.
        $shouldDropLegacy = $hasLegacyEntry && ! $legacyFileStillPresent;
        $shouldAddCustom = ! $hasCustomEntry;

        if (! $shouldDropLegacy && ! $shouldAddCustom) {
            return;
        }

        if ($shouldDropLegacy) {
            $files = array_values(array_filter($files, fn ($entry) => $entry !== 'app/helpers.php'));
        }

        if ($shouldAddCustom) {
            $files[] = 'app/Helpers/custom.php';
        }

        $data['autoload']['files'] = array_values(array_unique($files));

        $this->files->put(
            $composerPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->updated[] = 'composer.json (rewrote helpers autoload entry)';
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * Print the update summary.
     */
    private function printSummary(bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $hasChanges = ! empty($this->updated) || ! empty($this->added) || ! empty($this->removed) || ! empty($this->skipped) || ! empty($this->untracked);

        if (! $hasChanges) {
            $this->components->info($prefix.'Everything is up to date!');

            return;
        }

        if (! empty($this->updated)) {
            $this->components->twoColumnDetail("<fg=green>{$prefix}Updated</>", count($this->updated).' files');
            foreach ($this->updated as $path) {
                $this->line("  <fg=green>↻</> {$path}");
            }
        }

        if (! empty($this->added)) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=blue>{$prefix}Added</>", count($this->added).' new files');
            foreach ($this->added as $path) {
                $this->line("  <fg=blue>+</> {$path}");
            }
        }

        if (! empty($this->removed)) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=red>{$prefix}Removed</>", count($this->removed).' deprecated files');
            foreach ($this->removed as $path) {
                $this->line("  <fg=red>-</> {$path}");
            }
        }

        // Show untracked files (dry-run: not yet resolved; normal: already resolved into updated/skipped)
        if ($dryRun && ! empty($this->untracked)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=magenta>Untracked</>', count($this->untracked).' files need review');
            foreach ($this->untracked as $path) {
                $this->line("  <fg=magenta>?</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>Run without --dry-run to choose which files to update.</>');
        }

        if (! empty($this->skipped)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Skipped</>', count($this->skipped).' user-modified files');
            foreach ($this->skipped as $path) {
                $this->line("  <fg=yellow>~</> {$path}");
            }
            $this->newLine();
            $this->line('  <fg=gray>Use --force to overwrite user-modified files.</>');
        }

        if (! $dryRun && (! empty($this->updated) || ! empty($this->added))) {
            $this->newLine();
            $this->components->warn('Run the following commands to apply frontend changes:');
            $this->line('  <fg=cyan>npm install && npm run build</>');
        }
    }
}
