<?php

/*
|--------------------------------------------------------------------------
| FileManager — granular ability matrix (red-line regression)
|--------------------------------------------------------------------------
|
| FileManager routes are excluded from CheckResourcePermission, so the context
| `authorize` closure is the ONLY gate in front of every file operation. Until
| the granular-ability fix landed, every mutation resolved through a single
| `write` ability whose built-in `global` closure OR-collapsed the four file
| permissions — a role holding nothing but `files.create` could delete files
| and empty the trash.
|
| Two independent halves have to hold for the fix to mean anything, and both
| are asserted here:
|
|   1. ROUTING — which controller handler asks for which ability. Derived from
|      the FileManagerController source: an operation silently re-pointed at a
|      weaker verb (or back at the deprecated `authorizeWrite`) is exactly the
|      regression, and it is invisible to any assertion made on the authorizer
|      alone.
|   2. ENFORCEMENT — FileManagerAuthorizer + the built-in `global` context
|      closure, driven with an actor that holds one permission at a time.
|
| The two are then composed: `fmOperationAllowed(['files.create'], 'emptyTrash')`
| looks the ability up in the routing map and runs it through the real
| authorizer, so the assertion states the security claim itself ("create-only
| cannot empty the trash") rather than an implementation detail.
|
| No HTTP round trip: the mutating endpoints need FormRequests, media rows and
| a disk, none of which the deny path ever reaches.
|
*/

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Domain\FileManager\Services\FileManagerAuthorizer;
use Lvntr\StarterKit\Domain\FileManager\Support\ContextRegistry;
use Lvntr\StarterKit\Tests\Stubs\TestOwner;

// ──────────────────────────────────────────────────────────────────────────────
// Fixtures
// ──────────────────────────────────────────────────────────────────────────────

/**
 * An Eloquent + Authenticatable actor whose `can()` answers ONLY for the
 * permissions it was handed. The built-in `global` closure calls
 * `$actor->can('files.<verb>')`, so this is the whole permission surface the
 * gate sees — no Spatie tables, no Gate registration.
 *
 * @param  list<string>  $granted
 */
function fmActor(array $granted): Authenticatable
{
    $actor = new class extends Model implements Authenticatable
    {
        use Illuminate\Auth\Authenticatable;

        /** @var list<string> */
        public array $granted = [];

        protected $table = 'fm_ability_actors';

        public $timestamps = false;

        public function can(mixed $abilities, mixed $arguments = []): bool
        {
            return is_string($abilities) && in_array($abilities, $this->granted, true);
        }
    };

    $actor->forceFill(['id' => 1]);
    $actor->granted = $granted;

    return $actor;
}

/**
 * A `global`-context DTO built by hand.
 *
 * FileManagerContextDTO::forGlobal() would resolve App\Models\GlobalFileBucket,
 * which does not exist in the package suite — and the authorizer never resolves
 * the owner itself, it only forwards the one carried by the DTO.
 */
function fmGlobalContext(): FileManagerContextDTO
{
    $owner = (new TestOwner)->forceFill(['id' => 'global-bucket']);

    return new FileManagerContextDTO(
        context: 'global',
        contextId: null,
        owner: $owner,
        ownerType: $owner->getMorphClass(),
        ownerId: 'global-bucket',
    );
}

/** Raw FileManagerController source, read once. */
function fmControllerSource(): string
{
    static $source = null;

    return $source ??= (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Http/Controllers/FileManagerController.php'
    );
}

/**
 * Controller handler → ability, read off the FileManagerController source.
 *
 * Parsed per method block instead of with one cross-method pattern so a handler
 * that authorizes nothing at all can never borrow the next handler's call.
 *
 * A block WITHOUT an authorize* call is skipped — deliberately, so the map only
 * ever contains classified operations. That skip is also the hole this file used
 * to have: an unguarded handler simply vanished from the map and the exhaustive
 * `toBe([...])` below stayed green. fmControllerHandlers() closes it by naming
 * the handlers independently of whether they authorize anything.
 *
 * @return array<string, string>
 */
function fmControllerAbilityMap(): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = [];

    foreach (preg_split('/\n    public function /', fmControllerSource()) as $block) {
        if (! preg_match('/^(\w+)\(/', $block, $name)) {
            continue;
        }

        if (! preg_match('/\$this->authorizer->authorize(Read|Create|Update|Delete|Write)\(/', $block, $ability)) {
            continue;
        }

        $map[$name[1]] = strtolower($ability[1]);
    }

    ksort($map);

    return $map;
}

