<?php

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Response / Exception Handling Contract Tests
|--------------------------------------------------------------------------
|
| Pins the shape of the shared response envelope (success, status, message,
| data, errors, meta, trace_id, debug), the Laravel exception → envelope
| mapping, trace-id correlation between success and error branches, and
| edge cases like 204 No Content and Retry-After propagation.
|
*/

beforeEach(function () {
    Route::middleware('api')->group(function () {
        Route::get('/api/v1/_test/success', fn () => to_api(['id' => 1]));
        Route::get('/api/v1/_test/success-message', fn () => to_api(['id' => 1], 'Operation custom.'));
        Route::get('/api/v1/_test/created', fn () => to_api(['id' => 1], 'Created.', 201));
        Route::get('/api/v1/_test/nocontent', fn () => to_api(status: 204));
        Route::get('/api/v1/_test/paginated', fn () => to_api(User::query()->paginate(5)));
        Route::get('/api/v1/_test/simple-paginated', fn () => to_api(User::query()->simplePaginate(5)));

        Route::get('/api/v1/_test/api-exception', fn () => throw ApiException::notFound('Custom not found.'));
        Route::get('/api/v1/_test/model-not-found', fn () => User::findOrFail(-1));
        Route::get('/api/v1/_test/abort-400', fn () => abort(400, 'internal detail should not leak'));
        Route::post('/api/v1/_test/validate', fn (Request $r) => $r->validate(['email' => 'required|email']));
        Route::get('/api/v1/_test/server-error', fn () => throw new RuntimeException('boom internal'));
    });
});

it('returns standard success envelope with a trace id header and body field', function () {
    $response = $this->getJson('/api/v1/_test/success');

    $response->assertOk()
        ->assertJsonStructure(['success', 'status', 'message', 'data', 'trace_id'])
        ->assertJson([
            'success' => true,
            'status' => 200,
            'message' => 'Operation successful.',
            'data' => ['id' => 1],
        ]);

    $headerTraceId = $response->headers->get('X-Request-ID');
    expect($headerTraceId)->not->toBeNull();
    expect($response->json('trace_id'))->toBe($headerTraceId);
});

it('honours a caller-supplied success message', function () {
    $this->getJson('/api/v1/_test/success-message')
        ->assertJson(['message' => 'Operation custom.']);
});

it('returns a 201 envelope for created resources', function () {
    $this->getJson('/api/v1/_test/created')
        ->assertStatus(201)
        ->assertJson([
            'success' => true,
            'status' => 201,
            'message' => 'Created.',
            'data' => ['id' => 1],
        ]);
});

it('emits an empty body for 204 and still attaches the trace id header', function () {
    $response = $this->getJson('/api/v1/_test/nocontent');

    $response->assertNoContent();
    expect($response->getContent())->toBe('');
    expect($response->headers->get('X-Request-ID'))->not->toBeNull();
});

it('attaches pagination meta for LengthAwarePaginator data', function () {
    User::factory()->count(3)->create();

    $this->getJson('/api/v1/_test/paginated')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('supports simplePaginate without raising a type error', function () {
    User::factory()->count(3)->create();

    $this->getJson('/api/v1/_test/simple-paginated')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'has_more'],
        ]);
});

it('maps ApiException throws to the shared envelope', function () {
    $response = $this->getJson('/api/v1/_test/api-exception');

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'status' => 404,
            'message' => 'Custom not found.',
            'data' => null,
        ])
        ->assertJsonStructure(['trace_id']);
});

it('uses the short model class name for ModelNotFoundException', function () {
    $this->getJson('/api/v1/_test/model-not-found')
        ->assertStatus(404)
        ->assertJson(['message' => 'User not found.']);
});

it('drops the raw abort() message and emits the default one for the status code', function () {
    $response = $this->getJson('/api/v1/_test/abort-400');

    $response->assertStatus(400);
    expect($response->json('message'))->toBe('Bad request.');
    expect($response->json('message'))->not->toBe('internal detail should not leak');
});

it('only surfaces the errors field on 422 validation errors', function () {
    $success = $this->getJson('/api/v1/_test/success');
    expect($success->json())->not->toHaveKey('errors');

    $this->postJson('/api/v1/_test/validate', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['email']])
        ->assertJson([
            'success' => false,
            'status' => 422,
            'message' => 'Validation error.',
        ]);
});

it('hides internal exception messages when APP_DEBUG is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_test/server-error');

    $response->assertStatus(500)
        ->assertJson(['message' => 'A server error occurred.'])
        ->assertJsonMissing(['message' => 'boom internal']);

    expect($response->json())->not->toHaveKey('debug');
});

it('exposes debug details only when APP_DEBUG is on', function () {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/v1/_test/server-error');

    $response->assertStatus(500);
    expect($response->json('debug.exception'))->toBe(RuntimeException::class);
});

it('echoes a sanitised client correlation id but never trusts it as the trace id', function () {
    $response = $this->getJson(
        '/api/v1/_test/success',
        ['X-Request-ID' => 'client-abc_123.v1']
    );

    expect($response->headers->get('X-Correlation-ID'))->toBe('client-abc_123.v1');
    expect($response->headers->get('X-Request-ID'))->not->toBe('client-abc_123.v1');
});

it('rejects malformed client request ids', function () {
    $response = $this->getJson(
        '/api/v1/_test/success',
        ['X-Request-ID' => 'bad id with spaces']
    );

    expect($response->headers->get('X-Correlation-ID'))->toBeNull();
});

it('shares one trace id between AssignTraceId middleware and the exception handler', function () {
    $response = $this->getJson('/api/v1/_test/api-exception');

    $header = $response->headers->get('X-Request-ID');
    expect($header)->not->toBeNull();
    expect($response->json('trace_id'))->toBe($header);
});

it('propagates Retry-After headers from ThrottleRequestsException', function () {
    Route::middleware(['api', 'throttle:1,1'])->get(
        '/api/v1/_test/throttle',
        fn () => to_api(['ok' => true])
    );

    $this->getJson('/api/v1/_test/throttle')->assertOk();
    $response = $this->getJson('/api/v1/_test/throttle');

    $response->assertStatus(429)
        ->assertJson(['success' => false, 'status' => 429]);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});
