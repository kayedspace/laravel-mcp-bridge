<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Execution;

use Fiber;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A command's output, kept for the result and handed out line by line while
 * the command runs.
 *
 * A command is synchronous: the console kernel returns when it is done, and
 * nothing between its lines can yield to the protocol. So the command runs
 * inside a fiber, and each line it prints suspends that fiber with the line
 * as the value. Whoever started the fiber resumes it after sending the line
 * on, which is how a thirty-second command reports progress rather than
 * silence.
 *
 * Only the fiber registered here is suspended, never whatever fiber the
 * runtime happens to be in: a foreign fiber would be suspended by a line it
 * has no idea how to resume.
 */
class StreamedOutput extends BufferedOutput
{
    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    protected static ?Fiber $streaming = null;

    /**
     * The fiber whose lines are streamed, or null to stream none.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>|null  $fiber
     */
    public static function stream(?Fiber $fiber): void
    {
        self::$streaming = $fiber;
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite($message, $newline);

        if (self::$streaming === null || Fiber::getCurrent() !== self::$streaming) {
            return;
        }

        $line = trim($message);

        if ($line !== '') {
            Fiber::suspend($line);
        }
    }
}
