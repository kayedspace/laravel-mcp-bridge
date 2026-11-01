<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KayedSpace\McpBridge\Contracts\AuthorizesTools;
use KayedSpace\McpBridge\Manifest\ManifestCompiler;
use KayedSpace\McpBridge\Manifest\ManifestRepository;
use KayedSpace\McpBridge\Security\KillSwitch;
use KayedSpace\McpBridge\Tests\Fixtures\AgentUser;
use KayedSpace\McpBridge\Tests\Fixtures\AppServer;
use KayedSpace\McpBridge\Tests\Fixtures\TestUser;
use Laravel\Mcp\Facades\Mcp;

beforeEach(function (): void {
    defineApiRoutes();

    app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);
});

describe('mcp-bridge:call', function (): void {
    beforeEach(function (): void {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        config()->set('auth.providers.users.model', AgentUser::class);

        AgentUser::query()->create([
            'id' => 1, 'name' => 'Operator', 'email' => 'ops@example.com', 'password' => 'x',
        ]);
    });

    it('calls a tool and prints what the agent would get', function (): void {
        $this->artisan('mcp-bridge:call list_courses --arg=per_page=2')
            ->expectsOutputToContain('Cardiology Basics')
            ->assertSuccessful();
    });

    it('builds nested arguments from a dotted name', function (): void {
        $this->artisan('mcp-bridge:call get_course --arg=course=12')
            ->expectsOutputToContain('"id": 12')
            ->assertSuccessful();
    });

    it('takes every argument at once as json', function (): void {
        $this->artisan('mcp-bridge:call', [
            'tool' => 'create_course',
            '--json' => '{"title":"Neurology","status":"draft"}',
        ])
            ->expectsOutputToContain('Neurology')
            ->assertSuccessful();
    });

    it('goes through the gate, so a destructive tool asks first', function (): void {
        // A confirmation is a handshake rather than an error, so the command
        // succeeds and prints the handle to pass back.
        $this->artisan('mcp-bridge:call purge_course')
            ->expectsOutputToContain('Ask the user to confirm')
            ->expectsOutputToContain('"handle"')
            ->assertSuccessful();
    });

    it('holds every scope unless told which, so a scoped tool can be tried', function (): void {
        Gate::define('run-reports', fn ($user): bool => true);

        $this->artisan('mcp-bridge:call list_reports')
            ->expectsOutputToContain('Scopes held')
            ->expectsOutputToContain('"total"')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:call list_reports --scopes=courses:read')
            ->expectsOutputToContain('MCP_BRIDGE_SCOPE')
            ->assertFailed();
    });

    it('reads an empty scope list as none, and says who it runs as', function (): void {
        Gate::define('run-reports', fn ($user): bool => true);

        $this->artisan('mcp-bridge:call list_reports --scopes=')
            ->expectsOutputToContain('AgentUser#1 (ops@example.com)')
            ->expectsOutputToContain('MCP_BRIDGE_SCOPE')
            ->assertFailed();
    });

    it('says so when the tool does not exist', function (): void {
        $this->artisan('mcp-bridge:call no_such_tool')
            ->expectsOutputToContain('--refused')
            ->assertFailed();
    });
});

describe('mcp-bridge:kill', function (): void {
    beforeEach(fn () => createKillsTable());

    it('survives a cache flush, because it is a table', function (): void {
        $definition = toolNamed('list_courses');
        $gate = app(AuthorizesTools::class);

        app(KillSwitch::class)->kill('list_courses');
        Cache::flush();

        expect($gate->visible($definition, new TestUser))->toBeFalse();
    });

    it('hides and refuses a tool that was switched off', function (): void {
        $definition = toolNamed('list_courses');
        $gate = app(AuthorizesTools::class);

        expect($gate->visible($definition, new TestUser))->toBeTrue();

        $this->artisan('mcp-bridge:kill list_courses')->assertSuccessful();

        expect($gate->visible($definition, new TestUser))->toBeFalse()
            ->and($gate->check(callTool($definition))?->errorCode)->toBe('MCP_BRIDGE_DISABLED');
    });

    it('switches off a whole source, and a whole surface', function (): void {
        $definition = toolNamed('list_courses');
        $gate = app(AuthorizesTools::class);

        $this->artisan('mcp-bridge:kill --source=route')->assertSuccessful();
        expect($gate->visible($definition, new TestUser))->toBeFalse();

        $this->artisan('mcp-bridge:kill --source=route --revive')->assertSuccessful();
        expect($gate->visible($definition, new TestUser))->toBeTrue();

        $this->artisan('mcp-bridge:kill --all')->assertSuccessful();
        expect($gate->visible($definition, new TestUser))->toBeFalse();
    });

    it('lists what is switched off, and needs a target', function (): void {
        app(KillSwitch::class)->kill('list_courses');

        $this->artisan('mcp-bridge:kill --list')
            ->expectsOutputToContain('list_courses')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:kill')->assertFailed();
    });
});

