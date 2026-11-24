<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'

const props = withDefaults(
  defineProps<{
    spots: SurfZoneSpot[]
    initialLimit?: number
  }>(),
  { initialLimit: 0 },
)

type TierKey = 'beginner' | 'intermediate' | 'advanced' | 'all'

const tierMeta: Record<Exclude<TierKey, 'all'>, { label: string; badgeClass: string }> = {
  beginner: {
    label: 'Beginner',
    badgeClass: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  },
  intermediate: {
    label: 'Intermediate',
    badgeClass: 'border-amber-200 bg-amber-50 text-amber-800',
  },
  advanced: {
    label: 'Advanced',
    badgeClass: 'border-rose-200 bg-rose-50 text-rose-800',
  },
}

const grouped = computed(() => {
  const buckets: Record<Exclude<TierKey, 'all'>, SurfZoneSpot[]> = {
    beginner: [],
    intermediate: [],
    advanced: [],
  }

  for (const spot of props.spots) {
    const key = String(spot.difficulty ?? 'beginner').toLowerCase()
    if (key === 'advanced') buckets.advanced.push(spot)
    else if (key === 'intermediate') buckets.intermediate.push(spot)
    else buckets.beginner.push(spot)
  }

  return buckets
})

const tabs = computed<Array<{ key: TierKey; label: string; count: number }>>(() => {
  const list: Array<{ key: TierKey; label: string; count: number }> = []
  ;(['beginner', 'intermediate', 'advanced'] as const).forEach((key) => {
    if (grouped.value[key].length > 0) {
      list.push({
        key,
        label: tierMeta[key].label,
        count: grouped.value[key].length,
      })
    }
  })

  if (list.length > 1) {
    list.push({ key: 'all', label: 'All zones', count: props.spots.length })
  }

  return list
})

const activeTab = ref<TierKey>(tabs.value[0]?.key ?? 'all')
const showAll = ref(false)

watch(
  tabs,
  (next) => {
    if (!next.some((t) => t.key === activeTab.value)) {
      activeTab.value = next[0]?.key ?? 'all'
    }
  },
)

watch(activeTab, () => {
  showAll.value = false
})

const visibleSpots = computed(() =>
  activeTab.value === 'all' ? props.spots : grouped.value[activeTab.value],
)

const hasHiddenSpots = computed(
  () => props.initialLimit > 0 && visibleSpots.value.length > props.initialLimit,
)

const displayedSpots = computed(() =>
  hasHiddenSpots.value && !showAll.value
    ? visibleSpots.value.slice(0, props.initialLimit)
    : visibleSpots.value,
)

const badgeClassFor = (difficulty?: string | null) => {
  const key = String(difficulty ?? 'beginner').toLowerCase()
  if (key === 'advanced') return tierMeta.advanced.badgeClass
  if (key === 'intermediate') return tierMeta.intermediate.badgeClass
  return tierMeta.beginner.badgeClass
}
</script>

<template>
  <div class="space-y-3">
    <div
      v-if="tabs.length > 1"
      class="inline-flex max-w-full flex-wrap gap-1 rounded-xl border border-sand-200 bg-sand-50 p-1"
      role="tablist"
      aria-label="Surf zone difficulty tiers"
    >
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab.key"
        class="rounded-lg px-3 py-1.5 text-xs font-bold transition-colors"
        :class="
          activeTab === tab.key
            ? 'bg-deep-900 text-white shadow-sm'
            : 'text-stone-700 hover:bg-white hover:text-deep-950'
        "
        @click="activeTab = tab.key"
      >
        {{ tab.label }} ({{ tab.count }})
      </button>
    </div>

    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
      <div
        v-for="spot in displayedSpots"
        :key="spot.id"
        class="flex min-w-0 items-center justify-between gap-3 rounded-xl border border-sand-200 bg-sand-50/70 px-3.5 py-2.5"
      >
        <div class="min-w-0">
          <p class="truncate text-xs font-bold text-deep-950">{{ spot.name }}</p>
          <p v-if="spot.area || spot.best_tide" class="truncate text-[11px] text-stone-500">
            {{ spot.area ?? 'Siargao' }}<template v-if="spot.best_tide"> · {{ spot.best_tide }}</template>
          </p>
        </div>
        <span
          class="shrink-0 rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider"
          :class="badgeClassFor(spot.difficulty)"
        >
          {{ spot.difficulty ?? 'beginner' }}
        </span>
      </div>
    </div>

    <button
      v-if="hasHiddenSpots"
      type="button"
      class="w-full rounded-xl border border-sand-200 bg-white py-2.5 text-xs font-bold text-deep-900 transition-colors hover:border-lagoon-600 hover:bg-sand-50"
      @click="showAll = !showAll"
    >
      {{ showAll ? 'Show fewer zones ▲' : `Show all ${visibleSpots.length} zones ▼` }}
    </button>
  </div>
</template>
