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
 *   1. {@see OpenApiExporter} produces the spec.
 *   2. The spec is imported through Postman's `POST /import/openapi`
 *      endpoint with `folderStrategy=Tags` so OpenAPI tags become
 *      Postman folders.
 *   3. On success, the newly issued UID is persisted to the
 *      `postman.collection_id` setting.
 *   4. The previous collection (if any) is deleted as a best-effort
 *      cleanup. Keeping the delete *after* a confirmed import means a
 *      failed push never leaves the workspace without a working
 *      collection — the old one stays until the new one is proven good.
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

        // 1. Import the fresh spec first. If this fails, the previous
        //    collection is still intact — the user is never left without
        //    a working Postman collection because of a transient API
        //    error, invalid credentials, or a Postman-side outage.
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

        // 2. Persist the new UID before touching the old collection — if
        //    the process dies between the delete call and a setting
        //    write, we would forget which collection is live.
        Setting::setValue('postman.collection_id', $newUid);

        // 3. Best-effort cleanup of the previous collection. A failure
        //    here only leaves an orphan in the workspace; the new one is
        //    already pinned in settings and functional.
        if ($previousCollectionId !== '' && $previousCollectionId !== $newUid) {
            Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->timeout(30)
                ->delete("https://api.getpostman.com/collections/{$previousCollectionId}");
        }

        return [
            'name' => $newName,
            'uid' => $newUid,
            'id' => $newId,
            'workspace_id' => $workspaceId,
            'previous_uid' => $previousCollectionId !== '' ? $previousCollectionId : null,
        ];
    }
}
