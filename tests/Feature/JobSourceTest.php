<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Exceptions\JobNoLongerAuthorized;
use KayedSpace\McpBridge\Execution\JobExecutor;
use KayedSpace\McpBridge\Execution\JobStatuses;
use KayedSpace\McpBridge\Execution\ReauthorizesJob;
use KayedSpace\McpBridge\Execution\TracksJobStatus;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Server\JobStatusTool;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\Jobs\ImportWithCallback;
use KayedSpace\McpBridge\Tests\Fixtures\Jobs\RecalculateCourse;
use KayedSpace\McpBridge\Tests\Fixtures\Jobs\SendDigestNow;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;
use Laravel\Mcp\Request as McpRequest;

function compileJobs(): CompileResult
{
    config()->set('mcp-bridge.sources.job.enabled', true);
    config()->set('mcp-bridge.sources.job.paths', [__DIR__.'/../Fixtures/Jobs']);

    return app(ManifestCompiler::class)->compile();
}

beforeEach(function (): void {
    app(Gate::class)->define('run-reports', fn (): bool => true);
});

describe('discovery', function (): void {
    it('finds job classes under the configured paths and names them', function (): void {
        expect(compileJobs()->names())->toContain('recalculate_course', 'send_digest_now');
    });

    it('refuses a job whose constructor an agent could not fill', function (): void {
        $result = compileJobs();

        expect($result->names())->not->toContain('import_with_callback')
            ->and($result->refusals['job:'.ImportWithCallback::class][0]->message)
            ->toContain('onEach')
            ->toContain('rows: it has no type');
    });

    it('sends job tools to the operations server', function (): void {
        expect(toolNamed('recalculate_course', compileJobs())->server)->toBe('ops');
    });

    it('says whether the work will be finished or merely accepted', function (): void {
        $result = compileJobs();

        expect(toolNamed('recalculate_course', $result)->description)
            ->toContain('accepted for processing rather than finished')
            ->and(toolNamed('send_digest_now', $result)->description)->toStartWith('Send the digest and wait.');
    });
});

describe('the schema', function (): void {
    it('reads the constructor, including enums, defaults and nullability', function (): void {
        $input = toolNamed('recalculate_course', compileJobs())->input;

        expect($input->get('course')?->type->value)->toBe('integer')
            ->and($input->get('course')?->required)->toBeTrue()
            ->and($input->get('course')?->description)->toBe('The id of the Course to use.')
            ->and($input->get('status')?->enum)->toBe(['draft', 'published', 'archived'])
            ->and($input->get('status')?->default)->toBe('draft')
            ->and($input->get('reason')?->nullable)->toBeTrue()
            ->and($input->get('reason')?->required)->toBeFalse()
            ->and($input->get('force')?->type->value)->toBe('boolean');
    });
});

describe('dispatch', function (): void {
    beforeEach(function (): void {
        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->softDeletes();
            $table->timestamps();
        });

        Course::query()->insert([['id' => 4, 'title' => 'Cardiology']]);
    });

    it('queues the job on its own queue, and says so', function (): void {
        Queue::fake();

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 4, 'status' => 'published'],
            new TokenUser(['ops:write'], 7),
        ));

        expect($result->successful())->toBeTrue()
            ->and($result->payload['status'])->toBe('queued')
            ->and($result->payload['queue'])->toBe('mcp-bridge');

        Queue::assertPushedOn('mcp-bridge', RecalculateCourse::class, function (RecalculateCourse $job): bool {
            // The model parameter arrives as the record, not as the id.
            return $job->course->is(Course::query()->find(4))
                && $job->status->value === 'published';
        });
    });

    it('runs a job that asked to be synchronous', function (): void {
        SendDigestNow::$ran = false;

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('send_digest_now', compileJobs()),
            ['audience' => 'subscribers'],
            new TokenUser(['ops:write'], 7),
        ));

        expect($result->payload['status'])->toBe('completed')
            ->and(SendDigestNow::$ran)->toBeTrue();
    });

    it('reports a record that does not exist as a not-found, not a crash', function (): void {
        Queue::fake();

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 999],
            new TokenUser(['ops:write'], 7),
        ));

        expect($result->status)->toBe(404)
            ->and($result->meta['code'])->toBe('MCP_BRIDGE_NOT_FOUND');
    });

    it('honours a queue named on the declaration', function (): void {
        Queue::fake();

        config()->set('mcp-bridge.sources.job.queue', 'agents');

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 4],
            new TokenUser(['ops:write'], 7),
        ));

        expect($result->payload['queue'])->toBe('agents');

        Queue::assertPushedOn('agents', RecalculateCourse::class);
    });
});

