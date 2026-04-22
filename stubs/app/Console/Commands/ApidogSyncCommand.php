<?php

namespace App\Console\Commands;

use App\Domain\ApiRoute\Actions\SyncApidogAction;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

/**
 * CLI wrapper around {@see SyncApidogAction}.
 *
 * The same action powers the admin UI button on the API Routes page, so
 * both entry points share identical behavior and configuration.
 */
class ApidogSyncCommand extends Command
{
    protected $signature = 'apidog:sync';

    protected $description = 'Push the Scramble OpenAPI document to Apidog';

    public function handle(SyncApidogAction $action): int
    {
        try {
            $result = spin(
                fn () => $action->execute(),
                'Syncing OpenAPI spec to Apidog...',
            );
        } catch (ApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Apidog project synced successfully.');
        $this->components->twoColumnDetail('<fg=cyan>Project ID</>', $result['project_id']);
        $this->components->twoColumnDetail('<fg=cyan>Endpoints</>', (string) $result['endpoint_count']);

        return self::SUCCESS;
    }
}
