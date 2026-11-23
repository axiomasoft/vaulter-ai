<?php

declare(strict_types=1);

namespace Vaulter\Ai\Bench;

use Composer\InstalledVersions;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Projection\IndexText;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Ai\Sheets\Host\DriveWorkbookOperations;
use Vaulter\Ai\Sheets\Host\WorkbookProblemMapper;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Tests\Support\FixedClock;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Sheets\Content\WorkbookCodec;
use Vaulter\Support\Identity;

/**
 * Measures one sheet.fill_formula assignment under the full allowed snapshot and under an SDK selection.
 *
 * Fake-model provider tokens and tokenizer estimates stay unmeasured. UTF-8 byte counts are the measured size.
 * A truncated page or a limit_exceeded read is never the model context and never the prepare token.
 */
final class OfficeCostCompare
{
    /** @var array<string, int> */
    private static array $limits = [];

    private static ?SqlProbe $probe = null;

    /** @var array<int, true> */
    private static array $attached = [];

    /**
     * @param  array<string, mixed>  $workload
     * @return array<string, mixed>
     */
    public static function run(Application $app, array $workload): array
    {
        self::assertWorkload($workload);
        self::$limits = self::limitsFrom($app, $workload);
        self::attachProbe($app);
        self::$probe = new SqlProbe;

        $clock = $app->make(Clock::class);
        if ($clock instanceof FixedClock) {
            $clock->set('2026-11-21T11:00:00Z');
        }

        $identity = self::identity($app, $workload);
        $valid = $identity['profile']['matches_declaration'] === true;
        $reasons = $valid ? [] : ['access profile does not match vaulter-ai.limits'];

        $scales = [];
        foreach ($workload['scales'] as $scale) {
            if (! is_array($scale)) {
                throw new RuntimeException('Each scale must be an object.');
            }
            $branches = [];
            foreach (['full_snapshot', 'sdk_selection'] as $strategy) {
                $branch = self::measureBranch($app, $workload, $scale, $strategy);
                $branches[$strategy] = $branch;
                if ($branch['correct'] !== true) {
                    $valid = false;
                    $reasons[] = $scale['id'].'/'.$strategy.': '.implode('; ', $branch['correctness_errors']);
                }
            }
            $scales[] = [
                'id' => $scale['id'],
                'multiplier' => $scale['multiplier'],
                'corpus' => [
                    'contract_blocks' => $scale['contract_blocks'],
                    'sheet_rows' => $scale['sheet_rows'],
                ],
                'branches' => $branches,
                'optimization' => self::optimization($branches, $identity, (string) $scale['id']),
            ];
        }

        return [
            'schema' => 'vaulter-ai-office-bench-report/v1',
            'task' => '5.2',
            'comparison_valid' => $valid,
            'invalid_reason' => $reasons === [] ? null : implode(' | ', $reasons),
            'identity' => $identity,
            'scales' => $scales,
        ];
    }

    /**
     * Decide whether one read may become model context or a prepare token.
     *
     * A partial page is not the area the command is allowed to change.
     *
     * @param  array{ok: bool, error_code: ?string, truncated: bool, complete: bool, bytes: int, rows: int}  $observation
     * @param  array<string, int>  $limits
     * @return array{admissible: bool, must_narrow: bool, fatal: bool, reasons: list<string>}
     */
    public static function admit(array $observation, array $limits): array
    {
        $reasons = [];
        $error = $observation['error_code'];
        if ($error === 'limit_exceeded') {
            $reasons[] = 'limit_exceeded';
        }
        if ($observation['truncated'] || ! $observation['complete']) {
            $reasons[] = 'incomplete_page';
        }
        if ($observation['bytes'] > $limits['read_max_bytes']) {
            $reasons[] = 'over_bytes';
        }
        if ($observation['rows'] > $limits['read_max_rows']) {
            $reasons[] = 'over_rows';
        }

        $fatal = ! $observation['ok'] && $error !== 'limit_exceeded';
        if ($fatal && $error !== null) {
            $reasons[] = 'read_failed';
        }

        $narrowable = array_values(array_intersect($reasons, ['limit_exceeded', 'incomplete_page', 'over_bytes', 'over_rows']));

        return [
            'admissible' => $observation['ok'] && $reasons === [],
            'must_narrow' => $narrowable !== [],
            'fatal' => $fatal,
            'reasons' => $reasons,
        ];
    }

