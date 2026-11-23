import { computed, ref, shallowRef } from 'vue'
import { OfficeWorkspaceApiError, type ListProposalsOptions, type OfficeWorkspaceClient } from '../api/client'
import type { ProposalDecisionResult, ProposalDetails, ResumeRunOptions, RunDetails } from '../api/types'

export interface UseProposalReviewOptions {
  initialProposals?: ProposalDetails[]
  initialRun?: RunDetails | null
}

function normalizeError(err: unknown): OfficeWorkspaceApiError {
  if (err instanceof OfficeWorkspaceApiError) {
    return err
  }
  const message = err instanceof Error ? err.message : 'Unexpected workspace error.'
  return new OfficeWorkspaceApiError(message, 0, 'temporarily_unavailable', true, 'retry')
}

export function useProposalReview(client: OfficeWorkspaceClient, options: UseProposalReviewOptions = {}) {
  const proposals = ref<ProposalDetails[]>(options.initialProposals ? [...options.initialProposals] : [])
  const run = shallowRef<RunDetails | null>(options.initialRun ?? null)
  const loading = ref(false)
  const resumingRun = ref(false)
  const submittingIds = ref<string[]>([])
  const error = ref<OfficeWorkspaceApiError | null>(null)
  const lastDecisionWon = ref<boolean | null>(null)

  const confirmedSteps = computed(() => run.value?.confirmed_steps ?? [])
  const remainingSteps = computed(() => run.value?.remaining_steps ?? [])

  function upsertProposal(updated: ProposalDetails): void {
    const idx = proposals.value.findIndex((item) => item.proposal_id === updated.proposal_id)
    if (idx >= 0) {
      proposals.value = [
        ...proposals.value.slice(0, idx),
        updated,
        ...proposals.value.slice(idx + 1),
      ]
    } else {
      proposals.value = [...proposals.value, updated]
    }

    if (run.value) {
      const patchResource = <T extends { proposal?: ProposalDetails | null }>(entry: T): T => (
        entry.proposal?.proposal_id === updated.proposal_id
          ? { ...entry, proposal: updated }
          : entry
      )
      run.value = {
        ...run.value,
        resources: run.value.resources.map(patchResource),
        confirmed_steps: run.value.confirmed_steps.map(patchResource),
        remaining_steps: run.value.remaining_steps.map(patchResource),
      }
    }
  }

  async function loadProposals(filter?: ListProposalsOptions): Promise<ProposalDetails[]> {
    loading.value = true
    error.value = null
    try {
      const loaded = await client.listProposals(filter)
      proposals.value = loaded
      return loaded
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      loading.value = false
    }
  }

  async function loadProposal(proposalId: string): Promise<ProposalDetails> {
    loading.value = true
    error.value = null
    try {
      const loaded = await client.getProposal(proposalId)
      upsertProposal(loaded)
      return loaded
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      loading.value = false
    }
  }

  async function loadProposalsByIds(ids: string[]): Promise<ProposalDetails[]> {
    if (ids.length === 0) {
      return []
    }
    loading.value = true
    error.value = null
    try {
      const results = await Promise.all(ids.map((id) => client.getProposal(id)))
      for (const item of results) {
        upsertProposal(item)
      }
      return results
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      loading.value = false
    }
  }

  async function loadRun(runId: string): Promise<RunDetails> {
    loading.value = true
    error.value = null
    try {
      const loaded = await client.getRun(runId)
      run.value = loaded
      for (const res of loaded.resources) {
        if (res.visible && res.proposal) {
          upsertProposal(res.proposal)
        }
      }
      return loaded
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      loading.value = false
    }
  }

  async function decide(proposalId: string, digest: string, approved: boolean, commandId?: string): Promise<ProposalDecisionResult> {
    if (!submittingIds.value.includes(proposalId)) {
      submittingIds.value = [...submittingIds.value, proposalId]
    }
    error.value = null
    try {
      const res = await client.decideProposal(proposalId, { digest, approved, commandId })
      lastDecisionWon.value = res.won
      upsertProposal(res.proposal)
      const activeRunId = res.proposal.run?.run_id ?? run.value?.run_id
      if (activeRunId && run.value?.run_id === activeRunId) {
        const refreshed = await client.getRun(activeRunId)
        run.value = refreshed
        for (const entry of refreshed.resources) {
          if (entry.visible && entry.proposal) {
            upsertProposal(entry.proposal)
          }
        }
      }
      return res
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      submittingIds.value = submittingIds.value.filter((id) => id !== proposalId)
    }
  }

  async function resume(runId: string, resumeOptions: ResumeRunOptions = {}): Promise<RunDetails> {
    resumingRun.value = true
    error.value = null
    try {
      const loaded = await client.resumeRun(runId, resumeOptions)
      run.value = loaded
      for (const entry of loaded.resources) {
        if (entry.visible && entry.proposal) {
          upsertProposal(entry.proposal)
        }
      }
      return loaded
    } catch (err) {
      error.value = normalizeError(err)
      throw error.value
    } finally {
      resumingRun.value = false
    }
  }

  return {
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
    loadProposal,
    loadProposalsByIds,
    loadRun,
    decide,
    resume,
  }
}
