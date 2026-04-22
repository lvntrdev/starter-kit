<?php

namespace App\Domain\ApiRoute\Actions;

use App\Domain\ApiRoute\Support\OpenApiExporter;
use App\Domain\Shared\Actions\BaseAction;
use App\Exceptions\ApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Action: Export the Scramble OpenAPI document and push it to Apidog.
 *
 * Uses Apidog's `POST /v1/projects/{projectId}/import-openapi` endpoint
 * (api-version 2024-03-28) with the OpenAPI JSON passed inline under
 * `input`. Unlike Postman, Apidog merges the spec into an existing
 * project rather than creating a fresh collection, so we rely on
 * OVERWRITE_EXISTING behavior — endpoints present in the pushed spec
 * replace their Apidog counterparts, and removed endpoints stay orphaned
 * until the user enables `deleteUnmatchedResources` manually.
 *
 * Configuration lives in the `apidog` settings group (admin UI → Settings
 * → API Clients → Apidog). The access_token is encrypted at rest.
 */
class SyncApidogAction extends BaseAction
{
    private const API_VERSION = '2024-03-28';

    public function __construct(private OpenApiExporter $exporter) {}

    /**
     * @return array{
     *     project_id: string,
     *     endpoint_count: int,
     * }
     */
    public function execute(): array
    {
        $accessToken = (string) (Setting::getValue('apidog.access_token') ?? '');
        $projectId = (string) (Setting::getValue('apidog.project_id') ?? '');

        if ($accessToken === '' || $projectId === '') {
            throw ApiException::badRequest(
                'Apidog integration is not configured. Set access token and project ID under Settings → API Clients → Apidog.',
            );
        }

        $openapi = $this->exporter->export();

        // Apidog's inline input is accepted as a stringified spec (not a
        // nested object), so we encode the array explicitly.
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'X-Apidog-Api-Version' => self::API_VERSION,
        ])
            ->acceptJson()
            ->timeout(60)
            ->post(
                "https://api.apidog.com/v1/projects/{$projectId}/import-openapi",
                [
                    'input' => json_encode($openapi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'options' => [
                        'endpointOverwriteBehavior' => 'OVERWRITE_EXISTING',
                        'schemaOverwriteBehavior' => 'OVERWRITE_EXISTING',
                        'updateFolderOfChangedEndpoint' => true,
                        'prependBasePath' => false,
                    ],
                ],
            );

        if (! $response->successful()) {
            throw ApiException::badRequest(
                'Apidog import failed ('.$response->status().'): '.substr($response->body(), 0, 500),
            );
        }

        return [
            'project_id' => $projectId,
            'endpoint_count' => count((array) ($openapi['paths'] ?? [])),
        ];
    }
}
