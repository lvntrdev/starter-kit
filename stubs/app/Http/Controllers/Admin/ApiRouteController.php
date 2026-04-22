<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ApiRoute\Actions\RegenerateApiDocsAction;
use App\Domain\ApiRoute\Actions\SyncApidogAction;
use App\Domain\ApiRoute\Actions\SyncPostmanAction;
use App\Domain\ApiRoute\Queries\ApiRouteListQuery;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Setting;
use Inertia\Inertia;
use Inertia\Response;

class ApiRouteController extends Controller
{
    public function index(ApiRouteListQuery $query): Response
    {
        $postmanKey = Setting::getValue('postman.api_key');
        $postmanWorkspace = Setting::getValue('postman.workspace_id');
        $apidogToken = Setting::getValue('apidog.access_token');
        $apidogProject = Setting::getValue('apidog.project_id');

        $settingsUrl = route('settings.index').'#api_clients';

        return Inertia::render('Admin/ApiRoutes/Index', [
            'routes' => $query->get(),
            'postman' => [
                'configured' => $this->filled($postmanKey) && $this->filled($postmanWorkspace),
                'collection_id' => Setting::getValue('postman.collection_id'),
                'workspace_id' => $postmanWorkspace,
                'settings_url' => $settingsUrl,
            ],
            'apidog' => [
                'configured' => $this->filled($apidogToken) && $this->filled($apidogProject),
                'project_id' => $apidogProject,
                'settings_url' => $settingsUrl,
            ],
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
