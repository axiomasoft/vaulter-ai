<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Scenario\ScenarioResult;
use AgenTools\Scenario\ScenarioRunner;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResumableOfficeExecutor;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRun;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\RunStateReducer;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\Runs;

/**
 * OfficeExecutor implementation for AgenTools ScenarioRunner / ToolPort execution.
 */
final readonly class AgenToolsOfficeExecutor implements ResumableOfficeExecutor
{
    public const string KEY = 'agentools';

    private const int DEFAULT_LEASE_SECONDS = 90;

    public function __construct(
        private OfficeToolPort $port,
        private HostPolicy $policy,
        private Runs $runs,
        private RunStateReducer $stateReducer,
        private ConfigRepository $config,
        private ScenarioRunner $runner = new ScenarioRunner,
        private ?StepCommitCoordinator $commits = null,
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
            : $this->defaultChannel();
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
                'executor' => self::KEY,
                'scenario' => $scenario,
                'scenario_context' => $scenarioContext,
            ],
        );

        $lease = $this->runs->claimLease($context, $run->runId, $this->leaseSeconds());
        if ($lease === null) {
            throw OfficeException::temporarilyUnavailable();
        }

        $saved = $run->input['scenario'] ?? null;
        $savedContext = $run->input['scenario_context'] ?? null;

        return $this->executeScenario($context, $run->runId, $lease,
            is_array($saved) ? $saved : $scenario, is_array($savedContext) ? $savedContext : $scenarioContext);
    }

    public function executeRun(CallContext $context, string $runId): OfficeRun
    {
        $run = $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();
        $profile = $this->policy->profile($context);
        if (($run->input['profile'] ?? null) !== $profile->name || $run->profileRevision !== $profile->revision
            || ($run->input['executor'] ?? self::KEY) !== self::KEY) {
            throw OfficeException::preconditionFailed();
        }
        $lease = $this->runs->claimLease($context, $runId, $this->leaseSeconds());
        if ($lease === null) {
            return $this->runs->find($context, $runId) ?? $run;
        }

        $scenario = $run->input['scenario'] ?? null;
        $scenarioContext = $run->input['scenario_context'] ?? null;
        if (is_array($scenario) && is_array($scenarioContext)) {
            $this->executeScenario($context, $runId, $lease, $scenario, $scenarioContext);
        } else {
            // Older runs have no replayable scenario. Reconcile their durable commit steps.
            try {
                $steps = $this->runs->listSteps($context, $runId);
                foreach ($steps as $step) {
                    if ($step->operation === OfficeOperation::Commit && in_array($step->state, ['waiting', 'dispatched'], true)) {
                        $this->commits?->resumeStep($context, $runId, $step->stepId);
                    }
                }
                $state = $this->stateReducer->reduce($this->runs->listSteps($context, $runId), executionFailed: true);
                $this->runs->finishRun($context, $runId, $lease, $state);
            } catch (Throwable $failure) {
                $this->runs->releaseLease($context, $runId, $lease, 'reconcile');

                throw $failure;
            }
        }

        return $this->runs->find($context, $runId) ?? $run;
    }

    /**
     * @param  array<array-key, mixed>  $scenario
     * @param  array<array-key, mixed>  $scenarioContext
     */
    private function executeScenario(CallContext $context, string $runId, string $lease, array $scenario, array $scenarioContext): ScenarioResult
    {
        try {
            $scopedPort = $this->port->forContext($context, $runId);
            $result = $this->runner->run(
                array_filter($scenario, is_string(...), ARRAY_FILTER_USE_KEY),
                array_filter($scenarioContext, is_string(...), ARRAY_FILTER_USE_KEY),
                $scopedPort,
                runId: $runId,
            );

            $steps = $this->runs->listSteps($context, $runId);
            $nextState = $this->stateReducer->reduce(
                $steps,
                executionFailed: $result->outcome !== ScenarioOutcome::Completed,
            );
            $this->runs->finishRun($context, $runId, $lease, $nextState);

            return $result;
        } catch (Throwable $failure) {
            $this->runs->releaseLease($context, $runId, $lease, 'reconcile');

            throw $failure;
        }
    }

    private function defaultChannel(): string
    {
        $channel = $this->config->get('vaulter-ai-agentools.channel');

        return is_string($channel) && $channel !== '' ? $channel : self::KEY;
    }

    /**
     * Lease that keeps a second worker from picking up the same run. It must outlast the slowest
     * scenario step, so operators tune it with VAULTER_AI_AGENTOOLS_LEASE_SECONDS.
     */
    private function leaseSeconds(): int
    {
        $seconds = $this->config->get('vaulter-ai-agentools.lease_seconds');

        return is_int($seconds) && $seconds > 0 ? $seconds : self::DEFAULT_LEASE_SECONDS;
    }
}
