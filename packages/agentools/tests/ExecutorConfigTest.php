<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Vaulter\Ai\AgenTools\AgenToolsOfficeExecutor;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Tests\Support\AgenToolsTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;

uses(AgenToolsTestCase::class);

/**
 * Dispatches a one-step read-only scenario and returns the lease length (seconds) the run was claimed with.
 *
 * @param  array<string, mixed>  $payload
 */
function dispatchAndMeasureLease(AgenToolsOfficeExecutor $executor, TextCommandFixture $fixture, array $payload = []): int
{
    $leases = [];
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$leases): void {
        // The claim is the only run update that sets state = running together with a lease.
        if (str_contains($query->sql, 'set "state" = ?, "lease_token" = ?, "lease_until" = ?, "updated_at" = ?')
            && ($query->bindings[0] ?? null) === 'running') {
            $leases[] = strtotime((string) $query->bindings[2]) - strtotime((string) $query->bindings[3]);
        }
    });

    $executor->dispatch($fixture->context, $payload + [
        'source_event_id' => 'evt-config-'.bin2hex(random_bytes(4)),
        'scenario' => [
            'spec_rev' => '2026-09',
            'scenario_id' => 'office-search-only',
            'steps' => [[
                'id' => 'search',
                'tool' => 'office_search',
                'input_map' => ['query' => '"contract"', 'resource_type' => '"text"', 'limit' => 1],
            ]],
        ],
    ]);

    expect($leases)->toHaveCount(1);

    return $leases[0];
}

beforeEach(function (): void {
    $this->fixture = new TextCommandFixture($this->app, automatic: true);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-config-1',
    ]);
});

test('runs are claimed for the configured lease', function (): void {
    Config::set('vaulter-ai-agentools.lease_seconds', 300);

    expect(dispatchAndMeasureLease($this->app->make(AgenToolsOfficeExecutor::class), $this->fixture))->toBe(300);
});

test('an invalid lease falls back to the 90 second default', function (mixed $configured): void {
    Config::set('vaulter-ai-agentools.lease_seconds', $configured);

    expect(dispatchAndMeasureLease($this->app->make(AgenToolsOfficeExecutor::class), $this->fixture))->toBe(90);
})->with([0, -5, '300', null]);

test('runs without an explicit channel land on the configured channel', function (): void {
    Config::set('vaulter-ai-agentools.channel', 'office-bot');
    $executor = $this->app->make(AgenToolsOfficeExecutor::class);

    dispatchAndMeasureLease($executor, $this->fixture, ['source_event_id' => 'evt-channel-1']);
    dispatchAndMeasureLease($executor, $this->fixture, ['source_event_id' => 'evt-channel-2', 'channel' => 'explicit']);

    $runs = $this->app->make(Runs::class);
    $configured = $runs->createOrFind($this->fixture->context, 'office-bot', 'evt-channel-1', 'rev-config-1', []);
    $explicit = $runs->createOrFind($this->fixture->context, 'explicit', 'evt-channel-2', 'rev-config-1', []);

    expect($configured->state)->toBe('completed')
        ->and($explicit->state)->toBe('completed');
});
