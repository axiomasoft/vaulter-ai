import type { Editor } from '@tiptap/core'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import type {
  ContentProtectionAttributeRule,
  ContentProtectionMode,
  ContentProtectionPolicyConfig,
  GetEditorContextOptions,
  NormalizedContentProtectionPolicy,
  VaulterEditorContext,
  VaulterEditorContextItem,
} from './types'

const LEAF_TOKEN = '\uFFFC'

const STRIPPED_SCHEMA_KEYS = new Set([
  'parseDOM',
  'toDOM',
  'parseHTML',
  'renderHTML',
  'renderText',
  'nodeView',
  'addNodeView',
  'addProseMirrorPlugins',
  'addKeyboardShortcuts',
  'addInputRules',
  'addPasteRules',
  'addCommands',
])

function uniqueStrings(raw: unknown): string[] {
  if (!Array.isArray(raw)) {
    return []
  }
  const seen = new Set<string>()
  for (const item of raw) {
    if (typeof item === 'string' && item !== '') {
      seen.add(item)
    }
  }
  return Array.from(seen)
}

function normalizeAttrRules(
  raw: ContentProtectionAttributeRule[] | undefined,
): Array<{ attribute: string; values?: unknown[]; node_types?: string[] }> {
  if (!Array.isArray(raw)) {
    return []
  }
  const out: Array<{ attribute: string; values?: unknown[]; node_types?: string[] }> = []
  for (const item of raw) {
    if (!item || typeof item.attribute !== 'string' || item.attribute === '') {
      continue
    }
    const entry: { attribute: string; values?: unknown[]; node_types?: string[] } = {
      attribute: item.attribute,
    }
    if (Array.isArray(item.values)) {
      entry.values = [...item.values]
    } else if ('value' in item && item.value !== undefined) {
      entry.values = [item.value]
    }
    const nodeTypes = uniqueStrings(
      item.node_types ?? (item.node_type ? [item.node_type] : []),
    )
    if (nodeTypes.length > 0) {
      entry.node_types = nodeTypes
    }
    out.push(entry)
  }
  return out
}

export function normalizeContentProtectionPolicy(
  config?: ContentProtectionPolicyConfig | null,
): NormalizedContentProtectionPolicy | null {
  if (!config) {
    return null
  }

  const hiddenTypes = uniqueStrings(config.hidden_types ?? config.hiddenTypes ?? [])
  const readOnlyTypes = uniqueStrings(config.read_only_types ?? config.readOnlyTypes ?? [])
  const hiddenAttributes = normalizeAttrRules(config.hidden_attributes ?? config.hiddenAttributes)
  const readOnlyAttributes = normalizeAttrRules(config.read_only_attributes ?? config.readOnlyAttributes)

  if (Array.isArray(config.rules)) {
    for (const rule of config.rules) {
      if (!rule || (rule.mode !== 'hidden' && rule.mode !== 'read_only')) {
        continue
      }
      const nodeTypes = uniqueStrings(
        rule.node_types ?? (rule.node_type ? [rule.node_type] : []),
      )
      const attr = typeof rule.attribute === 'string' && rule.attribute !== ''
        ? rule.attribute
        : (typeof rule.attr === 'string' && rule.attr !== '' ? rule.attr : null)

      if (attr !== null) {
        const entry: { attribute: string; values?: unknown[]; node_types?: string[] } = { attribute: attr }
        if (Array.isArray(rule.values)) {
          entry.values = [...rule.values]
        } else if ('value' in rule && rule.value !== undefined) {
          entry.values = [rule.value]
        }
        if (nodeTypes.length > 0) {
          entry.node_types = nodeTypes
        }
        if (rule.mode === 'hidden') {
          hiddenAttributes.push(entry)
        } else {
          readOnlyAttributes.push(entry)
        }
      } else if (nodeTypes.length > 0) {
        if (rule.mode === 'hidden') {
          for (const t of nodeTypes) {
            if (!hiddenTypes.includes(t)) hiddenTypes.push(t)
          }
        } else {
          for (const t of nodeTypes) {
            if (!readOnlyTypes.includes(t)) readOnlyTypes.push(t)
          }
        }
      }
    }
  }

  if (
    hiddenTypes.length === 0
    && readOnlyTypes.length === 0
    && hiddenAttributes.length === 0
    && readOnlyAttributes.length === 0
  ) {
    return null
  }

  return {
    hiddenTypes,
    readOnlyTypes,
    hiddenAttributes,
    readOnlyAttributes,
  }
}

function matchesAttributeRule(
  type: string,
  attrs: Record<string, unknown>,
  rule: { attribute: string; values?: unknown[]; node_types?: string[] },
): boolean {
  if (rule.node_types && rule.node_types.length > 0 && (type === '' || !rule.node_types.includes(type))) {
    return false
  }
  if (!(rule.attribute in attrs)) {
    return false
  }
  const actual = attrs[rule.attribute]
  if (Array.isArray(rule.values)) {
    return rule.values.includes(actual)
  }
  return actual === true
}

