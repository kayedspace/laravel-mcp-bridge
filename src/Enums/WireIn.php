<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Enums;

enum WireIn: string
{
    case Query = 'query';
    case Body = 'body';
    case Path = 'path';
    case Header = 'header';
    case CommandArgument = 'command-argument';
    case CommandOption = 'command-option';
    case Constructor = 'constructor';
}
