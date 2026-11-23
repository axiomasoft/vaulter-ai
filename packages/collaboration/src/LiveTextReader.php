<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration;

use Vaulter\Ai\Collaboration\Internal\JournalAccess;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Exceptions\Contracts\ProblemException;

/** Maps strict journal receipts and projected snapshots to Office read markers. */
final readonly class LiveTextReader
{
    private JournalAccess $access;

    public function __construct(private CommandJournal $journal, TextDocumentAccess $access)
    {
        $this->access = new JournalAccess($access);
    }

    public function receiptMarker(CallContext $context, ResourceEntry $resource, string $receipt): ?Marker
    {
        try {
            $marker = $this->journal->receiptMarker($this->access->actor($context, $resource), $receipt);
        } catch (ProblemException $failure) {
            throw JournalAccess::translate($failure);
        }

        return $marker === null ? null : new Marker($marker['generation'], $marker['position']);
    }

    public function projected(CallContext $context, ResourceEntry $resource, int $generation, int $version): ?Marker
    {
        try {
            $marker = $this->journal->projected($this->access->actor($context, $resource), $generation, $version);
        } catch (ProblemException $failure) {
            throw JournalAccess::translate($failure);
        }

        return $marker === null ? null : new Marker($marker['generation'], $marker['position']);
    }
}
