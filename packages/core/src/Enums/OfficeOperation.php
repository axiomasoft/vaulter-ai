<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Enums;

enum OfficeOperation: string
{
    case Search = 'office.search';
    case Read = 'office.read';
    case Describe = 'office.describe';
    case Prepare = 'office.prepare';
    case Commit = 'office.commit';
    case Status = 'office.status';
    case Verify = 'office.verify';

    public function toolName(): string
    {
        return str_replace('.', '_', $this->value);
    }

    public static function fromToolName(string $name): ?self
    {
        $canonical = str_starts_with($name, 'office_')
            ? 'office.'.substr($name, strlen('office_'))
            : $name;

        return self::tryFrom($canonical);
    }
}
