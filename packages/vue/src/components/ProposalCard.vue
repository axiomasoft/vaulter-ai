<script setup lang="ts">
import { computed } from 'vue'
import '../style.css'
import type { ProposalCardMessages, ProposalDetails } from '../api/types'
import ProposalDiffView from './ProposalDiffView.vue'

const DEFAULT_MESSAGES: ProposalCardMessages = {
  statusDraft: 'Черновик',
  statusPending: 'Ожидает решения человека',
  statusApprovedByHuman: 'Принято человеком',
  statusAutomatic: 'Автоматически',
  statusApprovedPendingExecution: 'Ожидает отправки владельцу',
  statusApprovedCommitted: 'Сохранено владельцем',
  statusOwnerRunning: 'Сохраняется владельцем',
  statusApprovedFailed: 'Ошибка выполнения',
  statusRejectedByHuman: 'Отклонено человеком',
  statusRejectedByOwner: 'Отклонено владельцем',
  statusOwnerNotStarted: 'Не отправлено владельцу',
  statusVerifyPassed: 'Проверено',
  statusVerifyFailed: 'Проверка не пройдена',
  statusVerifyUnverified: 'Не проверено',
  decisionWon: 'Решение принято',
  receiptLabel: 'Квитанция:',
  errorLabel: 'Ошибка:',
  ownerRejectionLabel: 'Причина отказа владельца:',
  detailsShow: 'Показать детали',
  detailsHide: 'Скрыть детали',
  acceptButton: 'Принять предложение',
  rejectButton: 'Отклонить',
  approvingButton: 'Отправка…',
  rejectingButton: 'Отклонение…',
  initiatorLabel: 'Инициатор:',
  reviewerLabel: 'Рецензент:',
  resourceLabel: 'Ресурс:',
  proposalIdLabel: 'Предложение:',
  commandIdLabel: 'command_id:',
  digestLabel: 'Digest:',
  expiresLabel: 'Истекает:',
  expiredNote: 'Срок предложения истёк.',
  readonlyNote: 'Нет права согласования для текущего пользователя.',
}

const props = withDefaults(defineProps<{
  proposal: ProposalDetails
  submitting?: boolean
  messages?: Partial<ProposalCardMessages>
}>(), {
  submitting: false,
  messages: () => ({}),
})

const m = computed<ProposalCardMessages>(() => ({
  ...DEFAULT_MESSAGES,
  ...props.messages,
}))

const emit = defineEmits<{
  decide: [payload: { proposalId: string; digest: string; approved: boolean }]
}>()

const humanStatusLabel = computed(() => {
  switch (props.proposal.approval.human_status) {
    case 'approved':
      return m.value.statusApprovedByHuman
    case 'rejected':
      return m.value.statusRejectedByHuman
    case 'automatic':
      return m.value.statusAutomatic
    default:
      return m.value.statusPending
  }
})

const ownerStatusLabel = computed(() => {
  switch (props.proposal.execution.owner_status) {
    case 'committed':
      return m.value.statusApprovedCommitted
    case 'pending_dispatch':
      return m.value.statusApprovedPendingExecution
    case 'running':
      return m.value.statusOwnerRunning
    case 'rejected':
      return m.value.statusRejectedByOwner
    default:
      return m.value.statusOwnerNotStarted
  }
})

const verificationStatusLabel = computed(() => {
  switch (props.proposal.verification.status) {
    case 'passed':
      return m.value.statusVerifyPassed
    case 'failed':
      return m.value.statusVerifyFailed
    default:
      return m.value.statusVerifyUnverified
  }
})

const canDecide = computed(() => (
  !props.submitting && props.proposal.approval.can_approve && props.proposal.approval.can_reject
))

function submitDecision(approved: boolean): void {
  if (!canDecide.value) {
    return
  }
  emit('decide', {
    proposalId: props.proposal.proposal_id,
    digest: props.proposal.digest,
    approved,
  })
}
</script>

