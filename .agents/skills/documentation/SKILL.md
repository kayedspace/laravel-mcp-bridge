---
name: documentation
description: >
  Write, extend and check the VitePress documentation site in docs/ for
  kayedspace/laravel-mcp-bridge. Use when asked to document a feature, when a
  source, attribute, config key, Artisan command or testing helper is added or
  changed, when the security model changes, or when checking that the docs
  still match the code.
license: MIT
---

# Documenting the package

The docs are a product with one job: let a Laravel developer install the
package, expose something, and trust what they exposed. The security model is
the subject they most need to get right, so it gets the most care.

Accuracy beats completeness, and both beat polish. A page that describes
behaviour the code does not have is worse than a missing page, because the
reader acts on it.

## How the site is structured, and why

The site lives in `docs/` and follows the same setup as the author's other
documentation site (`laravel-erpnext-docs`): VitePress 1.x, `srcDir: 'src'`,
local search, clean URLs, a small theme file, a nav and sidebar defined once in
`docs/.vitepress/config.ts`.

```
docs/
  package.json               scripts: build, start, docs:dev, docs:build, docs:preview
  .vitepress/
    config.ts                nav, sidebar, head, edit link
    theme/index.ts, style.css  brand colours only
  src/
    index.md                 landing page: hero, six features, one example, paths
    guide/                   tasks, in the order someone does them
    concepts/                how it works, and why
    sources/                 one page per source, plus an index table
    reference/               lookups: attributes, config, commands, testing, codes, PHP API
    public/                  logo and favicon
```

Why this split:

