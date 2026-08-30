<?php

use Illuminate\Database\Eloquent\Model;
use Lvntr\StarterKit\Http\Responses\DatatableQueryBuilder;

/*
|--------------------------------------------------------------------------
| DatatableQueryBuilder::columns() — sütun meta + payload şekillendirme
|--------------------------------------------------------------------------
|
| columns() tanımlandığında response'a `columns` meta listesi eklenir ve
| ?columns=key1,key2 isteği satır verisini seçili sütunlara (+ alwaysInclude)
| indirger. columns() tanımsızken davranış birebir eskisi gibidir.
|
*/

class DatatableColumnsTestUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

function dtPayload(DatatableQueryBuilder $builder): array
{
    $json = $builder->response()->toResponse(request())->getData(true);

    return $json['data'];
}

beforeEach(function (): void {
    DatatableColumnsTestUser::query()->create([
        'name' => 'Ayşe Yıldız',
        'email' => 'ayse@example.com',
        'password' => 'secret-hash',
    ]);
});

it('includes the columns meta when columns() is configured', function (): void {
    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id', 'name'])
            ->defaultSort('id')
            ->columns([
                ['key' => 'name', 'locked' => true],
                'email',
                ['key' => 'created_at', 'label' => 'sk-common.created_at', 'visible' => false],
            ]),
    );

    expect($payload['columns'])->toBe([
        ['key' => 'name', 'locked' => true],
        ['key' => 'email'],
        ['key' => 'created_at', 'label' => 'sk-common.created_at', 'visible' => false],
    ]);
});

it('omits the columns meta when columns() is not configured', function (): void {
    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id'),
    );

    expect($payload)->not->toHaveKey('columns');
    expect($payload['data'][0])->toHaveKeys(['id', 'name', 'email']);
});

it('shapes rows down to the requested columns plus id', function (): void {
    request()->merge(['columns' => 'name']);

    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'email', 'created_at']),
    );

    expect($payload['data'][0])->toHaveKeys(['id', 'name']);
    expect($payload['data'][0])->not->toHaveKeys(['email', 'created_at', 'password']);
});

it('keeps alwaysInclude keys when shaping', function (): void {
    request()->merge(['columns' => 'name']);

    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'email'])
            ->alwaysInclude(['email']),
    );

    expect($payload['data'][0])->toHaveKeys(['id', 'name', 'email']);
});

it('returns the full payload when no columns param is sent', function (): void {
    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'email']),
    );

    expect($payload['data'][0])->toHaveKeys(['id', 'name', 'email']);
});

it('fails closed to alwaysInclude when every requested column key is unknown', function (): void {
    request()->merge(['columns' => 'password,totally_unknown']);

    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'email']),
    );

    // Geçerli sütun eşleşmediyse satır tam olarak dönmez; yalnız alwaysInclude kalır.
    expect($payload['data'][0])->toHaveKeys(['id']);
    expect($payload['data'][0])->not->toHaveKeys(['name', 'email', 'password']);
});

it('fails closed to alwaysInclude when the columns param is present but empty', function (): void {
    request()->merge(['columns' => '']);

    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'email']),
    );

    expect($payload['data'][0])->toHaveKeys(['id']);
    expect($payload['data'][0])->not->toHaveKeys(['name', 'email']);
});

it('keeps the top-level segment for dot-notation column keys', function (): void {
    request()->merge(['columns' => 'meta.plan']);

    $payload = dtPayload(
        DatatableQueryBuilder::for(DatatableColumnsTestUser::class)
            ->sortable(['id'])
            ->defaultSort('id')
            ->columns(['name', 'meta.plan']),
    );

    // meta.plan → "meta" üst anahtarı korunur (satırda yoksa sessizce düşer).
    expect($payload['data'][0])->toHaveKeys(['id']);
    expect($payload['data'][0])->not->toHaveKey('name');
});
