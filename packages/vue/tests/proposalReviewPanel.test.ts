import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import {
  createOfficeWorkspaceClient,
  ProposalCard,
  ProposalReviewPanel,
  type OfficeWorkspaceClient,
  type ProposalDetails,
  type RunDetails,
} from '../src'

function makeProposal(overrides: Partial<ProposalDetails> = {}): ProposalDetails {
  return {
    proposal_id: overrides.proposal_id ?? '019a0000-0000-7000-8000-000000000001',
    digest: overrides.digest ?? 'a'.repeat(64),
    initiator: overrides.initiator ?? 'user/101',
    tenant_id: '61',
    project_scope: 'default',
    resource: overrides.resource ?? {
      resource_id: 'res-doc-1',
      resource_type: 'text',
      title: 'Договор поставки',
    },
    command: 'text.replace_exact@1',
    base: { generation: 1, position: 1 },
    targets: ['p42'],
    summary: 'Replace 15 with 30 in block p42.',
    diff: overrides.diff !== undefined ? overrides.diff : {
      kind: 'text_replace',
      block_id: 'p42',
      section_path: ['Расчёты', '4.2 Срок оплаты'],
      expected: '15',
      replacement: '30',
      start_utf16: 12,
      before_segments: [
        { text: 'Pay within ', marks: [] },
        { text: '15', marks: [{ type: 'bold' }] },
        { text: ' days.', marks: [] },
      ],
      after_segments: [
        { text: 'Pay within ', marks: [] },
        { text: '30', marks: [{ type: 'bold' }] },
        { text: ' days.', marks: [] },
      ],
    },
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
      ...overrides.approval,
    },
    execution: {
      owner_status: 'not_started',
      command_id: null,
      owner_key: 'text.single',
      dispatch_state: null,
      receipt_id: null,
      marker: null,
      rejection_reason: null,
      ...overrides.execution,
    },
    verification: {
      status: 'unverified',
      mode: null,
      outcome: null,
      marker: null,
      checks: null,
      ...overrides.verification,
    },
    run: overrides.run ?? null,
  }
}

