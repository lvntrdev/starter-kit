<?php

namespace Lvntr\StarterKit\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Lvntr\StarterKit\Domain\ApiClient\Actions\CreateApiClientAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\RevokeApiClientAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\UpdateApiClientAction;
use Lvntr\StarterKit\Http\Requests\Admin\ApiClient\StoreApiClientRequest;
use Lvntr\StarterKit\Http\Requests\Admin\ApiClient\UpdateApiClientRequest;
use Lvntr\StarterKit\Http\Resources\Admin\ApiClient\ApiClientResource;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;

/**
 * Admin panelden Passport OAuth istemcisi yönetimi.
 *
 * Bu controller kasıtlı olarak ince tutulmuştur:
 *   - Doğrulama → FormRequest
 *   - İş mantığı → Action
 *   - Yetkilendirme → Policy (Gate::authorize) + FormRequest::authorize
 *
 * Security: secret yalnızca store() response'unda plaintext döner.
 * Diğer tüm response'larda ApiClientResource::plain_secret null gelir.
 */
class ApiClientController extends Controller
{
    /**
     * İstemci listesini JSON olarak döndür (DataTable için).
     */
    public function dtApi(Request $request): ApiResponse
    {
        Gate::authorize('viewAny', Client::class);

        $query = Passport::client()
            ->newQuery()
            ->where('revoked', false);

        return DatatableQueryBuilder::for($query)
            ->sortable(['id', 'name', 'created_at'])
            ->resource(ApiClientResource::class)
            ->response();
    }

    /**
     * Yeni OAuth istemcisi oluştur.
     *
     * UYARI: Bu response'ta `plain_secret` alanı bir kez dönecek.
     * Frontend'in "kopyala ve sakla" UX göstermesi zorunludur.
     */
    public function store(
        StoreApiClientRequest $request,
        CreateApiClientAction $action,
    ): ApiResponse {
        $client = $action->execute(
            name: $request->validated('name'),
            grantType: $request->validated('grant_type'),
            redirectUris: $request->validated('redirect_uris', []),
        );

        return to_api(
            new ApiClientResource($client),
            __('sk-api-clients.created'),
        )->status(201)->headers([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * İstemci verilerini JSON olarak döndür (form için).
     */
    public function data(Client $client): ApiResponse
    {
        Gate::authorize('view', $client);

        return to_api(new ApiClientResource($client));
    }

    /**
     * İstemciyi güncelle.
     */
    public function update(
        UpdateApiClientRequest $request,
        Client $client,
        UpdateApiClientAction $action,
    ): ApiResponse {
        Gate::authorize('update', $client);

        $updated = $action->execute(
            client: $client,
            name: $request->validated('name'),
            redirectUris: $request->validated('redirect_uris', []),
        );

        return to_api(
            new ApiClientResource($updated),
            __('sk-api-clients.updated'),
        );
    }

    /**
     * İstemciyi ve tüm bağlı token'larını revoke et.
     */
    public function destroy(Client $client, RevokeApiClientAction $action): RedirectResponse
    {
        Gate::authorize('delete', $client);

        $action->execute($client);

        return back()->with('success', __('sk-api-clients.revoked'));
    }
}
