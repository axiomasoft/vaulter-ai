import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import {
  ChatPanel,
  createOfficeWorkspaceClient,
  type ChatSessionDetails,
  type ProposalDetails,
} from '../src'

function encodeSse(frames: string[]): ReadableStream<Uint8Array> {
  const encoder = new TextEncoder()
  return new ReadableStream({
    start(controller) {
      for (const frame of frames) {
        controller.enqueue(encoder.encode(frame))
      }
      controller.close()
    },
  })
}

function makePendingProposal(proposalId: string, runId: string): ProposalDetails {
  return {
    proposal_id: proposalId,
    digest: 'd'.repeat(64),
    initiator: 'user/10',
    tenant_id: '61',
    project_scope: 'default',
    resource: {
      resource_id: 'doc-1',
      resource_type: 'text',
      title: 'Договор поставки',
    },
    command: 'text.replace_exact@1',
    base: { generation: 1, position: 1 },
    targets: ['p42'],
    summary: 'Заменить 15 на 30',
    diff: null,
    approval: {
      mode: 'human',
      decision: 'pending',
      human_status: 'pending',
      reviewer: null,
      decided_at: null,
      expires_at: '2026-11-24T00:00:00Z',
      expired: false,
      can_approve: true,
      can_reject: true,
    },
    execution: {
      owner_status: 'not_started',
      command_id: null,
      owner_key: 'documents.single_text@1',
      dispatch_state: null,
      receipt_id: null,
      marker: null,
      rejection_reason: null,
    },
    verification: {
      status: 'unverified',
      mode: null,
      outcome: null,
      marker: null,
      checks: null,
    },
    run: {
      run_id: runId,
      state: 'awaiting_approval',
      step_id: 2,
    },
  }
}

