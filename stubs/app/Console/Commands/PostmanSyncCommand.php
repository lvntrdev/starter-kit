<?php

namespace App\Console\Commands;

use App\Domain\ApiRoute\Actions\SyncPostmanAction;
use App\Exceptions\ApiException;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

/**
 * CLI wrapper around {@see SyncPostmanAction}.
 *
 * The same action powers the admin UI button on the API Routes page, so
 * both entry points share identical behavior and configuration.
 */
class PostmanSyncCommand extends Command
{
    protected $signature = 'postman:sync';

    protected $description = 'Push the Scramble OpenAPI document to Postman as a fresh collection';

    public function handle(SyncPostmanAction $action): int
    {
        try {
            $result = spin(
                fn () => $action->execute(),
                'Syncing OpenAPI spec to Postman...',
            );
        } catch (ApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Postman collection synced successfully.');
        $this->components->twoColumnDetail('<fg=cyan>Collection</>', $result['name'] !== '' ? $result['name'] : '—');
        $this->components->twoColumnDetail('<fg=cyan>UID</>', $result['uid']);
        $this->components->twoColumnDetail('<fg=cyan>ID</>', $result['id']);
        $this->components->twoColumnDetail('<fg=cyan>Workspace</>', $result['workspace_id']);

        return self::SUCCESS;
    }
}
