<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * STUB of shopping-assistant-starter-kit's interface, for static analysis and
 * unit tests only. This repo takes no composer dependency on that plugin, so
 * the real interface is absent here and present in any shop that runs it.
 *
 * Nothing but `ToolWiringTest` can tell you this stub still matches upstream.
 * If that test fails, fix this file — do not weaken the test.
 */
interface ToolFactoryInterface
{
    public function create(ToolContext $context): ?object;
}
