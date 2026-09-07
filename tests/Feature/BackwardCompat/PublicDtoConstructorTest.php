<?php

/*
|--------------------------------------------------------------------------
| Backward Compatibility — public DTO constructor signatures
|--------------------------------------------------------------------------
|
| A DTO under src/ reaches the consumer through `composer update` alone, with
| no sk:update and no chance for the consumer to adapt first. Adding a
| parameter anywhere but the END of the signature breaks every existing call
| site instantly: named arguments raise ArgumentCountError for the new
| required parameter, positional ones silently shift into the wrong slot.
|
| This test pins the rule for FileItemDTO, whose `publicUrl` field was added
| in 13.7.0 and MUST stay last and optional.
|
*/

use Lvntr\StarterKit\Domain\FileManager\DTOs\FileItemDTO;

it('constructs FileItemDTO from the pre-13.7.0 argument list', function () {
    $dto = new FileItemDTO(
        id: 7,
        uuid: 'a-uuid',
        name: 'invoice',
        fileName: 'invoice.pdf',
        mimeType: 'application/pdf',
        size: 1024,
        folderId: null,
        url: 'https://example.test/files/7',
        createdAt: '2026-01-01T00:00:00+00:00',
    );

    expect($dto->publicUrl)->toBeNull()
        ->and($dto->createdAt)->toBe('2026-01-01T00:00:00+00:00');
});

it('keeps every FileItemDTO parameter added after the original nine optional', function () {
    $original = ['id', 'uuid', 'name', 'fileName', 'mimeType', 'size', 'folderId', 'url', 'createdAt'];

    $parameters = (new ReflectionClass(FileItemDTO::class))->getConstructor()->getParameters();
    $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $parameters);

    // The original nine keep their exact order, so a positional call still lands
    // in the right slots.
    expect(array_slice($names, 0, count($original)))->toBe($original);

    foreach (array_slice($parameters, count($original)) as $parameter) {
        expect($parameter->isOptional())->toBeTrue(
            "FileItemDTO::\${$parameter->getName()} was appended without a default value."
        );
    }
});
