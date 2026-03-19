<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Contracts\PipeableAction;
use App\Domain\Shared\Pipelines\ActionPipeline;

/**
 * Base Action class.
 * Actions encapsulate a single business operation.
 *
 * Convention:
 *   - One public method: execute()
 *   - Inject dependencies via constructor
 *   - Keep controllers thin by moving logic here
 *   - Dispatch domain events after successful operations
 *
 * For pipeline usage, implement PipeableAction interface
 * and add a handle(mixed $payload, Closure $next) method.
 *
 * Flow: Controller → Action → Repository → Model
 *       Action dispatches Events → Listeners handle side effects
 *
 * @see PipeableAction
 * @see ActionPipeline
 */
abstract class BaseAction
{
    //
}
