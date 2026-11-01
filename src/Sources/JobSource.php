<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\ClassTarget;
use KayedSpace\McpBridge\Data\InputSchema;
use KayedSpace\McpBridge\Data\Safety;
use KayedSpace\McpBridge\Data\SourceResult;
use KayedSpace\McpBridge\Data\ToolCall;
use KayedSpace\McpBridge\Data\ToolCandidate;
use KayedSpace\McpBridge\Discovery\AttributeReader;
use KayedSpace\McpBridge\Discovery\ClassScanner;
use KayedSpace\McpBridge\Discovery\ToolNamer;
use KayedSpace\McpBridge\Execution\JobExecutor;
use KayedSpace\McpBridge\Schema\ParameterMapper;
use KayedSpace\McpBridge\Sources\Concerns\RefusesCandidates;

/**
 * Queued jobs as tools.
 *
 * A job is the one source whose work does not finish inside the call: the
 * agent is told the work was accepted and onto which queue, which is the
 * honest answer, rather than a fabricated result.
 *
 * Like every non-HTTP source it is opt-in per class and must declare its own
 * authority. Unlike a command it is not destructive by default: a job that
 * sends a report and a job that deletes an account are equally opaque, so the
 * declaration decides, and the class name is no evidence either way.
 */
class JobSource implements Source
{
    use RefusesCandidates;

    public function __construct(
        protected ClassScanner $scanner,
        protected ToolNamer $namer,
        protected AttributeReader $attributes,
        protected ParameterMapper $parameters,
        protected JobExecutor $executor,
        protected Repository $config,
    ) {}

    public function key(): string
    {
        return 'job';
    }

    public function discover(): iterable
    {
        $candidates = [];

        foreach ($this->classes() as $class) {
            $candidate = $this->candidate($class);

            $candidates[$candidate->id] = $candidate;
        }

        ksort($candidates);

        return array_values($candidates);
    }

    public function schema(ToolCandidate $candidate): InputSchema
    {
        $target = $candidate->target;

        return $target instanceof ClassTarget
            ? $this->parameters->forClass($target->class)->input
            : InputSchema::empty();
    }

    public function validate(ToolCandidate $candidate): array
    {
        return $this->refusals($candidate);
    }

    public function execute(ToolCall $call): SourceResult
    {
        return $this->executor->execute($call);
    }

    /** @return list<class-string> */
    protected function classes(): array
    {
        return $this->scanner->in($this->paths(['app/Jobs']));
    }

    protected function settings(): Repository
    {
        return $this->config;
    }

    /** @param class-string $class */
    protected function candidate(string $class): ToolCandidate
    {
        $declarations = $this->attributes->read($class);
        $constructor = $this->parameters->forClass($class);

        return new ToolCandidate(
            source: $this->key(),
            id: "job:{$class}",
            target: new ClassTarget($class),
            suggestedName: $this->namer->forClass($class, $declarations->tool?->name),
            title: $declarations->tool?->title,
            description: $declarations->tool->description ?? $this->describe($class, $declarations->queue->sync ?? false),
            groups: $declarations->groups,
            safety: $this->safety()->with($declarations->safety),
            declarations: $declarations->raw,
            meta: [
                'opted_in' => $declarations->optedIn(),
                'ignored' => $declarations->ignored,
                'class' => $class,
                'unsupported' => $constructor->unsupported,
            ],
            declared: $declarations->toDeclared(),
        );
    }

    /**
     * An agent needs to know that a queued job is accepted rather than done,
     * because what it does next depends on which of those it was.
     *
     * @param  class-string  $class
     */
    protected function describe(string $class, bool $sync): string
    {
        $subject = Str::lower(Str::headline(class_basename($class)));

        return $sync
            ? "Run the {$subject} job and wait for it."
            : "Queue the {$subject} job. It is accepted for processing rather than finished when this returns; "
                .'pass the id it returns to job_status to learn what became of it.';
    }

    /**
     * Not destructive by default, unlike a command: a command is a verb a
     * human types, while a job is work the application already does to itself,
     * and guessing from the class name would be guessing.
     */
    protected function safety(): Safety
    {
        return new Safety(readOnly: false, destructive: false, idempotent: false, openWorld: false);
    }
}