describe('@vaulter/ai-vue ProposalCard and ProposalReviewPanel', () => {
  it('renders initiator, section diff with marks, digest, and separate human vs owner vs verified statuses', async () => {
    const pPending = makeProposal()
    const wrapper = mount(ProposalCard, {
      props: { proposal: pPending },
    })

    expect(wrapper.get('[data-testid="proposal-initiator"]').text()).toContain('user/101')
    expect(wrapper.get('[data-testid="proposal-resource-id"]').text()).toContain('res-doc-1')
    expect(wrapper.get('[data-testid="diff-section"]').text()).toBe('Расчёты / 4.2 Срок оплаты')
    expect(wrapper.get('[data-testid="diff-before"]').text()).toContain('Pay within 15 days.')
    expect(wrapper.get('[data-testid="diff-after"]').text()).toContain('Pay within 30 days.')
    expect(wrapper.get('[data-testid="proposal-digest"]').text()).toContain('a'.repeat(64))
    expect(wrapper.get('[data-testid="status-human"]').attributes('data-human-status')).toBe('pending')
    expect(wrapper.get('[data-testid="status-owner"]').attributes('data-owner-status')).toBe('not_started')
    expect(wrapper.get('[data-testid="status-verification"]').attributes('data-verification-status')).toBe('unverified')

    await wrapper.get('[data-action="approve"]').trigger('click')
    expect(wrapper.emitted('decide')).toEqual([[
      {
        proposalId: pPending.proposal_id,
        digest: pPending.digest,
        approved: true,
      },
    ]])
  })

  it('distinguishes human-approved from pending-dispatch owner status, handles race loss on P while Q stays pending, and resumes partial multi-resource run', async () => {
    const pId = '019a0000-0000-7000-8000-00000000000p'
    const qId = '019a0000-0000-7000-8000-00000000000q'
    const pDigest = 'b'.repeat(64)
    const qDigest = 'c'.repeat(64)

    const pPending = makeProposal({
      proposal_id: pId,
      digest: pDigest,
      run: { run_id: '019a0000-0000-7000-8000-0000000000r1', state: 'awaiting_approval', step_id: 2 },
    })
    const qPending = makeProposal({
      proposal_id: qId,
      digest: qDigest,
      targets: ['p71'],
      resource: { resource_id: 'res-doc-2', resource_type: 'text', title: 'Приложение 2' },
    })

    const pApprovedNotCommitted = makeProposal({
      proposal_id: pId,
      digest: pDigest,
      approval: {
        mode: 'human',
        decision: 'approved',
        human_status: 'approved',
        reviewer: 'user/202',
        decided_at: '2026-11-23T12:00:00Z',
        expires_at: '2026-11-24T00:00:00Z',
        expired: false,
        can_approve: false,
        can_reject: false,
      },
      execution: {
        owner_status: 'pending_dispatch',
        command_id: 'cmd-123',
        owner_key: 'text.single',
        dispatch_state: 'ready',
        receipt_id: null,
        marker: null,
        rejection_reason: null,
      },
      run: { run_id: '019a0000-0000-7000-8000-0000000000r1', state: 'reconcile', step_id: 2 },
    })

    const partialRun: RunDetails = {
      run_id: '019a0000-0000-7000-8000-0000000000r1',
      tenant_id: '61',
      project_scope: 'default',
      initiator: 'user/101',
      channel: 'draft',
      profile_revision: 'rev-1',
      state: 'partially_applied',
      created_at: '2026-11-23T12:00:00Z',
      updated_at: '2026-11-23T12:01:00Z',
      can_resume: true,
      summary: {
        total_resources: 2,
        confirmed_count: 1,
        remaining_count: 1,
        awaiting_approval_count: 0,
        conflict_count: 1,
      },
      confirmed_steps: [
        {
          resource_id: 'res-doc-1',
          visible: true,
          title: 'Договор поставки',
          status: 'completed',
          requires_reread: false,
          can_continue: false,
          execution: {
            owner_status: 'committed',
            command_id: 'cmd-123',
            owner_key: 'text.single',
            dispatch_state: 'delivered',
            receipt_id: 'rcpt-doc-1',
            marker: { generation: 1, position: 2 },
            rejection_reason: null,
          },
          verification: {
            status: 'passed',
            mode: 'at_commit',
            outcome: 'passed',
            marker: { generation: 1, position: 2 },
            checks: null,
          },
        },
      ],
      remaining_steps: [
        {
          resource_id: 'res-doc-2',
          visible: true,
          title: 'Приложение 2',
          status: 'conflict',
          error: 'precondition_failed',
          requires_reread: true,
          can_continue: true,
          proposal: qPending,
        },
      ],
      resources: [],
      steps: [],
    }
    partialRun.resources = [...partialRun.confirmed_steps, ...partialRun.remaining_steps]

    const completedRun: RunDetails = {
      ...partialRun,
      state: 'completed',
      can_resume: false,
      summary: {
        total_resources: 2,
        confirmed_count: 2,
        remaining_count: 0,
        awaiting_approval_count: 0,
        conflict_count: 0,
      },
      confirmed_steps: [
        ...partialRun.confirmed_steps,
        {
          resource_id: 'res-doc-2',
          visible: true,
          title: 'Приложение 2',
          status: 'completed',
          requires_reread: false,
          can_continue: false,
          execution: {
            owner_status: 'committed',
            command_id: 'cmd-456',
            owner_key: 'text.single',
            dispatch_state: 'delivered',
            receipt_id: 'rcpt-doc-2',
            marker: { generation: 1, position: 2 },
            rejection_reason: null,
          },
          verification: {
            status: 'passed',
            mode: 'at_commit',
            outcome: 'passed',
            marker: { generation: 1, position: 2 },
            checks: null,
          },
        },
      ],
      remaining_steps: [],
    }
    completedRun.resources = completedRun.confirmed_steps

    const client: OfficeWorkspaceClient = {
      listProposals: vi.fn(async () => [pPending, qPending]),
      getProposal: vi.fn(async (id: string) => (id === pId ? pPending : qPending)),
      decideProposal: vi.fn(async () => ({ won: false, proposal: pApprovedNotCommitted })),
      getRun: vi.fn(async () => partialRun),
      resumeRun: vi.fn(async () => completedRun),
    }

    const wrapper = mount(ProposalReviewPanel, {
      props: {
        client,
        runId: partialRun.run_id,
        proposalIds: [pId, qId],
      },
    })

    await flushPromises()

    expect(wrapper.get('[data-testid="confirmed-steps"]').text()).toContain('rcpt-doc-1')
    expect(wrapper.get('[data-testid="remaining-steps"]').text()).toContain('precondition_failed')
    expect(wrapper.find('[data-testid="requires-reread"]').exists()).toBe(true)

    // Reject on P loses race against reviewer user/202's approval; P shows human_status=approved vs owner_status=pending_dispatch, and Q stays pending.
    const cardP = wrapper.get(`[data-proposal-id="${pId}"]`)
    const cardQ = wrapper.get(`[data-proposal-id="${qId}"]`)
    await cardP.get('[data-action="reject"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="decision-lost-race"]').exists()).toBe(true)
    expect(cardP.get('[data-testid="status-human"]').attributes('data-human-status')).toBe('approved')
    expect(cardP.get('[data-testid="status-owner"]').attributes('data-owner-status')).toBe('pending_dispatch')
    expect(cardQ.get('[data-testid="status-human"]').attributes('data-human-status')).toBe('pending')

    // Continuing remaining steps updates the run to completed with both receipts.
    await wrapper.get('[data-action="resume-run"]').trigger('click')
    await flushPromises()

    expect(client.resumeRun).toHaveBeenCalledWith(partialRun.run_id, {})
    expect(wrapper.get('[data-testid="run-state"]').text()).toContain('completed')
    expect(wrapper.get('[data-testid="confirmed-steps"]').text()).toContain('rcpt-doc-2')
    expect(wrapper.find('[data-testid="remaining-steps"]').exists()).toBe(false)
  })

  it('builds workspace client requests with normalized URLs and surfaces structured wire errors', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({
      error: { code: 'resource_unavailable', retryable: false, next_action: 'fail' },
    }), { status: 404, headers: { 'Content-Type': 'application/json' } }))

    const client = createOfficeWorkspaceClient({
      baseUrl: 'https://host.example/',
      workspacePrefix: '/office/',
      fetchImpl: fetchMock as unknown as typeof fetch,
    })

    await expect(client.getProposal('019a0000-0000-7000-8000-000000000001')).rejects.toMatchObject({
      code: 'resource_unavailable',
      status: 404,
    })
    expect(fetchMock).toHaveBeenCalledWith(
      'https://host.example/office/proposals/019a0000-0000-7000-8000-000000000001',
      expect.objectContaining({ method: 'GET', credentials: 'same-origin' }),
    )
  })
})
