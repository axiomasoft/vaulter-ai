<?php

declare(strict_types=1);

use Vaulter\Ai\Bench\OfficeCostCompare;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

require_once dirname(__DIR__, 3).'/bench/office/compare.php';

test('a truncated, over-budget, or limit_exceeded read is not admissible model context', function (): void {
    $limits = [
        'search_page_max' => 5,
        'read_max_rows' => 20,
        'read_max_bytes' => 16384,
        'prepare_max_targets' => 20,
    ];

    $truncated = OfficeCostCompare::admit([
        'ok' => true,
        'error_code' => null,
        'truncated' => true,
        'complete' => false,
        'bytes' => 400,
        'rows' => 20,
    ], $limits);
    expect($truncated['admissible'])->toBeFalse()
        ->and($truncated['must_narrow'])->toBeTrue()
        ->and($truncated['fatal'])->toBeFalse()
        ->and($truncated['reasons'])->toContain('incomplete_page');

    $overBytes = OfficeCostCompare::admit([
        'ok' => true,
        'error_code' => null,
        'truncated' => false,
        'complete' => true,
        'bytes' => 16385,
        'rows' => 2,
    ], $limits);
    expect($overBytes['admissible'])->toBeFalse()
        ->and($overBytes['must_narrow'])->toBeTrue()
        ->and($overBytes['reasons'])->toContain('over_bytes');

    $limit = OfficeCostCompare::admit([
        'ok' => false,
        'error_code' => 'limit_exceeded',
        'truncated' => false,
        'complete' => false,
        'bytes' => 80,
        'rows' => 0,
    ], $limits);
    expect($limit['admissible'])->toBeFalse()
        ->and($limit['must_narrow'])->toBeTrue()
        ->and($limit['fatal'])->toBeFalse();

    $admitted = OfficeCostCompare::admit([
        'ok' => true,
        'error_code' => null,
        'truncated' => false,
        'complete' => true,
        'bytes' => 500,
        'rows' => 2,
    ], $limits);
    expect($admitted['admissible'])->toBeTrue()
        ->and($admitted['must_narrow'])->toBeFalse()
        ->and($admitted['fatal'])->toBeFalse();
});

test('office cost bench records both branches on the 1x and 10x assignment corpora', function (): void {
    $workload = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/bench/office/workload.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $workload['iterations'] = ['warmup' => 0, 'measured' => 2];

    $report = OfficeCostCompare::run($this->app, $workload);

    expect($report['comparison_valid'])->toBeTrue($report['invalid_reason'] ?? '')
        ->and($report['schema'])->toBe('vaulter-ai-office-bench-report/v1')
        ->and($report['identity']['profile']['matches_declaration'])->toBeTrue()
        ->and($report['identity']['profile']['limits'])->toMatchArray([
            'search_page_max' => 5,
            'read_max_rows' => 20,
            'read_max_bytes' => 16384,
            'prepare_max_targets' => 20,
        ])
        ->and($report['identity']['git'])->toHaveKeys(['sha', 'dirty'])
        ->and($report['identity']['lock']['path'])->toBe('composer.lock')
        ->and($report['identity']['build']['univer'])->toBe('1.5.2')
        ->and($report['identity']['model']['mode'])->toBe('fake')
        ->and($report['identity']['engine']['buffers']['status'])->toBeIn(['measured', 'unmeasured']);

    expect($report['scales'])->toHaveCount(2);
    foreach ($report['scales'] as $scale) {
        expect($scale['branches'])->toHaveKeys(['full_snapshot', 'sdk_selection']);
        $full = $scale['branches']['full_snapshot'];
        $sdk = $scale['branches']['sdk_selection'];

        foreach ([$full, $sdk] as $branch) {
            expect($branch['correct'])->toBeTrue(implode('; ', $branch['correctness_errors']))
                ->and($branch['observed_truth']['clause'])->toBe('Оплата в течение 30 дней.')
                ->and($branch['observed_truth']['verify_at_commit'])->toBe('passed')
                ->and($branch['observed_truth']['verify_current'])->toBe('passed')
                ->and($branch['observed_truth']['rows']['r-telecom']['value'])->toEqual(20)
                ->and($branch['observed_truth']['rows']['r-telecom']['formula'])->toBe('=C2-B2')
                ->and($branch['observed_truth']['rows']['r-travel']['value'])->toEqual(-20)
                ->and($branch['observed_truth']['rows']['r-travel']['formula'])->toBe('=C3-B3')
                ->and($branch['observed_truth']['rows']['r-fees']['value'])->toEqual(77)
                ->and($branch['observed_truth']['rows']['r-fees']['formula'])->toBeNull()
                ->and($branch['tokens']['provider_input']['status'])->toBe('unmeasured')
                ->and($branch['tokens']['provider_output']['status'])->toBe('unmeasured')
                ->and($branch['tokens']['tokenizer_estimate_input']['status'])->toBe('unmeasured')
                ->and($branch['tokens']['tokenizer_estimate_output']['status'])->toBe('unmeasured')
                ->and($branch['model_calls']['provider'])->toBe(0)
                ->and($branch['phases']['cold_read']['temperature'])->toBe('cold')
                ->and($branch['phases']['warm_read']['temperature'])->toBe('warm')
                ->and($branch['phases']['warm_read']['sample_count'])->toBe(2)
                ->and($branch['phases']['warm_read']['p95_confidence'])->toBe('indicative')
                ->and($branch['phases']['cold_read']['sql_queries'])->toBeGreaterThan(0)
                ->and($branch['phases']['write']['http_requests'])->toBeGreaterThan(0)
                ->and($branch['phases']['rebuild']['documents_reindexed'])->toBeGreaterThan(0);
        }

        expect($sdk['model_context_limited_to_selection'])->toBeTrue()
            ->and($sdk['model_context_contains']['filler_block'])->toBeFalse()
            ->and($sdk['model_context_contains']['filler_row'])->toBeFalse()
            ->and($sdk['model_context_contains']['untouched_row'])->toBeFalse()
            ->and($sdk['model_context_contains']['target_row'])->toBeTrue()
            ->and($sdk['model_context_contains']['clause'])->toBeTrue()
            ->and($sdk['snapshot_utf8_bytes'])->toBe(0);

        $optimization = $scale['optimization'];
        expect($optimization['reduced'])->toBeBool();
        if ($optimization['reduced'] !== true) {
            expect($optimization['section'])->toBeString()->not->toBe('')
                ->and($optimization['working_configuration']['scale'])->toBe($scale['id'])
                ->and($optimization['working_configuration']['engine']['db_driver'])->toBeString();
        }
    }

    $wide = $report['scales'][1];
    expect($wide['id'])->toBe('10x')
        ->and($wide['branches']['full_snapshot']['narrowed'])->toBeTrue()
        ->and($wide['branches']['full_snapshot']['narrow_reasons'])->not->toBeEmpty()
        ->and($wide['branches']['full_snapshot']['snapshot_utf8_bytes'])->toBeGreaterThan(16384)
        ->and($wide['branches']['full_snapshot']['model_context_contains']['filler_block'])->toBeFalse()
        ->and($wide['branches']['full_snapshot']['model_context_limited_to_selection'])->toBeTrue();
});
