<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import LiveMap, { type MapSpot } from '@/Components/Organisms/LiveMap.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import {
  MapPinIcon,
  ShieldCheckIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  SunIcon,
  ArrowUpRightIcon,
} from '@heroicons/vue/24/outline'

interface Spot extends MapSpot {
  barangay?: string | null
  side?: string | null
  side_label?: string | null
  section?: string | null
  section_label?: string | null
  description?: string | null
  break_type?: string | null
  best_tide?: string | null
  best_season?: string | null
  access?: string | null
  plus_code?: string | null
}

interface Props {
  spots: Spot[]
  areas: string[]
  sides: Record<string, string>
  sections: Record<string, string>
  mapCenter: { latitude: number; longitude: number; zoom: number }
  filters: { area?: string; difficulty?: string; side?: string; section?: string }
}

const props = defineProps<Props>()

const area = ref(props.filters.area ?? 'all')
const difficulty = ref(props.filters.difficulty ?? 'all')
const side = ref(props.filters.side ?? 'all')
const section = ref(props.filters.section ?? 'all')

const filtered = computed(() =>
  props.spots.filter((spot) => {
    const areaOk = area.value === 'all' || spot.area === area.value
    const diffOk = difficulty.value === 'all' || spot.difficulty === difficulty.value
    const sideOk = side.value === 'all' || spot.side === side.value
    const sectionOk = section.value === 'all' || spot.section === section.value
    return areaOk && diffOk && sideOk && sectionOk
  }),
)

const difficultyVariant = (difficulty?: string) => {
  if (difficulty === 'beginner') return 'success'
  if (difficulty === 'intermediate') return 'warning'
  return 'danger'
}

const safeCount = computed(() => props.spots.filter((s) => s.is_safe_zone).length)
const coastCount = computed(() => new Set(props.spots.map((s) => s.side).filter(Boolean)).size)

const emptyMessage = computed(() => {
  if (side.value === 'west') {
    return 'No West-side breaks are listed. Siargao\'s surf runs on the north, east and south coasts.'
  }
  return 'No breaks match those filters.'
})
</script>

<template>
  <Head title="Siargao Surf Spots" />

  <AppSidebarLayout>
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
      <header class="mb-8">
        <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">Siargao Island</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">
          The breaks, mapped properly
        </h1>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">
          Every pin is a real Siargao break, grouped by the municipality and coast it belongs to.
          Each break is listed as an inner or outer section of its reef, so the difficulty matches
          what you will actually paddle into. Beginners are only ever matched to breaks flagged as
          a safe zone.
        </p>
        <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
          <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ spots.length }} breaks</span>
          <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{{ safeCount }} beginner safe zones</span>
          <span class="rounded-full bg-sky-50 px-3 py-1 text-sky-700">{{ coastCount }} coasts</span>
          <span class="rounded-full bg-amber-50 px-3 py-1 text-amber-700">{{ areas.length }} municipalities</span>
        </div>
      </header>

      <div class="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
        <LiveMap :spots="filtered" :center="mapCenter" :zoom="mapCenter.zoom" height="520px" />

        <div>
          <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <select
              v-model="side"
              class="rounded-xl border-slate-200 bg-white text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
              <option value="all">All coasts</option>
              <option v-for="(label, value) in sides" :key="value" :value="value">{{ label }}</option>
            </select>

            <select
              v-model="section"
              class="rounded-xl border-slate-200 bg-white text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
              <option value="all">Inner and outer</option>
              <option v-for="(label, value) in sections" :key="value" :value="value">{{ label }}</option>
            </select>

            <select
              v-model="area"
              class="rounded-xl border-slate-200 bg-white text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
              <option value="all">All areas</option>
              <option v-for="item in areas" :key="item" :value="item">{{ item }}</option>
            </select>

            <select
              v-model="difficulty"
              class="rounded-xl border-slate-200 bg-white text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:ring-teal-500"
            >
              <option value="all">All levels</option>
              <option value="beginner">Beginner</option>
              <option value="intermediate">Intermediate</option>
              <option value="advanced">Advanced</option>
            </select>
          </div>

          <div class="max-h-[460px] space-y-3 overflow-y-auto pr-1">
            <article
              v-for="spot in filtered"
              :key="spot.id"
              class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5 transition hover:ring-teal-500/30"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <h2 class="text-sm font-bold text-slate-900">{{ spot.name }}</h2>
                  <p class="mt-0.5 flex items-center gap-1 text-xs text-slate-500">
                    <MapPinIcon class="h-3.5 w-3.5 shrink-0" />
                    <span>
                      {{ spot.barangay ? spot.barangay + ', ' : '' }}{{ spot.area ?? 'Siargao Island' }}
                    </span>
                  </p>
                </div>
                <Badge :variant="difficultyVariant(spot.difficulty)" class="shrink-0 capitalize">
                  {{ spot.difficulty }}
                </Badge>
              </div>

              <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-semibold">
                <span v-if="spot.side_label" class="rounded-full bg-sky-50 px-2 py-0.5 text-sky-700">
                  {{ spot.side_label }} coast
                </span>
                <span v-if="spot.section_label" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">
                  {{ spot.section_label }} break
                </span>
                <span v-if="spot.break_type" class="rounded-full bg-teal-50 px-2 py-0.5 text-teal-700">
                  {{ spot.break_type }}
                </span>
              </div>

              <p v-if="spot.description" class="mt-2 text-xs leading-relaxed text-slate-600">
                {{ spot.description }}
              </p>

              <dl class="mt-3 grid grid-cols-1 gap-1.5 text-xs sm:grid-cols-2">
                <div v-if="spot.best_tide" class="flex items-start gap-1.5 text-slate-600">
                  <ClockIcon class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                  <span><span class="font-semibold text-slate-700">Tide:</span> {{ spot.best_tide }}</span>
                </div>
                <div v-if="spot.best_season" class="flex items-start gap-1.5 text-slate-600">
                  <SunIcon class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-400" />
                  <span><span class="font-semibold text-slate-700">Season:</span> {{ spot.best_season }}</span>
                </div>
                <div v-if="spot.access" class="flex items-start gap-1.5 text-slate-600">
                  <ArrowUpRightIcon class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                  <span><span class="font-semibold text-slate-700">Access:</span> {{ spot.access }}</span>
                </div>
                <div v-if="spot.plus_code" class="flex items-start gap-1.5 text-slate-600">
                  <MapPinIcon class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
                  <span class="font-mono text-[11px]">{{ spot.plus_code }}</span>
                </div>
              </dl>

              <a
                v-if="spot.latitude !== null && spot.longitude !== null"
                :href="`https://www.google.com/maps/search/?api=1&query=${spot.latitude},${spot.longitude}`"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-sky-700 hover:text-sky-900"
              >
                <ArrowUpRightIcon class="h-3.5 w-3.5" />
                Open this pin in Google Maps
              </a>

              <p v-if="spot.hazard_notes" class="mt-3 flex items-start gap-1.5 rounded-xl bg-amber-50/70 p-2 text-xs leading-relaxed text-amber-800">
                <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                {{ spot.hazard_notes }}
              </p>

              <p v-if="spot.is_safe_zone" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                <ShieldCheckIcon class="h-3.5 w-3.5" />
                Safe zone — beginners welcome
              </p>
            </article>

            <p v-if="filtered.length === 0" class="rounded-2xl bg-slate-50 p-6 text-center text-sm text-slate-500">
              {{ emptyMessage }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>
