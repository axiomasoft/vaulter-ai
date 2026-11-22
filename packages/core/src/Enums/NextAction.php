<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Enums;

enum NextAction: string
{
    case Stop = 'stop';
    case RetrySame = 'retry_same';
    case Reread = 'reread';
    case AwaitApproval = 'await_approval';
    case RetryRead = 'retry_read';
    case InspectHistory = 'inspect_history';
    case Narrow = 'narrow';
    case Status = 'status';
}
