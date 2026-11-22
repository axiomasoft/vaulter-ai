<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

/**
 * Isolated Testbench harness booting Core + Documents + Laravel AI with AgenTools disabled.
 */
abstract class LaravelAiTestCase extends OfficeTestCase
{
    protected bool $bootLaravelAi = true;

    protected bool $bootAgenTools = false;
}
