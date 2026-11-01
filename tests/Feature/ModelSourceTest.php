<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Execution\ModelExecutor;
use KayedSpace\McpBridge\Execution\ToolRunner;
use KayedSpace\McpBridge\Manifest\CompileResult;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Server\GeneratedTool;
use KayedSpace\McpBridge\Tests\Fixtures\Cohort;
use KayedSpace\McpBridge\Tests\Fixtures\Course;
use KayedSpace\McpBridge\Tests\Fixtures\Enrolment;
use KayedSpace\McpBridge\Tests\Fixtures\Lesson;
use KayedSpace\McpBridge\Tests\Fixtures\Member;
use KayedSpace\McpBridge\Tests\Fixtures\Policies\CoursePolicy;
use KayedSpace\McpBridge\Tests\Fixtures\Policies\MemberPolicy;
use KayedSpace\McpBridge\Tests\Fixtures\Programme;
use KayedSpace\McpBridge\Tests\Fixtures\TokenUser;

function compileModels(array $operations = ['list', 'get', 'create', 'update', 'delete']): CompileResult
{
    config()->set('mcp-bridge.sources.model.enabled', true);
    config()->set('mcp-bridge.sources.model.paths', [__DIR__.'/../Fixtures']);
    config()->set('mcp-bridge.sources.model.operations', $operations);

    return app(ManifestCompiler::class)->compile();
}

function runModel(string $tool, array $arguments = []): SourceResult
{
    return app(ModelExecutor::class)->execute(callTool(
        toolNamed($tool, compileModels()),
        $arguments,
        new TokenUser(['courses:write'], 3),
    ));
}

