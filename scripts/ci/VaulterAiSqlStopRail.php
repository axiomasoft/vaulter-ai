<?php

declare(strict_types=1);

namespace Vaulter\Ai\Ci;

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node> */
final class VaulterAiSqlStopRail implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier
            && strcasecmp($scope->resolveName($node->class), DB::class) === 0 && strtolower($node->name->toString()) === 'transaction') {
            return [RuleErrorBuilder::message('Use VaulterDatabase::transaction() or OfficeDatabase::transaction() instead of raw Laravel transaction calls.')
                ->identifier('vaulterAi.rawTransaction')->build()];
        }

        if (! $node instanceof String_ && ! $node instanceof InterpolatedStringPart) {
            return [];
        }

        $violations = [];
        foreach ([
            'advisoryLock' => ['~\b(?:hashtext|pg_(?:try_)?advisory_(?:lock|unlock)(?:_shared|_all)?)\s*\(~i', 'Use 64-bit transaction-scoped PostgreSQL advisory locks.'],
            'tenantContext' => ["~\bset\s+(?:(?:session|local)\s+)?(?:app|vaulter)\.tenant_id\b|\bset_config\s*\([^)]*,\s*(?:false|'false'|0)\s*\)~i", 'Set PostgreSQL context only for the current transaction.'],
            'unsafeDdl' => ['~\bINITIALLY\s+DEFERRED\b|\bREPACK\s*\(\s*CONCURRENTLY\b~i', 'Use initially immediate constraints and avoid concurrent REPACK.'],
        ] as $id => [$pattern, $message]) {
            if (preg_match($pattern, $node->value) === 1) {
                $violations[] = RuleErrorBuilder::message($message)->identifier('vaulterAi.'.$id)->build();
            }
        }

        return $violations;
    }
}
