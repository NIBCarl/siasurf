<script setup lang="ts">
/**
 * Instructor GPS broadcaster.
 *
 * Only runs while the lesson is `in_progress` (the server rejects pings
 * otherwise). Uses watchPosition with high accuracy, throttled to one POST
 * every ~8 seconds so a 1-hour lesson produces a trail instead of thousands of
 * rows. Everything is opt-in: the instructor must tap "Go live", which is also
 * what triggers the browser permission prompt.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import axios from 'axios'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import { SignalIcon, SignalSlashIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Props {
  bookingId: number
  canBroadcast: boolean
}

const props = defineProps<Props>()

const PING_INTERVAL_MS = 8000

const broadcasting = ref(false)
const lastSentAt = ref<Date | null>(null)
const lastAccuracy = ref<number | null>(null)
const lastDistance = ref<number | null>(null)
const onSite = ref<boolean | null>(null)
const error = ref<string | null>(null)
const pingsSent = ref(0)

let watchId: number | null = null
let lastSent = 0
let lastPosition: GeolocationPosition | null = null
let flushTimer: ReturnType<typeof setInterval> | null = null

const statusLabel = computed(() => {
  if (!broadcasting.value) return 'Not sharing'
  if (pingsSent.value === 0) return 'Waiting for GPS fix'
  return 'Live'
})

const distanceLabel = computed(() => {
  if (lastDistance.value === null) return null
  return lastDistance.value < 1000
    ? `${Math.round(lastDistance.value)} m`
    : `${(lastDistance.value / 1000).toFixed(1)} km`
})

const send = async (position: GeolocationPosition) => {
  try {
    const { data } = await axios.post(route('instructor.bookings.location.update', props.bookingId), {
      latitude: position.coords.latitude,
      longitude: position.coords.longitude,
      accuracy_m: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : null,
      speed_mps: Number.isFinite(position.coords.speed) ? position.coords.speed : null,
      heading: Number.isFinite(position.coords.heading) ? position.coords.heading : null,
    })

    pingsSent.value += 1
    lastSentAt.value = new Date()
    lastAccuracy.value = data?.location?.accuracy_m ?? position.coords.accuracy ?? null
    lastDistance.value = data?.distance_from_spot_m ?? null
    onSite.value = data?.on_site ?? null
    error.value = null
    lastSent = Date.now()
  } catch (exception: any) {
    const message =
      exception?.response?.data?.message ??
      'Could not send your location. Check your connection and try again.'
    error.value = message

    // A rejected coordinate (outside Siargao) will never succeed; stop rather
    // than hammering the endpoint.
    if (exception?.response?.status === 422) {
      stop()
    }
  }
}

const handlePosition = (position: GeolocationPosition) => {
  lastPosition = position

  // watchPosition fires far more often than we want to persist.
  if (Date.now() - lastSent < PING_INTERVAL_MS) return

  send(position)
}

const handleError = (geoError: GeolocationPositionError) => {
  error.value =
    geoError.code === geoError.PERMISSION_DENIED
      ? 'Location permission was blocked. Allow location for this site and try again.'
      : geoError.code === geoError.POSITION_UNAVAILABLE
        ? 'Your device could not get a GPS fix. Move into the open and retry.'
        : 'Location request timed out. Try again.'
  stop()
}

function stop() {
  broadcasting.value = false

  if (watchId !== null) {
    navigator.geolocation.clearWatch(watchId)
    watchId = null
  }

  if (flushTimer) {
    clearInterval(flushTimer)
    flushTimer = null
  }
}

const start = () => {
  if (!props.canBroadcast || broadcasting.value) return

  if (!('geolocation' in navigator)) {
    error.value = 'This browser does not support location sharing.'
    return
  }

  error.value = null
  pingsSent.value = 0
  broadcasting.value = true

  watchId = navigator.geolocation.watchPosition(handlePosition, handleError, {
    enableHighAccuracy: true,
    maximumAge: 5000,
    timeout: 20000,
  })

  // Send the most recent fix on a timer even when the device is stationary,
  // so the student's map knows the session is still alive.
  flushTimer = setInterval(() => {
    if (lastPosition) send(lastPosition)
  }, PING_INTERVAL_MS * 2)
}

onBeforeUnmount(stop)
</script>

<template>
  <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-900/5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <span
          :class="[
            'flex h-10 w-10 items-center justify-center rounded-xl',
            broadcasting ? 'bg-sky-50 text-sky-600' : 'bg-slate-100 text-slate-500',
          ]"
        >
          <SignalIcon v-if="broadcasting" class="h-5 w-5" />
          <SignalSlashIcon v-else class="h-5 w-5" />
        </span>
        <div>
          <h3 class="text-sm font-bold text-slate-900">Live location</h3>
          <p class="mt-0.5 text-xs text-slate-500">
            Lets your student and SISA see you on the map during the lesson.
          </p>
        </div>
      </div>

      <span
        :class="[
          'rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider',
          broadcasting ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-500',
        ]"
      >
        {{ statusLabel }}
      </span>
    </div>

    <div v-if="broadcasting" class="mt-4 grid grid-cols-3 gap-2 text-center">
      <div class="rounded-xl bg-slate-50 p-2">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pings</p>
        <p class="text-sm font-extrabold text-slate-900">{{ pingsSent }}</p>
      </div>
      <div class="rounded-xl bg-slate-50 p-2">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Accuracy</p>
        <p class="text-sm font-extrabold text-slate-900">
          {{ lastAccuracy !== null ? `${Math.round(lastAccuracy)} m` : '—' }}
        </p>
      </div>
      <div class="rounded-xl bg-slate-50 p-2">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">From break</p>
        <p class="text-sm font-extrabold" :class="onSite ? 'text-emerald-600' : 'text-slate-900'">
          {{ distanceLabel ?? '—' }}
        </p>
      </div>
    </div>

    <p v-if="error" class="mt-3 flex items-start gap-1.5 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
      <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" />
      {{ error }}
    </p>

    <div class="mt-4">
      <BaseButton
        v-if="!broadcasting"
        variant="primary"
        size="sm"
        :disabled="!canBroadcast"
        @click="start"
      >
        <SignalIcon class="mr-1.5 h-4 w-4" />
        Go live
      </BaseButton>

      <BaseButton v-else variant="secondary" size="sm" @click="stop">
        <SignalSlashIcon class="mr-1.5 h-4 w-4" />
        Stop sharing
      </BaseButton>

      <p v-if="!canBroadcast" class="mt-2 text-[11px] text-slate-400">
        Available once the session is in progress.
      </p>
      <p v-else-if="lastSentAt" class="mt-2 text-[11px] text-slate-400">
        Last sent {{ lastSentAt.toLocaleTimeString() }}
      </p>
    </div>
  </section>
</template>
