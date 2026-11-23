<script setup lang="ts">
import { computed } from 'vue'
import type { ProposalDetails } from '../api/types'
import ProposalDiffView from './ProposalDiffView.vue'

const props = withDefaults(defineProps<{
  proposal: ProposalDetails
  submitting?: boolean
}>(), {
  submitting: false,
})

const emit = defineEmits<{
  decide: [payload: { proposalId: string; digest: string; approved: boolean }]
}>()

const humanStatusLabel = computed(() => {
  switch (props.proposal.approval.human_status) {
    case 'approved':
      return 'Принято человеком'
    case 'rejected':
      return 'Отклонено человеком'
    case 'automatic':
      return 'Автоматически'
    default:
      return 'Ожидает решения человека'
  }
})

const ownerStatusLabel = computed(() => {
  switch (props.proposal.execution.owner_status) {
    case 'committed':
      return 'Сохранено владельцем'
    case 'pending_dispatch':
      return 'Ожидает отправки владельцу'
    case 'running':
      return 'Сохраняется владельцем'
    case 'rejected':
      return 'Отклонено владельцем'
    default:
      return 'Не отправлено владельцу'
  }
})

const verificationStatusLabel = computed(() => {
  switch (props.proposal.verification.status) {
    case 'passed':
      return 'Проверено'
    case 'failed':
      return 'Проверка не пройдена'
    default:
      return 'Не проверено'
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
    class="vai-proposal-card"
    :data-proposal-id="proposal.proposal_id"
    :data-digest="proposal.digest"
    :data-decision="proposal.approval.decision"
  >
    <header class="vai-proposal-card__header">
      <div>
        <h3 class="vai-proposal-card__title" data-testid="proposal-resource-title">
          {{ proposal.resource.title || proposal.resource.resource_id }}
        </h3>
        <div class="vai-proposal-card__meta">
          <span data-testid="proposal-resource-id">Ресурс: {{ proposal.resource.resource_id }}</span>
          <span data-testid="proposal-initiator">Инициатор: {{ proposal.initiator }}</span>
          <span v-if="proposal.approval.reviewer" data-testid="proposal-reviewer">
            Рецензент: {{ proposal.approval.reviewer }}
          </span>
        </div>
      </div>

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
    </header>

    <div class="vai-proposal-card__identity">
      <span data-testid="proposal-id">Предложение: <code>{{ proposal.proposal_id }}</code></span>
      <span data-testid="proposal-digest">Digest: <code>{{ proposal.digest }}</code></span>
      <span v-if="proposal.execution.command_id" data-testid="proposal-command-id">
        command_id: <code>{{ proposal.execution.command_id }}</code>
      </span>
      <span v-if="proposal.execution.receipt_id" data-testid="proposal-receipt-id">
        Квитанция: <code>{{ proposal.execution.receipt_id }}</code>
      </span>
    </div>

    <ProposalDiffView :diff="proposal.diff" :summary="proposal.summary" />

    <div v-if="proposal.execution.rejection_reason" class="vai-proposal-card__error" data-testid="proposal-owner-error">
      Причина отказа владельца: <code>{{ proposal.execution.rejection_reason }}</code>
    </div>

    <footer class="vai-proposal-card__actions">
      <template v-if="canDecide">
        <button
          type="button"
          class="vai-btn vai-btn--approve"
          data-action="approve"
          :disabled="submitting"
          @click="submitDecision(true)"
        >
          Принять предложение
        </button>
        <button
          type="button"
          class="vai-btn vai-btn--reject"
          data-action="reject"
          :disabled="submitting"
          @click="submitDecision(false)"
        >
          Отклонить
        </button>
      </template>
      <span v-else-if="proposal.approval.decision === 'pending' && proposal.approval.expired" data-testid="proposal-expired-note">
        Срок предложения истёк.
      </span>
      <span v-else-if="proposal.approval.decision === 'pending'" data-testid="proposal-readonly-note">
        Нет права согласования для текущего пользователя.
      </span>
    </footer>
  </article>
</template>
