# Working with AI agents

Two audiences read this page: the AI coding agent that is helping you adopt and run the package, and the agent on the other end of the tools you expose.

## Two skills for your coding agent

The package ships two [Laravel Boost](https://github.com/laravel/boost) skills under `resources/boost/skills/`, and Boost installs both into an application that has the package.

- **`mcp-bridge`** is for building the surface. An agent reads it and knows how to expose an endpoint, open a model's relations, what the attributes mean, and which mistakes to avoid. Its core instruction is the one that matters most here: expose the smallest useful surface, and let the application's own middleware, policies and validation keep deciding. Never write a wrapper controller, a duplicate schema or a hand-built `Tool` class.
- **`mcp-bridge-operations`** is for running it. It starts every incident with `mcp-bridge:doctor`, covers the kill switch, token revocation, the deploy and the manifest cache, pruning, and a table from the symptom a client reports to the place to look.

They are separate so the one an agent loads stays short, and because the person exposing a tool and the person paged about it are often not the same.

`AGENTS.md` and `.agents/skills/` in the package repository are for an agent working on the package itself. They are development-only and are not shipped.

## What the agent is told

A client receives the server's `instructions` at `initialize`, before it lists a single tool. With `servers.<key>.instructions` left `null`, the package writes them from what that server actually serves: that the tools are the application's own API run as the token's owner, that an undeclared argument is refused with the accepted names, how lists take `filter`, `sort`, `include` and paging, the confirmation handshake when a destructive tool is present, `idempotency_key` when a write tool offers one, `job_status` when a job tool is served, `upload_file` when a tool takes a file, what each refusal code asks the agent to do, and which resources exist. A paragraph whose subject the server does not have is not sent, so the agent pays for nothing it cannot use.

A string replaces the text with your own, and `false` sends none.

The same text is available as a file for a team that keeps Claude Code skills in the repository:

```bash
php artisan mcp-bridge:connect claude --token=mcpb_... --apply --skill
```

writes `.claude/skills/<app>-mcp/SKILL.md` beside the client configuration. It is generated from the server, not shipped, so it never describes a tool the server does not have.

## Writing tools agents use well

An agent chooses a tool and fills its arguments from text, so most of the quality of your agent surface is the quality of that text.

- **Describe every tool.** A tool without a description gets one generated from its name and target, and `mcp-bridge:lint` warns about it. Say what it does and when to use it.
- **Add an example when the shape is not obvious.** One worked example often beats another sentence of prose:

  ```php
  #[McpExample(input: ['from' => '2026-01-01'], description: 'since the start of the year')]
  ```

- **Refine, do not duplicate.** Use `#[McpParam]` to describe, retype, hide or add one field. Do not write a second schema beside the FormRequest.
- **Say when a tool is destructive.** `#[IsDestructive]` from `laravel/mcp`, or `#[McpConfirm('...')]` for the message the user sees. See [destructive calls and confirmation](/guide/security#destructive-calls-and-confirmation).
- **Hide what the application fills.** `user_id` and friends belong in `security.fill_from_auth`, not in the agent's hands.
- **Keep lists narrow.** Declare the filters an endpoint supports, so the agent can narrow an answer rather than page through it. Declare `#[McpOutput(fields: [...])]` to return only the keys that matter.
- **Mark retired tools.** `#[McpDeprecated('Use list_courses instead.', until: '2.0')]` keeps a tool working and tells the agent to stop reaching for it.
- **Do not expose a command that prompts.** An agent cannot answer a question, and `--no-interaction` is always set, so the command fails. Add an option, or mark it `#[McpIgnore]`.
- **Do not pass secrets as tool arguments.** See [redaction](/guide/audit-and-events#redaction).
- **Rebuild a cached manifest after changing a declaration.** See [keeping the manifest current](/guide/operations#keeping-the-manifest-current).

## Seeing what the agent sees

```bash
php artisan mcp-bridge:export --format=markdown   # every tool, as the agent reads it
php artisan mcp-bridge:call list_courses --arg=per_page=2
```

If an answer reads badly in that output, it reads badly to the agent. Change the description or the inputs, not the agent.

## Anti-patterns

- Writing a `Tool` class by hand when a route or command already does the work. That is the duplication this package removes.
- Setting `mode` to `opt-out` to save typing. See [routes follow the mode](/guide/security#routes-follow-the-mode).
- Turning off `security.require_scope_for_non_http`. See [everything else is opt-in](/guide/security#everything-else-is-opt-in-and-needs-authority).