export function modeForNode(
  policy: NormalizedContentProtectionPolicy | null,
  node: { type?: string; attrs?: Record<string, unknown> },
): ContentProtectionMode {
  if (!policy) {
    return 'editable'
  }
  const type = typeof node.type === 'string' ? node.type : ''
  const attrs = node.attrs ?? {}

  if (type !== '' && policy.hiddenTypes.includes(type)) {
    return 'hidden'
  }
  for (const rule of policy.hiddenAttributes) {
    if (matchesAttributeRule(type, attrs, rule)) {
      return 'hidden'
    }
  }

  if (type !== '' && policy.readOnlyTypes.includes(type)) {
    return 'read_only'
  }
  for (const rule of policy.readOnlyAttributes) {
    if (matchesAttributeRule(type, attrs, rule)) {
      return 'read_only'
    }
  }

  return 'editable'
}

export function modeForPath(
  policy: NormalizedContentProtectionPolicy | null,
  ancestorAndSelfNodes: ReadonlyArray<{ type?: string; attrs?: Record<string, unknown> }>,
): ContentProtectionMode {
  if (!policy) {
    return 'editable'
  }
  let effective: ContentProtectionMode = 'editable'
  for (const node of ancestorAndSelfNodes) {
    const mode = modeForNode(policy, node)
    if (mode === 'hidden') {
      return 'hidden'
    }
    if (mode === 'read_only') {
      effective = 'read_only'
    }
  }
  return effective
}

export function modeForRange(
  doc: ProseMirrorNode,
  from: number,
  to: number,
  policy: NormalizedContentProtectionPolicy | null,
): ContentProtectionMode {
  if (!policy) {
    return 'editable'
  }

  const clampedFrom = Math.max(0, Math.min(doc.content.size, from))
  const clampedTo = Math.max(clampedFrom, Math.min(doc.content.size, to))

  const checkResolved = (pos: number): ContentProtectionMode => {
    const $pos = doc.resolve(pos)
    const chain: Array<{ type: string; attrs: Record<string, unknown> }> = []
    for (let d = 0; d <= $pos.depth; d += 1) {
      const n = $pos.node(d)
      chain.push({ type: n.type.name, attrs: n.attrs as Record<string, unknown> })
    }
    return modeForPath(policy, chain)
  }

  let effective = checkResolved(clampedFrom)
  if (effective === 'hidden') {
    return 'hidden'
  }

  if (clampedTo > clampedFrom) {
    const endMode = checkResolved(clampedTo)
    if (endMode === 'hidden') {
      return 'hidden'
    }
    if (endMode === 'read_only') {
      effective = 'read_only'
    }

    doc.nodesBetween(clampedFrom, clampedTo, (node) => {
      const m = modeForNode(policy, {
        type: node.type.name,
        attrs: node.attrs as Record<string, unknown>,
      })
      if (m === 'hidden') {
        effective = 'hidden'
        return false
      }
      if (m === 'read_only') {
        effective = 'read_only'
      }
      return true
    })
  }

  return effective
}

export function extractSanitizedBlockText(
  node: ProseMirrorNode,
  policy: NormalizedContentProtectionPolicy | null,
): string {
  const selfMode = modeForNode(policy, {
    type: node.type.name,
    attrs: node.attrs as Record<string, unknown>,
  })
  if (selfMode === 'hidden') {
    return ''
  }
  if (node.isText) {
    return node.text ?? ''
  }
  if (node.isLeaf) {
    return LEAF_TOKEN
  }
  let out = ''
  node.forEach((child) => {
    out += extractSanitizedBlockText(child, policy)
  })
  return out
}

function sanitizeSchemaValue(value: unknown): unknown {
  if (value === null || value === undefined || typeof value === 'function') {
    return undefined
  }
  if (typeof value !== 'object') {
    return value
  }
  if (Array.isArray(value)) {
    const arr: unknown[] = []
    for (const item of value) {
      const sanitized = sanitizeSchemaValue(item)
      if (sanitized !== undefined) {
        arr.push(sanitized)
      }
    }
    return arr
  }
  const obj = value as Record<string, unknown>
  const out: Record<string, unknown> = {}
  for (const [key, val] of Object.entries(obj)) {
    if (STRIPPED_SCHEMA_KEYS.has(key) || typeof val === 'function') {
      continue
    }
    const sanitized = sanitizeSchemaValue(val)
    if (sanitized !== undefined) {
      out[key] = sanitized
    }
  }
  return out
}

