<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Sources\Concerns;

use Illuminate\Config\Repository;
use KayedSpace\McpBridge\Contracts\Source;
use KayedSpace\McpBridge\Data\Refusal;
use KayedSpace\McpBridge\Data\ToolCandidate;

/**
 * The refusals every class-based source shares.
 *
 * Jobs, actions and models are all opt-in per class, all honour #[McpIgnore],
 * and all have to be buildable from an agent's arguments. Writing those three
 * checks out per source is how they drift: a fourth source arrives, copies two
 * of them, and quietly exposes what the others refuse.
 *
 * @phpstan-require-implements Source
 */
trait RefusesCandidates
{
    abstract protected function settings(): Repository;

    /**
     * Opt-in, in every mode. A non-HTTP source has no middleware to inherit,
     * so exposing one by pattern match would hand an agent an unprotected
     * entry point.
     *
     * @return list<Refusal>
     */
    protected function refusals(ToolCandidate $candidate): array
    {
        if ($candidate->meta['ignored'] ?? false) {
            return [Refusal::ignored($candidate->id)];
        }

        if (! ($candidate->meta['opted_in'] ?? false)) {
            return [Refusal::notExposed($candidate->id, 'any')];
        }

        $unsupported = $candidate->meta['unsupported'] ?? [];

        if (! is_array($unsupported) || $unsupported === []) {
            return [];
        }

        // Something an agent cannot fill is refused here rather than failing
        // at the first call, with the parameter and the reason named.
        return [Refusal::unbuildable($candidate->id, array_values(array_filter($unsupported, 'is_string')))];
    }

    /**
     * Where this source looks for classes. A glob is expanded by the scanner,
     * so a modular application names every module's directory at once.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function paths(array $default): array
    {
        $paths = array_values(array_filter(
            $this->settings()->array("mcp-bridge.sources.{$this->key()}.paths", []),
            'is_string',
        ));

        return $paths === [] ? $default : $paths;
    }
}
