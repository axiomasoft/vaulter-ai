<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Scenario\ScenarioResult;
use AgenTools\Scenario\ScenarioRunner;
use Throwable;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\OfficeExecutor;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\Runs;

/**
 * OfficeExecutor implementation for AgenTools ScenarioRunner / ToolPort execution.
 */
final readonly class AgenToolsOfficeExecutor implements OfficeExecutor
{
    public const string KEY = 'agentools';

    public function __construct(
        private OfficeToolPort $port,
        private HostPolicy $policy,
        private Runs $runs,
        private ScenarioRunner $runner = new ScenarioRunner,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(CallContext $context, array $payload): void
    {
        $scenario = $payload['scenario'] ?? null;
        if (! is_array($scenario)) {
            throw OfficeException::invalidInput('AgenTools dispatch requires a scenario payload.');
        }

        $channel = is_string($payload['channel'] ?? null) && $payload['channel'] !== ''
            ? $payload['channel']
            : 'agentools';
        $sourceEventId = is_string($payload['source_event_id'] ?? null) && $payload['source_event_id'] !== ''
            ? $payload['source_event_id']
            : 'event-'.bin2hex(random_bytes(8));

        /** @var array<string, mixed> $typedScenario */
        $typedScenario = [];
        foreach ($scenario as $key => $value) {
            if (is_string($key)) {
                $typedScenario[$key] = $value;
            }
        }

        /** @var array<string, mixed> $scenarioContext */
        $scenarioContext = is_array($payload['scenario_context'] ?? null) ? $payload['scenario_context'] : [];

        $this->runScenario($context, $channel, $sourceEventId, $typedScenario, $scenarioContext);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $scenarioContext
     */
    public function runScenario(
        CallContext $context,
        string $channel,
        string $sourceEventId,
        array $scenario,
        array $scenarioContext = [],
    ): ScenarioResult {
        $profile = $this->policy->profile($context);
        $run = $this->runs->createOrFind(
            context: $context,
            channel: $channel,
            sourceEventId: $sourceEventId,
            profileRevision: $profile->revision,
            inputPayload: [
                'scenario_id' => $scenario['scenario_id'] ?? 'office',
                'profile' => $profile->name,
            ],
        );

        $lease = $this->runs->claimLease($context, $run->runId);
        if ($lease === null) {
            throw OfficeException::temporarilyUnavailable();
        }

        try {
            $scopedPort = $this->port->forContext($context, $run->runId);
            $result = $this->runner->run($scenario, $scenarioContext, $scopedPort, runId: $run->runId);

            $nextState = $result->outcome === ScenarioOutcome::Completed ? 'completed' : 'rejected';
            $this->runs->finishRun($context, $run->runId, $lease, $nextState);

            return $result;
        } catch (Throwable $failure) {
            $this->runs->releaseLease($context, $run->runId, $lease, 'reconcile');

            throw $failure;
        }
    }
}
