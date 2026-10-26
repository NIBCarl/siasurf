<script setup lang="ts">
/**
 * Student-facing live instructor tracker.
 *
 * Listens on `student.{id}` for `.session.location.updated` and falls back to
 * polling, so the map is correct whether or not the Reverb socket is up.
 * Nothing renders until the instructor actually shares a position.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import LiveMap, { type MapSession, type MapSpot } from '@/Components/Organisms/LiveMap.vue'
import { MapPinIcon, SignalIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'

interface Props {
  bookingId: number
  studentId: number
  status: string
  spot?: MapSpot | null
}

const props = defineProps<Props>()

const session = ref<MapSession | null>(null)
const refreshing = ref(false)
const staleAfter = ref(120)

let poller: ReturnType<typeof setInterval> | null = null

const isLive = computed(() => props.status === 'in_progress')
const hasFix = computed(() => Boolean(session.value?.location))
const isFresh = computed(() => session.value?.location?.is_fresh !== false)

const spots = computed<MapSpot[]>(() => (props.spot ? [props.spot] : []))
const sessions = computed<MapSession[]>(() => (session.value ? [session.value] : []))

const distanceLabel = computed(() => {
  const value = session.value?.distance_from_spot_m
  if (value === null || value === undefined) return null
  return value < 1000 ? `${Math.round(value)} m` : `${(value / 1000).toFixed(1)} km`
})

const ageLabel = computed(() => {
  const age = session.value?.location?.age_seconds
  if (age === undefined || age === null) return null
  if (age < 60) return `${age}s ago`
  return `${Math.floor(age / 60)}m ago`
})

const refresh = async () => {
  if (!isLive.value) return

  refreshing.value = true
  try {
    const { data } = await axios.get(route('student.bookings.location', props.bookingId))
    session.value = data?.live ?? null
    staleAfter.value = data?.stale_after_seconds ?? 120
  } catch {
    // Keep the last known fix.
  } finally {
    refreshing.value = false
  }
}

const subscribe = () => {
  const echo = (window as any).Echo
  if (!echo || !isLive.value) return

  echo.private(`student.${props.studentId}`)
    .listen('.session.location.updated', (event: any) => {
      if (Number(event?.booking_id) !== Number(props.bookingId)) return

      session.value = {
        booking_id: props.bookingId,
        booking_reference: event.booking_reference,
        instructor: event.instructor,
        spot: event.spot ?? props.spot ?? null,
        location: event.location,
        distance_from_spot_m: event.distance_from_spot_m ?? null,
        on_site: event.on_site ?? null,
        status: 'in_progress',
        status_label: 'In progress',
      }
    })
}

onMounted(() => {
  if (!isLive.value) return

  refresh()
  poller = setInterval(refresh, 15000)
  subscribe()
})

onBeforeUnmount(() => {
  if (poller) clearInterval(poller)
})
</script>

<template>
  <section v-if="isLive" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-900/5">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
          <SignalIcon class="h-5 w-5" />
        </span>
        <div>
          <h3 class="text-sm font-bold text-slate-900">Instructor location</h3>
          <p class="mt-0.5 text-xs text-slate-500">
            <template v-if="hasFix && isFresh">Live during your lesson</template>
            <template v-else-if="hasFix">Last known position (signal may be lost)</template>
            <template v-else>Waiting for your instructor to share their position…</template>
          </p>
        </div>
      </div>

      <button
        type="button"
        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"
        :disabled="refreshing"
        title="Refresh"
        @click="refresh"
      >
        <ArrowPathIcon :class="['h-4 w-4', refreshing ? 'animate-spin' : '']" />
      </button>
    </div>

    <div v-if="hasFix" class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
      <span class="inline-flex items-center gap-1">
        <MapPinIcon class="h-3.5 w-3.5 text-slate-400" />
        {{ session?.spot?.label ?? spot?.label ?? spot?.name ?? 'Surf spot' }}
      </span>
      <span v-if="distanceLabel" class="inline-flex items-center gap-1">
        <SignalIcon class="h-3.5 w-3.5 text-slate-400" />
        {{ distanceLabel }} away
      </span>
      <span v-if="ageLabel" class="text-slate-400">updated {{ ageLabel }}</span>
    </div>

    <LiveMap
      :sessions="sessions"
      :spots="spots"
      :focus-booking-id="bookingId"
      height="320px"
      :zoom="14"
    />
  </section>
</template>
