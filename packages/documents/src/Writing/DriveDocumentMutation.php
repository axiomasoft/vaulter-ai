<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Closure;
use Vaulter\Documents\Contracts\AtomicDocumentMutation;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Operations\DriveContext;

/** Binds the public mutation port to the current DriveHandle actor. */
final readonly class DriveDocumentMutation implements DriveExtension
{
    public function __construct(private DriveContext $context, private AtomicDocumentMutation $atomic) {}

    /**
     * @template T
     *
     * @param  Closure(LockedDocumentMutation, DriveContext): T  $operation
     * @return T
     */
    public function run(string $resourceId, Closure $operation): mixed
    {
        return $this->atomic->run(
            $this->context, $resourceId, fn (LockedDocumentMutation $locked): mixed => $operation($locked, $this->context),
        );
    }
}
