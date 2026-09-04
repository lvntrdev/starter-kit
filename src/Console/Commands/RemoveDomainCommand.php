<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;

/**
 * Remove a previously scaffolded domain and all its associated files.
 *
 * Reverses everything that make:sk-domain creates:
 *   - Domain directory (Actions, DTOs, Queries, Events, Listeners)
 *   - Model, Factory, Migration
 *   - Admin & API Controllers, FormRequests
 *   - Route entries in web.php and api.php
 *   - DomainServiceProvider registrations (events)
 */
class RemoveDomainCommand extends Command
{
    use RefusesPackageSourceTree;

    protected $signature = 'remove:sk-domain
        {name : The domain name to remove (e.g. Student, Product)}
        {--force : Skip confirmation prompt}';

    protected $description = 'Remove a domain and all its associated files, routes and provider registrations';

    protected $aliases = ['remove:domain', 'sk-remove:domain'];

    /** Domain name variants */
    private string $dn;

    private string $dnPlural;

    private string $dnSnake;

    private string $dnPSnake;

    /** @var list<string> */
    private array $domainSegments = [];

    private string $domainPath;

    /** @var list<string> Tracks removed items for summary */
    private array $removed = [];

    /** @var list<string> Tracks skipped items */
    private array $skipped = [];

    public function handle(): int
    {
        if ($this->isPackageSourceTree()) {
            return $this->renderPackageSourceTreeStop();
        }

        $raw = $this->argument('name');

        if (! $this->isValidDomainName($raw)) {
            $this->error('Invalid domain name. Use only letters, numbers, hyphens and underscores in segments.');

            return self::FAILURE;
        }

        $this->domainSegments = collect(preg_split('/[\/\\\\]+/', $raw) ?: [])
            ->filter()
            ->map(fn (string $segment): string => Str::studly($segment))
            ->values()
            ->all();

        $this->domainPath = implode('/', $this->domainSegments);
        $this->dn = (string) last($this->domainSegments);
        $this->dnPlural = Str::plural($this->dn);
        $this->dnSnake = collect($this->domainSegments)->map(fn (string $segment): string => Str::snake($segment))->implode('_');
        $this->dnPSnake = Str::plural($this->dnSnake);

        // Safety check: prevent removing the User domain
        if ($this->dn === 'User') {
            $this->error('User domain cannot be removed — it is used by the system.');

            return self::FAILURE;
        }

        // Show what will be removed
        $this->newLine();
        $this->warn("⚠️  '{$this->domainPath}' domain and all related files will be removed:");
        $this->newLine();
        $this->showWillRemove();
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('This action cannot be undone. Continue?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("🗑  Removing domain: {$this->domainPath}");
        $this->newLine();

        // Remove files and directories
        $this->removeDomainDirectory();
        $this->removeModel();
        $this->removeFactory();
        $this->removeMigrations();
        $this->removeController('Admin');
        $this->removeController('Api');
        $this->removeFormRequests('Admin');
        $this->removeFormRequests('Api');
        $this->removeAdminResource();
        $this->removeVuePages();
        $this->removeTypeDefinition();

        // Clean up registrations
        $this->cleanServiceProvider();
        $this->cleanAdminRoutes();
        $this->cleanApiRoutes();

        // Summary
        $this->newLine();
        $this->info("✅ Domain '{$this->domainPath}' removed successfully!");
        $this->newLine();

        if (! empty($this->removed)) {
            $this->table(['#', 'Removed'], collect($this->removed)->map(fn ($r, $i) => [$i + 1, $r])->all());
        }

        if (! empty($this->skipped)) {
            $this->newLine();
            $this->warn('Skipped (not found):');
            foreach ($this->skipped as $s) {
                $this->line("  - {$s}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display a preview of what will be removed.
     */
    private function showWillRemove(): void
    {
        $items = [
            ['Domain Directory', "app/Domain/{$this->domainPath}/"],
            ['Model', "app/Models/{$this->domainPath}.php"],
            ['Factory', "database/factories/{$this->domainPath}Factory.php"],
            ['Migration(s)', "database/migrations/*_create_{$this->dnPSnake}_table.php"],
            ['Admin Controller', str_replace(base_path().'/', '', $this->controllerPath('Admin'))],
            ['API Controller', str_replace(base_path().'/', '', $this->controllerPath('Api'))],
            ['Admin Requests', "app/Http/Requests/Admin/{$this->domainPath}/"],
            ['API Requests', "app/Http/Requests/Api/{$this->domainPath}/"],
            ['Admin Resource', "app/Http/Resources/Admin/{$this->domainPath}/"],
            ['Vue Pages', "resources/js/pages/Admin/{$this->domainPath}/{$this->dnPlural}/"],
            ['Provider', 'DomainServiceProvider.php → import, events will be removed'],
            ['Admin Routes', "routes/web/{$this->dnPSnake}-route.php"],
            ['API Routes', "routes/api/{$this->dnPSnake}-route.php"],
        ];

        $this->table(['Layer', 'File/Location'], $items);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FILE / DIRECTORY REMOVAL
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Remove the entire domain directory (Actions, DTOs, Repositories, etc.)
     */
    private function removeDomainDirectory(): void
    {
        $dir = app_path("Domain/{$this->domainPath}");

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
            $this->pruneEmptyDirectories(dirname($dir), app_path('Domain'));
            $this->track("app/Domain/{$this->domainPath}/");
        } else {
            $this->skip("app/Domain/{$this->domainPath}/");
        }
    }

    /**
     * Remove the Eloquent model.
     */
    private function removeModel(): void
    {
        $this->removeFile(app_path("Models/{$this->domainPath}.php"), "app/Models/{$this->domainPath}.php");
    }

    /**
     * Remove the model factory.
     */
    private function removeFactory(): void
    {
        $this->removeFile(
            database_path("factories/{$this->domainPath}Factory.php"),
            "database/factories/{$this->domainPath}Factory.php"
        );
    }

    /**
     * Remove migration files matching the table name.
     */
    private function removeMigrations(): void
    {
        $pattern = database_path("migrations/*_create_{$this->dnPSnake}_table.php");
        $files = glob($pattern);

        if (! empty($files)) {
            foreach ($files as $file) {
                unlink($file);
                $this->track('database/migrations/'.basename($file));
            }
        } else {
            $this->skip("database/migrations/*_create_{$this->dnPSnake}_table.php");
        }
    }

    /**
     * Remove a controller file.
     */
    private function removeController(string $layer): void
    {
        $rel = str_replace(base_path().'/', '', $this->controllerPath($layer));
        $this->removeFile($this->controllerPath($layer), $rel);
    }

    /**
     * Remove FormRequest directory for a layer.
     */
    private function removeFormRequests(string $layer): void
    {
        $dir = app_path("Http/Requests/{$layer}/{$this->domainPath}");
        $rel = "app/Http/Requests/{$layer}/{$this->domainPath}/";

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
            $this->pruneEmptyDirectories(dirname($dir), app_path("Http/Requests/{$layer}"));
            $this->track($rel);
        } else {
            $this->skip($rel);
        }
    }

    /**
     * Remove Admin Resource directory.
     */
    private function removeAdminResource(): void
    {
        $dir = app_path("Http/Resources/Admin/{$this->domainPath}");
        $rel = "app/Http/Resources/Admin/{$this->domainPath}/";

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
            $this->pruneEmptyDirectories(dirname($dir), app_path('Http/Resources/Admin'));
            $this->track($rel);
        } else {
            $this->skip($rel);
        }
    }

    /**
     * Remove Vue pages directory.
     */
    private function removeVuePages(): void
    {
        $dnPlural = Str::plural($this->dn);
        $nestedPath = implode('/', array_slice($this->domainSegments, 0, -1));
        $vuePath = trim("Admin/{$nestedPath}/{$dnPlural}", '/');
        $dir = resource_path("js/pages/{$vuePath}");
        $rel = "resources/js/pages/{$vuePath}/";

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
            $this->pruneEmptyDirectories(dirname($dir), resource_path('js/pages/Admin'));
            $this->track($rel);
        } else {
            $this->skip($rel);
        }
    }

    /**
     * Remove TypeScript type definition file and its re-export from index.ts.
     */
    private function removeTypeDefinition(): void
    {
        $typePath = resource_path("js/types/{$this->dnSnake}.ts");
        $rel = "resources/js/types/{$this->dnSnake}.ts";

        if (file_exists($typePath)) {
            unlink($typePath);
            $this->track($rel);

            // Remove re-export from index.ts
            $indexPath = resource_path('js/types/index.ts');

            if (file_exists($indexPath)) {
                $content = file_get_contents($indexPath);
                $exportLine = "export type { {$this->dn} } from './{$this->dnSnake}';\n";
                $content = str_replace($exportLine, '', $content);
                file_put_contents($indexPath, $content);
            }
        } else {
            $this->skip($rel);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SERVICE PROVIDER CLEANUP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Remove all domain references from DomainServiceProvider.
     */
    private function cleanServiceProvider(): void
    {
        $path = app_path('Providers/DomainServiceProvider.php');
        $domainNamespace = str_replace('\\', '\\\\', $this->domainNamespace());
        $createdEventAlias = $this->providerAlias('Created');
        $updatedEventAlias = $this->providerAlias('Updated');
        $deletedEventAlias = $this->providerAlias('Deleted');
        $logCreatedAlias = $this->providerAlias('LogCreated');
        $logUpdatedAlias = $this->providerAlias('LogUpdated');
        $logDeletedAlias = $this->providerAlias('LogDeleted');

        if (! file_exists($path)) {
            $this->skip('DomainServiceProvider.php');

            return;
        }

        $content = file_get_contents($path);
        $original = $content;

        // Remove use statements containing this domain
        $content = preg_replace(
            "/use App\\\\Domain\\\\{$domainNamespace}\\\\.*?;\n/",
            '',
            $content
        );

        // Remove event listener lines + section comment
        $content = preg_replace(
            "/\n\s*\/\/ ── ".preg_quote($this->domainPath, '/')." Events[─ ]*\n/",
            "\n",
            $content
        );
        $content = preg_replace(
            "/ *Event::listen\(({$createdEventAlias}|{$updatedEventAlias}|{$deletedEventAlias})::class,\s*({$logCreatedAlias}|{$logUpdatedAlias}|{$logDeletedAlias})::class\);\n/",
            '',
            $content
        );

        // Clean up extra blank lines (more than 2 consecutive)
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        if ($content !== $original) {
            file_put_contents($path, $content);
            $this->track('DomainServiceProvider → imports, events removed');
        } else {
            $this->skip('DomainServiceProvider (reference not found)');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // ROUTE CLEANUP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Remove admin route file.
     */
    private function cleanAdminRoutes(): void
    {
        $rel = "routes/web/{$this->dnPSnake}-route.php";
        $this->removeFile(base_path($rel), $rel);
    }

    /**
     * Remove API route file.
     */
    private function cleanApiRoutes(): void
    {
        $rel = "routes/api/{$this->dnPSnake}-route.php";
        $this->removeFile(base_path($rel), $rel);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function controllerPath(string $layer): string
    {
        $nestedPath = implode('/', array_slice($this->domainSegments, 0, -1));
        $prefix = trim("Http/Controllers/{$layer}/{$nestedPath}", '/');

        return app_path("{$prefix}/{$this->dn}Controller.php");
    }

    private function domainNamespace(): string
    {
        return implode('\\', $this->domainSegments);
    }

    private function providerAlias(string $suffix): string
    {
        return implode('', $this->domainSegments).$suffix;
    }

    /**
     * Remove a single file if it exists.
     */
    private function removeFile(string $path, string $label): void
    {
        if (file_exists($path)) {
            unlink($path);
            $this->pruneEmptyDirectories(dirname($path), $this->pruneRootFor($path));
            $this->track($label);
        } else {
            $this->skip($label);
        }
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);

        foreach ($items as $item) {
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function pruneRootFor(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, app_path('Models/')) => app_path('Models'),
            str_starts_with($path, app_path('Http/Controllers/Admin/')) => app_path('Http/Controllers/Admin'),
            str_starts_with($path, app_path('Http/Controllers/Api/')) => app_path('Http/Controllers/Api'),
            str_starts_with($path, database_path('factories/')) => database_path('factories'),
            default => null,
        };
    }

    private function pruneEmptyDirectories(string $directory, ?string $stopAt = null): void
    {
        $current = rtrim($directory, DIRECTORY_SEPARATOR);
        $limit = $stopAt ? rtrim($stopAt, DIRECTORY_SEPARATOR) : null;

        while ($current !== '' && $current !== '.' && $current !== $limit && is_dir($current)) {
            if (count(array_diff(scandir($current), ['.', '..'])) !== 0) {
                break;
            }

            rmdir($current);
            $current = dirname($current);
        }
    }

    /**
     * Track a removed item for the summary.
     */
    private function track(string $label): void
    {
        $this->removed[] = $label;
        $this->line("  ✓ Removed: <info>{$label}</info>");
    }

    /**
     * Track a skipped item.
     */
    private function skip(string $label): void
    {
        $this->skipped[] = $label;
    }

    private function isValidDomainName(string $raw): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]*(?:[\/\\\\][A-Za-z][A-Za-z0-9_-]*)*$/', $raw) === 1;
    }
}
