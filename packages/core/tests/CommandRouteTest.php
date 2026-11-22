<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Writing\SingleTextOwner;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;

uses(OfficeTestCase::class);

test('a committed route binds the exact scoped key, proposal, digest and owner', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $other = $fixture->prepare(replacement: '60');
    $input = $fixture->commitInput($proposal);
    $db = $this->app->make(OfficeDatabase::class);
    $routeBeforeOwner = false;
    $db->connection()->beforeExecuting(function (string $sql) use ($db, &$routeBeforeOwner, $proposal): void {
        if (! $routeBeforeOwner && str_starts_with($sql, 'insert into "v_ai_text_commands"')) {
            $route = $db->query('ai_owner_routes')->where('proposal_id', $proposal->proposal_id)->first();
            $routeBeforeOwner = $route?->owner_key === 'documents.single_text@1' && $route->receipt_id === null;
        }
    });

    $commit = $fixture->call(OfficeOperation::Commit, $input);
    expect($commit->result->state)->toBe('committed')->and($routeBeforeOwner)->toBeTrue();
    $changedDigest = array_replace($input, ['digest' => str_repeat('f', 64)]);
    expect($fixture->call(OfficeOperation::Commit, $changedDigest)->errorCode)->toBe(ErrorCode::IdempotencyConflict)
        ->and($fixture->call(OfficeOperation::Commit, $fixture->commitInput($other))->errorCode)->toBe(ErrorCode::IdempotencyConflict)
        ->and($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'other-key'))->errorCode)->toBe(ErrorCode::IdempotencyConflict)
        ->and($fixture->call(OfficeOperation::Commit, $input)->toArray())->toBe($commit->toArray())
        ->and($db->query('ai_owner_routes')->count())->toBe(1);

    $otherScope = new CallContext('62', 'default', $fixture->context->effectiveSubject, driveId: $fixture->context->driveId, actor: $fixture->actor);
    expect($fixture->call(OfficeOperation::Commit, $input, $otherScope)->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'], $otherScope)->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Verify, ['receipt_id' => $commit->result->receipt_id, 'mode' => 'at_commit'], $otherScope)->errorCode)
        ->toBe(ErrorCode::ResourceUnavailable);

    $fixture->revoke();
    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Commit, $changedDigest)->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'])->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Verify, ['receipt_id' => $commit->result->receipt_id, 'mode' => 'at_commit'])->errorCode)
        ->toBe(ErrorCode::ResourceUnavailable);
});

test('verify refuses a well-formed answer with a different receipt or an older marker', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $commit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($fixture->prepare()));
    $receipt = $commit->result->receipt_id;
    $real = $this->app->make(OfficeRegistry::class)->owner(SingleTextOwner::KEY);
    $proxy = new class($real) implements CommandOwner
    {
        public bool $foreignReceipt = true;

        public function __construct(private readonly CommandOwner $owner) {}

        public function key(): string
        {
            return $this->owner->key();
        }

        public function supports(string $command, ?string $writeMode = null): bool
        {
            return $this->owner->supports($command, $writeMode);
        }

        public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
        {
            return $this->owner->commit($scope, $plan);
        }

        public function status(CommandScope $scope): CommandOutcome
        {
            return $this->owner->status($scope);
        }

        public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
        {
            $result = $this->owner->verify($context, $resourceId, $receiptId, $mode);
            if ($this->foreignReceipt) {
                $result->receipt_id = 'foreign-receipt';
            } else {
                $result->marker->position--;
            }

            return $result;
        }
    };
    $this->freshRegistry()->registerOwner('text.replace_exact@1', 'snapshot', $proxy);
    $input = ['receipt_id' => $receipt, 'mode' => 'at_commit'];
    expect($fixture->call(OfficeOperation::Verify, $input)->errorCode)->toBe(ErrorCode::TemporarilyUnavailable);
    $proxy->foreignReceipt = false;
    expect($fixture->call(OfficeOperation::Verify, $input)->errorCode)->toBe(ErrorCode::TemporarilyUnavailable);
});