describe('mcp-bridge:install', function (): void {
    // The publish path is fixed when the provider boots, so the file lands
    // in the test skeleton's config directory and is removed afterwards.
    afterEach(fn () => @unlink(app()->configPath('mcp-bridge.php')));

    it('publishes the configuration, migrates, and says what is left to do', function (): void {
        app(ManifestRepository::class)->clear();

        $this->artisan('mcp-bridge:install')
            ->expectsOutputToContain('Installed')
            ->assertSuccessful();

        expect(file_exists(app()->configPath('mcp-bridge.php')))->toBeTrue()
            ->and(Schema::hasTable('mcp_bridge_tokens'))->toBeTrue()
            ->and(Schema::hasTable('mcp_bridge_kills'))->toBeTrue()
            ->and(app(ManifestRepository::class)->isCached())->toBeFalse();
    });

    it('compiles a manifest in production only', function (): void {
        app()->instance('env', 'production');

        $this->artisan('mcp-bridge:install')->assertSuccessful();

        expect(app(ManifestRepository::class)->isCached())->toBeTrue();
    });
})->skip(
    fn (): bool => getenv('TEST_TOKEN') !== false,
    'Publishes into the skeleton every parallel worker shares; the serial run covers it.',
);

describe('mcp-bridge:doctor', function (): void {
    it('passes on a configured application', function (): void {
        Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
        createAuditTable();
        createKillsTable();
        config()->set('cache.default', 'file');

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('Everything checks out')
            ->assertSuccessful();
    });

    it('reports a package server whose path an application server has taken', function (): void {
        Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
        createAuditTable();
        createKillsTable();
        config()->set('cache.default', 'file');

        Mcp::web('/mcp', AppServer::class);
        app('router')->getRoutes()->refreshNameLookups();

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('is served by [KayedSpace\\McpBridge\\Tests\\Fixtures\\AppServer]')
            ->assertFailed();
    });

    it('reports a configured middleware that does not exist', function (): void {
        Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
        createAuditTable();
        createKillsTable();
        config()->set('cache.default', 'file');
        config()->set('mcp-bridge.execution.middleware', ['App\\Mcp\\NoSuchMiddleware']);

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('Call middleware [App\\Mcp\\NoSuchMiddleware]')
            ->assertFailed();
    });

    it('accepts a manifest compiled on demand outside production, and not in it', function (): void {
        Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
        createAuditTable();
        createKillsTable();
        config()->set('cache.default', 'file');
        app(ManifestRepository::class)->clear();

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('run mcp-bridge:cache in your deploy')
            ->assertSuccessful();

        app()->instance('env', 'production');

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('until you run mcp-bridge:cache')
            ->assertFailed();
    });

    it('refuses a cache store no other worker can see', function (): void {
        config()->set('cache.default', 'array');

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('Cache store [array]')
            ->assertFailed();
    });

    it('reports a guard the configuration names and nothing defines', function (): void {
        config()->set('auth.guards.mcp-bridge', null);

        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('Guard [mcp-bridge]')
            ->assertFailed();
    });

    it('reports a missing table rather than throwing', function (): void {
        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('run migrate')
            ->assertFailed();
    });

    it('reports a stdio server with nobody to run as', function (): void {
        config()->set('mcp-bridge.local', true);

        // The refusal would otherwise arrive in a client's log, one call at
        // a time, rather than here before anyone connects.
        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('set security.local_actor')
            ->assertFailed();
    });
});

