<?php

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;

class EnvSyncCommand extends Command
{
    use RefusesPackageSourceTree;

    protected $signature = 'env:sync {--reverse : Check .env for missing keys from .env.example}';

    protected $description = 'Sync .env keys to .env.example (with blank values for sensitive keys)';

    /**
     * Patterns for keys that may contain sensitive values.
     * Values of these keys will not be copied to .env.example.
     */
    private array $sensitivePatterns = [
        'KEY', 'SECRET', 'PASSWORD', 'TOKEN', 'HASH',
        'APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD',
        'MAIL_PASSWORD', 'AWS_SECRET',
    ];

    public function handle(): int
    {
        if ($this->isPackageSourceTree()) {
            return $this->renderPackageSourceTreeStop();
        }

        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        if (! file_exists($envPath)) {
            $this->error('.env file not found!');

            return self::FAILURE;
        }

        if (! file_exists($examplePath)) {
            $this->error('.env.example file not found!');

            return self::FAILURE;
        }

        if ($this->option('reverse')) {
            return $this->checkReverse($envPath, $examplePath);
        }

        return $this->syncToExample($envPath, $examplePath);
    }

    private function syncToExample(string $envPath, string $examplePath): int
    {
        $envKeys = $this->parseKeys($envPath);
        $exampleKeys = $this->parseKeys($examplePath);
        $exampleContent = file_get_contents($examplePath);

        $missingKeys = array_diff(array_keys($envKeys), array_keys($exampleKeys));

        if (empty($missingKeys)) {
            $this->info('✅ .env.example is up to date — no missing keys.');

            return self::SUCCESS;
        }

        // Idempotency: only add the "# Auto-added keys" header once. A file that
        // already carries the block from a previous run gets new keys appended
        // without a duplicate header — otherwise every run that finds missing
        // keys stacks another dated header on top of the last one.
        $hasExistingBlock = str_contains($exampleContent, '# Auto-added keys');

        $newLines = $hasExistingBlock ? "\n" : "\n# Auto-added keys (".now()->format('Y-m-d').")\n";

        foreach ($missingKeys as $key) {
            $value = $this->isSensitive($key) ? '' : $envKeys[$key];
            $newLines .= "{$key}={$value}\n";
            $this->line("  <fg=green>+</> {$key}");
        }

        file_put_contents($examplePath, rtrim($exampleContent).$newLines);

        $this->info(sprintf('✅ %d key(s) added to .env.example.', count($missingKeys)));

        return self::SUCCESS;
    }

    private function checkReverse(string $envPath, string $examplePath): int
    {
        $envKeys = $this->parseKeys($envPath);
        $exampleKeys = $this->parseKeys($examplePath);

        $missingInEnv = array_diff(array_keys($exampleKeys), array_keys($envKeys));

        if (empty($missingInEnv)) {
            $this->info('✅ Your .env file is up to date — all keys from .env.example are present.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('⚠️  Your .env file is missing %d key(s):', count($missingInEnv)));
        foreach ($missingInEnv as $key) {
            $this->line("  <fg=red>-</> {$key}");
        }

        return self::FAILURE;
    }

    /**
     * Parse the .env file and return a key=>value array.
     * Skips comment lines and empty lines.
     */
    private function parseKeys(string $path): array
    {
        $keys = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $keys[trim($key)] = trim($value);
        }

        return $keys;
    }

    private function isSensitive(string $key): bool
    {
        foreach ($this->sensitivePatterns as $pattern) {
            if (str_contains(strtoupper($key), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