/**
 * Every public method on FileManagerController except the constructor —
 * i.e. the complete set of things a route can be pointed at.
 *
 * Read WITHOUT looking at authorization, on purpose: this is the side of the
 * comparison that a handler cannot fall out of by forgetting the gate. Anything
 * public here that is not a classified operation in fmControllerAbilityMap() is
 * either an unauthorized action (the exact failure the controller docblock at
 * src/Http/Controllers/FileManagerController.php:44-62 warns about) or a public
 * helper that has no business on a controller whose every route is
 * permission-middleware-exempt. Both are review events, so both fail.
 *
 * @return list<string>
 */
function fmControllerHandlers(): array
{
    static $handlers = null;

    if ($handlers !== null) {
        return $handlers;
    }

    preg_match_all('/\n    public function (\w+)\(/', fmControllerSource(), $matches);

    $handlers = array_values(array_diff($matches[1], ['__construct']));

    sort($handlers);

    return $handlers;
}

/**
 * Does an actor holding exactly `$granted` get through the gate the given
 * controller handler puts in front of itself?
 *
 * @param  list<string>  $granted
 */
function fmOperationAllowed(array $granted, string $handler): bool
{
    $map = fmControllerAbilityMap();

    expect($map)->toHaveKey($handler); // guards against a renamed handler

    $method = 'authorize'.ucfirst($map[$handler]);

    test()->actingAs(fmActor($granted));

    try {
        (new FileManagerAuthorizer(new ContextRegistry))->{$method}(fmGlobalContext());

        return true;
    } catch (AuthorizationException) {
        return false;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. Routing — every handler asks for the ability that matches what it does
// ──────────────────────────────────────────────────────────────────────────────

it('maps every FileManager handler to the ability its operation actually needs', function (): void {
    // Exhaustive on purpose: an extra authorizing handler that is not listed
    // here is a new operation nobody classified, and that is a review event.
    expect(fmControllerAbilityMap())->toBe([
        'addFavorite' => 'update',
        'bulkDelete' => 'delete',
        'contents' => 'read',
        'copyFile' => 'create',
        'createFolder' => 'create',
        'deleteFile' => 'delete',
        'deleteFolder' => 'delete',
        'download' => 'read',
        'emptyTrash' => 'delete',
        'favoritesContents' => 'read',
        'moveItem' => 'update',
        'permanentlyDeleteItem' => 'delete',
        // Streams file bytes inline — same gate as download, never weaker.
        'preview' => 'read',
        'removeFavorite' => 'update',
        'renameFile' => 'update',
        'renameFolder' => 'update',
        'restoreItem' => 'update',
        'trashContents' => 'read',
        'tree' => 'read',
        'upload' => 'create',
    ]);
});

it('leaves no public FileManager handler outside the ability map', function (): void {
    // The map is built by skipping method blocks that contain no authorize*
    // call, so an action that authorizes NOTHING is absent from it rather than
    // wrong in it — and the exhaustive expectation above would still pass.
    // Comparing the map's keys against the public methods read straight off the
    // class is what makes an unguarded handler fail: it appears on the right
    // side and cannot appear on the left.
    expect(array_keys(fmControllerAbilityMap()))->toBe(fmControllerHandlers())
        // Sanity: an empty parse on either side would make the comparison
        // vacuously true.
        ->and(fmControllerHandlers())->not->toBeEmpty();
});

it('no FileManager handler still routes through the deprecated write ability', function (): void {
    expect(fmControllerAbilityMap())->not->toContain('write');
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. Enforcement — one permission at a time
// ──────────────────────────────────────────────────────────────────────────────

it('denies destructive operations to a create-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.create'], $handler))->toBeFalse();
})->with(['emptyTrash', 'deleteFile', 'bulkDelete', 'deleteFolder', 'permanentlyDeleteItem']);

it('allows the create operations to a create-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.create'], $handler))->toBeTrue();
})->with(['upload', 'createFolder', 'copyFile']);

it('denies mutation and read to a create-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.create'], $handler))->toBeFalse();
})->with(['renameFile', 'moveItem', 'addFavorite', 'restoreItem', 'tree', 'download']);

