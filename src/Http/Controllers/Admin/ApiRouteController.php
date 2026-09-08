<?php

namespace Lvntr\StarterKit\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\RegenerateApiDocsAction;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncApidogAction;
use Lvntr\StarterKit\Domain\ApiRoute\Actions\SyncPostmanAction;
use Lvntr\StarterKit\Domain\ApiRoute\Queries\ApiRouteListQuery;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\Http\Responses\ApiResponse;

class ApiRouteController extends Controller
{
    public function index(ApiRouteListQuery $query, SettingService $settings): Response
    {
        $postmanKey = $settings->getValue('postman.api_key');
        $postmanWorkspace = $settings->getValue('postman.workspace_id');
        $apidogToken = $settings->getValue('apidog.access_token');
        $apidogProject = $settings->getValue('apidog.project_id');

        return Inertia::render('Admin/ApiRoutes/Index', [
            'routes' => $query->get(),
            'postman' => [
                'configured' => $this->filled($postmanKey) && $this->filled($postmanWorkspace),
                'workspace_id' => $postmanWorkspace,
                'api_key_is_set' => $this->filled($postmanKey),
            ],
            'apidog' => [
                'configured' => $this->filled($apidogToken) && $this->filled($apidogProject),
                'project_id' => $apidogProject,
                'access_token_is_set' => $this->filled($apidogToken),
            ],
            // Resolved from the named route rather than hardcoded in the Vue
            // component: `api-dock.route_prefix` is configurable, so a consumer
            // serving the panel at e.g. `internal/docs` would otherwise get a
            // 404 from this screen's documentation button. Null when api-dock
            // is absent or disabled — the button is then not rendered at all.
            'api_docs_url' => Route::has('api-dock.docs') ? route('api-dock.docs') : null,
        ]);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    public function regenerateDocs(RegenerateApiDocsAction $action): ApiResponse
    {
        $output = $action->execute();

        return to_api(['output' => $output], 'API documentation regenerated successfully.');
    }

    public function syncPostman(SyncPostmanAction $action): ApiResponse
    {
        $result = $action->execute();

        return to_api($result, 'Postman collection synced successfully.');
    }

    public function syncApidog(SyncApidogAction $action): ApiResponse
    {
        $result = $action->execute();

        return to_api($result, 'Apidog project synced successfully.');
    }
}
