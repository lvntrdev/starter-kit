<?php

namespace App\Console\Commands;

use Database\Seeders\_01_RolePermissionSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

class SeedPermissionsCommand extends Command
{
    protected $signature = 'sk:seed-permissions
        {--fresh : Reset all role permissions to match config exactly}';

    protected $description = 'Seed roles and permissions from config';

    public function handle(): int
    {
        $seeder = new _01_RolePermissionSeeder;
        $seeder->setCommand($this);

        if ($this->option('fresh')) {
            $this->components->warn('Fresh mode: all roles will be synced to match config exactly.');
            $seeder->fresh = true;
        }

        spin(fn () => $seeder->run(), 'Seeding permissions...');

        $this->newLine();
        $this->components->info('Permissions seeded successfully.');

        return self::SUCCESS;
    }
}
