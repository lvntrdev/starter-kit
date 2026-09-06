<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Disposable fixture seeder for the Playwright E2E smoke suite.
 *
 * NOT part of the shipped consumer scaffold (`stubs/`) and NOT wired into
 * `DatabaseSeeder`. It is invoked directly by the E2E test harness against a
 * throwaway SQLite fixture database only.
 *
 * The email/password below are fixed TEST-ONLY defaults, overridable via
 * env. They exist solely to let the smoke test log in against a disposable
 * fixture database and must never be used against a real/production
 * database.
 */
class E2EAdminSeeder extends Seeder
{
    /**
     * Create (or find) the E2E admin user and grant the system_admin role.
     *
     * Idempotent: safe to run repeatedly against the same fixture — the user
     * is looked up by email, and the role assignment is synced rather than
     * appended.
     */
    public function run(): void
    {
        $email = env('E2E_ADMIN_EMAIL', 'e2e-admin@example.test');
        $password = env('E2E_ADMIN_PASSWORD', 'e2e-test-password-only');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'E2E',
                'last_name' => 'Admin',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'status' => 'active',
            ]
        );

        // Keep credentials in sync with env on reruns (fixture DB may be reused).
        $user->forceFill([
            'password' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->syncRoles(RoleEnum::SystemAdmin->value);
    }
}
