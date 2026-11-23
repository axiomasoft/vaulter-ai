<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import type { OfficeWorkspaceClient } from '../api/client'
import type { ProposalDetails, RunDetails } from '../api/types'
import { useProposalReview } from '../composables/useProposalReview'
import ProposalCard from './ProposalCard.vue'

const props = withDefaults(defineProps<{
  client: OfficeWorkspaceClient
  runId?: string
  proposalIds?: string[]
  initialRun?: RunDetails | null
  initialProposals?: ProposalDetails[]
  autoLoad?: boolean
}>(), {
  runId: undefined,
  proposalIds: () => [],
  initialRun: null,
  initialProposals: () => [],
  autoLoad: true,
})

const emit = defineEmits<{
  updated: [payload: { proposals: ProposalDetails[]; run: RunDetails | null }]
  error: [message: string]
}>()

const {
  proposals,
  run,
  confirmedSteps,
  remainingSteps,
  loading,
  resumingRun,
  submittingIds,
  error,
  lastDecisionWon,
  loadProposals,
  loadProposalsByIds,
  loadRun,
  decide,
  resume,
} = useProposalReview(props.client, {
  initialProposals: props.initialProposals,
  initialRun: props.initialRun,
})

const displayedProposals = computed(() => {
  const byId = new Map<string, ProposalDetails>()
  for (const item of proposals.value) {
    byId.set(item.proposal_id, item)
  }
  if (run.value) {
    for (const res of run.value.resources) {
      if (res.visible && res.proposal) {
        byId.set(res.proposal.proposal_id, res.proposal)
      }
    }
  }
  return Array.from(byId.values())
})

async function refresh(): Promise<void> {
  try {
    if (props.runId) {
      await loadRun(props.runId)
    }
    if (props.proposalIds.length > 0) {
      await loadProposalsByIds(props.proposalIds)
    } else if (!props.runId && props.initialProposals.length === 0) {
      await loadProposals()
    }
    emit('updated', { proposals: displayedProposals.value, run: run.value })
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Workspace refresh failed.')
  }
}

async function handleDecide(payload: { proposalId: string; digest: string; approved: boolean }): Promise<void> {
  try {
    await decide(payload.proposalId, payload.digest, payload.approved)
    emit('updated', { proposals: displayedProposals.value, run: run.value })
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Proposal decision failed.')
  }
}

async function handleResume(): Promise<void> {
  if (!run.value) {
    return
  }
  try {
    await resume(run.value.run_id)
    emit('updated', { proposals: displayedProposals.value, run: run.value })
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Run continuation failed.')
  }
}

onMounted(() => {
  if (props.autoLoad && !props.initialRun && props.initialProposals.length === 0) {
    void refresh()
  }
})

watch(() => props.runId, (nextRunId, prevRunId) => {
  if (nextRunId && nextRunId !== prevRunId && props.autoLoad) {
    void refresh()
  }
})
</script>

<template>
  <section class="vai-review-panel" data-testid="proposal-review-panel">
    <div v-if="error" class="vai-review-panel__alert vai-review-panel__alert--error" data-testid="workspace-error">
      Ошибка (<code>{{ error.code }}</code>): {{ error.message }}
    </div>

    <div v-if="lastDecisionWon === false" class="vai-review-panel__alert vai-review-panel__alert--info" data-testid="decision-lost-race">
      Предложение уже было решено другим рецензентом; показано актуальное итоговое решение.
    </div>

    <div v-if="run" class="vai-run-overview" data-testid="run-overview">
      <div class="vai-run-overview__header">
        <div>
          <h2 data-testid="run-id">Задание <code>{{ run.run_id }}</code></h2>
          <div class="vai-run-overview__meta">
            <span data-testid="run-state">Статус задания: <strong>{{ run.state }}</strong></span>
            <span data-testid="run-initiator">Инициатор: {{ run.initiator }}</span>
            <span data-testid="run-channel">Канал: {{ run.channel }}</span>
          </div>
        </div>

        <div class="vai-run-overview__actions">
          <button
            type="button"
            class="vai-btn"
            data-action="refresh-run"
            :disabled="loading"
            @click="refresh"
          >
            Обновить состояние
          </button>
          <button
            v-if="run.can_resume"
            type="button"
            class="vai-btn vai-btn--primary"
            data-action="resume-run"
            :disabled="resumingRun"
            @click="handleResume"
          >
            Продолжить незавершённые шаги
          </button>
        </div>
      </div>

      <div v-if="confirmedSteps.length > 0" class="vai-run-steps vai-run-steps--confirmed" data-testid="confirmed-steps">
        <h3>Подтверждённые шаги ({{ confirmedSteps.length }})</h3>
        <ul>
          <li
            v-for="step in confirmedSteps"
            :key="`conf-${step.resource_id}`"
            :data-resource-id="step.resource_id"
          >
            <strong>{{ step.title || step.resource_id }}</strong>
            — статус: {{ step.status }}
            <span v-if="step.execution?.receipt_id">
              · квитанция <code>{{ step.execution.receipt_id }}</code>
            </span>
            <span v-if="step.verification?.status === 'passed'"> · проверено</span>
          </li>
        </ul>
      </div>

      <div v-if="remainingSteps.length > 0" class="vai-run-steps vai-run-steps--remaining" data-testid="remaining-steps">
        <h3>Оставшиеся шаги ({{ remainingSteps.length }})</h3>
        <ul>
          <li
            v-for="step in remainingSteps"
            :key="`rem-${step.resource_id}`"
            :data-resource-id="step.resource_id"
          >
            <strong>{{ step.title || step.resource_id }}</strong>
            — статус: {{ step.status }}
            <span v-if="step.error"> (<code>{{ step.error }}</code>)</span>
            <span v-if="step.requires_reread" class="vai-badge vai-badge--warning" data-testid="requires-reread">
              Требуется повторное чтение цели
            </span>
          </li>
        </ul>
      </div>
    </div>

    <div class="vai-proposals-list" data-testid="proposals-list">
      <ProposalCard
        v-for="proposal in displayedProposals"
        :key="proposal.proposal_id"
        :proposal="proposal"
        :submitting="submittingIds.includes(proposal.proposal_id)"
        @decide="handleDecide"
      />
      <p v-if="!loading && displayedProposals.length === 0" data-testid="proposals-empty">
        Нет доступных предложений.
      </p>
    </div>
  </section>
</template>
