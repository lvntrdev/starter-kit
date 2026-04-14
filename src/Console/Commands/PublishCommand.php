<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\StarterKitServiceProvider;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class PublishCommand extends Command
{
    protected $signature = 'sk:publish
        {--tag=* : Tag(s) to publish (components, datatable, form, tabs, skeleton, ui, lang, config)}
        {--force : Overwrite existing files}
        {--destination= : Override destination base path (for testing or custom layouts)}';

    protected $description = 'Publish optional Starter Kit assets for customization';

    /** @var array<string, array{source: string, destination: string, label: string, group?: string}> */
    private const PUBLISHABLE_TAGS = [
        'components' => [
            'source' => 'resources/js/components',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit',
            'label' => 'All Vue Components',
        ],
        'datatable' => [
            'source' => 'resources/js/components/DatatableBuilder',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit/DatatableBuilder',
            'label' => 'DatatableBuilder (SkDatatable)',
            'group' => 'components',
        ],
        'form' => [
            'source' => 'resources/js/components/FormBuilder',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit/FormBuilder',
            'label' => 'FormBuilder (SkForm, SkFormInput, SkColorSelector)',
            'group' => 'components',
        ],
        'tabs' => [
            'source' => 'resources/js/components/TabBuilder',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit/TabBuilder',
            'label' => 'TabBuilder (SkTabs)',
            'group' => 'components',
        ],
        'skeleton' => [
            'source' => 'resources/js/components/Skeleton',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit/Skeleton',
            'label' => 'Skeleton (PageLoading, SkeletonBox, SkeletonCard, SkeletonTable, SkeletonText)',
            'group' => 'components',
        ],
        'ui' => [
            'source' => 'resources/js/components/ui',
            'destination' => 'resources/js/components/Lvntr-Starter-Kit/ui',
            'label' => 'UI (AppDialog, AvatarUpload, ConfirmDialogComponent, SkTag, ToastComponent)',
            'group' => 'components',
        ],
        'lang' => [
            'source' => 'resources/lang',
            'destination' => 'lang/vendor/starter-kit',
            'label' => 'Language Files (translations)',
        ],
        'config' => [
            'source' => 'config/starter-kit.php',
            'destination' => 'config/starter-kit.php',
            'label' => 'Configuration File',
        ],
    ];

    public function handle(): int
    {
        $tags = $this->option('tag');
        $force = (bool) $this->option('force');

        if (empty($tags)) {
            $tags = $this->promptForTags();
        }

        $totalCount = 0;

        foreach ($tags as $tag) {
            $result = $this->publishTag($tag, $force);

            if ($result === null) {
                return self::FAILURE;
            }

            $totalCount += $result;
        }

        $this->newLine();

        if ($totalCount > 0) {
            $this->components->info("Published {$totalCount} file(s) in total.");
        } else {
            $this->components->warn('No files published. Files already exist (use --force to overwrite).');
        }

        return self::SUCCESS;
    }

    /**
     * Prompt the user to select tag(s) interactively.
     *
     * @return list<string>
     */
    private function promptForTags(): array
    {
        $category = select(
            label: 'What would you like to publish?',
            options: [
                'components' => 'Vue Components (all or pick individual)',
                'lang' => 'Language Files (translations)',
                'config' => 'Configuration File',
            ],
        );

        if ($category !== 'components') {
            return [$category];
        }

        $componentTags = collect(self::PUBLISHABLE_TAGS)
            ->filter(fn (array $config) => isset($config['group']) && $config['group'] === 'components')
            ->mapWithKeys(fn (array $config, string $key) => [$key => $config['label']])
            ->prepend('All Components', 'components')
            ->all();

        $selected = multiselect(
            label: 'Which component(s) would you like to publish?',
            options: $componentTags,
            required: true,
        );

        // If "components" (all) is selected, just publish all
        if (in_array('components', $selected)) {
            return ['components'];
        }

        return $selected;
    }

    /**
     * Publish a single tag. Returns file count or null on failure.
     */
    private function publishTag(string $tag, bool $force): ?int
    {
        if (! isset(self::PUBLISHABLE_TAGS[$tag])) {
            $this->components->error("Unknown tag: {$tag}");
            $this->line('Available tags: '.implode(', ', array_keys(self::PUBLISHABLE_TAGS)));

            return null;
        }

        $config = self::PUBLISHABLE_TAGS[$tag];
        $source = StarterKitServiceProvider::basePath($config['source']);
        $destination = $this->resolveDestination($config['destination']);

        if (! file_exists($source)) {
            $this->components->error("Source not found: {$source}");

            return null;
        }

        $files = new Filesystem;
        $count = 0;

        $this->components->task("Publishing {$config['label']}", function () use ($files, $source, $destination, $force, &$count) {
            if ($files->isDirectory($source)) {
                $count = $this->publishDirectory($files, $source, $destination, $force);
            } else {
                if ($force || ! $files->exists($destination)) {
                    $dir = dirname($destination);
                    if (! $files->isDirectory($dir)) {
                        $files->makeDirectory($dir, 0755, true);
                    }
                    $files->copy($source, $destination);
                    $count = 1;
                }
            }
        });

        if ($count > 0) {
            $this->line("  → {$count} file(s) → {$config['destination']}");
        }

        return $count;
    }

    /**
     * Resolve the absolute destination path.
     *
     * When --destination is not provided, falls back to base_path() which
     * preserves the historical behavior. When provided, the tag's relative
     * destination is resolved under the override root so tests (or custom
     * layouts) can publish into an isolated directory without touching the
     * project's source tree.
     */
    private function resolveDestination(string $relative): string
    {
        $override = $this->option('destination');

        if (! is_string($override) || $override === '') {
            return base_path($relative);
        }

        $root = rtrim($override, DIRECTORY_SEPARATOR);

        return $root.DIRECTORY_SEPARATOR.ltrim($relative, DIRECTORY_SEPARATOR);
    }

    /**
     * Recursively publish a directory.
     */
    private function publishDirectory(Filesystem $files, string $source, string $destination, bool $force): int
    {
        $count = 0;

        if (! $files->isDirectory($destination)) {
            $files->makeDirectory($destination, 0755, true);
        }

        foreach ($files->allFiles($source, true) as $file) {
            $targetPath = $destination.DIRECTORY_SEPARATOR.$file->getRelativePathname();
            $targetDir = dirname($targetPath);

            if (! $files->isDirectory($targetDir)) {
                $files->makeDirectory($targetDir, 0755, true);
            }

            if (! $force && $files->exists($targetPath)) {
                continue;
            }

            $files->copy($file->getPathname(), $targetPath);
            $count++;
        }

        return $count;
    }
}
