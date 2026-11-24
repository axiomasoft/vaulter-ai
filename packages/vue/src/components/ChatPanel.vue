<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import '../style.css'
import { useOfficeChat, type OfficeChatCompatibleClient } from '../composables/useOfficeChat'
import type { ChatEditorSelection, ChatPanelMessages, ChatSessionDetails } from '../api/types'
import ProposalCard from './ProposalCard.vue'

const DEFAULT_MESSAGES: ChatPanelMessages = {
  title: 'AI Ассистент',
  sessionLabel: 'Сессия:',
  newSessionFallback: 'новая',
  newSessionButton: 'Новая сессия',
  sessionsLoading: 'Загрузка сессий…',
  sessionsEmpty: 'Нет сессий',
  sessionSwitchLabel: 'Переключить сессию',
  archiveSessionButton: 'Архивировать',
  resetHistoryNotice: 'История чата была сброшена, загружено текущее состояние.',
  hiddenNotice: 'Часть сообщений или результатов скрыта после изменения прав доступа.',
  inputPlaceholder: 'Опишите правку или вопрос по документу…',
  sendButton: 'Отправить',
  sendingButton: 'Отправка…',
  refreshButton: 'Обновить',
  cancelRunButton: 'Отменить',
  cancellingRunButton: 'Отмена…',
  retryRunButton: 'Повторить',
  retryingRunButton: 'Повтор…',
  selectionLabel: 'Выделение редактора:',
  toolCallsTitle: 'Вызовы инструментов',
  proposalsTitle: 'Предложения',
  errorPrefix: 'Ошибка',
  streamConnectedLabel: 'SSE подключён',
  streamDisconnectedLabel: 'SSE не подключён',
  providerLabel: 'Провайдер:',
  providerConfigured: 'настроен',
  providerOffline: 'оффлайн',
  runIdLabel: 'Задание (run_id):',
  runStateLabel: 'Состояние:',
  cursorLabel: 'Курсор:',
  userRoleLabel: 'Вы',
  assistantRoleLabel: 'Ассистент',
  runLabel: 'run',
  hiddenMessageNotice: 'Сообщение скрыто (нет доступа).',
  hiddenFragmentNotice: 'Фрагмент ответа скрыт (доступ отозван).',
  hiddenToolCallPrefix: 'Вызов',
  hiddenToolCallSuffix: 'скрыт (нет доступа)',
  receiptInlineLabel: 'квитанция',
  documentLabel: 'документ',
  blockLabel: 'блок',
  sheetLabel: 'таблица',
  tableLabel: 'таблица',
  tabLabel: 'лист',
  statusRunning: 'Выполняется',
  statusWaitingApproval: 'Ожидает подтверждения',
  statusOwnerCommitted: 'Сохранено владельцем',
  statusVerified: 'Проверено',
  statusVerificationFailed: 'Проверка не пройдена',
  statusInterrupted: 'Прервано',
  statusCancelled: 'Отменено',
  statusFailed: 'Ошибка',
}

const props = withDefaults(defineProps<{
  client: OfficeChatCompatibleClient
  sessionId?: string | null
  initialSession?: ChatSessionDetails | null
  resourceIds?: string[]
  selection?: ChatEditorSelection | null
  autoConnect?: boolean
  autoLoadSessions?: boolean
  baseReconnectDelayMs?: number
  maxReconnectAttempts?: number
  messages?: Partial<ChatPanelMessages>
}>(), {
  sessionId: null,
  initialSession: null,
  resourceIds: () => [],
  selection: null,
  autoConnect: true,
  autoLoadSessions: false,
  baseReconnectDelayMs: 150,
  maxReconnectAttempts: undefined,
  messages: () => ({}),
})

const m = computed<ChatPanelMessages>(() => ({
  ...DEFAULT_MESSAGES,
  ...props.messages,
}))

const emit = defineEmits<{
  sent: [payload: { runId: string; sessionId: string }]
  decided: [payload: { proposalId: string; approved: boolean; won: boolean }]
  error: [message: string]
}>()

