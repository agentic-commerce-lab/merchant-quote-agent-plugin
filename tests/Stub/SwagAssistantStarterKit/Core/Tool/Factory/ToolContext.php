<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * STUB of shopping-assistant-starter-kit's type, for static analysis and unit
 * tests only. This repo takes no composer dependency on that plugin, so the
 * real class is absent here and present in any shop that runs it.
 *
 * Upstream asserts this exact property list in their own test. Nothing but
 * `ToolWiringTest` can tell you this stub still matches upstream. If that
 * test fails, fix this file — do not weaken the test.
 */
final readonly class ToolContext
{
    public function __construct(
        public TraceRecorder $trace,
        public AssistantConfig $config,
    ) {}
}
