<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Data;

/**
 * One module of a modular application: a name an agent can be told, and a path
 * everything inside it shares.
 *
 * The path is what identifies ownership. A class belongs to the module whose
 * directory contains the file it was declared in, which is true whatever the
 * module package calls itself and whatever namespace the team chose.
 */
final readonly class Module
{
    public string $path;

    public function __construct(
        public string $name,
        string $path,
        public bool $enabled = true,
    ) {
        // Normalised on the way in, because the path may arrive relative or
        // with a `..` in it while the file a class was declared in is always
        // real, and comparing those two as strings would never match.
        $this->path = realpath($path) ?: $path;
    }

    /** Whether a file lives inside this module. */
    public function contains(string $file): bool
    {
        $path = rtrim($this->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $file = realpath($file) ?: $file;

        return str_starts_with($file, $path);
    }
}
