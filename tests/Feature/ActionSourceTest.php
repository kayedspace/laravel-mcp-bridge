<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Execution\ActionExecutor;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Tests\Fixtures\Actions\PublishCourse;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;

function compileActions(): CompileResult
{
    config()->set('mcp-bridge.sources.action.enabled', true);
    config()->set('mcp-bridge.sources.action.paths', [__DIR__.'/../Fixtures/Actions']);

    return app(ManifestCompiler::class)->compile();
}

function runAction(string $tool, array $arguments = []): SourceResult
{
    return app(ActionExecutor::class)->execute(callTool(
        toolNamed($tool, compileActions()),
        $arguments,
        new TokenUser(['courses:write'], 9),
    ));
}

beforeEach(function (): void {
    PublishCourse::$authorized = true;
    app(Gate::class)->define('publish-courses', fn (): bool => true);
});

describe('discovery', function (): void {
    it('finds action classes and names them after themselves', function (): void {
        expect(compileActions()->names())->toContain('publish_course', 'archive_course');
    });

    it('reads the schema from rules() when the action has them', function (): void {
        $input = toolNamed('publish_course', compileActions())->input;

        expect($input->get('course_id')?->type->value)->toBe('integer')
            ->and($input->get('course_id')?->required)->toBeTrue()
            ->and($input->get('course_id')?->min)->toBe(1)
            ->and($input->get('announce')?->type->value)->toBe('boolean')
            ->and($input->get('note')?->nullable)->toBeTrue()
            ->and($input->get('note')?->max)->toBe(120);
    });

    it('reads the schema from the signature when it has no rules', function (): void {
        $input = toolNamed('archive_course', compileActions())->input;

        expect($input->get('courseId')?->type->value)->toBe('integer')
            ->and($input->get('courseId')?->required)->toBeTrue()
            ->and($input->get('reason')?->required)->toBeFalse();
    });

    it('takes its safety from the declaration, never from the class name', function (): void {
        $result = compileActions();

        expect(toolNamed('archive_course', $result)->safety->destructive)->toBeTrue()
            ->and(toolNamed('publish_course', $result)->safety->destructive)->toBeFalse();
    });
});

describe('execution', function (): void {
    it('validates with the action own rules before calling it', function (): void {
        $result = runAction('publish_course', ['course_id' => 0]);

        expect($result->status)->toBe(422)
            ->and($result->payload['errors'])->toHaveKey('course_id');
    });

    it('calls the action with the validated arguments, as the agent', function (): void {
        $result = runAction('publish_course', ['course_id' => 12, 'announce' => true, 'note' => 'now live']);

        expect($result->successful())->toBeTrue()
            ->and($result->payload)->toMatchArray([
                'id' => 12,
                'announced' => true,
                'note' => 'now live',
                'actor' => 9,
            ]);
    });

    it('drops what the rules never mentioned', function (): void {
        $result = runAction('publish_course', ['course_id' => 12, 'force' => true]);

        expect($result->successful())->toBeTrue()
            ->and($result->payload)->not->toHaveKey('force');
    });

    it('refuses when the action own authorize() says no', function (): void {
        PublishCourse::$authorized = false;

        $result = runAction('publish_course', ['course_id' => 12]);

        expect($result->status)->toBe(403)
            ->and($result->payload['message'])->toContain('not allowed');
    });

    it('calls an invokable action with its signature arguments', function (): void {
        $result = runAction('archive_course', ['courseId' => 3, 'reason' => 'outdated']);

        expect($result->payload)->toBe(['id' => 3, 'reason' => 'outdated']);
    });
});