const draftText = ref('')

const {
  sessionId: boundSessionId,
  sessions,
  loadingSessions,
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
  canCancelActiveRun,
  canRetryLatestRun,
  loading,
  sending,
  cancelling,
  retrying,
  streamConnected,
  submittingProposalIds,
  error,
  ensureSession,
  refreshSession,
  loadSessions,
  switchSession,
  openNewSession,
  archiveSession,
  cancelRun,
  retryRun,
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
    if (props.autoLoadSessions && props.client.listChatSessions) {
      void loadSessions().catch(() => undefined)
    }
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to initialize chat panel.')
  }
})

async function handleSessionChange(event: Event): Promise<void> {
  const target = event.target as HTMLSelectElement | null
  const nextId = target?.value
  if (!nextId || nextId === boundSessionId.value) {
    return
  }
  try {
    await switchSession(nextId)
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to switch session.')
  }
}

async function handleNewSession(): Promise<void> {
  try {
    await openNewSession()
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to create session.')
  }
}

async function handleCancelRun(): Promise<void> {
  try {
    await cancelRun()
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to cancel run.')
  }
}

async function handleRetryRun(): Promise<void> {
  try {
    await retryRun()
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Failed to retry run.')
  }
}

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
    class="vai-chat-panel vaulter-ai-chat"
    data-testid="office-chat-panel"
    :data-session-id="boundSessionId || undefined"
    :data-run-id="activeRunId || undefined"
  >
    <slot name="header" :session-id="boundSessionId" :active-run="activeRun" :messages="m">
      <header class="vai-chat-panel__header vaulter-ai-chat__header">
        <div class="vai-chat-panel__identity">
          <span data-testid="chat-session-id">
            {{ m.sessionLabel }} <code>{{ boundSessionId || m.newSessionFallback }}</code>
          </span>
          <span v-if="activeRunId" data-testid="chat-bound-run-id">
            {{ m.runIdLabel }} <code>{{ activeRunId }}</code>
          </span>
          <span v-if="activeRun" data-testid="chat-run-state">
            {{ m.runStateLabel }} <strong>{{ activeRun.state }}</strong>
          </span>
          <span data-testid="chat-cursor">
            {{ m.cursorLabel }} <code>{{ lastSeq }}</code>
          </span>
          <span
            class="vai-badge"
            data-testid="chat-stream-status"
            :data-connected="streamConnected ? 'true' : 'false'"
          >
            {{ streamConnected ? m.streamConnectedLabel : m.streamDisconnectedLabel }}
          </span>
          <span v-if="provider" class="vai-chat-panel__provider" data-testid="chat-provider-status">
            {{ m.providerLabel }} {{ provider.driver || 'default' }}<template v-if="provider.model"> ({{ provider.model }})</template>
            · {{ provider.configured ? m.providerConfigured : m.providerOffline }}
          </span>
        </div>

        <slot name="sessions" :sessions="sessions" :session-id="boundSessionId" :loading="loadingSessions" :switch-session="switchSession" :open-new-session="openNewSession" :archive-session="archiveSession" :messages="m">
          <div v-if="client.listChatSessions || sessions.length > 0" class="vaulter-ai-chat__sessions" data-testid="chat-session-switcher">
            <select
              class="vaulter-ai-chat__select"
              data-testid="chat-session-select"
              :aria-label="m.sessionSwitchLabel"
              :value="boundSessionId || ''"
              :disabled="loadingSessions"
              @change="handleSessionChange"
            >
              <option v-if="!boundSessionId" value="">{{ m.newSessionFallback }}</option>
              <option v-for="s in sessions" :key="s.session_id" :value="s.session_id">
                {{ s.session_id }} ({{ s.state }})
              </option>
            </select>
            <button
              type="button"
              class="vai-btn vaulter-ai-btn"
              data-action="new-session"
              :disabled="loading"
              @click="handleNewSession"
            >
              {{ m.newSessionButton }}
            </button>
          </div>
        </slot>

        <slot name="status" :active-run="activeRun" :messages="m">
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
            <span
              v-if="activeRun && (activeRun.state === 'cancelled' || activeRun.state === 'cancelling')"
              class="vai-badge vai-badge--warning"
              data-testid="chat-status-cancelled"
              :data-status="activeRun.state"
            >
              {{ m.statusCancelled }}
            </span>
          </div>
        </slot>
      </header>
    </slot>

    <div v-if="error" class="vai-chat-panel__alert vai-chat-panel__alert--error vaulter-ai-banner vaulter-ai-banner--error" data-testid="chat-error">
      {{ m.errorPrefix }} (<code>{{ error.code }}</code>): {{ error.message }}
    </div>

    <div v-if="hasHiddenContent" class="vai-chat-panel__alert vai-chat-panel__alert--redacted vaulter-ai-banner vaulter-ai-banner--warning" data-testid="chat-redacted-banner">
      {{ m.hiddenNotice }}
    </div>

    <slot name="selection" :selection="visibleSelection" :messages="m">
      <div v-if="visibleSelection" class="vai-chat-panel__selection" data-testid="chat-editor-selection">
        <strong>{{ m.selectionLabel }}</strong>
        <template v-if="visibleSelection.kind === 'text_block'">
          <span>{{ m.documentLabel }} <code>{{ visibleSelection.resource_id }}</code> · {{ m.blockLabel }} <code>{{ visibleSelection.block_id }}</code></span>
          <span v-if="visibleSelection.excerpt" data-testid="chat-selection-excerpt"> — «{{ visibleSelection.excerpt }}»</span>
        </template>
        <template v-else>
          <span>{{ m.sheetLabel }} <code>{{ visibleSelection.resource_id }}</code> · {{ m.tabLabel }} <code>{{ visibleSelection.sheet_id }}</code></span>
        </template>
      </div>
    </slot>

    <slot name="messages" :messages-list="messages" :live-fragments="liveFragments" :messages="m">
      <div class="vai-chat-panel__messages vaulter-ai-chat__messages" data-testid="chat-messages">
        <div
          v-for="msg in messages"
          :key="msg.message_id"
          class="vai-chat-message vaulter-ai-chat__message"
          :class="{ 'vaulter-ai-chat__message--user': !('hidden' in msg && msg.hidden) && msg.role === 'user' }"
          :data-message-id="msg.message_id"
          :data-role="'hidden' in msg && msg.hidden ? 'hidden' : msg.role"
          :data-hidden="'hidden' in msg && msg.hidden ? 'true' : 'false'"
        >
          <slot name="message" :message="msg" :messages="m">
            <template v-if="'hidden' in msg && msg.hidden">
              <div class="vai-chat-message--hidden" data-testid="chat-hidden-fragment">
                {{ m.hiddenMessageNotice }}
              </div>
            </template>
            <template v-else>
              <div class="vai-chat-message__meta">
                <strong>{{ msg.role === 'user' ? m.userRoleLabel : m.assistantRoleLabel }}</strong>
                <span v-if="msg.run_id"> · {{ m.runLabel }} <code>{{ msg.run_id }}</code></span>
              </div>
              <div class="vai-chat-message__content">{{ msg.content }}</div>
              <div v-if="msg.selection" class="vai-chat-message__selection" data-testid="chat-message-selection">
                <template v-if="msg.selection.kind === 'text_block'">
                  {{ m.blockLabel }} <code>{{ msg.selection.block_id }}</code>
                  <span v-if="msg.selection.excerpt"> («{{ msg.selection.excerpt }}»)</span>
                </template>
                <template v-else>
                  {{ m.tabLabel }} <code>{{ msg.selection.sheet_id }}</code> / {{ m.tableLabel }} <code>{{ msg.selection.table_id }}</code>
                </template>
              </div>
            </template>
          </slot>
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
              {{ m.hiddenFragmentNotice }}
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
    </slot>

    <slot name="tool-calls" :tool-calls="toolCalls" :messages="m">
      <div v-if="toolCalls.length > 0" class="vai-chat-panel__tools" data-testid="chat-tool-calls">
        <h3>{{ m.toolCallsTitle }}</h3>
        <ul>
          <li
            v-for="tc in toolCalls"
            :key="tc.call_id"
            :data-call-id="tc.call_id"
            :data-hidden="'hidden' in tc && tc.hidden ? 'true' : 'false'"
            :data-state="'hidden' in tc && tc.hidden ? 'hidden' : tc.state"
          >
            <template v-if="'hidden' in tc && tc.hidden">
              <span data-testid="chat-hidden-tool-call">{{ m.hiddenToolCallPrefix }} <code>{{ tc.call_id }}</code> {{ m.hiddenToolCallSuffix }}</span>
            </template>
            <template v-else>
              <code>{{ tc.tool }}</code>@{{ tc.tool_version }} — {{ tc.state }}
              <span v-if="tc.command_id"> · command_id <code>{{ tc.command_id }}</code></span>
              <span v-if="tc.receipt_id"> · {{ m.receiptInlineLabel }} <code>{{ tc.receipt_id }}</code></span>
            </template>
          </li>
        </ul>
      </div>
    </slot>

    <slot name="proposals" :proposals="activeProposals" :decide="handleDecide" :messages="m">
      <div v-if="activeProposals.length > 0" class="vai-chat-panel__proposals" data-testid="chat-proposals">
        <ProposalCard
          v-for="proposal in activeProposals"
          :key="proposal.proposal_id"
          :proposal="proposal"
          :submitting="submittingProposalIds.includes(proposal.proposal_id)"
          :messages="m.proposalCard"
          @decide="handleDecide"
        />
      </div>
    </slot>

    <slot name="composer" :draft-text="draftText" :sending="sending" :submit="handleSubmit" :messages="m">
      <form class="vai-chat-panel__composer vaulter-ai-chat__composer" data-testid="chat-composer" @submit.prevent="handleSubmit">
        <textarea
          v-model="draftText"
          class="vaulter-ai-chat__textarea"
          data-testid="chat-input"
          rows="2"
          :placeholder="m.inputPlaceholder"
          :disabled="sending"
        />
        <div class="vai-chat-panel__actions vaulter-ai-chat__actions">
          <button
            type="submit"
            class="vai-btn vai-btn--primary vaulter-ai-btn vaulter-ai-btn--primary"
            data-action="send-chat"
            :disabled="sending || draftText.trim() === ''"
          >
            {{ sending ? m.sendingButton : m.sendButton }}
          </button>
          <slot name="run-actions" :active-run="activeRun" :can-cancel="canCancelActiveRun" :can-retry="canRetryLatestRun" :cancelling="cancelling" :retrying="retrying" :cancel-run="handleCancelRun" :retry-run="handleRetryRun" :messages="m">
            <button
              v-if="canCancelActiveRun"
              type="button"
              class="vai-btn vaulter-ai-btn vaulter-ai-btn--danger"
              data-testid="chat-cancel-run"
              data-action="cancel-run"
              :disabled="cancelling"
              @click="handleCancelRun"
            >
              {{ cancelling ? m.cancellingRunButton : m.cancelRunButton }}
            </button>
            <button
              v-if="canRetryLatestRun"
              type="button"
              class="vai-btn vaulter-ai-btn"
              data-testid="chat-retry-run"
              data-action="retry-run"
              :disabled="retrying"
              @click="handleRetryRun"
            >
              {{ retrying ? m.retryingRunButton : m.retryRunButton }}
            </button>
          </slot>
          <button
            v-if="boundSessionId"
            type="button"
            class="vai-btn vaulter-ai-btn"
            data-action="refresh-chat"
            :disabled="loading"
            @click="refreshSession()"
          >
            {{ m.refreshButton }} ({{ resetCount }})
          </button>
        </div>
      </form>
    </slot>
  </section>
</template>
