<?php

namespace App\Domain\ApiRoute\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Produces the Scramble OpenAPI spec for the external API client sync
 * pipelines (Postman, Apidog, …). The spec is emitted unchanged — every
 * operation's content-type list stays as Scramble generated it, so the
 * pushed collection mirrors the real server contract. Changing the body
 * format in the target tool is a per-request UI choice, not something
 * this exporter should dictate.
 *
 * Each call writes to a unique temp path under storage/app/postman/ so
 * the CLI command and the admin UI button can run concurrently without
 * racing on a shared file. The file is always removed in a `finally`
 * block, even on failure.
 */
class OpenApiExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $dir = storage_path('app/postman');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/openapi-'.Str::uuid()->toString().'.json';

        try {
            if (Artisan::call('scramble:export', ['--path' => $path]) !== 0) {
                throw ApiException::serverError('Failed to export the OpenAPI document via Scramble.');
            }

            if (! File::exists($path)) {
                throw ApiException::serverError('Scramble did not produce an OpenAPI document.');
            }

            $spec = json_decode((string) file_get_contents($path), true);

            if (! is_array($spec)) {
                throw ApiException::serverError('Exported OpenAPI JSON is invalid.');
            }

            return $spec;
        } finally {
            @unlink($path);
        }
    }
}
