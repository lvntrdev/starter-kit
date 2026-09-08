<?php

namespace Lvntr\StarterKit\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\AiTool;
use LvntR\ApiDock\Attributes\ApiFeature;
use Lvntr\StarterKit\Domain\Shared\Services\DefinitionService;
use Lvntr\StarterKit\Http\Controllers\Concerns\ListsDefinitions;
use Lvntr\StarterKit\Http\Responses\ApiResponse;

#[ApiFeature(stability: 'stable')]
class DefinitionController extends Controller
{
    use ListsDefinitions;

    /**
     * Get all definitions (enum + DB), optionally filtered by keys.
     *
     * GET /api/v1/definitions
     * GET /api/v1/definitions?keys=userStatus,identityType
     */
    #[AiTool(name: 'list_definitions', description: 'Read the option lists (enum-backed and database-backed) the application uses for select fields, status columns and typed lookups.')]
    #[AiHint('Read-only lookup. Call it once and reuse the result: definitions change rarely and DefinitionService caches them for about an hour. Pass `keys` to fetch only the lists you need instead of the whole set.')]
    #[AiPitfall('`keys` is one comma-separated string, not a repeated query parameter: `?keys=userStatus,identityType`, never `?keys[]=userStatus`.', order: 10)]
    #[AiPitfall('Labels are resolved in the request locale, values are not. Key your logic off the value; the same list returns different labels per locale.', order: 20)]
    #[AiExample(
        name: 'Two definition lists',
        request: ['keys' => 'userStatus,identityType'],
        response: [
            'success' => true,
            'status' => 200,
            'message' => 'Operation successful.',
            'data' => [
                'userStatus' => [
                    ['value' => 'active', 'label' => 'Active', 'order' => 1, 'severity' => 'success', 'icon' => null, 'explanation' => null, 'visibility' => null],
                    ['value' => 'inactive', 'label' => 'Inactive', 'order' => 2, 'severity' => 'danger', 'icon' => null, 'explanation' => null, 'visibility' => null],
                ],
                'identityType' => [
                    ['value' => 'citizen', 'label' => 'Citizen', 'order' => 1, 'severity' => null, 'icon' => null, 'explanation' => null, 'visibility' => null],
                    ['value' => 'foreigner', 'label' => 'Foreigner', 'order' => 2, 'severity' => null, 'icon' => null, 'explanation' => null, 'visibility' => null],
                ],
            ],
        ],
    )]
    public function index(Request $request, DefinitionService $service): ApiResponse
    {
        return $this->listDefinitions($request, $service);
    }
}