function normalizeSpecAttrs(rawAttrs: Record<string, unknown> | undefined): Record<string, Record<string, unknown>> | undefined {
  if (!rawAttrs) {
    return undefined
  }
  const normalized: Record<string, Record<string, unknown>> = {}
  for (const [name, def] of Object.entries(rawAttrs)) {
    if (!name) continue
    if (!def || typeof def !== 'object') {
      normalized[name] = { type: 'string', nullable: true }
      continue
    }
    const d = def as Record<string, unknown>
    const entry: Record<string, unknown> = {
      type: typeof d.type === 'string' ? d.type : 'string',
    }
    if ('default' in d && typeof d.default !== 'function') {
      entry.default = d.default
    }
    if ('nullable' in d) {
      entry.nullable = Boolean(d.nullable)
    }
    if (typeof d.validate === 'string') {
      entry.validate = d.validate
    }
    normalized[name] = entry
  }
  return normalized
}

function describePolicy(policy: NormalizedContentProtectionPolicy): VaulterEditorContext['contentProtection'] {
  const parts: string[] = []
  if (policy.hiddenTypes.length > 0) {
    parts.push(`Hidden node types: ${policy.hiddenTypes.join(', ')}`)
  }
  if (policy.readOnlyTypes.length > 0) {
    parts.push(`Read-only node types (cannot be modified): ${policy.readOnlyTypes.join(', ')}`)
  }
  if (policy.hiddenAttributes.length > 0) {
    parts.push(`Hidden node attributes: ${Array.from(new Set(policy.hiddenAttributes.map((r) => r.attribute))).join(', ')}`)
  }
  if (policy.readOnlyAttributes.length > 0) {
    parts.push(`Read-only node attributes (cannot be modified): ${Array.from(new Set(policy.readOnlyAttributes.map((r) => r.attribute))).join(', ')}`)
  }
  return {
    hidden_types: policy.hiddenTypes,
    read_only_types: policy.readOnlyTypes,
    hidden_attributes: policy.hiddenAttributes,
    read_only_attributes: policy.readOnlyAttributes,
    summary: parts.length === 0 ? 'No content protection restrictions.' : parts.join('; '),
  }
}

function collectSanitizedItems(
  node: ProseMirrorNode,
  policy: NormalizedContentProtectionPolicy | null,
  ancestors: Array<{ type: string; attrs: Record<string, unknown> }>,
  items: VaulterEditorContextItem[],
): void {
  const current = { type: node.type.name, attrs: node.attrs as Record<string, unknown> }
  const chain = [...ancestors, current]
  const mode = modeForPath(policy, chain)
  if (mode === 'hidden') {
    return
  }

  if (node.isTextblock && (node.type.name === 'paragraph' || node.type.name === 'heading' || typeof current.attrs.blockId === 'string')) {
    const item: VaulterEditorContextItem = {
      type: node.type.name,
      text: extractSanitizedBlockText(node, policy),
    }
    if (typeof current.attrs.blockId === 'string' && current.attrs.blockId !== '') {
      item.block_id = current.attrs.blockId
    }
    if (mode === 'read_only') {
      item.protection = 'read_only'
    }
    items.push(item)
    return
  }

  node.forEach((child) => {
    collectSanitizedItems(child, policy, chain, items)
  })
}

export function getEditorContext(
  editor: Editor,
  options: GetEditorContextOptions = {},
): VaulterEditorContext {
  const policy = normalizeContentProtectionPolicy(options.contentProtectionPolicy)
  const nodes: Record<string, Record<string, unknown>> = {}
  const marks: Record<string, Record<string, unknown>> = {}

  for (const [name, nodeType] of Object.entries(editor.schema.nodes)) {
    const clean = (sanitizeSchemaValue(nodeType.spec) as Record<string, unknown> | undefined) ?? {}
    clean.name = name
    const attrs = normalizeSpecAttrs(nodeType.spec.attrs as Record<string, unknown> | undefined)
    if (attrs) {
      clean.attrs = attrs
    }
    if (policy) {
      clean.protection = modeForNode(policy, { type: name, attrs: {} })
    }
    nodes[name] = clean
  }

  for (const [name, markType] of Object.entries(editor.schema.marks)) {
    const clean = (sanitizeSchemaValue(markType.spec) as Record<string, unknown> | undefined) ?? {}
    clean.name = name
    const attrs = normalizeSpecAttrs(markType.spec.attrs as Record<string, unknown> | undefined)
    if (attrs) {
      clean.attrs = attrs
    }
    marks[name] = clean
  }

  const items: VaulterEditorContextItem[] = []
  if (options.includeItems !== false) {
    collectSanitizedItems(editor.state.doc, policy, [], items)
  }

  if (Array.isArray(options.customNodes)) {
    for (const custom of options.customNodes) {
      if (!custom || typeof custom.extensionName !== 'string') continue
      const existingIdx = items.findIndex((item) => item.extensionName === custom.extensionName)
      if (existingIdx >= 0) {
        items[existingIdx] = { ...custom }
      } else {
        items.push({ ...custom })
      }
    }
  }

  const result: VaulterEditorContext = {
    version: 'vaulter.ai.editor-context/v1',
    schema_version: 1,
    serializedSchema: { nodes, marks },
    items,
  }

  if (policy) {
    result.contentProtection = describePolicy(policy)
  }

  return result
}
