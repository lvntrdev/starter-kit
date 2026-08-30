<?php

namespace Lvntr\StarterKit\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Lvntr\StarterKit\Domain\ApiClient\Actions\CreatePersonalAccessTokenAction;
use Lvntr\StarterKit\Domain\ApiClient\Actions\RevokeApiTokenAction;
use Lvntr\StarterKit\Http\Requests\Admin\ApiToken\StoreApiTokenRequest;
use Lvntr\StarterKit\Http\Resources\Admin\ApiToken\ApiTokenResource;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;

/**
 * Admin panelden Personal Access Token (PAT) yönetimi.
 *
 * Security: access_token yalnızca store() response'unda bir kez döner.
 * Sonraki liste/görüntüleme response'larında null gelir — bu kasıtlıdır.
 *
 * Privilege escalation koruması: destroy() ApiTokenPolicy::delete()
 * üzerinden gider. Kullanıcı yalnızca kendi token'ını ya da
 * api-tokens.delete izniyle başkasının token'ını revoke edebilir.
 */
class ApiTokenController extends Controller
{
    /**
     * Token listesini JSON olarak döndür (DataTable için).
     *
     * Kısmi erişim modeli:
     *   - api-tokens.read izni varsa tüm token'lar listelenir (admin görünümü).
     *   - İzin yoksa yalnızca $request->user()'ın kendi token'ları listelenir.
     *     Bu, token leak durumunda kullanıcının kendi token'larını iptal edebilmesini sağlar.
     */
    public function dtApi(Request $request): ApiResponse
    {
        $user = $request->user();

        $query = Passport::token()
            ->newQuery()
            ->where('revoked', false)
            ->whereHas('client', fn ($q) => $q->whereJsonContains('grant_types', 'personal_access'));

        if (! $user->can('api-tokens.read')) {
            $query->where('user_id', $user->id);
        }

        // NOT: Passport\Token::user() ilişkisi $this->client->provider erişimi
        // nedeniyle Eloquent eager-load tip çıkarımı sırasında null property
        // hatası fırlatır. Bu yüzden ->with(['user']) kullanılmaz; user'lar
        // ApiTokenResource::collection() içinde tek query ile önçekilir.
        return DatatableQueryBuilder::for($query)
            ->sortable(['id', 'name', 'created_at'])
            ->resource(ApiTokenResource::class)
            ->response();
    }

    /**
     * Yeni Personal Access Token oluştur.
     *
     * UYARI: Bu response'ta `access_token` alanı bir kez dönecek.
     * Frontend'in "kopyala ve sakla" UX göstermesi zorunludur.
     *
     * Token sonraki request'lerde gizlenir (DB'de hash olarak saklanır).
     *
     * Privilege escalation koruması: Token yalnızca $request->user() adına
     * üretilir. user_id body alanı kabul edilmez — StoreApiTokenRequest'e
     * eklenmiş olsa dahi bu controller onu kullanmaz.
     */
    public function store(
        StoreApiTokenRequest $request,
        CreatePersonalAccessTokenAction $action,
    ): ApiResponse {
        $result = $action->execute(
            user: $request->user(),
            name: $request->validated('name'),
            scopes: $request->validated('scopes', []),
        );

        // ApiTokenResource'a access_token ekle — sadece bu response'ta
        $resource = new ApiTokenResource($result->token);
        $resource->accessToken = $result->accessToken;

        return to_api($resource, __('sk-api-tokens.created'))
            ->status(201)
            ->headers([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Pragma' => 'no-cache',
            ]);
    }

    /**
     * Token'ı revoke et.
     *
     * Policy kontrol edilir — privilege escalation riski burada yönetilir.
     */
    public function destroy(Token $token, RevokeApiTokenAction $action): RedirectResponse
    {
        Gate::authorize('delete', $token);

        $action->execute($token);

        return back()->with('success', __('sk-api-tokens.revoked'));
    }
}
