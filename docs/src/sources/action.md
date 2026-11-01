# Actions

Action classes (one use case per class) are a shape many Laravel applications already organise their write paths in. This source exposes them as tools without asking for another abstraction.

## What it exposes

Classes under `sources.action.paths` (default `['app/Actions']`) that have a `handle()` or `__invoke()` method. `handle` is preferred when both exist. The source is off by default (`sources.action.enabled`).

## What it needs to be opted in

On the class: `#[McpTool]`, `#[McpScope]` and `#[McpAbility]`. A candidate whose signature cannot be built from JSON is refused with the parameter named, exactly as for [jobs](/sources/job).

## Where the schema comes from

- **An action that declares `rules()`** is read from those rules. This is what makes [`lorisleiva/laravel-actions`](https://github.com/lorisleiva/laravel-actions) work without this package depending on it: the rules are the declaration, wherever they came from. Rules describe what an action *accepts*, which is a better description than its signature, which only says what it needs.
- **An action without `rules()`** is read from its own signature: typed parameters, defaults, nullability, backed enums and models.
- **A parameter typed as a [`spatie/laravel-data`](https://github.com/spatie/laravel-data) object** contributes the object's own validation rules, flat: an agent sees `title` and `status`, not a `data` whose shape it would have to guess. The executor builds the object with `validateAndCreate()`, so the object's rules are what validate the call.

## Example

An action in the shape `lorisleiva/laravel-actions` uses, where `rules()` says what is accepted, `authorize()` says who may and `handle()` does the work:

```php
use KayedSpace\McpBridge\Attributes\McpAbility;
use KayedSpace\McpBridge\Attributes\McpScope;
use KayedSpace\McpBridge\Attributes\McpTool;

#[McpTool(description: 'Publish a course.')]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
class PublishCourse
{
    public function rules(): array
    {
        return [
            'course_id' => 'required|integer|min:1',
            'announce' => 'boolean',
            'note' => 'nullable|string|max:120',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function handle(int $course_id, bool $announce = false, ?string $note = null): array
    {
        return ['id' => $course_id, 'announced' => $announce, 'note' => $note];
    }
}
```

An action with no rules is the signature itself, and its safety is declared:

```php
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[McpTool(description: 'Archive a course, with an optional reason.')]
#[McpScope('courses:write')]
#[McpAbility('publish-courses')]
#[IsDestructive]
class ArchiveCourse
{
    public function __invoke(int $courseId, ?string $reason = null): array
    {
        return ['id' => $courseId, 'reason' => $reason];
    }
}
```

These compile to `publish_course` and `archive_course`: the class name, minus a trailing `Action`.

## How it runs

`ActionExecutor` runs the action the way your own code does:

1. Validate the arguments with the action's `rules()`, if it has them. Keys the rules do not mention are **dropped**, because an action's rules are its statement of what it accepts. A failure becomes a field-by-field validation error. A parameter typed as a data object is then built from the arguments with its own `validateAndCreate()`, which validates the same way.
2. Call the action's own `authorize()` through the container. A `false` answer is a refusal (`MCP_BRIDGE_FORBIDDEN`). It is called rather than reimplemented, so an action behaves for an agent exactly as it does elsewhere, and an action package this one has never heard of gets the same treatment.
3. Call `handle()` or `__invoke()` through the container with the validated arguments, as the actor.

Whatever the action returns becomes the result: return an array, a model or a resource, and it is shaped like any other output.

## Safety

An action is not destructive by default. It is a use case, so what it does is as opaque as a job, the declaration decides, and nothing is inferred from the class name. Declare `#[IsDestructive]` for confirmation.

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `sources.action.enabled` | `false` | Switch the source on. |
| `sources.action.paths` | `['app/Actions']` | Where classes are scanned. |

The shipped `ops` server lists `action` among its sources, so action tools land on `/mcp/ops`, behind `auth:mcp-bridge`. Set `sources.action.server` to place them elsewhere; the full order is in [where a tool lands](/guide/servers-and-modules#where-a-tool-lands).
