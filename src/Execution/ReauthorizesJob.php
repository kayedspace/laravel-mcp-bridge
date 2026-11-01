<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use KayedSpace\McpBridge\Exceptions\JobNoLongerAuthorized;

/**
 * Job middleware that asks again, when the job runs, whether the agent that
 * asked for it is still allowed to have it.
 *
 * A token is how an agent is switched off. Revoking one has to stop work the
 * agent has already queued, or the revocation only covers what it has not
 * got around to yet, which is the opposite of how anyone reads "revoked".
 *
 * Re-deriving the answer from the live Gate rather than carrying a signed
 * claim in the payload. A signature only proves the payload was not edited,
 * which the queue already assumes; asking the Gate again proves the authority
 * still exists, which is the thing actually in question.
 *
 * Laravel merges a job's `$middleware` property with whatever its own
 * `middleware()` returns, so this rides along without the job knowing.
 */
final readonly class ReauthorizesJob
{
    /**
     * @param  class-string<Authenticatable>  $actorType
     * @param  list<string>  $abilities
     */
    public function __construct(
        public string $tool,
        public string $actorType,
        public int|string $actorId,
        public array $abilities,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $actor = $this->actor();

        if ($actor === null) {
            // The account is gone, so there is nobody left to have authorised
            // this. Failing loudly rather than deleting it quietly, because a
            // job that vanishes is a job nobody investigates.
            throw JobNoLongerAuthorized::actorGone($this->tool, $this->actorId);
        }

        foreach ($this->abilities as $ability) {
            if (! $this->gate()->forUser($actor)->allows($ability)) {
                throw JobNoLongerAuthorized::abilityLost($this->tool, $ability);
            }
        }

        $next($job);
    }

    protected function actor(): ?Authenticatable
    {
        if (! is_subclass_of($this->actorType, Model::class)) {
            return null;
        }

        $actor = $this->actorType::query()->find($this->actorId);

        return $actor instanceof Authenticatable ? $actor : null;
    }

    protected function gate(): Gate
    {
        return app(Gate::class);
    }
}