describe('mcp-bridge:export', function (): void {
    it('writes the compiled tools as json', function (): void {
        $path = sys_get_temp_dir().'/mcp-bridge-export.json';

        $this->artisan("mcp-bridge:export --path={$path}")->assertSuccessful();

        $exported = json_decode((string) file_get_contents($path), true);

        expect($exported)->toBeArray()
            ->and(array_column($exported, 'name'))->toContain('list_courses');

        unlink($path);
    });

    it('writes markdown for a human to read', function (): void {
        $this->artisan('mcp-bridge:export --format=markdown')
            ->expectsOutputToContain('## `list_courses`')
            ->assertSuccessful();
    });
});

describe('mcp-bridge:connect', function (): void {
    it('prints the cli line for Claude', function (): void {
        $this->artisan('mcp-bridge:connect claude --token=mcpb_abc')
            ->expectsOutputToContain('claude mcp add --transport http')
            ->expectsOutputToContain('Bearer mcpb_abc')
            ->assertSuccessful();
    });

    it('prints json for an editor', function (): void {
        $this->artisan('mcp-bridge:connect cursor')
            ->expectsOutputToContain('.cursor/mcp.json')
            ->expectsOutputToContain('"mcpServers"')
            ->assertSuccessful();
    });

    it('prints each command-configured client in its own spelling', function (): void {
        $this->artisan('mcp-bridge:connect gemini --token=mcpb_abc')
            ->expectsOutputToContain('gemini mcp add --transport http laravel')
            ->expectsOutputToContain('"Authorization: Bearer mcpb_abc"')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:connect copilot --token=mcpb_abc')
            ->expectsOutputToContain('copilot mcp add --transport http laravel')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:connect qwen --token=mcpb_abc')
            ->expectsOutputToContain('qwen mcp add --transport http laravel')
            ->assertSuccessful();
    });

    it('keeps the token out of the Codex configuration by passing it through an environment variable', function (): void {
        $this->artisan('mcp-bridge:connect codex --token=mcpb_abc')
            ->expectsOutputToContain('export LARAVEL_MCP_TOKEN=mcpb_abc')
            ->expectsOutputToContain('codex mcp add laravel')
            ->expectsOutputToContain('--url http')
            ->expectsOutputToContain('--bearer-token-env-var LARAVEL_MCP_TOKEN')
            ->assertSuccessful();
    });

    it('shapes the entry the way each file-configured client reads it', function (): void {
        $this->artisan('mcp-bridge:connect vscode --token=mcpb_abc')
            ->expectsOutputToContain('.vscode/mcp.json')
            ->expectsOutputToContain('"servers"')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:connect opencode --token=mcpb_abc')
            ->expectsOutputToContain('opencode.json')
            ->expectsOutputToContain('"type": "remote"')
            ->assertSuccessful();

        $this->artisan('mcp-bridge:connect windsurf --token=mcpb_abc')
            ->expectsOutputToContain('mcp_config.json')
            ->expectsOutputToContain('"serverUrl"')
            ->assertSuccessful();
    });

    it('refuses a client it does not know, and names the ones it does', function (): void {
        $this->artisan('mcp-bridge:connect notepad')
            ->expectsOutputToContain('There is no client called [notepad]')
            ->assertFailed();
    });

    it('connects to the server that was asked for', function (): void {
        $this->artisan('mcp-bridge:connect claude --server=ops')
            ->expectsOutputToContain('/mcp/ops')
            ->assertSuccessful();
    });

    it('writes and merges an editor file on this machine with --apply', function (): void {
        $base = sys_get_temp_dir().'/mcp-bridge-connect-'.uniqid();
        mkdir($base.'/.cursor', 0777, true);
        file_put_contents($base.'/.cursor/mcp.json', json_encode(['mcpServers' => ['other' => ['type' => 'http', 'url' => 'https://other.test/mcp']]]));
        app()->setBasePath($base);

        $this->artisan('mcp-bridge:connect cursor --token=mcpb_abc --apply')
            ->expectsOutputToContain('Written to')
            ->assertSuccessful();

        $written = json_decode((string) file_get_contents($base.'/.cursor/mcp.json'), true);

        expect($written['mcpServers'])->toHaveKeys(['other', 'laravel'])
            ->and($written['mcpServers']['laravel']['headers']['Authorization'])->toBe('Bearer mcpb_abc')
            ->and($written['mcpServers']['laravel']['url'])->toEndWith('/mcp');
    });

    it('needs a token to apply, and says so when claude is not on this machine', function (): void {
        $this->artisan('mcp-bridge:connect claude --apply')
            ->expectsOutputToContain('Pass --token')
            ->assertFailed();

        $path = getenv('PATH');
        putenv('PATH=/nonexistent');

        try {
            $this->artisan('mcp-bridge:connect claude --token=mcpb_abc --apply')
                ->expectsOutputToContain('not on this machine')
                ->expectsOutputToContain('claude mcp add --transport http')
                ->assertFailed();
        } finally {
            putenv('PATH='.$path);
        }
    });

    it('has nothing to apply for a client with no local file, and prints the block instead', function (): void {
        $this->artisan('mcp-bridge:connect n8n --token=mcpb_abc --apply')
            ->expectsOutputToContain('no file on this machine')
            ->expectsOutputToContain('"mcpServers"')
            ->assertFailed();
    });

    it('prints the subprocess setup for stdio, and says what it needs', function (): void {
        // One phrase per printed line: an expected substring is matched
        // against a single console write.
        $this->artisan('mcp-bridge:connect claude --stdio --server=ops')
            ->expectsOutputToContain('claude mcp add --transport stdio')
            ->expectsOutputToContain('mcp:start ops')
            ->expectsOutputToContain('Set [mcp-bridge.local] to true')
            ->expectsOutputToContain('Set [mcp-bridge.security.local_actor]')
            ->assertSuccessful();
    });
});

