<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use LogicException;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;

final class CommandResult
{
    public static function response(OfficeOperation $operation, CommandOutcome $outcome): OfficeResponse
    {
        $result = ['command_id' => $outcome->commandId, 'state' => $outcome->state];
        $result += match ($outcome->state) {
            'running' => [],
            'rejected' => ['reason' => $outcome->reason->value ?? throw new LogicException('Missing rejection reason.')],
            'committed' => [
                'receipt_id' => $outcome->receiptId ?? throw new LogicException('Missing receipt.'),
                'marker' => $outcome->marker?->toStdClass() ?? throw new LogicException('Missing receipt marker.'),
            ],
            default => throw new LogicException('Unknown owner outcome.'),
        };

        return OfficeResponse::success($operation, (object) $result);
    }
}
