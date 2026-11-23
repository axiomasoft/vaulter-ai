<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useOfficeChat, type OfficeChatCompatibleClient } from '../composables/useOfficeChat'
import type { ChatEditorSelection, ChatSessionDetails } from '../api/types'
import ProposalCard from './ProposalCard.vue'

const props = withDefaults(defineProps<{
  client: OfficeChatCompatibleClient
  sessionId?: string | null
  initialSession?: ChatSessionDetails | null
  resourceIds?: string[]
  selection?: ChatEditorSelection | null
  autoConnect?: boolean
  baseReconnectDelayMs?: number
  maxReconnectAttempts?: number
}>(), {
  sessionId: null,
  initialSession: null,
  resourceIds: () => [],
  selection: null,
  autoConnect: true,
  baseReconnectDelayMs: 150,
  maxReconnectAttempts: undefined,
})

const emit = defineEmits<{
  sent: [payload: { runId: string; sessionId: string }]
  decided: [payload: { proposalId: string; approved: boolean; won: boolean }]
  error: [message: string]
}>()

const draftText = ref('')

const {
  sessionId: boundSessionId,
  activeRunId,
  activeRun,
  messages,
  liveFragments,
  toolCalls,
  activeProposals,
  provider,
  selection: activeSelection,
  lastSeq,
  resetCount,
  hasHiddenContent,
  currentInterrupted,
  currentWaitingApproval,
  currentOwnerCommitted,
  currentVerified,
  currentVerificationFailed,
  loading,
  sending,
  streamConnected,
  submittingProposalIds,
  error,
  ensureSession,
  refreshSession,
  connectStream,
  sendMessage,
  decideProposal,
  setSelection,
  setResourceIds,
} = useOfficeChat(props.client, {
  sessionId: props.sessionId,
  initialSession: props.initialSession,
  resourceIds: props.resourceIds,
  initialSelection: props.selection,
  baseReconnectDelayMs: props.baseReconnectDelayMs,
  maxReconnectAttempts: props.maxReconnectAttempts,
})

watch(() => props.selection, (next) => {
  setSelection(next ?? null)
})

watch(() => props.resourceIds, (next) => {
  setResourceIds(next)
}, { deep: true })

watch(() => props.sessionId, async (nextId, prevId) => {
  if (nextId && nextId !== prevId) {
    try {
      await refreshSession(nextId)
      if (props.autoConnect) {
        connectStream(nextId)
      }
    } catch (err) {
      emit('error', err instanceof Error ? err.message : 'Failed to load chat session.')
    }
  }
})

const visibleSelection = computed<ChatEditorSelection | null>(() => {
  if (hasHiddenContent.value && activeSelection.value) {
    const anyMessageHidden = messages.value.some((m) => 'hidden' in m && m.hidden === true)
    if (anyMessageHidden) {
      return null
    }
  }
  return activeSelection.value
})

onMounted(async () => {
  if (!props.autoConnect) {
    return
  }
  try {
    if (props.initialSession) {
      connectStream(props.initialSession.session_id, props.initialSession.last_seq)
    } else if (props.sessionId) {
      const loaded = await refreshSession(props.sessionId)
      connectStream(loaded.session_id, loaded.last_seq)
    } else {
      const id = await ensureSession()
      connectStream(id, lastSeq.value)
    }
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to initialize chat panel.')
  }
})

async function handleSubmit(): Promise<void> {
  const content = draftText.value
  if (content.trim() === '') {
    return
  }
  try {
    const runId = await sendMessage(content)
    draftText.value = ''
    if (boundSessionId.value) {
      emit('sent', { runId, sessionId: boundSessionId.value })
    }
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to send chat message.')
  }
}

async function handleDecide(payload: { proposalId: string; digest: string; approved: boolean }): Promise<void> {
  try {
    const result = await decideProposal(payload.proposalId, payload.digest, payload.approved)
    emit('decided', { proposalId: payload.proposalId, approved: payload.approved, won: result.won })
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to submit proposal decision.')
  }
}
</script>

