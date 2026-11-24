import type { Editor } from '@tiptap/core'
import type { OfficeChatClient } from '../api/client'

export type ContentProtectionMode = 'editable' | 'read_only' | 'hidden'

export interface ContentProtectionAttributeRule {
  attribute: string
  values?: unknown[]
  value?: unknown
  node_types?: string[]
  node_type?: string
}

export interface ContentProtectionRuleEntry {
  mode: 'hidden' | 'read_only' | string
  node_type?: string
  node_types?: string[]
  attribute?: string
  attr?: string
  value?: unknown
  values?: unknown[]
}

export interface ContentProtectionPolicyConfig {
  hidden_types?: string[]
  read_only_types?: string[]
  hidden_attributes?: ContentProtectionAttributeRule[]
  read_only_attributes?: ContentProtectionAttributeRule[]
  hiddenTypes?: string[]
  readOnlyTypes?: string[]
  hiddenAttributes?: ContentProtectionAttributeRule[]
  readOnlyAttributes?: ContentProtectionAttributeRule[]
  rules?: ContentProtectionRuleEntry[]
}

export interface NormalizedContentProtectionPolicy {
  hiddenTypes: string[]
  readOnlyTypes: string[]
  hiddenAttributes: Array<{ attribute: string; values?: unknown[]; node_types?: string[] }>
  readOnlyAttributes: Array<{ attribute: string; values?: unknown[]; node_types?: string[] }>
}

export interface VaulterEditorContextItem {
  block_id?: string
  type?: string
  text?: string
  protection?: ContentProtectionMode
  extensionName?: string
  name?: string
  description?: string
  [key: string]: unknown
}

export interface VaulterEditorContext {
  version: 'vaulter.ai.editor-context/v1'
  schema_version: 1
  serializedSchema: {
    nodes: Record<string, Record<string, unknown>>
    marks: Record<string, Record<string, unknown>>
  }
  items: VaulterEditorContextItem[]
  contentProtection?: {
    hidden_types: string[]
    read_only_types: string[]
    hidden_attributes: Array<{ attribute: string; values?: unknown[]; node_types?: string[] }>
    read_only_attributes: Array<{ attribute: string; values?: unknown[]; node_types?: string[] }>
    summary: string
  }
}

export interface GetEditorContextOptions {
  contentProtectionPolicy?: ContentProtectionPolicyConfig | null
  includeItems?: boolean
  customNodes?: Array<{
    extensionName: string
    name?: string
    description?: string
    [key: string]: unknown
  }>
}

export type VaulterAiAction =
  | 'shorten'
  | 'lengthen'
  | 'tone'
  | 'fixGrammar'
  | 'translate'
  | 'summarize'
  | 'prompt'

export type VaulterAiFormat = 'rich-text' | 'plain-text'

export type VaulterAiStatus = 'idle' | 'loading' | 'preview' | 'error'

export interface VaulterAiCommandOptions {
  format?: VaulterAiFormat
  insert?: boolean
  autoAccept?: boolean
  stream?: boolean
  modelName?: string
  language?: string
  tone?: string
  text?: string
  prompt?: string
  customOptions?: Record<string, unknown>
}

export interface VaulterAiToneOptions extends VaulterAiCommandOptions {
  tone: string
}

export interface VaulterAiTranslateOptions extends VaulterAiCommandOptions {
  language: string
}

export interface VaulterAiTextPromptOptions extends VaulterAiCommandOptions {
  text?: string
  prompt?: string
}

export interface VaulterAiAcceptOptions {
  insertAt?: number
  append?: boolean
}

export interface VaulterAiRejectOptions {
  type?: 'reset' | 'clear'
}

export interface VaulterAiRegenerateOptions extends VaulterAiCommandOptions {}

export interface VaulterAiCommandRequest {
  action: VaulterAiAction
  prompt: string
  text: string
  html: string
  format: VaulterAiFormat
  tone?: string
  language?: string
  selection: { from: number; to: number }
  editorContext: VaulterEditorContext
  contentProtectionPolicy: NormalizedContentProtectionPolicy | null
  signal: AbortSignal
  options: VaulterAiCommandOptions
}

export type VaulterAiStreamResult =
  | string
  | ReadableStream<Uint8Array | string>
  | AsyncIterable<string | Uint8Array>