    /** @param  list<string>  $argv */
    public static function main(array $argv): int
    {
        $workloadPath = __DIR__.'/workload.json';
        $output = null;
        $warmup = null;
        $measured = null;
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--workload=')) {
                $workloadPath = substr($arg, strlen('--workload='));
            } elseif (str_starts_with($arg, '--output=')) {
                $output = substr($arg, strlen('--output='));
            } elseif (str_starts_with($arg, '--warmup=')) {
                $warmup = self::nonNegativeInt(substr($arg, strlen('--warmup=')), '--warmup');
            } elseif (str_starts_with($arg, '--measured=')) {
                $measured = self::positiveInt(substr($arg, strlen('--measured=')), '--measured');
            } else {
                fwrite(STDERR, "Unknown argument {$arg}\n");

                return 2;
            }
        }

        $workload = json_decode((string) file_get_contents($workloadPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($workload)) {
            throw new RuntimeException('Workload JSON must be an object.');
        }
        if ($warmup !== null) {
            $workload['iterations']['warmup'] = $warmup;
        }
        if ($measured !== null) {
            $workload['iterations']['measured'] = $measured;
        }

        $harness = new OfficeBenchHarness('boot');
        $harness->boot();
        $report = self::run($harness->application(), $workload);

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if ($output === null) {
            fwrite(STDOUT, $json);
        } else {
            $dir = dirname($output);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("Cannot create {$dir}");
            }
            file_put_contents($output, $json);
        }

        return $report['comparison_valid'] === true ? 0 : 3;
    }

    /**
     * @param  array<string, mixed>  $workload
     * @param  array<string, mixed>  $scale
     * @return array<string, mixed>
     */
    private static function measureBranch(Application $app, array $workload, array $scale, string $strategy): array
    {
        $seed = self::seed($app, $workload, $scale);
        $errors = [];
        $assignment = $workload['assignment'];
        $targets = $assignment['target_row_ids'];

        $cold = self::phase(function () use ($app, $seed, $strategy, $assignment, $targets): array {
            $search = self::office($app, $seed['fixture'], 'office.search', [
                'query' => $assignment['sheet_title'],
                'resource_type' => 'sheet',
                'limit' => self::$limits['search_page_max'],
            ]);
            $discovery = $strategy === 'full_snapshot'
                ? self::discoverFull($app, $seed, $assignment)
                : ['snapshot_utf8_bytes' => 0, 'snapshot_text' => '', 'outline' => null, 'wide_table' => null];
            $selection = self::readSelection($app, $seed, $assignment, $targets);
            $snapshotFits = $discovery['snapshot_utf8_bytes'] <= self::$limits['read_max_bytes'];
            $outlineOk = $discovery['outline'] === null || $discovery['outline']['admission']['admissible'] === true;
            $wideOk = $discovery['wide_table'] === null || $discovery['wide_table']['admission']['admissible'] === true;
            $narrowed = $strategy === 'full_snapshot' && (! $snapshotFits || ! $outlineOk || ! $wideOk);
            $useSnapshot = $strategy === 'full_snapshot' && ! $narrowed;
            $modelText = $useSnapshot ? $discovery['snapshot_text'] : $selection['text'];
            $selectionRejected = $selection['sheet']['admission']['admissible'] !== true
                || $selection['clause']['admission']['admissible'] !== true;

            return [
                'search' => self::callBrief($search),
                'snapshot_utf8_bytes' => $discovery['snapshot_utf8_bytes'],
                'narrowed' => $narrowed,
                'narrow_reasons' => self::narrowReasons($discovery, $snapshotFits),
                'model_text' => $modelText,
                'model_context_utf8_bytes' => strlen($modelText),
                'selection_utf8_bytes' => strlen($selection['text']),
                'read_token' => $selection['sheet']['result']['read_token'] ?? null,
                'selection_fatal' => $selectionRejected || $selection['sheet']['admission']['fatal'] === true || $selection['clause']['admission']['fatal'] === true,
                'http_requests' => 1 + ($discovery['outline'] === null ? 0 : 1) + ($discovery['wide_table'] === null ? 0 : 1) + 2,
                'http_bytes' => self::httpBytesOf(array_filter([
                    $search, $discovery['outline'], $discovery['wide_table'], $selection['clause'], $selection['sheet'],
                ])),
            ];
        });

        /** @var array<string, mixed> $coldValue */
        $coldValue = $cold['value'];
        $modelText = (string) $coldValue['model_text'];
        $clause = (string) $assignment['clause_text'];
        $markers = [
            'filler_block' => str_contains($modelText, 'FILLER-BLOCK'),
            'filler_row' => str_contains($modelText, 'r-fill-'),
            'untouched_row' => str_contains($modelText, 'r-fees'),
            'target_row' => str_contains($modelText, 'r-telecom'),
            'clause' => str_contains($modelText, $clause),
        ];

        $write = self::phase(function () use ($app, $seed, $assignment, $coldValue, $strategy, $scale): array {
            $token = $coldValue['read_token'];
            $includedSelection = false;
            if (! is_string($token) || $token === '') {
                $includedSelection = true;
                $again = self::readSelection($app, $seed, $assignment, $assignment['target_row_ids']);
                $token = $again['sheet']['result']['read_token'] ?? null;
            }
            if (! is_string($token) || $token === '') {
                return ['ok' => false, 'error' => 'no complete selection token', 'http_requests' => $includedSelection ? 2 : 0, 'http_bytes' => 0, 'included_selection_read' => $includedSelection];
            }
            $prepare = self::office($app, $seed['fixture'], 'office.prepare', [
                'resource_id' => $seed['sheet_id'],
                'command' => $assignment['command'],
                'read_token' => $token,
                'arguments' => [
                    'sheet_id' => $assignment['sheet_id'],
                    'table_id' => $assignment['table_id'],
                    'scope' => $assignment['scope'],
                    'destination_column_id' => $assignment['destination_column_id'],
                    'expression' => $assignment['expression'],
                ],
            ]);
            if ($prepare['ok'] !== true) {
                return ['ok' => false, 'error' => 'prepare '.$prepare['error_code'], 'http_requests' => 1, 'http_bytes' => self::httpBytesOf([$prepare]), 'included_selection_read' => $includedSelection];
            }
            $commit = self::office($app, $seed['fixture'], 'office.commit', [
                'resource_id' => $seed['sheet_id'],
                'command_id' => 'bench-'.$scale['id'].'-'.$strategy,
                'proposal_id' => $prepare['result']['proposal_id'] ?? '',
                'digest' => $prepare['result']['digest'] ?? '',
            ]);

            return [
                'ok' => $commit['ok'] === true,
                'error' => $commit['ok'] === true ? null : 'commit '.$commit['error_code'],
                'receipt_id' => $commit['result']['receipt_id'] ?? null,
                'http_requests' => 2,
                'http_bytes' => self::httpBytesOf([$prepare, $commit]),
                'included_selection_read' => $includedSelection,
            ];
        });

        $receipt = $write['value']['receipt_id'] ?? null;
        $lag = self::phase(function () use ($app, $seed, $assignment, $targets): array {
            $read = self::office($app, $seed['fixture'], 'office.read', [
                'resource_id' => $seed['sheet_id'],
                'selector' => [
                    'kind' => 'sheet_table',
                    'sheet_id' => $assignment['sheet_id'],
                    'table_id' => $assignment['table_id'],
                    'columns' => [$assignment['destination_column_id']],
                    'row_ids' => $targets,
                ],
            ]);
            $rows = is_array($read['result']['data']['rows'] ?? null) ? $read['result']['data']['rows'] : [];
            $seen = [];
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['row_id'] ?? null)) {
                    $seen[$row['row_id']] = $row['values'][$assignment['destination_column_id']] ?? null;
                }
            }

            return [
                'http_requests' => 1,
                'http_bytes' => self::httpBytesOf([$read]),
                'published_values' => $seen,
                'read_ok' => $read['ok'] === true,
            ];
        });

        $recovery = self::phase(function () use ($app, $seed, $receipt): array {
            if (! is_string($receipt) || $receipt === '') {
                return ['at_commit' => null, 'current' => null, 'http_requests' => 0, 'http_bytes' => 0];
            }
            $atCommit = self::office($app, $seed['fixture'], 'office.verify', [
                'resource_id' => $seed['sheet_id'],
                'receipt_id' => $receipt,
                'mode' => 'at_commit',
            ]);
            $current = self::office($app, $seed['fixture'], 'office.verify', [
                'resource_id' => $seed['sheet_id'],
                'receipt_id' => $receipt,
                'mode' => 'current',
            ]);

            return [
                'at_commit' => $atCommit['result']['outcome'] ?? null,
                'current' => $current['result']['outcome'] ?? null,
                'http_requests' => 2,
                'http_bytes' => self::httpBytesOf([$atCommit, $current]),
            ];
        });

        $rebuild = self::phase(function () use ($app, $seed): array {
            $count = $app->make(IndexText::class)->rebuildDrive(
                $seed['drive_id'],
                $seed['tenant_id'],
                $seed['project_scope'],
                true,
            );

            return ['documents_reindexed' => $count, 'http_requests' => 0, 'http_bytes' => 0];
        });

        $observed = self::observeTruth($seed, $assignment);
        $observed['truth']['verify_at_commit'] = $recovery['value']['at_commit'];
        $observed['truth']['verify_current'] = $recovery['value']['current'];
        $iterations = $workload['iterations'];
        $warmSamples = [];
        for ($i = 0; $i < $iterations['warmup'] + $iterations['measured']; $i++) {
            $sample = self::phase(function () use ($app, $seed, $strategy, $assignment, $coldValue): array {
                if ($strategy === 'full_snapshot' && $coldValue['narrowed'] !== true) {
                    $loaded = self::snapshotTexts($seed);
                    $bytes = strlen($loaded['text']);

                    return ['http_requests' => 0, 'http_bytes' => 0, 'bytes' => $bytes];
                }
                $selection = self::readSelection($app, $seed, $assignment, $assignment['target_row_ids']);

                return [
                    'http_requests' => 2,
                    'http_bytes' => self::httpBytesOf([$selection['clause'], $selection['sheet']]),
                    'bytes' => strlen($selection['text']),
                ];
            });
            if ($i >= $iterations['warmup']) {
                $warmSamples[] = $sample;
            }
        }

        if ($coldValue['selection_fatal'] === true) {
            $errors[] = 'addressed selection was not admissible';
        }
        if (! is_string($coldValue['read_token']) || $coldValue['read_token'] === '') {
            $errors[] = 'prepare token missing';
        }
        if ($write['value']['ok'] !== true) {
            $errors[] = (string) ($write['value']['error'] ?? 'write failed');
        }
        if (($recovery['value']['at_commit'] ?? null) !== 'passed' || ($recovery['value']['current'] ?? null) !== 'passed') {
            $errors[] = 'verify did not pass';
        }
        if ($observed['matches'] !== true) {
            $errors[] = 'observed cells or clause differ from the assignment truth';
        }
        if ($strategy === 'sdk_selection' && ($markers['filler_block'] || $markers['filler_row'] || $markers['untouched_row'] || ! $markers['target_row'] || ! $markers['clause'])) {
            $errors[] = 'SDK model context is not limited to the selected clause and target rows';
        }
        if ($strategy === 'full_snapshot' && $coldValue['narrowed'] === true && ($markers['filler_block'] || $markers['filler_row'])) {
            $errors[] = 'narrowed full-snapshot context still contains filler';
        }

        $scriptedPlan = json_encode([
            'command' => $assignment['command'],
            'target_row_ids' => $targets,
            'clause_block_id' => $assignment['clause_block_id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'strategy' => $strategy,
            'correct' => $errors === [],
            'correctness_errors' => $errors,
            'narrowed' => $coldValue['narrowed'],
            'narrow_reasons' => $coldValue['narrow_reasons'],
            'model_context_limited_to_selection' => $strategy === 'sdk_selection' || $coldValue['narrowed'] === true,
            'model_context_utf8_bytes' => $coldValue['model_context_utf8_bytes'],
            'selection_utf8_bytes' => $coldValue['selection_utf8_bytes'],
            'snapshot_utf8_bytes' => $coldValue['snapshot_utf8_bytes'],
            'model_context_contains' => $markers,
            'admitted_selector' => [
                'text_block' => $assignment['clause_block_id'],
                'sheet_table' => [
                    'sheet_id' => $assignment['sheet_id'],
                    'table_id' => $assignment['table_id'],
                    'columns' => ['plan', 'actual'],
                    'row_ids' => $targets,
                ],
            ],
            'observed_truth' => $observed['truth'],
            'model_calls' => ['provider' => 0, 'scripted' => 1],
            'tokens' => self::unmeasuredTokens(),
            'utf8_bytes' => [
                'input' => $coldValue['model_context_utf8_bytes'],
                'output' => strlen($scriptedPlan),
                'output_kind' => 'scripted_tool_plan',
            ],
            'phases' => [
                'cold_read' => self::phaseReport($cold, 'cold', [$cold], (int) $coldValue['http_requests'], (int) $coldValue['http_bytes']),
                'warm_read' => self::phaseReport(null, 'warm', $warmSamples, null, null),
                'write' => self::phaseReport($write, 'write', [$write], (int) $write['value']['http_requests'], (int) $write['value']['http_bytes']),
                'index_lag' => [
                    'contract_publication_ms' => $seed['contract_publication_ms'],
                    'sheet_publication_ms' => $lag['ms'],
                    'projection_build_id' => $seed['projection_build_id'],
                    'sheet_published_values' => $lag['value']['published_values'],
                    'sql_queries' => $lag['sql_queries'],
                    'http_requests' => $lag['value']['http_requests'],
                    'http_bytes' => $lag['value']['http_bytes'],
                    'buffers' => $lag['buffers'],
                    'disk' => $lag['disk'],
                ],
                'rebuild' => [
                    'ms' => $rebuild['ms'],
                    'documents_reindexed' => $rebuild['value']['documents_reindexed'],
                    'sql_queries' => $rebuild['sql_queries'],
                    'http_requests' => 0,
                    'http_bytes' => 0,
                    'buffers' => $rebuild['buffers'],
                    'disk' => $rebuild['disk'],
                ],
                'recovery' => [
                    'ms' => $recovery['ms'],
                    'verify_at_commit' => $recovery['value']['at_commit'],
                    'verify_current' => $recovery['value']['current'],
                    'sql_queries' => $recovery['sql_queries'],
                    'http_requests' => $recovery['value']['http_requests'],
                    'http_bytes' => $recovery['value']['http_bytes'],
                    'buffers' => $recovery['buffers'],
                    'disk' => $recovery['disk'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $workload
     * @param  array<string, mixed>  $scale
     * @return array<string, mixed>
     */
    private static function seed(Application $app, array $workload, array $scale): array
    {
        $assignment = $workload['assignment'];
        $truth = $assignment['truth'];
        $rows = [];
        foreach (['r-telecom', 'r-travel', 'r-fees'] as $rowId) {
            $spec = $truth[$rowId];
            $rows[] = [
                'row_id' => $rowId,
                'plan' => $spec['plan'],
                'actual' => $spec['actual'],
                'delta' => ($spec['untouched'] ?? false) === true ? $spec['delta'] : null,
            ];
        }
        $extra = (int) $scale['sheet_rows'] - 3;
        for ($i = 1; $i <= $extra; $i++) {
            $rows[] = [
                'row_id' => sprintf('r-fill-%03d', $i),
                'plan' => 1,
                'actual' => 1,
                'delta' => null,
            ];
        }

        $fixture = new SheetCommandFixture($app, automatic: true, seed: [
            'sheet_id' => $assignment['sheet_id'],
            'table_id' => $assignment['table_id'],
            'first_data_row' => 2,
            'rows' => $rows,
        ]);
        $fixture->authorizeHttp();

        $started = hrtime(true);
        $contract = Documents::in($fixture->drive->actingAs($fixture->actor))->create(
            (string) $assignment['contract_title'],
            'vaulter/rich-text',
            self::contractDocument($assignment, (int) $scale['contract_blocks']),
        );
        $clauseRead = self::office($app, $fixture, 'office.read', [
            'resource_id' => $contract->id,
            'selector' => ['kind' => 'text_block', 'block_id' => $assignment['clause_block_id']],
        ]);
        $publicationMs = round((hrtime(true) - $started) / 1_000_000, 3);

        $scope = $app->make(DocumentScopeResolver::class)->forContext($fixture->context, (string) $fixture->drive->snapshot()->id);
        $content = Documents::in($fixture->drive->actingAs($fixture->actor))->content($contract->id);
        $documentKey = Identity::key($content->documentId) ?? $content->documentId;
        $head = $app->make(TextProjectionReader::class)->head($scope['tenant_id'], $scope['project_scope'], $documentKey);

        return [
            'fixture' => $fixture,
            'sheet_id' => $fixture->node->id,
            'contract_id' => $contract->id,
            'drive_id' => $fixture->drive->snapshot()->id,
            'tenant_id' => $scope['tenant_id'],
            'project_scope' => $scope['project_scope'],
            'contract_publication_ms' => $publicationMs,
            'clause_visible' => $clauseRead['ok'] === true && self::segmentText($clauseRead) === $assignment['clause_text'],
            'projection_build_id' => is_array($head) ? $head['build_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private static function contractDocument(array $assignment, int $blocks): array
    {
        $content = [
            self::block('heading', 'h-payment', [self::text((string) $assignment['contract_title'])], 1),
            self::block('paragraph', (string) $assignment['clause_block_id'], [self::text((string) $assignment['clause_text'])]),
        ];
        for ($i = 3; $i <= $blocks; $i++) {
            $content[] = self::block('paragraph', sprintf('p-fill-%03d', $i), [
                self::text('FILLER-BLOCK '.sprintf('%03d', $i).' Служебный абзац договора без срока оплаты.'),
            ]);
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function block(string $type, string $id, array $content, ?int $level = null): array
    {
        return [
            'type' => $type,
            'attrs' => ['blockId' => $id] + ($level === null ? [] : ['level' => $level]),
            'content' => $content,
        ];
    }

    /** @return array{type: string, text: string} */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $assignment
     * @return array{snapshot_utf8_bytes: int, snapshot_text: string, outline: array<string, mixed>, wide_table: array<string, mixed>}
     */
    private static function discoverFull(Application $app, array $seed, array $assignment): array
    {
        $loaded = self::snapshotTexts($seed);
        $outline = self::office($app, $seed['fixture'], 'office.read', [
            'resource_id' => $seed['contract_id'],
            'selector' => ['kind' => 'text_outline'],
        ]);
        $wide = self::office($app, $seed['fixture'], 'office.read', [
            'resource_id' => $seed['sheet_id'],
            'selector' => [
                'kind' => 'sheet_table',
                'sheet_id' => $assignment['sheet_id'],
                'table_id' => $assignment['table_id'],
                'columns' => ['plan', 'actual'],
            ],
        ]);

        return [
            'snapshot_utf8_bytes' => strlen($loaded['text']),
            'snapshot_text' => $loaded['text'],
            'outline' => $outline,
            'wide_table' => $wide,
        ];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array{text: string}
     */
    private static function snapshotTexts(array $seed): array
    {
        /** @var SheetCommandFixture $fixture */
        $fixture = $seed['fixture'];
        $docs = Documents::in($fixture->drive->actingAs($fixture->actor));
        $contract = $docs->content($seed['contract_id']);
        $sheet = $docs->content($seed['sheet_id']);
        $contractJson = json_encode($contract->content->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sheetJson = json_encode(app(WorkbookCodec::class)->snapshot($sheet->content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['text' => $contractJson."\n".$sheetJson];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $assignment
     * @param  list<string>  $targets
     * @return array{clause: array<string, mixed>, sheet: array<string, mixed>, text: string}
     */
    private static function readSelection(Application $app, array $seed, array $assignment, array $targets): array
    {
        $clause = self::office($app, $seed['fixture'], 'office.read', [
            'resource_id' => $seed['contract_id'],
            'selector' => ['kind' => 'text_block', 'block_id' => $assignment['clause_block_id']],
        ]);
        $sheet = self::office($app, $seed['fixture'], 'office.read', [
            'resource_id' => $seed['sheet_id'],
            'selector' => [
                'kind' => 'sheet_table',
                'sheet_id' => $assignment['sheet_id'],
                'table_id' => $assignment['table_id'],
                'columns' => ['plan', 'actual'],
                'row_ids' => $targets,
            ],
        ]);
        $clauseJson = json_encode($clause['result']['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sheetJson = json_encode($sheet['result']['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['clause' => $clause, 'sheet' => $sheet, 'text' => $clauseJson."\n".$sheetJson];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $assignment
     * @return array{matches: bool, truth: array<string, mixed>}
     */
    private static function observeTruth(array $seed, array $assignment): array
    {
        /** @var SheetCommandFixture $fixture */
        $fixture = $seed['fixture'];
        $catalog = $fixture->catalog();
        $table = null;
        foreach ($catalog['workbook']->tables as $candidate) {
            if ($candidate->table_id === $assignment['table_id'] && $candidate->sheet_id === $assignment['sheet_id']) {
                $table = $candidate;
                break;
            }
        }
        $rowById = [];
        $columnById = [];
        if ($table !== null) {
            foreach ($table->rows as $row) {
                $rowById[$row->row_id] = $row->row;
            }
            foreach ($table->columns as $column) {
                $columnById[$column->column_id] = $column->column;
            }
        }
        $cells = [];
        $read = $fixture->drive->actingAs($fixture->actor)->extension(DriveWorkbookOperations::class)->readTable(
            $fixture->node->id,
            (string) $assignment['table_id'],
            ['plan', 'actual', (string) $assignment['destination_column_id']],
            ['r-telecom', 'r-travel', 'r-fees'],
        );
        foreach ($read['ranges'] as $range) {
            foreach ($range->cells as $cell) {
                $cells[$cell->row.'|'.$cell->column] = $cell;
            }
        }

        $planColumn = $columnById['plan'] ?? 2;
        $actualColumn = $columnById['actual'] ?? 3;
        $deltaColumn = $columnById[$assignment['destination_column_id']] ?? 4;
        $planLetter = WorkbookProblemMapper::columnLetter($planColumn);
        $actualLetter = WorkbookProblemMapper::columnLetter($actualColumn);
        $rows = [];
        $matches = $seed['clause_visible'] === true && $table !== null;
        foreach ($assignment['truth'] as $rowId => $spec) {
            $rowNumber = $rowById[$rowId] ?? null;
            $cell = is_int($rowNumber) ? ($cells[$rowNumber.'|'.$deltaColumn] ?? null) : null;
            $formula = $cell->formula ?? null;
            $value = $cell->value ?? null;
            $untouched = ($spec['untouched'] ?? false) === true;
            $expectedFormula = $untouched ? null : sprintf('=%s%d-%s%d', $actualLetter, $rowNumber, $planLetter, $rowNumber);
            $rows[$rowId] = [
                'row' => $rowNumber,
                'formula' => is_string($formula) ? $formula : null,
                'value' => is_int($value) || is_float($value) ? $value + 0 : $value,
            ];
            if ($rows[$rowId]['formula'] !== $expectedFormula || $rows[$rowId]['value'] != $spec['delta']) {
                $matches = false;
            }
        }

        $clause = Documents::in($fixture->drive->actingAs($fixture->actor))->content($seed['contract_id']);
        $clauseText = null;
        foreach ($clause->content->toArray()['content'] ?? [] as $block) {
            if (($block['attrs']['blockId'] ?? null) === $assignment['clause_block_id']) {
                $clauseText = $block['content'][0]['text'] ?? null;
            }
        }
        if ($clauseText !== $assignment['clause_text']) {
            $matches = false;
        }

        return [
            'matches' => $matches,
            'truth' => [
                'clause' => $clauseText,
                'rows' => $rows,
                'verify_at_commit' => null,
                'verify_current' => null,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $branches
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private static function optimization(array $branches, array $identity, string $scaleId): array
    {
        $full = $branches['full_snapshot'];
        $sdk = $branches['sdk_selection'];
        $sections = [
            'model_context_utf8_bytes' => [$full['model_context_utf8_bytes'], $sdk['model_context_utf8_bytes']],
            'cold_read_sql' => [$full['phases']['cold_read']['sql_queries'], $sdk['phases']['cold_read']['sql_queries']],
            'warm_read_p95_ms' => [$full['phases']['warm_read']['p95_ms'], $sdk['phases']['warm_read']['p95_ms']],
            'write_ms' => [$full['phases']['write']['ms'], $sdk['phases']['write']['ms']],
            'recovery_ms' => [$full['phases']['recovery']['ms'], $sdk['phases']['recovery']['ms']],
            'snapshot_utf8_bytes' => [$full['snapshot_utf8_bytes'], $sdk['snapshot_utf8_bytes']],
        ];
        $decisive = ['model_context_utf8_bytes', 'cold_read_sql'];
        $lower = [];
        $notLower = [];
        foreach ($decisive as $name) {
            [$left, $right] = $sections[$name];
            if ($right < $left) {
                $lower[] = $name;
            } else {
                $notLower[] = $name;
            }
        }
        $reduced = $lower !== [];
        $section = $reduced ? null : ($notLower[0] ?? 'model_context_utf8_bytes');

        return [
            'reduced' => $reduced,
            'lower_sections' => $lower,
            'section' => $section,
            'sections' => array_map(static fn (array $pair, string $name): array => [
                'name' => $name,
                'full_snapshot' => $pair[0],
                'sdk_selection' => $pair[1],
                'sdk_lower' => $pair[1] < $pair[0],
            ], $sections, array_keys($sections)),
            'working_configuration' => [
                'scale' => $scaleId,
                'engine' => $identity['engine'],
                'profile' => $identity['profile'],
                'model' => $identity['model'],
                'full_admitted_selector' => $full['admitted_selector'],
                'sdk_admitted_selector' => $sdk['admitted_selector'],
                'full_narrowed' => $full['narrowed'],
                'full_narrow_reasons' => $full['narrow_reasons'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $primary
     * @param  list<array<string, mixed>>  $samples
     * @return array<string, mixed>
     */
    private static function phaseReport(?array $primary, string $temperature, array $samples, ?int $httpRequests, ?int $httpBytes): array
    {
        $durations = array_map(static fn (array $sample): float => (float) $sample['ms'], $samples);
        $sql = array_map(static fn (array $sample): int => (int) $sample['sql_queries'], $samples);
        $requests = $httpRequests;
        $bytes = $httpBytes;
        if ($temperature === 'warm') {
            $requests = self::nearestRankInt(array_map(static fn (array $sample): int => (int) $sample['value']['http_requests'], $samples), 0.50);
            $bytes = self::nearestRankInt(array_map(static fn (array $sample): int => (int) $sample['value']['http_bytes'], $samples), 0.50);
        }
        $buffers = $samples[0]['buffers'] ?? ['status' => 'unmeasured', 'reason' => 'no_sample'];
        $disk = $samples[0]['disk'] ?? ['status' => 'unmeasured', 'reason' => 'no_sample'];

        return [
            'temperature' => $temperature,
            'sample_count' => count($samples),
            'samples_ms' => $durations,
            'ms' => $primary['ms'] ?? self::nearestRank($durations, 0.50),
            'p50_ms' => self::nearestRank($durations, 0.50),
            'p95_ms' => self::nearestRank($durations, 0.95),
            'p95_method' => 'nearest rank ceil(p*n)',
            'p95_confidence' => count($samples) >= 20 ? 'nearest_rank' : 'indicative',
            'sql_queries' => self::nearestRankInt($sql, 0.50),
            'http_requests' => $requests ?? 0,
            'http_bytes' => $bytes ?? 0,
            'buffers' => $buffers,
            'disk' => $disk,
            'primary_ms' => $primary['ms'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $call */
    private static function callBrief(array $call): array
    {
        return [
            'ok' => $call['ok'],
            'error_code' => $call['error_code'],
            'admission' => $call['admission'],
        ];
    }

    /**
     * @param  array<string, mixed>  $discovery
     * @return list<string>
     */
    private static function narrowReasons(array $discovery, bool $snapshotFits): array
    {
        $reasons = [];
        if (! $snapshotFits && $discovery['outline'] !== null) {
            $reasons[] = 'snapshot_over_bytes';
        }
        foreach (['outline' => 'outline', 'wide_table' => 'wide_table'] as $key => $label) {
            $call = $discovery[$key] ?? null;
            if (! is_array($call)) {
                continue;
            }
            if ($call['admission']['admissible'] !== true) {
                foreach ($call['admission']['reasons'] as $reason) {
                    $reasons[] = $label.':'.$reason;
                }
            }
        }

        return $reasons;
    }

    /** @param  list<array<string, mixed>>  $calls */
    private static function httpBytesOf(array $calls): int
    {
        $bytes = 0;
        foreach ($calls as $call) {
            $bytes += (int) ($call['request_bytes'] ?? 0) + (int) ($call['response_bytes'] ?? 0);
        }

        return $bytes;
    }

    /** @param  array<string, mixed>  $call */
    private static function segmentText(array $call): string
    {
        $segments = $call['result']['data']['segments'] ?? [];
        if (! is_array($segments)) {
            return '';
        }
        $text = '';
        foreach ($segments as $segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null)) {
                $text .= $segment['text'];
            }
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function office(Application $app, SheetCommandFixture $fixture, string $operation, array $input): array
    {
        if ($operation !== 'office.search' && ! isset($input['resource_id'])) {
            throw new RuntimeException('resource_id is required');
        }
        $payload = ['version' => 1, 'operation' => $operation, 'input' => $input];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $request = Request::create(
            '/api/vaulter-ai/v1/operations',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer scenario-test-token',
                'CONTENT_LENGTH' => (string) strlen($body),
            ],
            content: $body,
        );
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $raw = $response->getContent();
        $kernel->terminate($request, $response);
        $decoded = json_decode($raw === false ? '' : $raw, true);
        $ok = is_array($decoded) && ($decoded['ok'] ?? false) === true;
        $error = null;
        if (! $ok) {
            $code = is_array($decoded) ? ($decoded['error']['code'] ?? null) : null;
            $error = is_string($code) ? $code : 'invalid_response';
        }
        $result = $ok && is_array($decoded['result'] ?? null) ? $decoded['result'] : null;
        $truncated = is_array($result) && ($result['truncated'] ?? false) === true;
        $cursor = is_array($result) ? ($result['next_cursor'] ?? null) : null;
        $rows = 0;
        $data = is_array($result) ? ($result['data'] ?? null) : null;
        if (is_array($data)) {
            if (is_array($data['rows'] ?? null)) {
                $rows = count($data['rows']);
            } elseif (is_array($data['blocks'] ?? null)) {
                $rows = count($data['blocks']);
            } elseif (($data['kind'] ?? null) === 'text_block') {
                $rows = 1;
            }
        } elseif (is_array($result) && is_array($result['items'] ?? null)) {
            $rows = count($result['items']);
        }
        $responseBytes = strlen($raw === false ? '' : $raw);
        $admission = self::admit([
            'ok' => $ok,
            'error_code' => $error,
            'truncated' => $truncated,
            'complete' => $ok && ! $truncated && ($cursor === null || $cursor === ''),
            'bytes' => $responseBytes,
            'rows' => $rows,
        ], self::$limits);

        return [
            'ok' => $ok,
            'error_code' => $error,
            'result' => $result,
            'rows' => $rows,
            'request_bytes' => strlen($body),
            'response_bytes' => $responseBytes,
            'http_status' => $response->getStatusCode(),
            'admission' => $admission,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return array{ms: float, sql_queries: int, sql_chars: int, buffers: array<string, mixed>, disk: array<string, mixed>, value: T}
     */
    private static function phase(callable $fn): array
    {
        $probe = self::$probe ?? throw new RuntimeException('SQL probe is not attached.');
        $before = self::bufferCounters();
        $probe->reset();
        $start = hrtime(true);
        $probe->begin();
        try {
            $value = $fn();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $probe->end();
        }
        $after = self::bufferCounters();
        $buffers = self::bufferDelta($before, $after);

        return [
            'ms' => round($elapsed, 3),
            'sql_queries' => $probe->queries,
            'sql_chars' => $probe->sqlChars,
            'buffers' => $buffers,
            'disk' => $buffers['status'] === 'measured'
                ? ['status' => 'measured', 'blocks_read' => $buffers['blks_read']]
                : ['status' => 'unmeasured', 'reason' => $buffers['reason'] ?? 'engine_has_no_shared_buffers'],
            'value' => $value,
        ];
    }

    /** @return array<string, mixed> */
    private static function bufferCounters(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return ['status' => 'unmeasured', 'reason' => 'engine_has_no_shared_buffers', 'driver' => $driver];
        }
        try {
            $row = DB::selectOne('select blks_read, blks_hit from pg_stat_database where datname = current_database()');
        } catch (Throwable) {
            return ['status' => 'unmeasured', 'reason' => 'pg_stat_database_unavailable', 'driver' => $driver];
        }
        if ($row === null) {
            return ['status' => 'unmeasured', 'reason' => 'pg_stat_database_unavailable', 'driver' => $driver];
        }

        return [
            'status' => 'measured',
            'driver' => $driver,
            'blks_read' => (int) $row->blks_read,
            'blks_hit' => (int) $row->blks_hit,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private static function bufferDelta(array $before, array $after): array
    {
        if (($before['status'] ?? null) !== 'measured' || ($after['status'] ?? null) !== 'measured') {
            return [
                'status' => 'unmeasured',
                'reason' => $before['reason'] ?? $after['reason'] ?? 'engine_has_no_shared_buffers',
                'driver' => $before['driver'] ?? $after['driver'] ?? null,
            ];
        }

        return [
            'status' => 'measured',
            'driver' => $after['driver'],
            'blks_read' => $after['blks_read'] - $before['blks_read'],
            'blks_hit' => $after['blks_hit'] - $before['blks_hit'],
        ];
    }

    private static function attachProbe(Application $app): void
    {
        $connections = [DB::connection(), $app->make(VaulterDatabase::class)->connection()];
        foreach ($connections as $connection) {
            $id = spl_object_id($connection);
            if (isset(self::$attached[$id])) {
                continue;
            }
            self::$attached[$id] = true;
            $connection->listen(static function (QueryExecuted $query): void {
                $probe = self::$probe;
                if ($probe === null || ! $probe->on) {
                    return;
                }
                $probe->queries++;
                $probe->sqlChars += strlen($query->sql);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $workload
     * @return array<string, int>
     */
    private static function limitsFrom(Application $app, array $workload): array
    {
        $config = $app->make('config');
        $declared = $workload['access_profile'];
        $read = static function (string $key, int $fallback) use ($config): int {
            $value = $config->get('vaulter-ai.limits.'.$key, $fallback);

            return is_int($value) && $value > 0 ? $value : $fallback;
        };
        $searchMax = $read('search_max_limit', 50);
        $page = min($searchMax, (int) $declared['search_page_max']);

        return [
            'search_page_max' => $page,
            'read_max_rows' => $read('read_max_rows', 20),
            'read_max_bytes' => $read('read_max_bytes', 16384),
            'prepare_max_targets' => $read('prepare_max_targets', 20),
        ];
    }

    /**
     * @param  array<string, mixed>  $workload
     * @return array<string, mixed>
     */
    private static function identity(Application $app, array $workload): array
    {
        $root = dirname(__DIR__, 2);
        $sha = self::capture('git -C '.escapeshellarg($root).' rev-parse HEAD');
        $dirty = self::capture('git -C '.escapeshellarg($root).' status --porcelain');
        $lock = $root.'/composer.lock';
        $driver = DB::connection()->getDriverName();
        $engineVersion = null;
        try {
            $engineVersion = $driver === 'pgsql' ? DB::scalar('select version()') : DB::scalar('select sqlite_version()');
        } catch (Throwable) {
            $engineVersion = null;
        }
        $declared = $workload['access_profile'];
        $matches = (int) $declared['search_page_max'] === self::$limits['search_page_max']
            && (int) $declared['read_max_rows'] === self::$limits['read_max_rows']
            && (int) $declared['read_max_bytes'] === self::$limits['read_max_bytes']
            && (int) $declared['prepare_max_targets'] === self::$limits['prepare_max_targets'];
        $targets = $workload['assignment']['target_row_ids'];
        if (count($targets) > self::$limits['prepare_max_targets']) {
            $matches = false;
        }

        return [
            'git' => ['sha' => $sha, 'dirty' => is_string($dirty) && $dirty !== ''],
            'lock' => [
                'path' => 'composer.lock',
                'sha256' => is_file($lock) ? hash_file('sha256', $lock) : null,
            ],
            'build' => [
                'php' => PHP_VERSION,
                'univer' => $workload['engine']['univer'],
                'calculator' => $workload['engine']['calculator'],
                'vaulter_sheets' => InstalledVersions::getPrettyVersion('axiomasoft/vaulter-sheets'),
                'vaulter_ai_sheets' => InstalledVersions::getPrettyVersion('axiomasoft/vaulter-ai-sheets'),
            ],
            'engine' => [
                'db_driver' => $driver,
                'db_version' => is_string($engineVersion) ? $engineVersion : null,
                'buffers' => self::bufferCounters(),
            ],
            'model' => [
                'id' => $workload['model']['id'],
                'mode' => $workload['model']['mode'],
                'tokenizer' => $workload['model']['tokenizer'],
            ],
            'profile' => [
                'name' => $declared['name'],
                'matches_declaration' => $matches,
                'limits' => self::$limits,
            ],
            'targets' => $targets,
            'command' => $workload['assignment']['command'],
        ];
    }

    /** @return array<string, array{status: string, reason: string}> */
    private static function unmeasuredTokens(): array
    {
        return [
            'provider_input' => ['status' => 'unmeasured', 'reason' => 'fake_model'],
            'provider_output' => ['status' => 'unmeasured', 'reason' => 'fake_model'],
            'tokenizer_estimate_input' => ['status' => 'unmeasured', 'reason' => 'fake_model_has_no_tokenizer'],
            'tokenizer_estimate_output' => ['status' => 'unmeasured', 'reason' => 'fake_model_has_no_tokenizer'],
        ];
    }

    /** @param  list<float|int>  $samples */
    private static function nearestRank(array $samples, float $percentile): float
    {
        $sorted = array_map(static fn (float|int $sample): float => (float) $sample, $samples);
        sort($sorted);
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $rank = (int) ceil($percentile * $count);

        return round($sorted[max(0, min($count, $rank) - 1)], 3);
    }

    /** @param  list<int>  $samples */
    private static function nearestRankInt(array $samples, float $percentile): int
    {
        return (int) round(self::nearestRank($samples, $percentile));
    }

    /** @param  array<string, mixed>  $workload */
    private static function assertWorkload(array $workload): void
    {
        foreach (['access_profile', 'model', 'engine', 'assignment', 'scales', 'iterations'] as $key) {
            if (! isset($workload[$key]) || ! is_array($workload[$key])) {
                throw new RuntimeException("Workload is missing {$key}.");
            }
        }
        $pages = $workload['scales'];
        $ids = array_map(static fn (mixed $scale): string => is_array($scale) ? (string) ($scale['id'] ?? '') : '', $pages);
        if ($ids !== ['1x', '10x']) {
            throw new RuntimeException('Workload scales must be 1x then 10x.');
        }
        foreach ($pages as $scale) {
            if (! is_array($scale) || (int) $scale['contract_blocks'] < 2 || (int) $scale['sheet_rows'] < 3) {
                throw new RuntimeException('Scale corpus is smaller than the canonical contract clause and three sheet rows.');
            }
        }
        $measured = $workload['iterations']['measured'] ?? 0;
        $warmup = $workload['iterations']['warmup'] ?? -1;
        if (! is_int($measured) || $measured < 1 || ! is_int($warmup) || $warmup < 0) {
            throw new RuntimeException('Iterations need warmup >= 0 and measured >= 1.');
        }
        $targets = $workload['assignment']['target_row_ids'] ?? null;
        if ($targets !== ['r-telecom', 'r-travel']) {
            throw new RuntimeException('This bench assignment writes only r-telecom and r-travel.');
        }
    }

    private static function nonNegativeInt(string $raw, string $flag): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
            throw new RuntimeException("{$flag} must be a non-negative integer.");
        }

        return (int) $raw;
    }

    private static function positiveInt(string $raw, string $flag): int
    {
        $value = self::nonNegativeInt($raw, $flag);
        if ($value < 1) {
            throw new RuntimeException("{$flag} must be >= 1.");
        }

        return $value;
    }

    private static function capture(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }
        $output = shell_exec($command.' 2>/dev/null');

        return is_string($output) ? trim($output) : null;
    }
}

/**
 * Counts SQL that runs while a bench phase is open.
 */
final class SqlProbe
{
    public bool $on = false;

    public int $queries = 0;

    public int $sqlChars = 0;

    private int $depth = 0;

    public function begin(): void
    {
        $this->depth++;
        $this->on = true;
    }

    public function end(): void
    {
        $this->depth = max(0, $this->depth - 1);
        $this->on = $this->depth > 0;
    }

    public function reset(): void
    {
        $this->queries = 0;
        $this->sqlChars = 0;
    }
}

if (! class_exists(OfficeTestCase::class)) {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
}

/**
 * Boots the same Testbench harness the sheet suite uses, for the CLI entrypoint.
 */
final class OfficeBenchHarness extends OfficeTestCase
{
    public function boot(): void
    {
        // The Pest suite receives these from phpunit.xml.dist. The CLI applies the same
        // defaults only when the operator has not already set them, including APP_KEY.
        // tearDown() is intentionally not called: outside PHPUnit it flushes an
        // uninitialized PHPUnit configuration registry.
        foreach ([
            'APP_KEY' => 'base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ] as $key => $value) {
            $current = getenv($key);
            if ($current === false || $current === '') {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        $this->setUp();
    }

    public function application(): Application
    {
        return $this->app;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(OfficeCostCompare::main($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
        exit(2);
    }
}