<template>
  <article
    class="vai-proposal-card vaulter-ai-proposal-card"
    :data-proposal-id="proposal.proposal_id"
    :data-digest="proposal.digest"
    :data-decision="proposal.approval.decision"
  >
    <slot name="header" :proposal="proposal" :messages="m">
      <header class="vai-proposal-card__header">
        <div>
          <h3 class="vai-proposal-card__title" data-testid="proposal-resource-title">
            {{ proposal.resource.title || proposal.resource.resource_id }}
          </h3>
          <div class="vai-proposal-card__meta">
            <span data-testid="proposal-resource-id">{{ m.resourceLabel }} {{ proposal.resource.resource_id }}</span>
            <span data-testid="proposal-initiator">{{ m.initiatorLabel }} {{ proposal.initiator }}</span>
            <span v-if="proposal.approval.reviewer" data-testid="proposal-reviewer">
              {{ m.reviewerLabel }} {{ proposal.approval.reviewer }}
            </span>
          </div>
        </div>

        <slot name="status" :proposal="proposal" :human-status-label="humanStatusLabel" :owner-status-label="ownerStatusLabel" :verification-status-label="verificationStatusLabel">
          <div class="vai-proposal-card__badges">
            <span
              class="vai-badge vai-badge--human"
              data-testid="status-human"
              :data-human-status="proposal.approval.human_status"
            >
              {{ humanStatusLabel }}
            </span>
            <span
              class="vai-badge vai-badge--owner"
              data-testid="status-owner"
              :data-owner-status="proposal.execution.owner_status"
            >
              {{ ownerStatusLabel }}
            </span>
            <span
              class="vai-badge vai-badge--verify"
              data-testid="status-verification"
              :data-verification-status="proposal.verification.status"
            >
              {{ verificationStatusLabel }}
            </span>
          </div>
        </slot>
      </header>
    </slot>

    <slot name="meta" :proposal="proposal" :messages="m">
      <div class="vai-proposal-card__identity">
        <span data-testid="proposal-id">{{ m.proposalIdLabel }} <code>{{ proposal.proposal_id }}</code></span>
        <span data-testid="proposal-digest">{{ m.digestLabel }} <code>{{ proposal.digest }}</code></span>
        <span v-if="proposal.execution.command_id" data-testid="proposal-command-id">
          {{ m.commandIdLabel }} <code>{{ proposal.execution.command_id }}</code>
        </span>
        <span v-if="proposal.execution.receipt_id" data-testid="proposal-receipt-id">
          {{ m.receiptLabel }} <code>{{ proposal.execution.receipt_id }}</code>
        </span>
      </div>
    </slot>

    <slot name="diff" :proposal="proposal">
      <ProposalDiffView :diff="proposal.diff" :summary="proposal.summary" />
    </slot>

    <div v-if="proposal.execution.rejection_reason" class="vai-proposal-card__error vaulter-ai-banner vaulter-ai-banner--error" data-testid="proposal-owner-error">
      {{ m.ownerRejectionLabel }} <code>{{ proposal.execution.rejection_reason }}</code>
    </div>

    <footer class="vai-proposal-card__actions vaulter-ai-proposal-card__actions">
      <slot name="actions" :proposal="proposal" :can-decide="canDecide" :submitting="submitting" :decide="submitDecision" :messages="m">
        <template v-if="canDecide">
          <button
            type="button"
            class="vai-btn vai-btn--approve vaulter-ai-btn vaulter-ai-btn--primary"
            data-action="approve"
            :disabled="submitting"
            @click="submitDecision(true)"
          >
            {{ submitting ? m.approvingButton : m.acceptButton }}
          </button>
          <button
            type="button"
            class="vai-btn vai-btn--reject vaulter-ai-btn vaulter-ai-btn--danger"
            data-action="reject"
            :disabled="submitting"
            @click="submitDecision(false)"
          >
            {{ submitting ? m.rejectingButton : m.rejectButton }}
          </button>
        </template>
        <span v-else-if="proposal.approval.decision === 'pending' && proposal.approval.expired" data-testid="proposal-expired-note">
          {{ m.expiredNote }}
        </span>
        <span v-else-if="proposal.approval.decision === 'pending'" data-testid="proposal-readonly-note">
          {{ m.readonlyNote }}
        </span>
      </slot>
    </footer>
  </article>
</template>