<template>
  <section
    class="vai-chat-panel"
    data-testid="office-chat-panel"
    :data-session-id="boundSessionId || undefined"
    :data-run-id="activeRunId || undefined"
  >
    <header class="vai-chat-panel__header">
      <div class="vai-chat-panel__identity">
        <span data-testid="chat-session-id">
          Сессия: <code>{{ boundSessionId || 'новая' }}</code>
        </span>
        <span v-if="activeRunId" data-testid="chat-bound-run-id">
          Задание (run_id): <code>{{ activeRunId }}</code>
        </span>
        <span v-if="activeRun" data-testid="chat-run-state">
          Состояние: <strong>{{ activeRun.state }}</strong>
        </span>
        <span data-testid="chat-cursor">
          Курсор: <code>{{ lastSeq }}</code>
        </span>
        <span
          class="vai-badge"
          data-testid="chat-stream-status"
          :data-connected="streamConnected ? 'true' : 'false'"
        >
          {{ streamConnected ? 'SSE подключён' : 'SSE не подключён' }}
        </span>
        <span v-if="provider" class="vai-chat-panel__provider" data-testid="chat-provider-status">
          Провайдер: {{ provider.driver || 'default' }}<template v-if="provider.model"> ({{ provider.model }})</template>
          · {{ provider.configured ? 'настроен' : 'оффлайн' }}
        </span>
      </div>

      <div class="vai-chat-panel__statuses" data-testid="chat-lifecycle-statuses">
        <span
          v-if="currentInterrupted"
          class="vai-badge vai-badge--warning"
          data-testid="chat-status-interrupted"
          data-status="assistant.interrupted"
        >
          assistant.interrupted
        </span>
        <span
          v-if="currentWaitingApproval"
          class="vai-badge vai-badge--pending"
          data-testid="chat-status-waiting-approval"
          data-status="waiting_approval"
        >
          waiting approval
        </span>
        <span
          v-if="currentOwnerCommitted"
          class="vai-badge vai-badge--owner"
          data-testid="chat-status-owner-committed"
          data-status="owner_committed"
        >
          owner committed
        </span>
        <span
          v-if="currentVerified"
          class="vai-badge vai-badge--verified"
          data-testid="chat-status-verified"
          data-status="verified"
        >
          verified
        </span>
        <span
          v-else-if="currentVerificationFailed"
          class="vai-badge vai-badge--error"
          data-testid="chat-status-verification-failed"
          data-status="verification_failed"
        >
          verification failed
        </span>
      </div>
    </header>

    <div v-if="error" class="vai-chat-panel__alert vai-chat-panel__alert--error" data-testid="chat-error">
      Ошибка (<code>{{ error.code }}</code>): {{ error.message }}
    </div>

    <div v-if="hasHiddenContent" class="vai-chat-panel__alert vai-chat-panel__alert--redacted" data-testid="chat-redacted-banner">
      Часть сообщений или результатов скрыта после изменения прав доступа.
    </div>

    <div v-if="visibleSelection" class="vai-chat-panel__selection" data-testid="chat-editor-selection">
      <strong>Выделение редактора:</strong>
      <template v-if="visibleSelection.kind === 'text_block'">
        <span>документ <code>{{ visibleSelection.resource_id }}</code> · блок <code>{{ visibleSelection.block_id }}</code></span>
        <span v-if="visibleSelection.excerpt" data-testid="chat-selection-excerpt"> — «{{ visibleSelection.excerpt }}»</span>
      </template>
      <template v-else>
        <span>таблица <code>{{ visibleSelection.resource_id }}</code> · лист <code>{{ visibleSelection.sheet_id }}</code></span>
      </template>
    </div>

    <div class="vai-chat-panel__messages" data-testid="chat-messages">
      <div
        v-for="msg in messages"
        :key="msg.message_id"
        class="vai-chat-message"
        :data-message-id="msg.message_id"
        :data-role="'hidden' in msg && msg.hidden ? 'hidden' : msg.role"
        :data-hidden="'hidden' in msg && msg.hidden ? 'true' : 'false'"
      >
        <template v-if="'hidden' in msg && msg.hidden">
          <div class="vai-chat-message--hidden" data-testid="chat-hidden-fragment">
            Сообщение скрыто (нет доступа).
          </div>
        </template>
        <template v-else>
          <div class="vai-chat-message__meta">
            <strong>{{ msg.role === 'user' ? 'Вы' : 'Ассистент' }}</strong>
            <span v-if="msg.run_id"> · run <code>{{ msg.run_id }}</code></span>
          </div>
          <div class="vai-chat-message__content">{{ msg.content }}</div>
          <div v-if="msg.selection" class="vai-chat-message__selection" data-testid="chat-message-selection">
            <template v-if="msg.selection.kind === 'text_block'">
              Блок <code>{{ msg.selection.block_id }}</code>
              <span v-if="msg.selection.excerpt"> («{{ msg.selection.excerpt }}»)</span>
            </template>
            <template v-else>
              Лист <code>{{ msg.selection.sheet_id }}</code> / таблица <code>{{ msg.selection.table_id }}</code>
            </template>
          </div>
        </template>
      </div>

      <div
        v-for="frag in liveFragments"
        :key="frag.id"
        class="vai-chat-fragment"
        :data-fragment-id="frag.id"
        :data-run-id="frag.run_id || undefined"
        :data-interrupted="frag.interrupted ? 'true' : 'false'"
        :data-hidden="frag.hidden ? 'true' : 'false'"
      >
        <template v-if="frag.hidden">
          <div class="vai-chat-fragment--hidden" data-testid="chat-hidden-fragment">
            Фрагмент ответа скрыт (доступ отозван).
          </div>
        </template>
        <template v-else>
          <div v-if="frag.interrupted" class="vai-chat-fragment__badge" data-testid="chat-interrupted-fragment-badge">
            assistant.interrupted
          </div>
          <div v-if="frag.text" class="vai-chat-fragment__text">{{ frag.text }}</div>
        </template>
      </div>
    </div>

    <div v-if="toolCalls.length > 0" class="vai-chat-panel__tools" data-testid="chat-tool-calls">
      <h3>Вызовы инструментов</h3>
      <ul>
        <li
          v-for="tc in toolCalls"
          :key="tc.call_id"
          :data-call-id="tc.call_id"
          :data-hidden="'hidden' in tc && tc.hidden ? 'true' : 'false'"
          :data-state="'hidden' in tc && tc.hidden ? 'hidden' : tc.state"
        >
          <template v-if="'hidden' in tc && tc.hidden">
            <span data-testid="chat-hidden-tool-call">Вызов <code>{{ tc.call_id }}</code> скрыт (нет доступа)</span>
          </template>
          <template v-else>
            <code>{{ tc.tool }}</code>@{{ tc.tool_version }} — {{ tc.state }}
            <span v-if="tc.command_id"> · command_id <code>{{ tc.command_id }}</code></span>
            <span v-if="tc.receipt_id"> · квитанция <code>{{ tc.receipt_id }}</code></span>
          </template>
        </li>
      </ul>
    </div>

    <div v-if="activeProposals.length > 0" class="vai-chat-panel__proposals" data-testid="chat-proposals">
      <ProposalCard
        v-for="proposal in activeProposals"
        :key="proposal.proposal_id"
        :proposal="proposal"
        :submitting="submittingProposalIds.includes(proposal.proposal_id)"
        @decide="handleDecide"
      />
    </div>

    <form class="vai-chat-panel__composer" data-testid="chat-composer" @submit.prevent="handleSubmit">
      <textarea
        v-model="draftText"
        data-testid="chat-input"
        rows="2"
        placeholder="Опишите правку или вопрос по документу…"
        :disabled="sending"
      />
      <div class="vai-chat-panel__actions">
        <button
          type="submit"
          class="vai-btn vai-btn--primary"
          data-action="send-chat"
          :disabled="sending || draftText.trim() === ''"
        >
          {{ sending ? 'Отправка…' : 'Отправить' }}
        </button>
        <button
          v-if="boundSessionId"
          type="button"
          class="vai-btn"
          data-action="refresh-chat"
          :disabled="loading"
          @click="refreshSession()"
        >
          Обновить ({{ resetCount }})
        </button>
      </div>
    </form>
  </section>
</template>
