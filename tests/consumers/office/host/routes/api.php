<?php

declare(strict_types=1);

use App\Models\StandActor;
use App\Models\StandOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

function assertStandFixtureToken(Request $request): ?JsonResponse
{
    $expected = (string) config('stand.fixture_token', '');
    $supplied = (string) $request->header('X-Fixture-Token', '');
    if ($expected === '' || ! hash_equals($expected, $supplied)) {
        return response()->json(['error' => 'forbidden'], 403);
    }

    return null;
}

Route::post('/_fixture/seed', static function (Request $request): JsonResponse {
    if (($deny = assertStandFixtureToken($request)) !== null) {
        return $deny;
    }

    $suffix = bin2hex(random_bytes(4));
    $writer = StandActor::query()->create(['name' => 'Writer', 'email' => "writer-{$suffix}@example.test"]);
    $reader = StandActor::query()->create(['name' => 'Reader', 'email' => "reader-{$suffix}@example.test"]);
    $foreign = StandActor::query()->create(['name' => 'Foreign', 'email' => "foreign-{$suffix}@example.test"]);

    $owner = StandOwner::query()->create(['name' => 'Stand Workspace '.$suffix]);
    $drive = Vaulter::drive($owner)->asSystem('stand-seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($writer), RoleRegistry::WRITER));
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($reader), RoleRegistry::READER));

    $node = Documents::in($drive)->create('Договор поставки', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-calc', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Расчёты']]],
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-42', 'level' => 2], 'content' => [['type' => 'text', 'text' => '4.2 Срок оплаты']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                ['type' => 'text', 'text' => '😀 Pay within '],
                ['type' => 'text', 'text' => '15', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' working days.'],
            ]],
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-71', 'level' => 2], 'content' => [['type' => 'text', 'text' => '7.1']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p71'], 'content' => [['type' => 'text', 'text' => 'Human terms.']]],
        ],
    ]);

    $driveId = $drive->snapshot()->id;
    $writerToken = 'w-'.bin2hex(random_bytes(16));
    $readerToken = 'r-'.bin2hex(random_bytes(16));
    $foreignToken = 'f-'.bin2hex(random_bytes(16));

    foreach ([
        [$writerToken, '61', 'default', $writer->getKey(), $driveId],
        [$readerToken, '61', 'default', $reader->getKey(), $driveId],
        [$foreignToken, '99', 'other', $foreign->getKey(), $driveId],
    ] as [$tok, $tenant, $scope, $uid, $did]) {
        DB::table('stand_tokens')->insert([
            'token_hash' => hash('sha256', $tok),
            'tenant_id' => $tenant,
            'project_scope' => $scope,
            'user_id' => $uid,
            'drive_id' => $did,
            'profile' => 'documents',
            'policy_revision' => 'stand-rev-1',
        ]);
    }

    return response()->json([
        'resource_id' => $node->id,
        'tenant_id' => '61',
        'project_scope' => 'default',
        'drive_id' => $driveId,
        'writer_user_id' => $writer->getKey(),
        'writer_token' => $writerToken,
        'reader_token' => $readerToken,
        'foreign_token' => $foreignToken,
    ]);
});