beforeEach(function (): void {
    CoursePolicy::$allows = true;
    Gate::policy(Course::class, CoursePolicy::class);
    Gate::define('manage-catalogue', fn (): bool => true);

    Schema::create('courses', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
});

describe('discovery', function (): void {
    it('makes one tool per operation the policy answers for', function (): void {
        $names = compileModels()->names();

        expect($names)->toContain('model_list_courses', 'model_get_course', 'model_create_course', 'model_update_course')
            ->and($names)->not->toContain('model_delete_course');
    });

    it('explains the operation its policy is silent about', function (): void {
        $refusals = compileModels()->refusals;

        expect($refusals['model:'.Course::class.':delete'][0]->message)
            ->toContain('CoursePolicy::delete()')
            ->toContain('did not mean to allow it');
    });

    it('produces nothing at all for a model with no policy', function (): void {
        $result = compileModels(['list']);

        // Enrolment is opted in and fully authorised, and still produces
        // nothing, because nothing can decide per record.
        expect($result->names())->not->toContain('model_list_enrolments')
            ->and($result->refusals['model:'.Enrolment::class.':list'][0]->message)
            ->toContain('has no registered policy');
    });

    it('only honours the operations the application configured', function (): void {
        expect(compileModels(['list'])->names())->toBe(['model_list_cohorts', 'model_list_courses', 'model_list_members', 'model_list_programmes']);
    });

    it('produces no tool from an empty operations list', function (): void {
        // An empty list used to fall back to the defaults, which is the
        // opposite of what an empty allowlist should do.
        expect(compileModels([])->names())->not->toContain('model_list_courses', 'model_get_course');
    });

    it('infers safety from the operation, which is the one place it can', function (): void {
        $result = compileModels();

        expect(toolNamed('model_list_courses', $result)->safety->readOnly)->toBeTrue()
            ->and(toolNamed('model_update_course', $result)->safety->idempotent)->toBeTrue()
            ->and(toolNamed('model_update_course', $result)->safety->destructive)->toBeFalse()
            ->and(toolNamed('model_create_course', $result)->safety->readOnly)->toBeFalse();
    });
});

describe('the schema', function (): void {
    it('exposes only mass-assignable attributes for a write', function (): void {
        $input = toolNamed('model_create_course', compileModels())->input;

        expect($input->get('title'))->not->toBeNull()
            ->and($input->get('status')?->enum)->toBe(['draft', 'published', 'archived'])
            ->and($input->get('id'))->toBeNull()
            ->and($input->get('deleted_at'))->toBeNull();
    });

    it('gives a list the same query inputs a route list would have', function (): void {
        $input = toolNamed('model_list_courses', compileModels())->input;

        expect($input->get('filter.title')?->description)->toContain('contains')
            ->and($input->get('filter.status')?->enum)->toBe(['draft', 'published', 'archived'])
            ->and($input->get('sort.*')?->enum)->toContain('id', '-id', 'created_at')
            ->and($input->get('per_page')?->max)->toBe(25);
    });

    it('asks for the key on the operations that need one', function (): void {
        $result = compileModels();

        expect(toolNamed('model_get_course', $result)->input->get('id')?->required)->toBeTrue()
            ->and(toolNamed('model_update_course', $result)->input->get('id')?->required)->toBeTrue()
            ->and(toolNamed('model_create_course', $result)->input->get('id'))->toBeNull();
    });
});

it('keeps hidden and redacted attributes out of filters, sorts and writes', function (): void {
    Gate::policy(Member::class, MemberPolicy::class);

    $result = compileModels(['list', 'create']);
    $list = toolNamed('model_list_members', $result)->input;
    $create = toolNamed('model_create_member', $result)->input;

    expect($list->get('filter.name'))->not->toBeNull()
        ->and($list->get('filter.password'))->toBeNull()
        ->and($list->get('filter.api_token'))->toBeNull()
        ->and($list->get('sort.*')?->enum)->toContain('name', '-email')
        ->and($list->get('sort.*')?->enum)->not->toContain('password', '-password', 'api_token')
        ->and($create->get('email'))->not->toBeNull()
        ->and($create->get('password'))->toBeNull()
        ->and($create->get('api_token'))->toBeNull();
});

it('prefixes model tools so they sit beside the routes in front of the same model', function (): void {
    defineApiRoutes();

    $names = compileModels(['list', 'get'])->names();

    expect($names)->toContain('get_course', 'model_get_course', 'list_courses', 'model_list_courses');

    config()->set('mcp-bridge.naming.source_prefixes', ['model' => 'db']);

    expect(compileModels(['get'])->names())->toContain('db_get_course');
});

it('refuses a model tool whose name a route already claims, and keeps the route', function (): void {
    defineApiRoutes();
    config()->set('mcp-bridge.naming.source_prefixes', []);

    $result = compileModels(['get']);
    $refusals = $result->refusals['model:KayedSpace\\McpBridge\\Tests\\Fixtures\\Course:get'] ?? [];

    expect(toolNamed('get_course', $result)->source)->toBe('route')
        ->and(array_map(fn ($refusal): string => $refusal->code, $refusals))->toBe(['MCP_BRIDGE_NAME_TAKEN'])
        ->and($refusals[0]->message)->toContain('#[McpTool(name:');
});

describe('execution', function (): void {
    // No explicit ids: PostgreSQL would not advance the sequence past them,
    // and the next create would collide with the first row.
    beforeEach(fn () => Course::query()->insert([
        ['title' => 'Cardiology', 'status' => 'published'],
        ['title' => 'Paediatrics', 'status' => 'draft'],
    ]));

    it('lists through the query layer, filtered and paginated', function (): void {
        $result = runModel('model_list_courses', ['filter' => ['status' => 'published']]);

        expect($result->successful())->toBeTrue()
            ->and($result->payload['data'])->toHaveCount(1)
            ->and($result->payload['data'][0]['title'])->toBe('Cardiology')
            ->and($result->payload['meta']['per_page'])->toBe(15);
    });

    it('answers the page the agent asked for', function (): void {
        foreach (range(3, 12) as $i) {
            Course::query()->insert(['title' => "Course {$i}", 'status' => 'published']);
        }

        $result = runModel('model_list_courses', ['page' => 2, 'per_page' => 5]);

        expect(array_column($result->payload['data'], 'id'))->toBe([6, 7, 8, 9, 10])
            ->and($result->payload['meta']['current_page'])->toBe(2);
    });

    it('redacts a list the way it redacts a record', function (): void {
        config()->set('mcp-bridge.security.redact', ['title']);

        $actor = new TokenUser(['courses:write'], 3);
        $list = app(ToolRunner::class)->run(callTool(toolNamed('model_list_courses', compileModels()), [], $actor));
        $record = app(ToolRunner::class)->run(callTool(toolNamed('model_get_course', compileModels()), ['id' => 1], $actor));

        expect($list->structured['data'][0])->not->toHaveKey('title')
            ->and($list->structured['data'][0])->not->toHaveKey('deleted_at')
            ->and($record->structured['data'])->not->toHaveKey('title');
    });

    it('reads one record', function (): void {
        expect(runModel('model_get_course', ['id' => 2])->payload['data']['title'])->toBe('Paediatrics');
    });

    it('creates and updates through the model', function (): void {
        $created = runModel('model_create_course', ['title' => 'Neurology', 'status' => 'draft']);

        expect($created->payload['data']['title'])->toBe('Neurology')
            ->and($created->meta['created'])->toBeTrue();

        $updated = runModel('model_update_course', ['id' => 1, 'status' => 'archived']);

        expect($updated->payload['data']['status'])->toBe('archived')
            ->and(Course::query()->find(1)?->status->value)->toBe('archived');
    });

    it('asks the policy about the record, and refuses when it says no', function (): void {
        CoursePolicy::$allows = false;

        $result = runModel('model_get_course', ['id' => 1]);

        expect($result->status)->toBe(403);
    });

    it('reports a missing record as not found', function (): void {
        expect(runModel('model_get_course', ['id' => 404])->status)->toBe(404);
    });

    it('never writes an attribute the schema did not offer', function (): void {
        $result = runModel('model_create_course', ['title' => 'Neurology', 'id' => 99]);

        expect($result->payload['data']['id'])->not->toBe(99);
    });
});

describe('fields the application fills', function (): void {
    it('never offers an agent a field filled from the authenticated user', function (): void {
        // user_id is in Course::$fillable, and in the shipped fill_from_auth
        // default, so a model write used to hand the agent the ownership of
        // the record it was creating.
        $input = toolNamed('model_create_course', compileModels())->input;

        expect($input->get('user_id'))->not->toBeNull()
            ->and($input->get('user_id')?->hidden)->toBeTrue()
            ->and($input->get('user_id')?->filledFrom)->toBe('auth')
            ->and($input->get('title')?->hidden)->toBeFalse();
    });

    it('keeps it out of the schema the agent is actually handed', function (): void {
        // Hidden on the field is only half of it: what matters is that the
        // advertised schema does not mention it.
        $tool = app(GeneratedTool::class, ['definition' => toolNamed('model_create_course', compileModels())]);

        $properties = $tool->toArray()['inputSchema']['properties'];

        expect($properties)->toHaveKey('title')
            ->and($properties)->not->toHaveKey('user_id');
    });
});

it('fills the owner from the actor rather than leaving the column unset', function (): void {
    // Hiding user_id from the agent only works if something then sets it. A
    // route has a controller for that; a model write does not, so a
    // non-nullable column made this tool fail every time.
    $definition = toolNamed('model_create_course', compileModels());

    $field = $definition->input->get('user_id');
    expect($field)->not->toBeNull();
    expect($field->hidden)->toBeTrue();
    expect($field->filledFrom)->toBe('auth');

    // The model executor, not app(ExecutesTools::class): that contract is
    // bound to the route executor, so resolving it here runs a model tool
    // through the wrong one.
    $result = app(ModelExecutor::class)->execute(callTool(
        $definition,
        ['title' => 'Immunology', 'status' => 'draft'],
        new TokenUser(['courses:write'], 77),
    ));

    expect($result->successful())->toBeTrue()
        ->and(Course::query()->where('title', 'Immunology')->value('user_id'))->toBe(77);
});

describe('includes', function (): void {
    beforeEach(function (): void {
        Schema::create('programmes', function ($table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        Schema::table('courses', function ($table): void {
            $table->unsignedBigInteger('programme_id')->nullable();
        });
        Schema::create('lessons', function ($table): void {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->timestamps();
        });

        $this->programme = Programme::query()->create(['title' => 'Medicine']);
        $course = Course::query()->create(['title' => 'Anatomy']);
        $course->forceFill(['programme_id' => $this->programme->getKey()])->save();
        Lesson::query()->create(['title' => 'Bones', 'course_id' => $course->getKey(), 'programme_id' => $this->programme->getKey()]);
    });

    it('offers the declared includes on list and get, without a hidden relation or one past the depth', function (): void {
        $result = compileModels();
        $list = toolNamed('model_list_programmes', $result)->input;
        $get = toolNamed('model_get_programme', $result)->input;

        expect($list->get('include.*')?->enum)->toBe(['courses', 'courses.lessons', 'coursesCount', 'notes'])
            ->and($get->get('include.*')?->enum)->toBe(['courses', 'courses.lessons', 'coursesCount', 'notes'])
            ->and($get->get('id'))->not->toBeNull()
            ->and($get->get('filter.title'))->toBeNull();

        config()->set('mcp-bridge.query.max_include_depth', 1);

        expect(toolNamed('model_list_programmes', compileModels())->input->get('include.*')?->enum)
            ->toBe(['courses', 'coursesCount', 'notes']);
    });

    it('loads relations with the list and onto one record, and never a hidden one', function (): void {
        $list = runModel('model_list_programmes', ['include' => ['courses.lessons', 'coursesCount']]);
        $get = runModel('model_get_programme', ['id' => $this->programme->getKey(), 'include' => ['courses']]);

        expect($list->payload['data'][0]['courses'][0]['lessons'][0]['title'])->toBe('Bones')
            ->and($list->payload['data'][0]['courses_count'])->toBe(1)
            ->and($get->payload['data']['courses'][0]['title'])->toBe('Anatomy')
            ->and($get->payload['data'])->not->toHaveKey('secrets')
            ->and(runModel('model_get_programme', ['id' => $this->programme->getKey(), 'include' => ['secrets']])->status)->toBe(400);
    });

    it('opens a gated relation only to an actor the gate allows, and names what they may have', function (): void {
        Gate::define('see-notes', fn (): bool => false);

        $closed = runModel('model_list_programmes', ['include' => ['notes']]);

        expect($closed->status)->toBe(400)
            ->and($closed->payload['message'])->toContain('courses')
            ->and($closed->payload['message'])->not->toContain(', notes');

        Gate::define('see-notes', fn (): bool => true);

        expect(runModel('model_get_programme', ['id' => $this->programme->getKey(), 'include' => ['notes']])->payload['data']['notes'][0]['title'])->toBe('Bones');
    });
});

describe('what a model declares about its own tools', function (): void {
    beforeEach(function (): void {
        Schema::create('cohorts', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });
    });

    it('narrows its operations although the policy would allow them all, and never widens them', function (): void {
        $names = compileModels()->names();

        expect($names)->toContain('model_list_cohorts', 'model_get_cohort', 'model_create_cohort')
            ->and($names)->not->toContain('model_update_cohort', 'model_delete_cohort');

        $narrowed = compileModels(['list', 'get']);

        expect($narrowed->names())->not->toContain('model_create_cohort')
            ->and($narrowed->refusals['model:'.Cohort::class.':create'][0]->message)->toContain('sources.model.operations');
    });

    it('fills a column from the actor, keeps an exempted one for the agent, describes a field and caps its pages', function (): void {
        config()->set('mcp-bridge.security.fill_from_auth', ['owner_id', 'code']);

        $result = compileModels();
        $create = toolNamed('model_create_cohort', $result)->input;
        $list = toolNamed('model_list_cohorts', $result)->input;

        expect($create->get('owner_id')?->hidden)->toBeTrue()
            ->and($create->get('owner_id')?->filledFrom)->toBe('auth')
            ->and($create->get('code')?->hidden)->toBeFalse()
            ->and($create->get('title')?->description)->toBe('The cohort title, shown to students.')
            ->and($list->get('per_page')?->max)->toBe(5);

        $created = runModel('model_create_cohort', ['title' => 'Spring', 'code' => 'SP-1', 'owner_id' => 99]);

        expect($created->payload['data']['owner_id'])->toBe(3)
            ->and($created->payload['data']['code'])->toBe('SP-1');
    });
});

describe('fields the actor fills', function (): void {
    it('fills the owner on create and leaves it alone on update', function (): void {
        $created = runModel('model_create_course', ['title' => 'Owned', 'status' => 'draft', 'user_id' => 99]);

        expect($created->payload['data']['user_id'])->toBe(3);

        $theirs = Course::query()->create(['title' => 'Theirs', 'status' => 'draft', 'user_id' => 9]);

        runModel('model_update_course', ['id' => $theirs->getKey(), 'status' => 'archived']);

        expect(Course::query()->find($theirs->getKey())?->user_id)->toBe(9);
    });
});

describe('runtime caps', function (): void {
    it('fetches no more rows than the tool caps, so the next page starts where this one ended', function (): void {
        Schema::create('cohorts', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });

        foreach (range(1, 8) as $i) {
            Cohort::query()->create(['title' => "Cohort {$i}"]);
        }

        $page = runModel('model_list_cohorts', ['per_page' => 20]);

        expect($page->payload['meta']['per_page'])->toBe(5)
            ->and($page->payload['data'])->toHaveCount(5);
    });

    it('holds a call to the configured number of includes', function (): void {
        Schema::create('programmes', function ($table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        Schema::table('courses', function ($table): void {
            $table->unsignedBigInteger('programme_id')->nullable();
        });
        config()->set('mcp-bridge.query.max_includes', 1);
        Programme::query()->create(['title' => 'Medicine']);

        $result = runModel('model_list_programmes', ['include' => ['courses', 'coursesCount']]);

        expect($result->status)->toBe(400)
            ->and($result->payload['message'])->toContain('At most 1 include');
    });
});
