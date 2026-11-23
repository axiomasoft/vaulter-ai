<script setup lang="ts">
import { computed } from 'vue'
import type { ProposalDiff, TextSegment, TextSegmentMark } from '../api/types'

const props = defineProps<{
  diff: ProposalDiff | null
  summary?: string
}>()

const hasSectionPath = computed(() => (
  props.diff?.kind === 'text_replace' && props.diff.section_path.length > 0
))

function segmentStyle(marks: TextSegmentMark[]): Record<string, string> {
  const style: Record<string, string> = {}
  const decorations: string[] = []
  for (const mark of marks) {
    if (mark.type === 'bold' || mark.type === 'strong') {
      style.fontWeight = '700'
    } else if (mark.type === 'italic' || mark.type === 'em') {
      style.fontStyle = 'italic'
    } else if (mark.type === 'underline') {
      decorations.push('underline')
    } else if (mark.type === 'strike') {
      decorations.push('line-through')
    } else if (mark.type === 'code') {
      style.fontFamily = 'monospace'
    }
  }
  if (decorations.length > 0) {
    style.textDecoration = decorations.join(' ')
  }
  return style
}

function linkHref(marks: TextSegmentMark[]): string | null {
  for (const mark of marks) {
    if (mark.type === 'link' && mark.attrs && typeof mark.attrs.href === 'string') {
      const href = mark.attrs.href.trim()
      if (href.startsWith('https://') || href.startsWith('http://') || href.startsWith('/')) {
        return href
      }
    }
  }
  return null
}

function markBadge(marks: TextSegmentMark[]): string {
  if (marks.length === 0) {
    return ''
  }
  return marks.map((m) => m.type).join(',')
}

function normalizedSegments(segments: TextSegment[]): TextSegment[] {
  return segments.filter((s) => s.text.length > 0)
}
</script>

<template>
  <div class="vai-diff" data-testid="proposal-diff">
    <div v-if="!diff" class="vai-diff__empty" data-diff-state="unavailable">
      {{ summary || 'Фрагмент изменений недоступен.' }}
    </div>

    <div v-else-if="diff.kind === 'text_replace'" class="vai-diff__text">
      <div class="vai-diff__location">
        <span v-if="hasSectionPath" class="vai-diff__section" data-testid="diff-section">
          {{ diff.section_path.join(' / ') }}
        </span>
        <span class="vai-diff__block" data-testid="diff-block-id">блок {{ diff.block_id }}</span>
      </div>

      <div class="vai-diff__row vai-diff__row--before" data-testid="diff-before">
        <span class="vai-diff__label">Было:</span>
        <span class="vai-diff__segments">
          <template v-for="(seg, idx) in normalizedSegments(diff.before_segments)" :key="`b-${idx}`">
            <a
              v-if="linkHref(seg.marks)"
              :href="linkHref(seg.marks) ?? undefined"
              target="_blank"
              rel="noopener noreferrer"
              class="vai-diff__segment"
              :style="segmentStyle(seg.marks)"
              :data-marks="markBadge(seg.marks) || undefined"
            >{{ seg.text }}</a>
            <span
              v-else
              class="vai-diff__segment"
              :style="segmentStyle(seg.marks)"
              :data-marks="markBadge(seg.marks) || undefined"
            >{{ seg.text }}</span>
          </template>
        </span>
      </div>

      <div class="vai-diff__row vai-diff__row--after" data-testid="diff-after">
        <span class="vai-diff__label">Станет:</span>
        <span class="vai-diff__segments">
          <template v-for="(seg, idx) in normalizedSegments(diff.after_segments)" :key="`a-${idx}`">
            <a
              v-if="linkHref(seg.marks)"
              :href="linkHref(seg.marks) ?? undefined"
              target="_blank"
              rel="noopener noreferrer"
              class="vai-diff__segment"
              :style="segmentStyle(seg.marks)"
              :data-marks="markBadge(seg.marks) || undefined"
            >{{ seg.text }}</a>
            <span
              v-else
              class="vai-diff__segment"
              :style="segmentStyle(seg.marks)"
              :data-marks="markBadge(seg.marks) || undefined"
            >{{ seg.text }}</span>
          </template>
        </span>
      </div>
    </div>

    <div v-else-if="diff.kind === 'sheet_fill_formula'" class="vai-diff__sheet">
      <div class="vai-diff__location" data-testid="diff-sheet-location">
        Лист {{ diff.sheet_id }} · таблица {{ diff.table_id }} · колонка {{ diff.destination_column_letter }}
        · формула <code>{{ diff.formula_template }}</code>
      </div>
      <div class="vai-diff__rows" data-testid="diff-sheet-rows">
        Строки: {{ diff.row_ids.join(', ') }}
      </div>
    </div>

    <div v-else class="vai-diff__command">
      <code>{{ diff.command }}</code> · {{ diff.summary }}
    </div>
  </div>
</template>
