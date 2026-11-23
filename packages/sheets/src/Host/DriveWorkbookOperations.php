<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Host;

use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Sheets\Contracts\WorkbookOperations;
use Vaulter\Sheets\Data\WorkbookCommand;

/**
 * DriveExtension binding Vaulter Sheets public WorkbookOperations port to the current DriveHandle actor.
 */
final readonly class DriveWorkbookOperations implements DriveExtension
{
    public function __construct(
        private DriveContext $context,
        private WorkbookOperations $workbooks,
    ) {}

    /** @return array<mixed> */
    public function catalog(string $node): array
    {
        return $this->workbooks->catalog($this->context, $node);
    }

    /** @return array<mixed> */
    public function readTable(string $node, string $table): array
    {
        return $this->workbooks->readTable($this->context, $node, $table);
    }

    /** @return array<mixed> */
    public function prepareFormula(
        string $node,
        string $table,
        string $destination,
        string $left,
        string $right,
        string $scope,
    ): array {
        return $this->workbooks->prepareFormula($this->context, $node, $table, $destination, $left, $right, $scope);
    }

    /** @return array<mixed> */
    public function commit(string $node, WorkbookCommand $command): array
    {
        return $this->workbooks->commit($this->context, $node, $command);
    }

    /** @return array<mixed> */
    public function status(string $node, string $command): array
    {
        return $this->workbooks->status($this->context, $node, $command);
    }

    /** @return array<mixed> */
    public function verify(string $node, string $command): array
    {
        return $this->workbooks->verify($this->context, $node, $command);
    }
}