describe('mcp-bridge:tools --explain', function (): void {
    it('says which layer decided each value, and which layers set each input', function (): void {
        defineApiRoutes();
        app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

        $this->artisan('mcp-bridge:tools', ['tool' => 'list_courses', '--explain' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('list_courses')
            ->expectsOutputToContain('rules');
    });

    it('credits configuration when configuration is what named the tool', function (): void {
        config(['mcp-bridge.routes' => ['api.v1.courses.index' => ['name' => 'browse_catalogue']]]);

        defineApiRoutes();
        app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

        $this->artisan('mcp-bridge:tools', ['tool' => 'browse_catalogue', '--explain' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('config');
    });

    it('asks for a name rather than guessing', function (): void {
        $this->artisan('mcp-bridge:tools', ['--explain' => true])->assertFailed();
    });
});

describe('mcp-bridge:describe', function (): void {
    it('shows a tool the way the agent reads it', function (): void {
        defineApiRoutes();
        app(ManifestRepository::class)->write(app(ManifestCompiler::class)->compile()->definitions);

        $this->artisan('mcp-bridge:describe', ['tool' => 'list_courses'])
            ->assertSuccessful()
            ->expectsOutputToContain('List published courses');
    });

    it('points at the companion package rather than pretending to write one', function (): void {
        // The LLM SDK lives there, so it is not in the dependency tree of an
        // application running this in production.
        $this->artisan('mcp-bridge:describe', ['--ai' => true])
            ->assertFailed()
            ->expectsOutputToContain('kayedspace/laravel-mcp-bridge-ai');
    });
});

it('writes the server instructions as a skill beside the client configuration with --skill', function (): void {
    $base = sys_get_temp_dir().'/mcp-bridge-skill-'.uniqid();
    mkdir($base, 0777, true);
    app()->setBasePath($base);

    $this->artisan('mcp-bridge:connect cursor --token=mcpb_abc --skill')
        ->expectsOutputToContain('Skill written to')
        ->assertSuccessful();

    $skill = (string) file_get_contents($base.'/.claude/skills/laravel-mcp/SKILL.md');

    expect($skill)->toStartWith("---\nname: laravel-mcp\n")
        ->toContain('# The laravel MCP server')
        ->toContain('MCP_BRIDGE_SCOPE');
});

it('tells the operator when the cached manifest was written by another version', function (): void {
    Schema::create('mcp_bridge_tokens', fn ($table) => $table->id());
    createAuditTable();
    createKillsTable();
    config()->set('cache.default', 'file');

    $manifest = app(ManifestRepository::class);
    file_put_contents($manifest->path(), '<?php return '.var_export(['version' => 1, 'tools' => []], true).';');

    try {
        $this->artisan('mcp-bridge:doctor')
            ->expectsOutputToContain('written by another version')
            ->assertFailed();
    } finally {
        $manifest->clear();
    }
});