describe('authority at run time', function (): void {
    beforeEach(function (): void {
        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        Course::query()->insert([['id' => 4, 'title' => 'Cardiology']]);
        TestUser::query()->insert([['id' => 7, 'name' => 'Agent owner']]);
    });

    it('sends the re-authorization check along with the job', function (): void {
        Queue::fake();

        app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 4, 'status' => 'published'],
            new TokenUser(['ops:write'], 7),
        ));

        Queue::assertPushed(RecalculateCourse::class, fn (RecalculateCourse $job): bool => collect($job->middleware)
            ->contains(fn (object $middleware): bool => $middleware instanceof ReauthorizesJob));
    });

    it('will not run the job once the ability is gone', function (): void {
        $middleware = new ReauthorizesJob('recalculate_course', TestUser::class, 7, ['run-reports']);

        $ran = false;
        $middleware->handle(new stdClass, function () use (&$ran): void {
            $ran = true;
        });

        expect($ran)->toBeTrue();

        // Revoked between the dispatch and the worker picking it up.
        app(Gate::class)->define('run-reports', fn (): bool => false);

        expect(fn () => $middleware->handle(new stdClass, fn (): null => null))
            ->toThrow(JobNoLongerAuthorized::class, 'can no longer [run-reports]');
    });

    it('will not run the job once the actor is gone', function (): void {
        TestUser::query()->where('id', 7)->delete();

        $middleware = new ReauthorizesJob('recalculate_course', TestUser::class, 7, ['run-reports']);

        expect(fn () => $middleware->handle(new stdClass, fn (): null => null))
            ->toThrow(JobNoLongerAuthorized::class, 'no longer exists');
    });
});

describe('what became of it', function (): void {
    beforeEach(function (): void {
        Schema::create('courses', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->softDeletes();
            $table->timestamps();
        });

        Course::query()->insert([['id' => 4, 'title' => 'Cardiology']]);
    });

    it('returns an id of its own, and answers job_status for the caller who queued it', function (): void {
        Queue::fake();

        $actor = new TokenUser(['ops:write'], 7);

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 4],
            $actor,
        ));

        $id = $result->payload['id'];

        expect($id)->toBeString();

        // Tracking rides along as the outermost middleware, so a job refused
        // in the worker is recorded as failed rather than left queued.
        Queue::assertPushed(RecalculateCourse::class, fn (RecalculateCourse $job): bool => $job->middleware[0] instanceof TracksJobStatus
            && $job->middleware[1] instanceof ReauthorizesJob);

        auth()->guard()->setUser($actor);

        $response = app(JobStatusTool::class)->handle(new McpRequest(['id' => $id]));

        expect($response->getStructuredContent())->toMatchArray(['id' => $id, 'status' => 'queued', 'job' => 'RecalculateCourse'])
            ->and($response->responses()->first()->isError())->toBeFalse();
    });

    it('answers another caller as if the job never existed', function (): void {
        Queue::fake();

        $result = app(JobExecutor::class)->execute(callTool(
            toolNamed('recalculate_course', compileJobs()),
            ['course' => 4],
            new TokenUser(['ops:write'], 7),
        ));

        auth()->guard()->setUser(new TokenUser(['ops:write'], 8));

        $response = app(JobStatusTool::class)->handle(new McpRequest(['id' => $result->payload['id']]));

        expect($response->isError())->toBeTrue()
            ->and(app(JobStatusTool::class)->handle(new McpRequest(['id' => 'no-such-id']))->isError())->toBeTrue();
    });

    it('marks the job running, then done, or failed with the reason', function (): void {
        $statuses = app(JobStatuses::class);
        $definition = toolNamed('recalculate_course', compileJobs());

        $done = $statuses->queued(callTool($definition), RecalculateCourse::class);

        (new TracksJobStatus($done))->handle(new stdClass, function () use ($statuses, $done): void {
            expect($statuses->find($done)['status'])->toBe('running');
        });

        expect($statuses->find($done)['status'])->toBe('done')
            ->and($statuses->find($done)['finished_at'])->not->toBeNull();

        $failed = $statuses->queued(callTool($definition), RecalculateCourse::class);

        expect(fn () => (new TracksJobStatus($failed))->handle(new stdClass, function (): never {
            throw new RuntimeException('the import broke');
        }))->toThrow(RuntimeException::class);

        expect($statuses->find($failed)['status'])->toBe('failed')
            ->and($statuses->find($failed)['error'])->toBe('the import broke');
    });

    it('keeps the failure detail to itself unless the application exposes error details', function (): void {
        $actor = new TokenUser(['ops:write'], 7);
        $statuses = app(JobStatuses::class);

        $id = $statuses->queued(callTool(toolNamed('recalculate_course', compileJobs()), actor: $actor), RecalculateCourse::class);
        $statuses->mark($id, JobStatuses::FAILED, 'the import broke');

        auth()->guard()->setUser($actor);

        $hidden = app(JobStatusTool::class)->handle(new McpRequest(['id' => $id]));

        expect($hidden->getStructuredContent()['error'])->toBeNull()
            ->and($hidden->getStructuredContent()['status'])->toBe('failed');

        config(['mcp-bridge.security.expose_error_details' => true]);

        $shown = app(JobStatusTool::class)->handle(new McpRequest(['id' => $id]));

        expect($shown->getStructuredContent()['error'])->toBe('the import broke');
    });
});

it('fills a hidden constructor argument from the actor and never from the agent', function (): void {
    Queue::fake();
    Schema::create('courses', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->softDeletes();
        $table->timestamps();
    });
    Course::query()->create(['title' => 'Anatomy']);
    config()->set('mcp-bridge.security.fill_from_auth', ['reason']);

    $tool = toolNamed('recalculate_course', compileJobs());

    expect($tool->input->get('reason')?->hidden)->toBeTrue();

    $result = app(JobExecutor::class)->execute(callTool($tool, ['course' => 1, 'reason' => 'sent anyway'], new TokenUser(['ops:write'], 7)));

    expect($result->successful())->toBeTrue();

    Queue::assertPushed(RecalculateCourse::class, fn (RecalculateCourse $job): bool => $job->reason === '7');
});