Route::post('/_fixture/runs', static function (Request $request, OfficeRegistry $registry, Runs $runs): JsonResponse {
    if (($deny = assertStandFixtureToken($request)) !== null) {
        return $deny;
    }

    $data = $request->json()->all();
    $resourceId = (string) ($data['resource_id'] ?? '');
    $writerUserId = (int) ($data['writer_user_id'] ?? 0);
    $driveId = (string) ($data['drive_id'] ?? '');
    $faultMode = is_string($data['fault_mode'] ?? null) ? $data['fault_mode'] : null;

    $actor = StandActor::query()->find($writerUserId);
    if ($actor === null || $resourceId === '' || $driveId === '') {
        return response()->json(['error' => 'invalid_seed_ref'], 400);
    }

    $context = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', 'stand-rev-1', $driveId, $actor);
    $sourceEventId = 'stand-evt-'.(string) Str::uuid7();
    $profile = (string) config('stand.profile');

    if ($profile === 'ai-agentools') {
        $executor = $registry->executor('agentools');
        if ($executor === null) {
            return response()->json(['error' => 'agentools_unavailable'], 500);
        }
        $scenario = [
            'spec_rev' => '2026-09',
            'scenario_id' => 'stand-agentools-replace',
            'steps' => [
                ['id' => 'read_block', 'tool' => 'office_read', 'input_map' => ['resource_id' => '$ctx.resource_id', 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']]],
                ['id' => 'prepare', 'tool' => 'office_prepare', 'input_map' => ['resource_id' => '$ctx.resource_id', 'command' => '"text.replace_exact@1"', 'read_token' => '$steps.read_block.read_token', 'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30']]],
                ['id' => 'commit', 'tool' => 'office_commit', 'input_map' => ['resource_id' => '$ctx.resource_id', 'proposal_id' => '$steps.prepare.proposal_id', 'digest' => '$steps.prepare.digest']],
                ['id' => 'verify', 'tool' => 'office_verify', 'input_map' => ['resource_id' => '$ctx.resource_id', 'receipt_id' => '$steps.commit.receipt_id', 'mode' => '"at_commit"']],
            ],
        ];
        $executor->dispatch($context, [
            'channel' => 'agentools',
            'source_event_id' => $sourceEventId,
            'scenario' => $scenario,
            'scenario_context' => ['resource_id' => $resourceId],
        ]);
        $run = $runs->createOrFind($context, 'agentools', $sourceEventId, 'stand-rev-1', []);

        return response()->json(['run_id' => $run->runId, 'state' => $run->state, 'channel' => 'agentools']);
    }

    $executor = $registry->executor('laravel-ai');
    if ($executor === null) {
        return response()->json(['error' => 'laravel_ai_unavailable'], 500);
    }

    $run = $runs->createOrFind($context, 'laravel-ai', $sourceEventId, 'stand-rev-1', [
        'prompt' => 'Change payment days in 4.2 from 15 to 30.',
        'resource_id' => $resourceId,
        'profile' => 'documents',
    ]);
    DB::table('stand_run_contexts')->insert([
        'run_id' => $run->runId,
        'tenant_id' => '61',
        'project_scope' => 'default',
        'user_id' => $actor->getKey(),
        'drive_id' => $driveId,
        'resource_id' => $resourceId,
        'profile' => 'documents',
        'policy_revision' => 'stand-rev-1',
        'fault_mode' => $faultMode,
    ]);

    $executor->dispatch($context, [
        'channel' => 'laravel-ai',
        'source_event_id' => $sourceEventId,
        'prompt' => 'Change payment days in 4.2 from 15 to 30.',
        'resource_id' => $resourceId,
    ]);

    return response()->json(['run_id' => $run->runId, 'state' => 'queued', 'channel' => 'laravel-ai'], 202);
});

Route::get('/_fixture/runs/{runId}', static function (Request $request, string $runId, Runs $runs): JsonResponse {
    if (($deny = assertStandFixtureToken($request)) !== null) {
        return $deny;
    }

    $row = DB::table('v_ai_runs')->where('run_id', $runId)->first();
    if ($row === null) {
        return response()->json(['error' => 'not_found'], 404);
    }

    $steps = DB::table('v_ai_run_steps')->where('run_id', $runId)->orderBy('step_id')->get();
    $commitReceipt = null;
    $commitCommandId = null;
    $commitCount = 0;
    $verifyState = null;
    $verifyReceiptId = null;
    $verifyOutcome = null;
    $resourceId = DB::table('stand_run_contexts')->where('run_id', $runId)->value('resource_id');
    foreach ($steps as $s) {
        if ($resourceId === null && $s->resource_id !== null) {
            $resourceId = $s->resource_id;
        }
        if ($s->operation === OfficeOperation::Commit->value) {
            $commitCount++;
            $commitCommandId = $s->command_id;
            $commitReceipt = $s->receipt_id;
        }
        if ($s->operation === OfficeOperation::Verify->value) {
            $verifyState = $s->state;
            $verifyReceiptId = $s->receipt_id;
            $verifyOutcome = $s->state === 'verified' ? 'passed' : 'failed';
        }
    }

    $commandsQuery = DB::table('v_ai_text_commands');
    $outboxQuery = DB::table('v_outbox')->where('type', 'office.text_command_committed');
    if (is_string($resourceId) && $resourceId !== '') {
        $commandsQuery->where('resource_id', $resourceId);
        $outboxQuery->where('aggregate_id', $resourceId);
    }

    return response()->json([
        'run_id' => $runId,
        'state' => $row->state,
        'step_count' => $steps->count(),
        'commit_step_count' => $commitCount,
        'command_id' => $commitCommandId,
        'receipt_id' => $commitReceipt,
        'verify_state' => $verifyState,
        'verify_receipt_id' => $verifyReceiptId,
        'verify_outcome' => $verifyOutcome,
        'text_command_count' => $commandsQuery->count(),
        'outbox_commit_count' => $outboxQuery->count(),
    ]);
});
