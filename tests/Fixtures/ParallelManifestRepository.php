<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Tests\Fixtures;

use KayedSpace\McpBridge\Manifest\ManifestRepository;

/**
 * Parallel workers share one Testbench skeleton, so without this they write
 * and delete the same manifest file under each other.
 */
class ParallelManifestRepository extends ManifestRepository
{
    public function path(): string
    {
        return $this->app->bootstrapPath('cache/mcp-bridge-'.getenv('TEST_TOKEN').'.php');
    }
}
