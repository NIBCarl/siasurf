<script setup lang="ts">
/**
 * SISA live-session board.
 *
 * Reverb gives instant updates, but a missed socket must never blind the
 * dashboard, so the page also polls the JSON feed every 12 seconds. The two
 * paths converge on the same `sessions` array.
 */
import { Head } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import LiveMap, { type MapSession } from '@/Components/Organisms/LiveMap.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import {
  SignalIcon,
  MapPinIcon,
  ClockIcon,
  ArrowPathIcon,
} from '@heroicons/vue/24/outline'

interface Props {
  sessions: MapSession[]
  meta: {
    generated_at: string
    on_site_radius_m: number
    stale_after_seconds: number
    siargao_bounds: Record<string, number>
  }
}

const props = defineProps<Props>()

const sessions = ref<MapSession[]>(props.sessions)
const lastSync = ref<string>(props.meta.generated_at)
const refreshing = ref(false)
const focusBookingId = ref<number | null>(null)

let poller: ReturnType<typeof setInterval> | null = null

const located = computed(() => sessions.value.filter((s) => s.location))
const onSite = computed(() => sessions.value.filter((s) => s.on_site === true))
const offSite = computed(() => sessions.value.filter((s) => s.on_site === false))

const refresh = async () => {
  refreshing.value = true
  try {
    const { data } = await axios.get(route('admin.api.live-sessions'))
    if (Array.isArray(data?.sessions)) {
      sessions.value = data.sessions
      lastSync.value = data?.meta?.generated_at ?? new Date().toISOString()
    }
  } catch {
    // Keep the last good snapshot rather than blanking the board.
  } finally {
    refreshing.value = false
  }
}

const distanceLabel = (value?: number | null) => {
  if (value === null || value === undefined) return '—'
  return value < 1000 ? `${Math.round(value)} m` : `${(value / 1000).toFixed(1)} km`
}

const remainingLabel = (seconds?: number) => {
  if (!seconds || seconds <= 0) return '—'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

const subscribe = () => {
  const echo = (window as any).Echo
  if (!echo) return

  echo.private('admin.notifications')
    .listen('.session.location.updated', (event: any) => {
      const bookingId = event?.booking_id
      if (!bookingId) return

      const index = sessions.value.findIndex((s) => s.booking_id === bookingId)
      const patch: Partial<MapSession> = {
        location: event.location,
        distance_from_spot_m: event.distance_from_spot_m ?? null,
        on_site: event.on_site ?? null,
      }

      if (index === -1) {
        sessions.value = [
          {
            booking_id: bookingId,
            booking_reference: event.booking_reference,
            instructor: event.instructor,
            student: event.student,
            spot: event.spot,
            ...patch,
          } as MapSession,
          ...sessions.value,
        ]
      } else {
        sessions.value[index] = { ...sessions.value[index], ...patch }
      }

      lastSync.value = event.timestamp ?? new Date().toISOString()
    })
}

onMounted(() => {
  poller = setInterval(refresh, 12000)
  subscribe()
})

onBeforeUnmount(() => {
  if (poller) clearInterval(poller)
})
</script>

<template>
  <Head title="Live Sessions" />

  <AppSidebarLayout>
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
      <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">Safety monitoring</p>
          <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Live sessions</h1>
          <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">
            Every lesson running on Siargao today. A lesson counts as
            <span class="font-semibold text-emerald-700">on site</span> when the instructor is
            within {{ meta.on_site_radius_m }} m of the booked break.
          </p>
        </div>

        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-900/10 transition hover:bg-slate-50"
          :disabled="refreshing"
          @click="refresh"
        >
          <ArrowPathIcon :class="['h-4 w-4', refreshing ? 'animate-spin' : '']" />
          Refresh
        </button>
      </header>

      <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Sharing location</p>
          <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ located.length }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">On site</p>
          <p class="mt-1 text-2xl font-extrabold text-emerald-600">{{ onSite.length }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-900/5">
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Away from break</p>
          <p class="mt-1 text-2xl font-extrabold text-amber-600">{{ offSite.length }}</p>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
        <LiveMap
          :sessions="sessions"
          :focus-booking-id="focusBookingId"
          height="540px"
          :zoom="10"
        />

        <div class="max-h-[540px] space-y-3 overflow-y-auto pr-1">
          <button
            v-for="session in sessions"
            :key="session.booking_id"
            type="button"
            class="w-full rounded-2xl bg-white p-4 text-left shadow-sm ring-1 transition hover:ring-teal-500/40"
            :class="session.on_site === false ? 'ring-amber-200' : 'ring-slate-900/5'"
            @click="focusBookingId = session.booking_id"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm font-bold text-slate-900">
                  {{ session.instructor?.name ?? 'Instructor' }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">
                  {{ session.student?.name ?? 'Student' }} ·
                  <span class="font-mono">{{ session.booking_reference }}</span>
                </p>
              </div>
              <Badge
                :variant="session.location ? (session.on_site ? 'success' : 'warning') : 'default'"
              >
                {{ session.location ? (session.on_site ? 'On site' : 'Away') : 'No ping' }}
              </Badge>
            </div>

            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-slate-600">
              <span class="inline-flex items-center gap-1">
                <MapPinIcon class="h-3.5 w-3.5 text-slate-400" />
                {{ session.spot?.label ?? session.spot?.name ?? 'No break' }}
              </span>
              <span class="inline-flex items-center gap-1">
                <SignalIcon class="h-3.5 w-3.5 text-slate-400" />
                {{ distanceLabel(session.distance_from_spot_m) }}
              </span>
              <span class="inline-flex items-center gap-1">
                <ClockIcon class="h-3.5 w-3.5 text-slate-400" />
                {{ remainingLabel(session.session_time_remaining) }} left
              </span>
            </div>

            <p class="mt-2 text-[11px] text-slate-400">
              {{ session.status_label }}
              <span v-if="session.location?.age_seconds !== undefined">
                · pinged {{ session.location.age_seconds }}s ago
              </span>
            </p>
          </button>

          <p v-if="sessions.length === 0" class="rounded-2xl bg-slate-50 p-6 text-center text-sm text-slate-500">
            No lessons scheduled or running today.
          </p>
        </div>
      </div>

      <p class="mt-4 text-right text-[11px] text-slate-400">Last sync {{ lastSync }}</p>
    </div>
  </AppSidebarLayout>
</template>
