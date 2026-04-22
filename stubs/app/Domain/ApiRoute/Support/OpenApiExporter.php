<?php

namespace App\Domain\ApiRoute\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Produces the Scramble OpenAPI spec in a form friendly to external API
 * clients (Postman, Apidog, …): request bodies are rewritten from
 * `application/json` to `multipart/form-data` so the target tool shows
 * editable form tables instead of raw JSON editors.
 *
 * Keeping this isolated from any specific sync action lets both push
 * pipelines share identical output without repeating the export / rewrite
 * logic.
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
        $path = $dir.'/openapi.json';

        if (Artisan::call('scramble:export', ['--path' => $path]) !== 0) {
            throw ApiException::serverError('Failed to export the OpenAPI document via Scramble.');
        }

        if (! File::exists($path)) {
            throw ApiException::serverError('Scramble did not produce an OpenAPI document.');
        }

        $spec = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        if (! is_array($spec)) {
            throw ApiException::serverError('Exported OpenAPI JSON is invalid.');
        }

        return $this->rewriteRequestBodiesAsFormData($spec);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function rewriteRequestBodiesAsFormData(array $spec): array
    {
        if (! isset($spec['paths']) || ! is_array($spec['paths'])) {
            return $spec;
        }

        foreach ($spec['paths'] as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $content = $operation['requestBody']['content'] ?? null;
                if (! is_array($content) || ! isset($content['application/json'])) {
                    continue;
                }

                $content['multipart/form-data'] = $content['application/json'];
                unset($content['application/json']);

                $spec['paths'][$path][$method]['requestBody']['content'] = $content;
            }
        }

        return $spec;
    }
}
