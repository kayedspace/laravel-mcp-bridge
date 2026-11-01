<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use Illuminate\Http\JsonResponse;

/**
 * Binds a model that has no policy, which is what `security.require_policy`
 * is about. No shared route points here; the tests that need it register
 * their own, so no other test's surface moves.
 */
class EnrolmentController
{
    public function show(Enrolment $enrolment): JsonResponse
    {
        return new JsonResponse(['data' => $enrolment->toArray()]);
    }

    public function update(Enrolment $enrolment): JsonResponse
    {
        return new JsonResponse(['data' => $enrolment->toArray()]);
    }
}
