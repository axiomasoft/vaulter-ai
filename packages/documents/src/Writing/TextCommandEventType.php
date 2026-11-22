<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Vaulter\Attributes\EventSpec;
use Vaulter\Events\Concerns\DescribesEvent;
use Vaulter\Events\EventTypeContract;

enum TextCommandEventType: string implements EventTypeContract
{
    use DescribesEvent;

    #[EventSpec(
        subject: 'node',
        data: ['resource_id' => 'string', 'command_id' => 'string', 'receipt_id' => 'string', 'generation' => 'int', 'position' => 'int'],
        activity: false,
        external: false,
        journal: null,
        description: 'An Office text command committed.',
    )]
    case Committed = 'office.text_command_committed';
}
