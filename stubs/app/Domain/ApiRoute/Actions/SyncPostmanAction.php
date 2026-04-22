<?php

namespace App\Domain\ApiRoute\Actions;

use App\Domain\ApiRoute\Support\OpenApiExporter;
use App\Domain\Shared\Actions\BaseAction;
use App\Exceptions\ApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Action: Export the Scramble OpenAPI document and push it to Postman as
 * a fresh collection.
 *
 * Workflow:
 *   1. {@see OpenApiExporter} produces the spec with form-data bodies.
 *   2. Any previous collection (tracked via `postman.collection_id`
 *      setting) is deleted to keep the workspace free of duplicates.
 *   3. The spec is imported through Postman's `POST /import/openapi`
 *      endpoint with `folderStrategy=Tags` so OpenAPI tags become
 *      Postman folders.
 *   4. The newly issued collection UID is persisted back to the
 *      `postman.collection_id` setting so the next invocation can
 *      replace this collection in turn.
 *
 * Configuration lives in the `postman` settings group (admin UI → Settings
 * → API Clients → Postman). The api_key is encrypted at rest; collection_id
 * is managed automatically by this action.
 */
class SyncPostmanAction extends BaseAction
{
    public function __construct(private OpenApiExporter $exporter) {}

    /**
     * @return array{
     *     name: string,
     *     uid: string,
     *     id: string,
     *     workspace_id: string,
     *     previous_uid: ?string,
     * }
     */
    public function execute(): array
    {
        $apiKey = (string) (Setting::getValue('postman.api_key') ?? '');
        $workspaceId = (string) (Setting::getValue('postman.workspace_id') ?? '');
        $previousCollectionId = (string) (Setting::getValue('postman.collection_id') ?? '');

        if ($apiKey === '' || $workspaceId === '') {
            throw ApiException::badRequest(
                'Postman integration is not configured. Set API key and workspace ID under Settings → API Clients → Postman.',
            );
        }

        $openapi = $this->exporter->export();

        if ($previousCollectionId !== '') {
            Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(30)
                ->delete("https://api.getpostman.com/collections/{$previousCollectionId}");
        }

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->timeout(60)
            ->post(
                "https://api.getpostman.com/import/openapi?workspace={$workspaceId}",
                [
                    'type' => 'json',
                    'input' => $openapi,
                    'options' => [
                        'folderStrategy' => 'Tags',
                    ],
                ],
            );

        if (! $response->successful()) {
            throw ApiException::badRequest(
                'Postman import failed ('.$response->status().'): '.substr($response->body(), 0, 500),
            );
        }

        $newUid = (string) $response->json('collections.0.uid', '');
        $newId = (string) $response->json('collections.0.id', '');
        $newName = (string) $response->json('collections.0.name', '');

        if ($newUid === '') {
            throw ApiException::serverError('Postman responded without a collection UID.');
        }

        Setting::setValue('postman.collection_id', $newUid);

        return [
            'name' => $newName,
            'uid' => $newUid,
            'id' => $newId,
            'workspace_id' => $workspaceId,
            'previous_uid' => $previousCollectionId !== '' ? $previousCollectionId : null,
        ];
    }
}
