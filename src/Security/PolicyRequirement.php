<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Security;

use Illuminate\Contracts\Auth\Access\Gate;
use KayedSpace\McpBridge\Data\Refusal;

/**
 * Whether a model has a policy, and whether that policy answers for an
 * operation.
 *
 * Two sources ask this. A model tool must have one, because the agent is
 * reaching straight at a record and nothing else stands between them. A
 * route write must have one when `security.require_policy` says so, which is
 * the stricter posture a public endpoint wants.
 *
 * The question is one question, so it is asked in one place, the way
 * `AuthorityRequirement` holds the exposure rule for the compiler and the
 * lint together. The two callers differ in when they ask, not in what a
 * missing policy means.
 */
class PolicyRequirement
{
    public function __construct(protected Gate $gate) {}

    /**
     * The refusal for a model with no registered policy, or null.
     *
     * @param  class-string  $model
     */
    public function missingFor(string $model): ?Refusal
    {
        return is_object($this->gate->getPolicyFor($model))
            ? null
            : Refusal::missingPolicy($model);
    }

    /**
     * The refusal for a policy that does not answer for an operation.
     *
     * A policy silent on an ability is a policy that did not mean to allow
     * it. Asking the Gate anyway would get a deny at call time; refusing at
     * compile time says so while a developer is looking.
     *
     * @param  class-string  $model
     */
    public function silentOn(string $model, string $ability, string $id): ?Refusal
    {
        $policy = $this->gate->getPolicyFor($model);

        if (! is_object($policy)) {
            return Refusal::missingPolicy($model);
        }

        return method_exists($policy, $ability)
            ? null
            : Refusal::policySilent($id, $policy::class, $ability);
    }
}
