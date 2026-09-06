<?php

/*
|--------------------------------------------------------------------------
| MakeDomainCommand — Domain Generator v2 Tests
|--------------------------------------------------------------------------
|
| Bu test suite'i şunları doğrular:
|
|  1. Backward compat: flag'siz çağrı yeni opt-in dosyalarını üretmez.
|  2. --with-policy: Policy dosyası üretilir.
|  3. --with-factory: Factory dosyası üretilir.
|  4. --with-seeder: Seeder dosyası üretilir.
|  5. --with-test: Pest feature test dosyası üretilir.
|  6. --with= kısa formu birden fazla flag'i aktif eder.
|  7. --relations= ile belongsTo, hasMany, morphTo relation metodları
|     üretilir; migration'a uygun kolonlar eklenir.
|  8. Idempotency: varolan dosya üzerine yazmaz, uyarı gösterir.
|  9. Circular dependency: A → A self-referans skip edilir.
| 10. Duplicate relation: tekrar eden relation skip edilir.
|
| Artisan PendingCommand'ın expectsQuestion() / confirm() mockları
| interactive prompt'ları bypass etmek için kullanılır.
|
*/

/**
 * Tüm testlerde ortak argümanlar — interactive prompt'ları bypass eden.
 * Komutun confirm() çağrısı da var; expectsConfirmation() ile handle ediyoruz.
 *
 * @return array<string, mixed>
 */
function baseArgs(string $name, string $extraFields = 'title:string'): array
{
    return [
        'name' => $name,
        '--fields' => $extraFields,
        '--id-type' => 'id',
        '--no-admin' => true,
        '--no-api' => true,
        '--no-events' => true,
        '--no-soft-deletes' => true,
        '--vue' => 'none',
    ];
}

// ── BACKWARD COMPAT ────────────────────────────────────────────────────────

it('backward compat: flag\'siz çağrı policy, seeder ve test üretmez', function () {
    $this->artisan('make:sk-domain', baseArgs('BackCompatTest'))
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    expect(file_exists(app_path('Policies/BackCompatTestPolicy.php')))->toBeFalse();
    expect(file_exists(database_path('seeders/BackCompatTestSeeder.php')))->toBeFalse();
    expect(file_exists(base_path('tests/Feature/BackCompatTestTest.php')))->toBeFalse();
});

// ── --with-policy ──────────────────────────────────────────────────────────

