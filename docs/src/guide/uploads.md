# File uploads

An agent cannot attach a file to a request the way a browser does, and base64 inside a tool's arguments would spend its context on bytes it cannot read and break on anything large. So uploads take two steps: the agent **stages** a file once and gets an id, then passes that id to whichever tool wants a file.

## How it works

1. A route tool whose FormRequest has a `file`, `image`, `mimes`, `mimetypes` or `dimensions` rule gets an input described as "The id of a file staged with the upload_file tool", marked with the JSON Schema format `mcp-upload`. The rule itself stays in the description, so the agent still reads the type and size constraint.
2. The server adds a tool called `upload_file`, taking `name` (with its extension) and `contents` (base64). It returns an `upload_id`, the `name`, the `size` and an `expires_at`.
3. The agent calls the real tool with that id. The package claims the staged file and attaches it to the sub-request where a browser upload would have put it, so **your FormRequest's own rules run unchanged**.

`upload_file` is registered on a server only when a tool on that server takes a file. A tool an agent can see is a tool it will eventually try.

## Example

```php
class CourseCoverRequest extends FormRequest
{
    public function rules(): array
    {
        return ['cover' => 'required|file|mimes:png,jpg|max:2048'];
    }
}

class CourseController extends Controller
{
    #[McpTool(description: 'Attach a cover image to a course.')]
    public function cover(CourseCoverRequest $request): JsonResponse
    {
        $file = $request->file('cover');
        // $file->getClientOriginalName() is the name the agent staged it with
    }
}
```

The agent calls `upload_file` with `name` and `contents`, then `attach_course_cover` (or whatever the tool is named) with `cover` set to the returned id.

## Ownership and lifetime

A staged file belongs to whoever staged it. Claiming checks the owner as well as the id, so one agent cannot attach another's upload to its own call by guessing an id. An id that is not the actor's, has expired or was never staged does not arrive, and your `required` rule says so in the validation error.

Files are stored on `uploads.disk` under `mcp-bridge/uploads/`, with a row in `mcp_bridge_uploads`. They expire after `uploads.ttl` (default `24h`) and are limited to `uploads.max_kb` (default 10240, so 10 MB). Expiry stops a file being claimed; it does not delete it. Delete old files and rows:

```bash
php artisan mcp-bridge:prune-uploads
php artisan mcp-bridge:prune-uploads --older-than=12h
```

Schedule it; see [operating in production](/guide/operations#scheduling).

## Configuration

| Key | Default | Effect |
| --- | --- | --- |
| `uploads.enabled` | `true` | Whether the `upload_file` tool is offered. |
| `uploads.disk` | `local` | The filesystem disk staged files are written to. |
| `uploads.max_kb` | `10240` | Largest accepted file, in kilobytes. |
| `uploads.ttl` | `24h` | How long a staged file waits to be used. |

::: warning `upload_file` is not a manifest tool
It is added to the server directly, outside the compiled manifest, so it has no definition to run through `ToolRunner` and declares no scope or ability of its own. It writes its own audit entry, carrying the file's name and size and never its bytes: a trail that copied every byte an agent sent would be a second place the files are stored rather than a record of them.

Staging a file on its own grants nothing. The upload is useful only to a tool that accepts a file, and that tool is scope-checked and policy-checked as usual, so the receiving tool is where the authority is enforced. What bounds the uploader itself is the server's middleware, the size limit and the expiry. If that is not enough for your application, set `uploads.enabled` to `false`.
:::
