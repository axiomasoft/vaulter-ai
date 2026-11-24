<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import '../style.css'
import type { OfficeWorkspaceClient } from '../api/client'
import type { ProposalDetails, ProposalReviewPanelMessages, RunDetails } from '../api/types'
import { useProposalReview } from '../composables/useProposalReview'
import ProposalCard from './ProposalCard.vue'

const DEFAULT_MESSAGES: ProposalReviewPanelMessages = {
  title: 'Проверка предложений',
  emptyState: 'Нет доступных предложений.',
  refreshButton: 'Обновить',
  refreshingButton: 'Обновление…',
  refreshRunButton: 'Обновить состояние',
  resumeRunButton: 'Продолжить незавершённые шаги',
  filterAll: 'Все',
  filterOpen: 'Ожидают',
  filterResolved: 'Завершённые',
  errorPrefix: 'Ошибка',
  lostRaceNotice: 'Предложение уже было решено другим рецензентом; показано актуальное итоговое решение.',
  runTitle: 'Задание',
  runStateLabel: 'Статус задания:',
  runInitiatorLabel: 'Инициатор:',
  runChannelLabel: 'Канал:',
  confirmedStepsTitle: 'Подтверждённые шаги',
  remainingStepsTitle: 'Оставшиеся шаги',
  stepStatusLabel: '— статус:',
  receiptInlineLabel: '· квитанция',
  verifiedInlineLabel: '· проверено',
  requiresRereadBadge: 'Требуется повторное чтение цели',
}

const props = withDefaults(defineProps<{
  client: OfficeWorkspaceClient
  runId?: string
  proposalIds?: string[]
  initialRun?: RunDetails | null
  initialProposals?: ProposalDetails[]
  autoLoad?: boolean
  messages?: Partial<ProposalReviewPanelMessages>
}>(), {
  runId: undefined,
  proposalIds: () => [],
  initialRun: null,
  initialProposals: () => [],
  autoLoad: true,
  messages: () => ({}),
})

const m = computed<ProposalReviewPanelMessages>(() => ({
  ...DEFAULT_MESSAGES,
  ...props.messages,
}))

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
  <section class="vai-review-panel vaulter-ai-review-panel" data-testid="proposal-review-panel">
    <slot name="header" :run="run" :loading="loading" :refresh="refresh" :messages="m" />

    <div v-if="error" class="vai-review-panel__alert vai-review-panel__alert--error vaulter-ai-banner vaulter-ai-banner--error" data-testid="workspace-error">
      {{ m.errorPrefix }} (<code>{{ error.code }}</code>): {{ error.message }}
    </div>

    <div v-if="lastDecisionWon === false" class="vai-review-panel__alert vai-review-panel__alert--info vaulter-ai-banner" data-testid="decision-lost-race">
      {{ m.lostRaceNotice }}
    </div>

    <div v-if="run" class="vai-run-overview" data-testid="run-overview">
      <div class="vai-run-overview__header">
        <div>
          <h2 data-testid="run-id">{{ m.runTitle }} <code>{{ run.run_id }}</code></h2>
          <div class="vai-run-overview__meta">
            <span data-testid="run-state">{{ m.runStateLabel }} <strong>{{ run.state }}</strong></span>
            <span data-testid="run-initiator">{{ m.runInitiatorLabel }} {{ run.initiator }}</span>
            <span data-testid="run-channel">{{ m.runChannelLabel }} {{ run.channel }}</span>
          </div>
        </div>

        <div class="vai-run-overview__actions vaulter-ai-review-panel__actions">
          <button
            type="button"
            class="vai-btn vaulter-ai-btn"
            data-action="refresh-run"
            :disabled="loading"
            @click="refresh"
          >
            {{ m.refreshRunButton }}
          </button>
          <button
            v-if="run.can_resume"
            type="button"
            class="vai-btn vai-btn--primary vaulter-ai-btn vaulter-ai-btn--primary"
            data-action="resume-run"
            :disabled="resumingRun"
            @click="handleResume"
          >
            {{ m.resumeRunButton }}
          </button>
        </div>
      </div>

      <div v-if="confirmedSteps.length > 0" class="vai-run-steps vai-run-steps--confirmed" data-testid="confirmed-steps">
        <h3>{{ m.confirmedStepsTitle }} ({{ confirmedSteps.length }})</h3>
        <ul>
          <li
            v-for="step in confirmedSteps"
            :key="`conf-${step.resource_id}`"
            :data-resource-id="step.resource_id"
          >
            <strong>{{ step.title || step.resource_id }}</strong>
            {{ m.stepStatusLabel }} {{ step.status }}
            <span v-if="step.execution?.receipt_id">
              {{ m.receiptInlineLabel }} <code>{{ step.execution.receipt_id }}</code>
            </span>
            <span v-if="step.verification?.status === 'passed'"> {{ m.verifiedInlineLabel }}</span>
          </li>
        </ul>
      </div>

      <div v-if="remainingSteps.length > 0" class="vai-run-steps vai-run-steps--remaining" data-testid="remaining-steps">
        <h3>{{ m.remainingStepsTitle }} ({{ remainingSteps.length }})</h3>
        <ul>
          <li
            v-for="step in remainingSteps"
            :key="`rem-${step.resource_id}`"
            :data-resource-id="step.resource_id"
          >
            <strong>{{ step.title || step.resource_id }}</strong>
            {{ m.stepStatusLabel }} {{ step.status }}
            <span v-if="step.error"> (<code>{{ step.error }}</code>)</span>
            <span v-if="step.requires_reread" class="vai-badge vai-badge--warning" data-testid="requires-reread">
              {{ m.requiresRereadBadge }}
            </span>
          </li>
        </ul>
      </div>
    </div>

    <div class="vai-proposals-list" data-testid="proposals-list">
      <slot
        v-for="proposal in displayedProposals"
        :key="proposal.proposal_id"
        name="item"
        :proposal="proposal"
        :submitting="submittingIds.includes(proposal.proposal_id)"
        :decide="handleDecide"
      >
        <ProposalCard
          :proposal="proposal"
          :submitting="submittingIds.includes(proposal.proposal_id)"
          :messages="m.card"
          @decide="handleDecide"
        />
      </slot>
      <p v-if="!loading && displayedProposals.length === 0" data-testid="proposals-empty">
        <slot name="empty">{{ m.emptyState }}</slot>
      </p>
    </div>

    <slot name="footer" :proposals="displayedProposals" :run="run" :messages="m" />
  </section>
</template>
