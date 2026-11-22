<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Enums;

enum ApprovalMode: string
{
    case Automatic = 'automatic';
    case Human = 'human';
}
