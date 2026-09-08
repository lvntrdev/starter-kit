<?php

namespace Lvntr\StarterKit\Domain\ApiRoute\Actions;

use Illuminate\Support\Facades\Artisan;
use Lvntr\StarterKit\Domain\ApiRoute\Support\OpenApiExporter;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Exceptions\ApiException;
use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Action: write the api-dock OpenAPI document to disk (admin "Regenerate Docs").
 *
 * Mechanism: `api-dock:export --openapi`, which builds the document through
 * api-dock's `DocumentGenerator` — the same entry point the panel's spec
 * route and {@see OpenApiExporter}
 * use, so all three describe an identical contract. `scramble:export` is not
 * used: it renders the raw Scramble document without `ApiDockTransformer`, so
 * the artifact on disk would differ from the panel (`--api=` does not help —
 * api-dock documents the host application's own Scramble API, not a private
 * one). The command is registered unconditionally (Spatie `hasCommands()`),
 * so it is callable from a web request.
 *
 * The file lands in an `admin/` subdirectory of
 * `config('api-dock.ai.export_path')` — `storage/api-dock/admin/openapi.json`
 * by default — not at the old Scramble `export_path`. That subdirectory is the
 * point: the bare export path writes `openapi.json` straight into
 * `storage/api-dock/`, which is byte-for-byte the default
 * `api-dock.snapshot.path` that `api-dock:diff` and `api-dock:sync --check`
 * compare against. Writing there would mean an admin pressing "Regenerate
 * Docs" after an endpoint was removed silently rewrites the CI baseline, and
 * the next check no longer sees the breaking change. Nothing in the kit reads
 * this artifact: the panel generates live, so it exists for external consumers
 * only.
 */
class RegenerateApiDocsAction extends BaseAction
{
    public function execute(): string
    {
        // Runs inside a web request, where the provider's boot-time Scramble
        // context gate did not register the document wiring — apply it now so
        // the export carries the bearer scheme + ApiResponse envelope.
        StarterKitServiceProvider::applyScrambleDocumentWiring();

        $exitCode = Artisan::call('api-dock:export', [
            '--openapi' => true,
            '--output' => $this->exportDirectory(),
        ]);
        $output = trim(Artisan::output());

        // ExportCommand swallows its own throwables and returns FAILURE, so
        // without this check a failed export answers the admin UI with a
        // success envelope carrying the error text in the payload.
        if ($exitCode !== 0) {
            throw ApiException::serverError($output !== '' ? $output : 'Failed to export the OpenAPI document.');
        }

        return $output;
    }

    /**
     * Where the admin button's artifact is written — always a directory of its
     * own, never the one holding the snapshot the diff commands read.
     */
    private function exportDirectory(): string
    {
        $configured = config('api-dock.ai.export_path');
        $base = is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/')
            : storage_path('api-dock');

        return $base.'/admin';
    }
}
