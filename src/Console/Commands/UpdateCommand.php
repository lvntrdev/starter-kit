<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\StarterKitServiceProvider;

use function Laravel\Prompts\confirm;
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
        // Shared domain base classes
        'app/Domain/Shared/',

        // Base enums and contracts from package
        'app/Enums/PermissionEnum.php',

        // Middleware from package
        'app/Http/Middleware/CheckResourcePermission.php',
        'app/Http/Middleware/SecurityHeaders.php',

        // API Response builder
        'app/Http/Responses/ApiResponse.php',

        // Traits from package
        'app/Traits/',

        // Helpers
        'app/helpers.php',

        // Exception handler
        'app/Exceptions/ApiExceptionHandler.php',
    ];

    /** @var list<string> */
    private array $updated = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var list<string> */
    private array $added = [];

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

        // 2. Update user-modifiable files only if not modified
        $this->updateModifiableFiles($force, $dryRun);

        // 3. Add new files that don't exist yet
        $this->addNewFiles($dryRun);

        // 3b. Inject filesystem config if missing (added in later versions)
        if (! $dryRun) {
            $this->injectFilesystemsConfig();
        }

        // 4. Run new migrations
        if (! $dryRun && ! empty($this->added) && $this->hasNewMigrations()) {
            if (confirm('New migrations found. Run them now?', default: true)) {
                spin(function () {
                    return $this->callSilently('migrate', ['--force' => true]) === 0;
                }, 'Running new migrations...');
                $this->components->info('Migrations completed.');
            }
        }

        // 5. Update hash registry
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
                    // No hash record — file predates hash registry or was never tracked.
                    // Assume user may have modified it; skip to be safe.
                    $this->skipped[] = $relativePath;

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
     * Add new stub files that don't exist in the application yet.
     */
    private function addNewFiles(bool $dryRun): void
    {
        $stubsPath = StarterKitServiceProvider::stubsPath();

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
     *
     * @return array<string, string>
     */
    private function loadHashRegistry(): array
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));

        if (! $this->files->exists($hashFile)) {
            return [];
        }

        return json_decode($this->files->get($hashFile), true) ?: [];
    }

    /**
     * Update the hash registry with current file hashes.
     */
    private function updateHashRegistry(): void
    {
        $hashFile = config('starter-kit.published_hashes', storage_path('starter-kit/hashes.json'));
        $hashes = $this->loadHashRegistry();
        $stubsPath = StarterKitServiceProvider::stubsPath();

        foreach ($this->files->allFiles($stubsPath, true) as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = base_path($relativePath);

            if ($this->files->exists($targetPath)) {
                $hashes[$relativePath] = md5_file($targetPath);
            }
        }

        $dir = dirname($hashFile);
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

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

        if (str_contains($content, "'do'")) {
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

        $pos = strrpos($content, '    ],');
        if ($pos !== false) {
            $content = substr_replace($content, $diskConfig."\n\n    ],", $pos, strlen('    ],'));
        }

        $this->files->put($configPath, $content);

        $this->updated[] = 'config/filesystems.php (injected DO Spaces disk)';
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

        if (empty($this->updated) && empty($this->added) && empty($this->skipped)) {
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
