<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

/**
 * Reset and reinstall the site: drop all tables, run migrations,
 * seed data with default settings, create Passport keys, and create the admin user.
 */
class SiteInstallCommand extends Command
{
    /**
     * Environments in which this destructive command may run.
     *
     * @var list<string>
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'setup'];

    /**
     * Environments that are always blocked, even if misconfigured into the
     * allow-list. Matched exactly or as a substring (case-insensitive) so
     * `prod`, `production`, `prod-eu`, `my-prod` all trip the guard.
     *
     * @var list<string>
     */
    private const BLOCKED_ENVIRONMENT_KEYWORDS = ['prod', 'production'];

    protected $signature = 'site:install';

    protected $description = 'Reset the database and reinstall the site with default settings';

    public function handle(): int
    {
        $environment = app()->environment();

        // 1. Show target database and environment up-front
        $this->newLine();
        $this->components->info('Site install target');
        $this->showTargetDetails($environment);
        $this->newLine();

        // 2a. Hard block — never run in anything that looks like production,
        // even if someone adds it to ALLOWED_ENVIRONMENTS by mistake.
        if ($this->isBlockedEnvironment($environment)) {
            $this->components->error(sprintf(
                'site:install is permanently blocked in environments matching [%s]. Current: [%s].',
                implode(', ', self::BLOCKED_ENVIRONMENT_KEYWORDS),
                $environment,
            ));

            return self::FAILURE;
        }

        // 2b. Environment guard — only `local` and `setup` may run this
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $this->components->error(sprintf(
                'site:install can only run in [%s] environments. Current: [%s].',
                implode(', ', self::ALLOWED_ENVIRONMENTS),
                $environment,
            ));

            return self::FAILURE;
        }

        if (! confirm('This will DROP all tables and reinstall from scratch. Continue?', default: false)) {
            $this->components->info('Installation cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();

        // 3. Fresh migrations
        spin(function () {
            return $this->callSilently('migrate:fresh', ['--force' => true]) === 0;
        }, 'Running migrate:fresh...');

        // 4. Seeders (roles, permissions, definitions, default settings)
        $this->runSeeders();

        // 5. Passport keys
        spin(function () {
            return $this->callSilently('passport:keys', ['--force' => true]) === 0;
        }, 'Installing Passport keys...');

        // 6. Default admin user
        $email = 'admin@demo.com';
        $password = 'password';

        spin(function () use ($email, $password) {
            $user = User::create([
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(RoleEnum::SystemAdmin->value);

            return true;
        }, "Creating admin user ({$email})...");

        $this->newLine();
        $this->components->info('Site installation completed successfully!');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=green>Admin Email</>', $email);
        $this->components->twoColumnDetail('<fg=green>Admin Password</>', $password);
        $this->components->twoColumnDetail('<fg=green>Admin Role</>', RoleEnum::SystemAdmin->value);

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Determine whether the given environment name matches any blocked
     * keyword (case-insensitive substring match). Guards against typos
     * like "Production", "prod-eu", "my-prod-01".
     */
    private function isBlockedEnvironment(string $environment): bool
    {
        $normalized = mb_strtolower($environment);

        foreach (self::BLOCKED_ENVIRONMENT_KEYWORDS as $keyword) {
            if (str_contains($normalized, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the environment and database connection details that this
     * command will operate against so the user can confirm the target.
     */
    private function showTargetDetails(string $environment): void
    {
        $connectionName = (string) Config::get('database.default');
        $connection = (array) Config::get("database.connections.{$connectionName}", []);

        $driver = (string) ($connection['driver'] ?? 'unknown');
        $database = (string) ($connection['database'] ?? 'unknown');
        $host = (string) ($connection['host'] ?? '');
        $port = (string) ($connection['port'] ?? '');
        $target = $host !== '' ? "{$host}".($port !== '' ? ":{$port}" : '') : $driver;

        $this->components->twoColumnDetail('<fg=cyan>Environment</>', $environment);
        $this->components->twoColumnDetail('<fg=cyan>Connection</>', "{$connectionName} ({$driver})");
        $this->components->twoColumnDetail('<fg=cyan>Database</>', $database);
        $this->components->twoColumnDetail('<fg=cyan>Host</>', $target);

        // Probe the connection so typos/unreachable hosts fail early with a clear error.
        try {
            DB::connection($connectionName)->getPdo();
            $this->components->twoColumnDetail('<fg=cyan>Status</>', '<fg=green>connected</>');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('<fg=cyan>Status</>', '<fg=red>unreachable</>');
            $this->components->error("Database connection failed: {$e->getMessage()}");
        }
    }

    /**
     * Discover and run seeders from the seeders directory in alphabetical order.
     * Files starting with "_" are auto-discovered and executed.
     */
    private function runSeeders(): void
    {
        $seederPath = database_path('seeders');
        $files = glob($seederPath.'/_*.php');
        sort($files);

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $displayName = preg_replace('/^_\d+_/', '', $className);
            $fqcn = 'Database\\Seeders\\'.$className;

            if (! class_exists($fqcn)) {
                $this->components->warn("Class [{$fqcn}] not found — skipping.");

                continue;
            }

            spin(function () use ($fqcn) {
                return $this->callSilently('db:seed', [
                    '--class' => $fqcn,
                    '--force' => true,
                ]) === 0;
            }, "Seeding: {$displayName}...");
        }
    }
}