it('denies uploads to a delete-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.delete'], $handler))->toBeFalse();
})->with(['upload', 'createFolder', 'copyFile']);

it('allows the delete operations to a delete-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.delete'], $handler))->toBeTrue();
})->with(['emptyTrash', 'deleteFile', 'bulkDelete', 'deleteFolder', 'permanentlyDeleteItem']);

it('denies reads to an update-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.update'], $handler))->toBeFalse();
})->with(['tree', 'contents', 'favoritesContents', 'trashContents', 'download']);

it('allows the update operations to an update-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.update'], $handler))->toBeTrue();
})->with(['renameFile', 'renameFolder', 'moveItem', 'addFavorite', 'removeFavorite', 'restoreItem']);

it('allows every read operation to a read-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.read'], $handler))->toBeTrue();
})->with(['tree', 'contents', 'favoritesContents', 'trashContents', 'download']);

it('denies every mutation to a read-only holder', function (string $handler): void {
    expect(fmOperationAllowed(['files.read'], $handler))->toBeFalse();
})->with(['upload', 'createFolder', 'copyFile', 'renameFile', 'moveItem', 'emptyTrash', 'deleteFile', 'bulkDelete']);

it('denies every operation when nobody is authenticated', function (): void {
    $this->app['auth']->forgetGuards();

    $authorizer = new FileManagerAuthorizer(new ContextRegistry);

    foreach (['authorizeRead', 'authorizeCreate', 'authorizeUpdate', 'authorizeDelete'] as $method) {
        expect(fn () => $authorizer->{$method}(fmGlobalContext()))->toThrow(AuthorizationException::class);
    }
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. The built-in `global` closure itself — fail-closed + deprecated alias
// ──────────────────────────────────────────────────────────────────────────────

it('fails closed on an ability name the global context does not know', function (): void {
    // Granted EVERYTHING — an unknown verb must still be refused, so this can
    // only go green through the `default => false` arm.
    $actor = fmActor(['files.read', 'files.create', 'files.update', 'files.delete']);
    $definition = (new ContextRegistry)->get('global');
    $owner = fmGlobalContext()->owner;

    expect($definition->authorize($actor, 'purge', $owner))->toBeFalse()
        ->and($definition->authorize($actor, '', $owner))->toBeFalse()
        ->and($definition->authorize($actor, 'READ', $owner))->toBeFalse()
        ->and($definition->authorize($actor, 'files.delete', $owner))->toBeFalse();
});

it('resolves the deprecated write ability to update — never to create or delete', function (): void {
    $definition = (new ContextRegistry)->get('global');
    $owner = fmGlobalContext()->owner;

    expect($definition->authorize(fmActor(['files.update']), 'write', $owner))->toBeTrue()
        ->and($definition->authorize(fmActor(['files.create']), 'write', $owner))->toBeFalse()
        ->and($definition->authorize(fmActor(['files.delete']), 'write', $owner))->toBeFalse()
        ->and($definition->authorize(fmActor(['files.read']), 'write', $owner))->toBeFalse();
});

it('authorizeWrite is an alias of authorizeUpdate, not of create or delete', function (): void {
    $authorizer = new FileManagerAuthorizer(new ContextRegistry);

    // An update holder must pass; anything thrown here fails the test.
    test()->actingAs(fmActor(['files.update']));
    $authorizer->authorizeWrite(fmGlobalContext());
    expect(true)->toBeTrue();

    test()->actingAs(fmActor(['files.create']));
    expect(fn () => $authorizer->authorizeWrite(fmGlobalContext()))->toThrow(AuthorizationException::class);

    test()->actingAs(fmActor(['files.delete']));
    expect(fn () => $authorizer->authorizeWrite(fmGlobalContext()))->toThrow(AuthorizationException::class);
});

it('grants each ability to the matching permission and to no other', function (): void {
    $definition = (new ContextRegistry)->get('global');
    $owner = fmGlobalContext()->owner;

    $abilities = ['read', 'create', 'update', 'delete'];

    foreach ($abilities as $granted) {
        $actor = fmActor(['files.'.$granted]);

        foreach ($abilities as $asked) {
            expect($definition->authorize($actor, $asked, $owner))
                ->toBe($granted === $asked, "files.{$granted} holder asked for {$asked}");
        }
    }
});
