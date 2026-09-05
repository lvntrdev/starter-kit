<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Exercise the command's provider-name resolution in isolation: parse a
 * bootstrap/providers.php body, then resolve its listed providers to canonical
 * FQCNs the same way injectBootstrapProviders() does before deduping.
 *
 * @return list<string>
 */
function resolveProviderNames(string $code): array
{
    $command = new InstallCommand;
    $stmts = (new ParserFactory)->createForHostVersion()->parse($code);

    $collectUse = new ReflectionMethod($command, 'collectUseAliases');
    $useMap = $collectUse->invoke($command, $stmts);

    /** @var Node\Stmt\Return_ $return */
    $return = (new NodeFinder)->findFirstInstanceOf($stmts, Node\Stmt\Return_::class);

    $collectNames = new ReflectionMethod($command, 'collectProviderClassNames');

    return $collectNames->invoke($command, $return->expr, $useMap);
}

it('resolves short provider names against use imports to their FQCN', function (): void {
    // Mirrors the shipped stubs/bootstrap/providers.php shape.
    $code = <<<'PHP'
<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    FortifyServiceProvider::class,
    SettingsServiceProvider::class,
];
PHP;

    // Because the short names resolve to their FQCNs, injection finds them
    // already present and adds NO duplicate.
    expect(resolveProviderNames($code))->toContain(
        'App\Providers\DomainServiceProvider',
        'App\Providers\FortifyServiceProvider',
        'App\Providers\SettingsServiceProvider',
    );
});

it('normalizes fully-qualified entries to no leading slash', function (): void {
    $code = <<<'PHP'
<?php

return [
    \App\Providers\DomainServiceProvider::class,
];
PHP;

    expect(resolveProviderNames($code))->toBe(['App\Providers\DomainServiceProvider']);
});

it('honors a use alias when resolving', function (): void {
    $code = <<<'PHP'
<?php

use App\Providers\DomainServiceProvider as Domain;

return [
    Domain::class,
];
PHP;

    expect(resolveProviderNames($code))->toBe(['App\Providers\DomainServiceProvider']);
});

it('leaves an unimported short name unresolved so it will be added', function (): void {
    $code = <<<'PHP'
<?php

return [
    AppServiceProvider::class,
];
PHP;

    // No `use` import → cannot resolve to App\Providers\* → injection adds the FQCN.
    expect(resolveProviderNames($code))->not->toContain('App\Providers\DomainServiceProvider');
});
