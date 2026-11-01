---
layout: home
title: Laravel MCP Bridge, turn your Laravel app into an MCP server for AI agents
titleTemplate: false
description: Turn your Laravel app into an MCP server for AI agents, built on laravel/mcp. Your authentication, gates and policies still decide.
head:
  - - meta
    - name: keywords
      content: Laravel MCP, Model Context Protocol, MCP server, Laravel package, AI agents, laravel/mcp, tools
hero:
  name: Laravel MCP Bridge
  text: Turn your Laravel app into an MCP server for AI agents.
  tagline: Your authentication, gates and policies still decide.
  image:
    light: /mark.svg
    dark: /mark-dark.svg
    alt: Laravel MCP Bridge
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: Security model
      link: /guide/security
    - theme: alt
      text: GitHub
      link: https://github.com/kayedspace/laravel-mcp-bridge
features:
  - icon: { src: /icons/attribute.svg, width: 28, height: 28 }
    title: One attribute, no tool classes
    details: "FormRequest rules, route model binding, Spatie Query Builder chains and Scramble or Scribe attributes become the input schema. Mark a route with #[McpTool] and it is a tool."
    link: /concepts/schema
    linkText: How schemas are inferred
  - icon: { src: /icons/auth.svg, width: 28, height: 28 }
    title: Your auth still decides
    details: A route tool runs through the HTTP kernel, so your middleware authenticates it, your FormRequest validates it and your policy authorizes it. A tool can take permissions away, never add one.
    link: /concepts/how-it-works
    linkText: Follow a call
  - icon: { src: /icons/toggle.svg, width: 28, height: 28 }
    title: Opt-in, or opt-out
    details: Nothing is exposed until you mark it. Switch routes to read-only or opt-out with one setting; commands, jobs and models stay opt-in with a scope and a Gate ability in every mode.
    link: /guide/security#routes-follow-the-mode
    linkText: Exposure modes
  - icon: { src: /icons/servers.svg, width: 28, height: 28 }
    title: Eight sources, two servers
    details: Routes and resources on /mcp for agents acting as your users. Commands, jobs, actions, models, remote MCP servers and OpenAPI documents on /mcp/ops for operators.
    link: /sources/
    linkText: Browse the sources
  - icon: { src: /icons/retry.svg, width: 28, height: 28 }
    title: Built for agents that retry and guess
    details: Destructive tools ask for confirmation with a single-use handle, writes take an idempotency key, and an unknown argument is refused with the names that would work.
    link: /concepts/output-and-errors
    linkText: What agents receive
  - icon: { src: /icons/operate.svg, width: 28, height: 28 }
    title: Ready to operate
    details: Scoped tokens, a panel to issue and revoke them, an audit row per call, a kill switch without a deploy, a lint for CI and checks in php artisan doctor.
    link: /guide/operations
    linkText: Operating in production
---

## One attribute

Laravel MCP Bridge turns what a Laravel application already declares into an MCP server, built on the official [`laravel/mcp`](https://github.com/laravel/mcp) package.

```php
use KayedSpace\McpBridge\Attributes\McpTool;

class CourseController extends Controller
{
    #[McpTool(description: 'List published courses with optional title search.')]
    public function index(CourseIndexRequest $request)
    {
        // unchanged
    }
}
```

The agent sees `list_courses` with a typed input schema built from `CourseIndexRequest`. The call goes through the same route pipeline as a REST request, so the same middleware and policies decide.

## Pick your path

- [Get started](/guide/getting-started): install, expose one endpoint, connect a client and watch a tool work.
- [Read the security model](/guide/security) before exposing anything that is not a route.
- Understand the [four-step pipeline](/concepts/how-it-works): discovery, schema, compilation, running.
- Look up an [attribute](/reference/attributes), a [config key](/reference/configuration) or an [Artisan command](/reference/commands).
