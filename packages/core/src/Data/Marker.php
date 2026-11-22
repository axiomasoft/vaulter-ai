<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use InvalidArgumentException;
use stdClass;

/**
 * Monotonic version marker {generation, position} within JS safe integer range.
 */
final readonly class Marker
{
    public const int MAX_SAFE_INTEGER = 9007199254740991;

    public function __construct(
        public int $generation,
        public int $position,
    ) {
        if ($generation < 0 || $generation > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("Marker generation out of safe integer bounds: {$generation}.");
        }
        if ($position < 0 || $position > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("Marker position out of safe integer bounds: {$position}.");
        }
    }

    /** @return array{generation: int, position: int} */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'position' => $this->position,
        ];
    }

    public function toStdClass(): stdClass
    {
        return (object) $this->toArray();
    }

    public function equals(self $other): bool
    {
        return $this->generation === $other->generation && $this->position === $other->position;
    }
}
