<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import LiveMap, { type MapSpot } from '@/Components/Organisms/LiveMap.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { MapPinIcon, ShieldCheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Props {
  spots: MapSpot[]
  areas: string[]
  mapCenter: { latitude: number; longitude: number; zoom: number }
  filters: { area?: string; difficulty?: string }
}

const props = defineProps<Props>()

const area = ref(props.filters.area ?? 'all')
const difficulty = ref(props.filters.difficulty ?? 'all')

const filtered = computed(() =>
  props.spots.filter((spot) => {
    const areaOk = area.value === 'all' || spot.area === area.value
    const diffOk = difficulty.value === 'all' || spot.difficulty === difficulty.value
    return areaOk && diffOk
  }),
)

const difficultyVariant = (difficulty?: string) => {
  if (difficulty === 'beginner') return 'success'
  if (difficulty === 'intermediate') return 'warning'
  return 'danger'
}

const safeCount = computed(() => props.spots.filter((s) => s.is_safe_zone).length)
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
          Every pin is a real Siargao break, grouped by the municipality it belongs to.
          Beginners are only ever matched to breaks flagged as a safe zone.
        </p>
        <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
          <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ spots.length }} breaks</span>
          <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">{{ safeCount }} beginner safe zones</span>
          <span class="rounded-full bg-sky-50 px-3 py-1 text-sky-700">{{ areas.length }} municipalities</span>
        </div>
      </header>

      <div class="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
        <LiveMap :spots="filtered" :center="mapCenter" :zoom="mapCenter.zoom" height="520px" />

        <div>
          <div class="mb-4 flex flex-wrap gap-3">
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
                    <MapPinIcon class="h-3.5 w-3.5" />
                    {{ spot.area ?? 'Siargao Island' }}
                  </p>
                </div>
                <Badge :variant="difficultyVariant(spot.difficulty)" class="capitalize">
                  {{ spot.difficulty }}
                </Badge>
              </div>

              <p v-if="spot.hazard_notes" class="mt-3 flex items-start gap-1.5 text-xs leading-relaxed text-amber-700">
                <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                {{ spot.hazard_notes }}
              </p>

              <p v-if="spot.is_safe_zone" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                <ShieldCheckIcon class="h-3.5 w-3.5" />
                Safe zone — beginners welcome
              </p>
            </article>

            <p v-if="filtered.length === 0" class="rounded-2xl bg-slate-50 p-6 text-center text-sm text-slate-500">
              No breaks match those filters.
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppSidebarLayout>
</template>