it('--with-policy generates a Policy class', function () {
    $args = array_merge(baseArgs('Article'), ['--with-policy' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $policyPath = app_path('Policies/ArticlePolicy.php');
    expect(file_exists($policyPath))->toBeTrue();

    $content = file_get_contents($policyPath);
    expect($content)->toContain('class ArticlePolicy');
    expect($content)->toContain('use HandlesAuthorization');
    expect($content)->toContain('public function viewAny');
    expect($content)->toContain('public function create');
    expect($content)->toContain('public function update');
    expect($content)->toContain('public function delete');
});

// ── --with-factory ─────────────────────────────────────────────────────────

it('--with-factory generates a Factory class', function () {
    $args = array_merge(baseArgs('Product', 'name:string,price:decimal'), ['--with-factory' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $factoryPath = database_path('factories/ProductFactory.php');
    expect(file_exists($factoryPath))->toBeTrue();

    $content = file_get_contents($factoryPath);
    expect($content)->toContain('class ProductFactory extends Factory');
    expect($content)->toContain("'name'");
    expect($content)->toContain("'price'");
    expect($content)->toContain('fake()->');
});

// ── --with-seeder ──────────────────────────────────────────────────────────

it('--with-seeder generates a Seeder class', function () {
    $args = array_merge(baseArgs('Category'), ['--with-seeder' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $seederPath = database_path('seeders/CategorySeeder.php');
    expect(file_exists($seederPath))->toBeTrue();

    $content = file_get_contents($seederPath);
    expect($content)->toContain('class CategorySeeder extends Seeder');
    expect($content)->toContain('Category::factory()->count(10)->create()');
});

// ── --with-test ────────────────────────────────────────────────────────────

it('--with-test generates a Pest feature test file', function () {
    $args = array_merge(baseArgs('Order', 'total:decimal'), ['--with-test' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $testPath = base_path('tests/Feature/OrderTest.php');
    expect(file_exists($testPath))->toBeTrue();

    $content = file_get_contents($testPath);
    expect($content)->toContain("it('can create a order'");
    expect($content)->toContain("it('can update a order'");
    expect($content)->toContain("it('can delete a order'");
    expect($content)->toContain('Order::factory()');
});

// ── --with-permissions ─────────────────────────────────────────────────────

it('--with-permissions registers the resource in permission-resources.php', function () {
    $configPath = config_path('permission-resources.php');
    copy(dirname(__DIR__, 3).'/stubs/config/permission-resources.php', $configPath);

    $args = array_merge(baseArgs('PermInvoice'), ['--with-permissions' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $content = file_get_contents($configPath);
    expect($content)->toContain("'perm_invoices' => null, // all abilities");
    expect($content)->toContain("'perm_invoices' => ['en' => 'Perm Invoices', 'tr' => 'Perm Invoices'], // TODO: translate to Turkish");
});

it('--with-permissions on a second run does not duplicate the entry', function () {
    $configPath = config_path('permission-resources.php');
    copy(dirname(__DIR__, 3).'/stubs/config/permission-resources.php', $configPath);

    $args = array_merge(baseArgs('Coupon'), ['--with-permissions' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $content = file_get_contents($configPath);
    expect(substr_count($content, "'coupons' => null, // all abilities"))->toBe(1);
});

it('--with-permissions skipped without crashing when permission-resources.php is absent', function () {
    $args = array_merge(baseArgs('Ticket'), ['--with-permissions' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    expect(file_exists(app_path('Models/Ticket.php')))->toBeTrue();
});

// ── --with= shorthand ──────────────────────────────────────────────────────

it('--with= shorthand activates multiple opt-in flags', function () {
    $args = array_merge(
        baseArgs('Invoice', 'amount:decimal,note:text'),
        ['--with' => 'policy,factory,seeder,test']
    );

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    expect(file_exists(app_path('Policies/InvoicePolicy.php')))->toBeTrue();
    expect(file_exists(database_path('factories/InvoiceFactory.php')))->toBeTrue();
    expect(file_exists(database_path('seeders/InvoiceSeeder.php')))->toBeTrue();
    expect(file_exists(base_path('tests/Feature/InvoiceTest.php')))->toBeTrue();
});

// ── --relations= belongsTo ─────────────────────────────────────────────────

it('--relations=belongsTo:User injects belongsTo method into model', function () {
    $args = array_merge(baseArgs('Post'), ['--relations' => 'belongsTo:User']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $modelPath = app_path('Models/Post.php');
    expect(file_exists($modelPath))->toBeTrue();

    $content = file_get_contents($modelPath);
    expect($content)->toContain('public function user()');
    expect($content)->toContain('BelongsTo');
    expect($content)->toContain('return $this->belongsTo(User::class)');
});

// ── --relations= hasMany ───────────────────────────────────────────────────

it('--relations=hasMany:Comment injects hasMany method into model', function () {
    $args = array_merge(baseArgs('Blog'), ['--relations' => 'hasMany:Comment']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $modelPath = app_path('Models/Blog.php');
    expect(file_exists($modelPath))->toBeTrue();

    $content = file_get_contents($modelPath);
    expect($content)->toContain('public function comments()');
    expect($content)->toContain('HasMany');
    expect($content)->toContain('return $this->hasMany(Comment::class)');
});

// ── --relations= morphTo ───────────────────────────────────────────────────

it('--relations=morphTo:commentable injects morphTo method into model', function () {
    $args = array_merge(baseArgs('Reaction', 'type:string'), ['--relations' => 'morphTo:commentable']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $modelPath = app_path('Models/Reaction.php');
    expect(file_exists($modelPath))->toBeTrue();

    $content = file_get_contents($modelPath);
    expect($content)->toContain('public function commentable()');
    expect($content)->toContain('MorphTo');
    expect($content)->toContain('return $this->morphTo()');
});

// ── belongsTo migration FK ─────────────────────────────────────────────────

it('belongsTo relation adds foreignId column to migration', function () {
    $args = array_merge(baseArgs('Comment', 'body:text'), ['--relations' => 'belongsTo:Post']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_comments_table.php'));
    expect($migrations)->not->toBeEmpty();

    $content = file_get_contents($migrations[0]);
    expect($content)->toContain("foreignId('post_id')");
    expect($content)->toContain('constrained()');
});

// ── morphTo migration morphs() ─────────────────────────────────────────────

it('morphTo relation adds morphs column to migration', function () {
    $args = array_merge(baseArgs('Tag', 'label:string'), ['--relations' => 'morphTo:taggable']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $migrations = glob(database_path('migrations/*_create_tags_table.php'));
    expect($migrations)->not->toBeEmpty();

    $content = file_get_contents($migrations[0]);
    expect($content)->toContain("morphs('taggable')");
});

// ── Idempotency ────────────────────────────────────────────────────────────

it('running the command twice does not overwrite existing Policy', function () {
    $args = array_merge(baseArgs('Widget'), ['--with-policy' => true]);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $policyPath = app_path('Policies/WidgetPolicy.php');
    expect(file_exists($policyPath))->toBeTrue();

    // Dosyaya işaretçi ekle
    $marker = '// IDEMPOTENCY_MARKER';
    file_put_contents($policyPath, file_get_contents($policyPath).$marker);

    // İkinci çalıştırma
    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    // Dosya değiştirilmemiş olmalı
    $content = file_get_contents($policyPath);
    expect($content)->toContain($marker);
});

// ── Circular dependency guard ──────────────────────────────────────────────

it('self-referential relation is skipped (circular dependency guard)', function () {
    // Note → Note: belongsTo kendi modeline
    $args = array_merge(baseArgs('NoteCircular', 'content:text'), ['--relations' => 'belongsTo:NoteCircular']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    $modelPath = app_path('Models/NoteCircular.php');

    if (file_exists($modelPath)) {
        $content = file_get_contents($modelPath);
        // Self-referential belongsTo inject edilmemiş olmalı
        expect($content)->not->toContain('return $this->belongsTo(NoteCircular::class)');
    }
});

// ── Duplicate relation guard ───────────────────────────────────────────────

it('duplicate relations are deduplicated', function () {
    // Her çalıştırmada artifact sil — testbench persistent app_path kullanıyor
    $modelPath = app_path('Models/DedupeReceipt.php');

    if (file_exists($modelPath)) {
        unlink($modelPath);
    }

    $args = array_merge(baseArgs('DedupeReceipt', 'amount:decimal'), ['--relations' => 'hasMany:Item,hasMany:Item']);

    $this->artisan('make:sk-domain', $args)
        ->expectsConfirmation('Proceed with this configuration?', 'yes')
        ->assertSuccessful();

    expect(file_exists($modelPath))->toBeTrue();

    $content = file_get_contents($modelPath);
    // Sadece bir items() metodu olmalı
    expect(substr_count($content, 'public function items()'))->toBe(1);
});
