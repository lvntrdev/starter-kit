<?php

namespace Lvntr\StarterKit\Domain\ApiRoute\Support;

use LvntR\ApiDock\Support\DocumentGenerator;
use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Produces the OpenAPI document for the external API client sync pipelines
 * (Postman, Apidog, …).
 *
 * Mechanism: api-dock's `DocumentGenerator`, resolved from the container.
 * That class is api-dock's single entry point for building the document —
 * the panel's spec route and every `api-dock:*` console command go through
 * it — so what gets pushed to Postman/Apidog is byte-for-byte what the panel
 * renders. `scramble:export` is deliberately NOT used any more: it calls the
 * raw Scramble generator and never applies `ApiDockTransformer`, so its
 * output drifts from the panel's (no `x-api-dock` document metadata).
 * `scramble:export --api=` does exist in 0.13, but it cannot close that gap
 * either: api-dock registers no private Scramble API, it appends to the host
 * application's own one (`config('api-dock.scramble_api')`, default
 * `Scramble::DEFAULT_API`), so the api name is already identical and the
 * missing piece is the transformer, not the config.
 *
 * The document is emitted unchanged — every operation's content-type list
 * stays as generated, so the pushed collection mirrors the real server
 * contract. Changing the body format in the target tool is a per-request UI
 * choice, not something this exporter should dictate.
 */
class OpenApiExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        // The admin UI sync buttons run this inside a web request, where the
        // provider's boot-time Scramble context gate did not register the
        // document wiring — apply it now so the export carries the bearer
        // scheme + ApiResponse envelope. The generator is resolved after this
        // call, never constructor-injected, so the wiring cannot lose the race.
        StarterKitServiceProvider::applyScrambleDocumentWiring();

        $generate = app(DocumentGenerator::class);

        return $generate();
    }
}