- **guide/** answers "how do I". The sidebar splits it into three groups:
  **Start** (`getting-started`, a working call in three commands, then
  `adopting`, `connecting-clients` and `security`), **Features** (list
  endpoints, uploads, documentation packages, servers and modules, Filament,
  AI agents) and **Operate** (audit, operations, testing, troubleshooting).
  `security.md` sits in Start because it is the page people must
  not skip.
- **concepts/** answers "why does it behave like that": the four-step
  pipeline from `AGENTS.md`, schema inference, naming, output and errors.
- **sources/** has one page per source, always in the same shape: what it
  exposes, what it needs to be opted in, a working example, how it runs,
  safety, configuration.
- **reference/** is exhaustive and boring on purpose. Every attribute, every
  config key, every command, every helper, every code. A reader arrives with a
  name and wants a row.

### Where a new page goes

| You are documenting | Put it in | Also touch |
| --- | --- | --- |
| A new source | `src/sources/<key>.md` | `sources/index.md` table, `config.ts` sidebar, `guide/security.md` table, `reference/configuration.md`, `concepts/how-it-works.md` if the count of sources is stated |
| A task a user performs | `src/guide/<task>.md` | `config.ts`: the `start`, `features` or `operate` list |
| An explanation of behaviour | `src/concepts/<topic>.md` | `config.ts` sidebar |
| A lookup | The matching page in `src/reference/` | Nothing else |

Do not add a new top-level section without a reason. Sidebar entries are added
in `config.ts` by hand; a page that is not in the sidebar is invisible to
anyone who does not know the URL.

## Run, build, preview

```bash
cd docs
rtk npm install
rtk npm run docs:dev        # http://localhost:5173, live reload
rtk npm run build           # outputs to docs/.vitepress/dist
rtk npm run start           # serves the build, honours $PORT (default 4173)
```

**The build is the test.** VitePress fails on a dead internal link, so a
passing build means every `[text](/path)` resolves. It does not check
`#anchors`: after adding a link with an anchor, confirm the heading exists by
running the build and grepping `docs/.vitepress/dist/<page>.html` for
`id="<anchor>"`. A heading's id is its text, lowercased, with runs of
punctuation and spaces turned into one hyphen (`mcp-bridge:lint` becomes
`mcp-bridge-lint`).

`docs/.vitepress/dist`, `docs/.vitepress/cache` and `docs/node_modules` are
ignored by the root `.gitignore`. The docs must also be `export-ignore`d in
`.gitattributes` (`/docs export-ignore`) so `composer require` does not
download them. Check that the line is there; the dist archive check in the
`package-release` skill catches it if it is not.

## Writing conventions

- **Voice.** British-neutral, plain, confident. Second person ("you") for
  tasks, third person for what the package does. No marketing adjectives, no
  "simply", no "just".
- **Why, not what.** The code and the reference say what. Prose says why a
  rule exists: why model tools need a policy, why a handle is single use, why
  `mode` does not apply to commands. Reuse the reasoning in the code comments
  and `AGENTS.md`; it is already right.
- **No em dashes or en dashes anywhere**, in prose, code, comments or commit
  messages. Use commas, colons or a new sentence. A plain hyphen only inside
  compound words and in command flags. Check before finishing:

  ```bash
  rtk grep -rnP "[\x{2013}\x{2014}]" docs/src docs/.vitepress/config.ts
  ```

- **No emojis.**
- **Code samples must be real.** Every class, attribute, method, config key,
  option and command in a sample exists in `src/` or `config/`, spelled
  exactly. Prefer a sample lifted from `tests/Feature` or `tests/Fixtures` and
  trimmed over one invented. Include the `use` statements when the namespace is
  not obvious. Samples are PHP unless they are shell, and a shell sample is a
  command a person can run.
- **One name per concept**, the technical one, used everywhere: "tool",
  "source", "manifest", "candidate", "authority", "scope", "ability".
- **Link, do not repeat.** The security rules are stated in full in
  `guide/security.md`. Other pages state the rule that applies in one line and
  link there.
- **Admonitions** (`::: tip`, `::: warning`, `::: info`, `::: danger`) are for
  a consequence the reader would otherwise miss. Do not decorate.
- **Tables for lookups, prose for reasoning.** A reference page that is not a
  table is probably hiding something.
- **Say what is not enforced.** If the runtime records something but does not
  act on it, the page says so, in the place the reader meets it.
  `#[McpTimeout]` is reported in the description and noted by the lint, and
  never interrupts an in-process call; `sources.command.timeout` and
  `sources.job.timeout` only set the idempotency in-flight window for a tool
  without the attribute, and reach neither the description nor the lint.
- **State a rule once.** Each of these has one home, and every other page
  gives one sentence and a link: server placement
  (`guide/servers-and-modules#where-a-tool-lands`), the command deny-list
  floor (`sources/command`), the model policy table (`sources/model`), the
  kill-switch commands and manifest staleness (`guide/operations`), redaction
  (`guide/audit-and-events#redaction`), idempotency keys
  (`concepts/output-and-errors`), the glossary (`concepts/how-it-works`),
  the route modes (`guide/security#routes-follow-the-mode`), detecting an
  agent call (`reference/php-api#the-facade`), source prefixes and name
  collisions (`concepts/naming#source-prefixes`, `concepts/naming#collisions`),
  the model source's exposed columns (`sources/model#the-schema-comes-from-the-model`),
  and the lint findings (`reference/error-codes#lint-findings`).
- **Name a model tool with its prefix.** `naming.source_prefixes` ships
  `model`, so a model tool is `model_get_course`. A bare `get_course` in a
  sample is a route tool; check which one the sample means.
- **Docs state what is.** No history: no "used to", "now", "yet", "once",
  "was removed". History belongs in git and on the GitHub release, never in the docs.
- **One line per paragraph**, sentence-case headings and sidebar labels, and
  absolute links (`/guide/security`), never relative `.md` links.
- Page titles are sentence case. The page's first heading is its title.

## The completeness checklist, keyed to the codebase

Run the row that matches your change. A change is not done until its row is.

| When the code gains... | The docs must gain... |
| --- | --- |
| **A source** (`src/Sources/*Source.php`, registered in `McpBridgeServiceProvider`) | A page in `src/sources/` in the standard shape; a row in the `sources/index.md` table, including default server and enabled-by-default; a row in the "What each source needs" table in `guide/security.md` and a sentence on its authority; a `sources.<key>` block in `reference/configuration.md`; the attribute applicability table in `sources/index.md`; a sidebar entry. If it is not authority-gated like a route, say why on the security page. |
| **An attribute** (`src/Attributes/*.php`) | A section in `reference/attributes.md` with signature, targets, every parameter and **the class that reads it, named**. If nothing reads it, say so or do not document it. Update the applicability table in `sources/index.md`. |
| **A config key** (`config/mcp-bridge.php`) | A row in `reference/configuration.md` with default and effect, and whether it is read at compile time or call time. Grep `src/` to confirm it is read; if it is not, it goes in "Present in the file but not read yet". |
| **An Artisan command** (`src/Console/*Command.php`) | An entry in `reference/commands.md` with the exact signature, options and exit behaviour, and a row in the summary table. If it is part of the deploy or operating flow, mention it in `guide/operations.md`. |
| **A testing helper** (a public method on `Testing/InteractsWithMcp.php`) | A row in `reference/testing.md` and, if it changes how people test, a section in `guide/testing.md`. |
| **A refusal or error code** (`MCP_BRIDGE_*`) | A row in `reference/error-codes.md`, and in the tables in `guide/troubleshooting.md` if a person would meet it. |
| **A lint finding** (`Lint/Linter.php`) | A row in the lint table in `reference/error-codes.md`, with its level. `guide/troubleshooting.md` links there and keeps no copy. |
| **A change to the security model** (anything in `src/Security/`, `AuthorityRequirement`, `ScopeGate`, exposure defaults, the deny list, confirmation) | `guide/security.md` updated in the same change, with the reasoning; `AGENTS.md` and the Boost skill checked for the same rule; a `breaking` label on the pull request if an exposure default moved (a default that exposes more is breaking). |
| **A public PHP API** (facade method, trait, contract, event) | `reference/php-api.md`, and `guide/audit-and-events.md` for events. |
| **A server, module or naming option** | `guide/servers-and-modules.md` or `concepts/naming.md`, plus `reference/configuration.md`. |
| **A new default** | `reference/configuration.md` and every page that quotes the old default. Grep for the old value. |

If a feature fits no row, it is probably a new guide page: add it to
`guide/`, the sidebar, and the landing page features if it is a headline.

## Verifying the docs still match the code

Drift is the main risk. Check mechanically; do not trust memory. Run these
from the repository root and compare against the docs.

**Config keys.** Every key the code reads, and every key the file ships:

```bash
rtk grep -rhno "mcp-bridge\.[a-z_.]*" src | rtk sort -u
rtk grep -n "=>" config/mcp-bridge.php
```

A key in the file that the first command never shows is unread; it belongs in
the "not read yet" table. Dynamic reads (`sources.{$key}.server`,
`modules.{$key}`, `servers.{$key}.*`) are not literal; read the class.

**Attributes.** `rtk ls src/Attributes` against the headings of
`reference/attributes.md`. For each, find the reader:

```bash
rtk grep -rn "McpTimeout\|McpGroup" src --include=*.php | rtk grep -v "src/Attributes"
```

If the only hits are in `AttributeReader` and `Declarations`, nothing acts on
it yet.

**Commands.** `rtk grep -n "signature" -A8 src/Console/*.php` against
`reference/commands.md`, option for option. Also check
`McpBridgeServiceProvider::boot()` for the registered list.

**Testing helpers.** `rtk grep -n "public function" src/Testing/InteractsWithMcp.php`
against `reference/testing.md`.

**Codes.** `rtk grep -rhno "MCP_BRIDGE_[A-Z_]*" src | rtk sort -u` against
`reference/error-codes.md`.

**Sources and defaults.** The provider's `afterResolving(SourceRegistry::class` block lists
the registered sources; `config/mcp-bridge.php` gives defaults and servers.
`Sources/*Source.php::validate()` is the truth about what a source needs to be
opted in.

**Security.** Re-read `AGENTS.md` "The rule that matters most",
`Security/AuthorityRequirement.php`, `Security/ScopeGate.php`,
`Security/ConfirmationHandles.php` and `Discovery/CommandFilter.php` against
`guide/security.md`. Behaviour in `tests/Feature/AuthorizationTest.php`,
`CommandSourceTest.php` and `ModelSourceTest.php` is the most reliable
description of what is enforced.

**Samples.** Anything lifted from tests comes from `tests/Feature/*Test.php`
and `tests/Fixtures`. When a fixture changes, update the page that quoted it.

**Links, dashes, build.**

```bash
cd docs && rtk npm run build
rtk grep -rnP "[\x{2013}\x{2014}]" src .vitepress/config.ts
```

## Failure modes to avoid

- **Documenting intended behaviour that does not exist.** The README and
  config comments sometimes describe what is planned. Only the code and the
  tests say what is real. Run the config-key grep above before calling a key
  read or unread: every key the shipped file holds is read today, including
  `security.auth` (`ActorResolver`, `RouteExecutor`) and `naming.style`, and
  there is no `executor` key. A key that turns up unread goes in a "not read"
  note on `reference/configuration.md`, never in a table as if it worked.
- **Placing tools from memory.** `ManifestCompiler::server()` decides: an
  explicit `sources.<key>.server`, then a server whose `groups` claims one of
  the tool's groups (`#[McpGroup]`, the documentation group, or a remote or
  OpenAPI key; `*` claims nothing), then the first server whose `sources`
  lists the source, then `default`. `modules.per_module_servers` overrides
  all of it (`ModuleRegistry::server()`). The shipped `ops` server lists
  every non-route source, so only route tools are on `default`. `#[McpGroup]`
  routes; module grouping only labels.
- **Saying there is no manifest without a cache.** `ManifestRepository::all()`
  reads `bootstrap/cache/mcp-bridge.php` when it exists and `discovery.cache`
  is on, and otherwise compiles on demand for the life of the process. A
  server never lists nothing for want of `mcp-bridge:cache`.
- **Claiming discovery runs no code.** Controllers are never called, but
  `rules()` is called on FormRequests, data classes and actions, remote
  servers and OpenAPI URLs are fetched at compile time, and
  `integrations.scramble.responses` runs Scramble's generator.
- **Quoting a default from memory.** Open `config/mcp-bridge.php`.
- **A security page that softens a rule.** Never describe a check as
  "recommended" when the code makes it mandatory, or as mandatory when it is
  a switch. State the rule, the switch, and the consequence of the switch.
- **Showing a sample that would fail the package's own lint.** A command
  example without `#[McpScope]` and `#[McpAbility]` teaches the wrong thing.
- **Describing an attribute as if it worked everywhere.** `#[McpParam]` is
  read for routes only; `#[McpQueue]` for jobs only. Check the reader.
- **Repeating the security rules on every page.** They drift apart. Link.
- **Inventing output.** Do not paste "example output" you did not get from
  running the command. Describe it in prose, or run it first.
- **Forgetting that the server reads the cached manifest when there is one.**
  Any guide that changes a declaration must tell the reader to rebuild a
  cached manifest (`mcp-bridge:cache`) or to clear it, and link
  `guide/operations#keeping-the-manifest-current`.
- **Showing a scoped tool called from a test or the shell without a token.**
  A factory user holds no token and the scope check fails closed. Tests give
  the actor one with `withMcpToken()`; `mcp-bridge:call` attaches one with
  `--scopes` (default `*`).
- **Em dashes and en dashes.** The user's rule, absolute.
- **A page that is not in the sidebar**, or a link to a page that moved.
- **Leaving the Boost skill and README out of step.**
  `resources/boost/skills/mcp-bridge/SKILL.md` ships to users and tells their
  coding agents how to use the package; a change to a rule here usually
  changes it there too.

## Done means

1. `cd docs && rtk npm run build` passes.
2. The dash grep is empty.
3. The checklist row for your change is complete.
4. Every new link with an anchor was confirmed in the built HTML.
5. Every sample was checked against `src/` or lifted from a test.
