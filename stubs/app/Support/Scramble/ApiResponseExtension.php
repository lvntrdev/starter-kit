<?php

namespace App\Support\Scramble;

use App\Http\Responses\ApiResponse;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Teaches Scramble how to document ApiResponse as the API envelope used
 * by every endpoint.
 *
 * Produces a single rich schema that covers both success and error shapes:
 *
 *   {
 *       "success": bool,
 *       "status": int,
 *       "message": string,
 *       "data": object|array|null,
 *       "errors": object|null,      // present on 422 validation errors
 *       "meta": object|null,        // present on paginated responses
 *       "trace_id": string|null,    // uuid, always attached by middleware
 *       "debug": object|null        // only when APP_DEBUG=true
 *   }
 *
 * Multi-status differentiation (201, 204, 4xx, 5xx schemas) requires an
 * OperationExtension rather than a TypeToSchemaExtension; that is tracked
 * separately and intentionally not handled here.
 */
class ApiResponseExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(ApiResponse::class);
    }

    public function toResponse(Type $type): ?Response
    {
        $envelope = new OpenApiObjectType;

        $envelope->addProperty(
            'success',
            (new BooleanType)->setDescription('True for 2xx responses, false for 4xx/5xx errors.')
        );

        $envelope->addProperty(
            'status',
            (new IntegerType)->setDescription('HTTP status code mirrored in the envelope for client convenience.')
        );

        $envelope->addProperty(
            'message',
            (new StringType)->setDescription('Human-readable English message — safe to surface in UI.')
        );

        $envelope->addProperty(
            'data',
            (new OpenApiObjectType)
                ->nullable(true)
                ->setDescription('Primary payload. Null on errors and on 204 responses.')
        );

        $envelope->addProperty(
            'errors',
            (new OpenApiObjectType)
                ->nullable(true)
                ->setDescription('Field-level validation errors. Present only on 422 Validation error responses.')
        );

        $envelope->addProperty(
            'meta',
            (new OpenApiObjectType)
                ->nullable(true)
                ->setDescription('Auxiliary metadata such as pagination (current_page, last_page, per_page, total, etc.).')
        );

        $envelope->addProperty(
            'trace_id',
            (new StringType)
                ->setDescription('Per-request UUID (also returned via the X-Request-ID header) for log correlation.')
        );

        $envelope->addProperty(
            'debug',
            (new OpenApiObjectType)
                ->nullable(true)
                ->setDescription('Exception details emitted only when APP_DEBUG=true. Must not be trusted in production.')
        );

        $envelope->setRequired(['success', 'status', 'message', 'data']);

        return Response::make(200)
            ->setDescription('Standard API response envelope.')
            ->setContent('application/json', Schema::fromType($envelope));
    }
}
