<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

/**
 * Isolated Testbench harness booting Core + Documents + AgenTools without Laravel AI.
 */
abstract class AgenToolsTestCase extends OfficeTestCase
{
    protected bool $bootLaravelAi = false;

    protected bool $bootAgenTools = true;
}