export interface VaulterAiCommandsOptions {
  chatClient?: OfficeChatClient
  resourceId?: string
  sessionId?: string
  endpoint?: string
  headers?: () => Record<string, string> | Promise<Record<string, string>>
  fetchImpl?: typeof fetch
  streamResolver?: (request: VaulterAiCommandRequest) => Promise<VaulterAiStreamResult> | VaulterAiStreamResult
  completionResolver?: (request: VaulterAiCommandRequest) => Promise<string> | string
  contentProtectionPolicy?:
    | ContentProtectionPolicyConfig
    | (() => ContentProtectionPolicyConfig | null | undefined)
  defaultFormat?: VaulterAiFormat
  defaultInsert?: boolean
  autoAccept?: boolean
  maxPastResponses?: number
  onLoading?: (payload: { action: VaulterAiAction; isStreaming: boolean }) => void
  onChunk?: (payload: { action: VaulterAiAction; chunk: string; response: string }) => void
  onSuccess?: (payload: { action: VaulterAiAction; response: string; isStreaming: boolean }) => void
  onError?: (payload: { action: VaulterAiAction | null; error: Error }) => void
  onAccept?: (payload: { action: VaulterAiAction | null; response: string | null }) => void
  onReject?: (payload: { action: VaulterAiAction | null }) => void
}

export interface VaulterAiStorage {
  state: VaulterAiStatus
  isStreaming: boolean
  action: VaulterAiAction | null
  response: string | null
  pastResponses: string[]
  generatedRange: { from: number; to: number } | null
  error: Error | null
}

export interface VaulterAiAutocompleteRequest {
  context: string
  blocks: string[]
  cursorBlockId: string | null
  editorContext: VaulterEditorContext
  signal: AbortSignal
}

export interface VaulterAiAutocompleteOptions {
  enabled?: boolean
  debounceMs?: number
  minChars?: number
  contextBlockLimit?: number
  inputLength?: number
  allowMidBlock?: boolean
  yieldInNodeTypes?: string[]
  endpoint?: string
  headers?: () => Record<string, string> | Promise<Record<string, string>>
  fetchImpl?: typeof fetch
  resolver?: (request: VaulterAiAutocompleteRequest) => Promise<VaulterAiStreamResult> | VaulterAiStreamResult
  contentProtectionPolicy?:
    | ContentProtectionPolicyConfig
    | (() => ContentProtectionPolicyConfig | null | undefined)
  shouldTrigger?: (editor: Editor) => boolean
  onAccept?: (suggestion: string) => void
  onCancel?: () => void
  onError?: (error: Error) => void
}

export interface VaulterAiAutocompleteStorage {
  suggestion: string
  loading: boolean
  pos: number | null
}

export interface StreamingRevealAwarenessLike {
  getStates(): Map<number, Record<string, unknown>>
  on?(event: 'change', cb: (...args: unknown[]) => void): void
  off?(event: 'change', cb: (...args: unknown[]) => void): void
}

export interface VaulterStreamingRevealOptions {
  fadeDurationMs?: number
  maxChars?: number
  maxBlocks?: number
  quantizeStepMs?: number
  className?: string
  aiInstanceId?: string
  awareness?: StreamingRevealAwarenessLike | null
  includeLocal?: boolean
  resolveAiInstanceId?: (clientId: number, transaction: unknown) => string | null | undefined
  yDoc?: unknown
  fragment?: unknown
}

export interface StreamingRevealSpanInfo {
  aiInstanceId: string
  expectedLength: number
  createdAt: number
}

export interface VaulterStreamingRevealStorage {
  getActiveSpans: () => readonly StreamingRevealSpanInfo[]
}

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    vaulterAiCommands: {
      shorten: (options?: VaulterAiCommandOptions) => ReturnType
      lengthen: (options?: VaulterAiCommandOptions) => ReturnType
      tone: (toneOrOptions: string | VaulterAiToneOptions) => ReturnType
      fixGrammar: (options?: VaulterAiCommandOptions) => ReturnType
      translate: (languageOrOptions: string | VaulterAiTranslateOptions) => ReturnType
      summarize: (options?: VaulterAiCommandOptions) => ReturnType
      aiTextPrompt: (options: string | VaulterAiTextPromptOptions) => ReturnType
      aiAccept: (options?: VaulterAiAcceptOptions) => ReturnType
      aiReject: (options?: VaulterAiRejectOptions) => ReturnType
      aiRegenerate: (options?: VaulterAiRegenerateOptions) => ReturnType
      aiAbort: () => ReturnType
    }
    vaulterAiAutocomplete: {
      triggerAutocomplete: () => ReturnType
      acceptAutocomplete: () => ReturnType
      dismissAutocomplete: () => ReturnType
    }
    streamingReveal: {
      triggerStreamingReveal: (from: number, to: number, aiInstanceId?: string) => ReturnType
      clearStreamingReveal: () => ReturnType
    }
  }

  interface Storage {
    vaulterAiCommands: VaulterAiStorage
    vaulterAiAutocomplete: VaulterAiAutocompleteStorage
    streamingReveal: VaulterStreamingRevealStorage
  }
}