describe('@vaulter/ai-vue ChatPanel & useOfficeChat', () => {
  it('binds to run_id, sends bounded selection, reconnects via Last-Event-ID, and renders distinct lifecycle statuses', async () => {
    const sessionId = '019a0000-0000-7000-8000-00000000c001'
    const runId = '019a0000-0000-7000-8000-00000000r001'
    const proposalId = '019a0000-0000-7000-8000-00000000p001'
    const eventHeaders: Record<string, string>[] = []
    let sseConnectionAttempt = 0

    const initialSession: ChatSessionDetails = {
      session_id: sessionId,
      state: 'active',
      profile_revision: 'rev-1',
      last_seq: 0,
      provider: {
        configured: true,
        connection: 'office_model',
        driver: 'openai',
        model: 'gpt-4o-mini',
        hint: 'office_model',
      },
      runs: [],
      tool_calls: [],
      messages: [],
    }

    const proposal = makePendingProposal(proposalId, runId)

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.endsWith('/chat/sessions') && init?.method === 'POST') {
        return new Response(JSON.stringify({ data: initialSession }), { status: 201 })
      }
      if (url.includes(`/chat/sessions/${sessionId}/messages`) && init?.method === 'POST') {
        return new Response(JSON.stringify({ data: { message_id: 'msg-1', run_id: runId } }), { status: 202 })
      }
      if (url.includes(`/proposals/${proposalId}`) && !url.includes('/decision')) {
        return new Response(JSON.stringify({ data: proposal }), { status: 200 })
      }
      if (url.includes(`/chat/sessions/${sessionId}/events`)) {
        sseConnectionAttempt += 1
        eventHeaders.push((init?.headers ?? {}) as Record<string, string>)

        if (sseConnectionAttempt === 1) {
          return new Response(encodeSse([
            `: connected\n\n`,
            `id: 1\nevent: assistant.delta\ndata: ${JSON.stringify({ run_id: runId, text: 'Черновик: ' })}\n\n`,
            `id: 2\nevent: assistant.interrupted\ndata: ${JSON.stringify({ run_id: runId })}\n\n`,
          ]), {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
          })
        }

        if (sseConnectionAttempt === 2) {
          return new Response(encodeSse([
            `id: 3\nevent: assistant.delta\ndata: ${JSON.stringify({ run_id: runId, text: 'подготовлено предложение.' })}\n\n`,
            `id: 4\nevent: approval.required\ndata: ${JSON.stringify({ run_id: runId, call_id: 'c-1', proposal_id: proposalId })}\n\n`,
          ]), {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
          })
        }

        if (sseConnectionAttempt === 3) {
          return new Response(encodeSse([
            `id: 5\nevent: tool.committed\ndata: ${JSON.stringify({ run_id: runId, call_id: 'c-1', proposal_id: proposalId, command_id: 'cmd-9', receipt_id: 'rcpt-9' })}\n\n`,
          ]), {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
          })
        }

        return new Response(encodeSse([
          `id: 6\nevent: tool.verified\ndata: ${JSON.stringify({ run_id: runId, call_id: 'c-1', proposal_id: proposalId, command_id: 'cmd-9', receipt_id: 'rcpt-9', verification: { ok: true, result: { outcome: 'passed' } } })}\n\n`,
          `id: 7\nevent: run.finished\ndata: ${JSON.stringify({ run_id: runId, state: 'completed', error: null })}\n\n`,
        ]), {
          status: 200,
          headers: { 'Content-Type': 'text/event-stream' },
        })
      }

      return new Response(JSON.stringify({ data: initialSession }), { status: 200 })
    })

    const client = createOfficeWorkspaceClient({
      baseUrl: 'https://host.example',
      fetchImpl: fetchMock as unknown as typeof fetch,
    })

    const hugeExcerpt = 'x'.repeat(2500)
    const wrapper = mount(ChatPanel, {
      props: {
        client,
        resourceIds: ['doc-1'],
        selection: {
          kind: 'text_block',
          resource_id: 'doc-1',
          block_id: 'p42',
          excerpt: hugeExcerpt,
          start_utf16: 0,
          end_utf16: 5000,
        },
        baseReconnectDelayMs: 5,
        maxReconnectAttempts: 5,
      },
    })

    await flushPromises()
    expect(wrapper.get('[data-testid="chat-session-id"]').text()).toContain(sessionId)
    expect(wrapper.get('[data-testid="chat-provider-status"]').text()).toContain('openai')
    expect(wrapper.get('[data-testid="chat-provider-status"]').text()).not.toContain('sk-')

    // Submit message with bounded selection
    await wrapper.get('[data-testid="chat-input"]').setValue('Обнови срок на 30 дней')
    await wrapper.get('[data-testid="chat-composer"]').trigger('submit')

    // Wait for all 4 SSE windows with reconnects to drain cleanly
    for (let i = 0; i < 20 && sseConnectionAttempt < 4; i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 15))
      await flushPromises()
    }
    await new Promise((resolve) => setTimeout(resolve, 25))
    await flushPromises()

    // Check Last-Event-ID cursors forwarded on reconnects
    expect(eventHeaders[0]?.['Last-Event-ID']).toBe('0')
    expect(eventHeaders[1]?.['Last-Event-ID']).toBe('2')
    expect(eventHeaders[2]?.['Last-Event-ID']).toBe('4')
    expect(eventHeaders[3]?.['Last-Event-ID']).toBe('5')

    // Check bounded selection sent in POST /messages was truncated <= 2048 bytes
    const messageCall = fetchMock.mock.calls.find(([u]) => String(u).includes('/messages'))
    expect(messageCall).toBeDefined()
    const postedBody = JSON.parse(String(messageCall?.[1]?.body ?? '{}'))
    expect(postedBody.selection.block_id).toBe('p42')
    expect(postedBody.selection.excerpt.length).toBeLessThanOrEqual(2048)
    expect(postedBody.selection.end_utf16).toBeLessThanOrEqual(2048)

    // Bound run_id, interrupted badge, owner committed, and verified badges must all be cleanly tracked
    expect(wrapper.get('[data-testid="chat-bound-run-id"]').text()).toContain(runId)
    expect(wrapper.find('[data-testid="chat-status-interrupted"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chat-status-owner-committed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chat-status-verified"]').exists()).toBe(true)

    // Interrupted fragment stayed separated from resumed fragment
    const fragments = wrapper.findAll('.vai-chat-fragment')
    expect(fragments.length).toBeGreaterThanOrEqual(2)
    expect(fragments[0].attributes('data-interrupted')).toBe('true')
    expect(fragments[1].attributes('data-interrupted')).toBe('false')
    expect(wrapper.text()).toContain('Черновик:')
    expect(wrapper.text()).toContain('подготовлено предложение.')
  })

  it('collapses fragments and redacts selections/receipts immediately on hidden or reset after ACL revocation', async () => {
    const sessionId = '019a0000-0000-7000-8000-00000000c002'
    const runId = '019a0000-0000-7000-8000-00000000r002'
    let stage: 'initial' | 'revoked' = 'initial'
    let sseAttempt = 0

    const visibleSession: ChatSessionDetails = {
      session_id: sessionId,
      state: 'active',
      profile_revision: 'rev-1',
      last_seq: 0,
      provider: { configured: true, connection: 'office_model', driver: 'openai', model: 'gpt-4o-mini', hint: null },
      runs: [{ run_id: runId, state: 'completed', channel: 'chat', created_at: '2026-11-23T00:00:00Z', updated_at: '2026-11-23T00:00:00Z' }],
      tool_calls: [],
      messages: [
        {
          message_id: 'm1',
          role: 'user',
          run_id: runId,
          content: 'СЕКРЕТНЫЙ ТЕКСТ ПОЛЬЗОВАТЕЛЯ',
          selection: { kind: 'text_block', resource_id: 'doc-secret', block_id: 'p1', excerpt: 'секретная выдержка' },
        },
      ],
    }

    const revokedSession: ChatSessionDetails = {
      session_id: sessionId,
      state: 'active',
      profile_revision: 'rev-1',
      last_seq: 2,
      provider: { configured: true, connection: 'office_model', driver: 'openai', model: 'gpt-4o-mini', hint: null },
      runs: [{ run_id: runId, state: 'completed', channel: 'chat', created_at: '2026-11-23T00:00:00Z', updated_at: '2026-11-23T00:00:00Z' }],
      tool_calls: [{ call_id: 'c-secret', run_id: runId, hidden: true }],
      messages: [{ message_id: 'm1', hidden: true }],
    }

    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith(`/chat/sessions/${sessionId}`)) {
        return new Response(JSON.stringify({ data: stage === 'initial' ? visibleSession : revokedSession }), { status: 200 })
      }
      if (url.includes(`/chat/sessions/${sessionId}/events`)) {
        sseAttempt += 1
        if (sseAttempt === 1) {
          return new Response(encodeSse([
            `id: 1\nevent: assistant.delta\ndata: ${JSON.stringify({ run_id: runId, text: 'СЕКРЕТНЫЙ ОТВЕТ' })}\n\n`,
          ]), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
        }
        if (sseAttempt === 2) {
          stage = 'revoked'
          return new Response(encodeSse([
            `id: 2\nevent: hidden\ndata: {}\n\nevent: reset\ndata: {}\n\n`,
          ]), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
        }
        return new Response(encodeSse([`: idle\n\n`]), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
      }
      return new Response(JSON.stringify({ data: revokedSession }), { status: 200 })
    })

    const client = createOfficeWorkspaceClient({
      baseUrl: 'https://host.example',
      fetchImpl: fetchMock as unknown as typeof fetch,
    })

    const wrapper = mount(ChatPanel, {
      props: {
        client,
        sessionId,
        baseReconnectDelayMs: 5,
        maxReconnectAttempts: 2,
      },
    })

    for (let i = 0; i < 15 && sseAttempt < 2; i += 1) {
      await new Promise((resolve) => setTimeout(resolve, 15))
      await flushPromises()
    }
    await new Promise((resolve) => setTimeout(resolve, 25))
    await flushPromises()

    expect(wrapper.find('[data-testid="chat-redacted-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chat-hidden-fragment"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="chat-hidden-tool-call"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('СЕКРЕТНЫЙ ТЕКСТ ПОЛЬЗОВАТЕЛЯ')
    expect(wrapper.text()).not.toContain('СЕКРЕТНЫЙ ОТВЕТ')
    expect(wrapper.text()).not.toContain('секретная выдержка')
  })
})
