<script setup lang="ts">
/**
 * LiveMap — Leaflet + OpenStreetMap.
 *
 * Uses OSM tiles (no API key, no billing) and custom divIcon pins, which avoids
 * the well-known broken-marker-path problem Leaflet has under bundlers.
 *
 * Everything is driven by props so the same component serves three jobs:
 *  - the public Siargao surf-spot directory,
 *  - the student's live instructor tracker,
 *  - SISA's live-session board and the admin coordinate picker.
 */
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

export interface MapSpot {
  id: number
  name: string
  area?: string | null
  label?: string
  difficulty?: string
  latitude: number | null
  longitude: number | null
  is_safe_zone?: boolean
  hazard_notes?: string | null
}

export interface MapLocation {
  latitude: number
  longitude: number
  accuracy_m?: number | null
  recorded_at?: string | null
  is_fresh?: boolean
  age_seconds?: number
}

export interface MapSession {
  booking_id: number
  booking_reference?: string
  status?: string
  status_label?: string
  instructor?: { id: number; name: string } | null
  student?: { id: number; name: string } | null
  spot?: MapSpot | null
  location?: MapLocation | null
  distance_from_spot_m?: number | null
  on_site?: boolean | null
  session_time_remaining?: number
}

interface Props {
  spots?: MapSpot[]
  sessions?: MapSession[]
  center?: { latitude: number; longitude: number } | null
  zoom?: number
  height?: string
  focusBookingId?: number | null
  /** When true, clicking the map emits `pick` with the clicked coordinates. */
  editable?: boolean
  picked?: { latitude: number; longitude: number } | null
  className?: string
}

const props = withDefaults(defineProps<Props>(), {
  spots: () => [],
  sessions: () => [],
  center: null,
  zoom: 11,
  height: '420px',
  focusBookingId: null,
  editable: false,
  picked: null,
  className: '',
})

const emit = defineEmits<{
  (e: 'pick', payload: { latitude: number; longitude: number }): void
}>()

// Siargao Island — General Luna / Cloud 9, used when nothing else locates us.
const DEFAULT_CENTER: [number, number] = [9.795, 126.155]

const container = ref<HTMLDivElement | null>(null)
// shallowRef keeps Leaflet's own objects intact: a deep ref would proxy the
// map and break both its typing and its internal identity checks.
const map = shallowRef<L.Map | null>(null)
const spotLayer = shallowRef<L.LayerGroup | null>(null)
const sessionLayer = shallowRef<L.LayerGroup | null>(null)
const pickedMarker = shallowRef<L.Marker | null>(null)
const hasFitOnce = ref(false)

const DIFFICULTY_COLORS: Record<string, string> = {
  beginner: '#10b981',
  intermediate: '#f59e0b',
  advanced: '#f43f5e',
}

const validSpots = computed(() =>
  props.spots.filter((s) => s.latitude !== null && s.longitude !== null),
)

const locatedSessions = computed(() =>
  props.sessions.filter(
    (s) => s.location && Number.isFinite(s.location.latitude) && Number.isFinite(s.location.longitude),
  ),
)

const pinHtml = (color: string, ring: boolean) => `
  <span style="
    display:block;width:18px;height:18px;border-radius:9999px;
    background:${color};border:3px solid #fff;
    box-shadow:0 0 0 ${ring ? '6px' : '2px'} ${color}${ring ? '40' : '33'}, 0 2px 6px rgba(0,0,0,.35);
  "></span>`

const spotIcon = (difficulty?: string, safe?: boolean) =>
  L.divIcon({
    className: 'siasurf-pin',
    html: pinHtml(DIFFICULTY_COLORS[difficulty ?? ''] ?? '#0284c7', Boolean(safe)),
    iconSize: [18, 18],
    iconAnchor: [9, 9],
    popupAnchor: [0, -12],
  })

const instructorIcon = (fresh: boolean) =>
  L.divIcon({
    className: 'siasurf-live-pin',
    html: `
      <span style="position:relative;display:block;width:22px;height:22px;">
        ${fresh ? '<span style="position:absolute;inset:-8px;border-radius:9999px;background:#0ea5e9;opacity:.28;animation:siasurf-pulse 1.8s ease-out infinite;"></span>' : ''}
        <span style="
          position:absolute;inset:0;border-radius:9999px;background:${fresh ? '#0ea5e9' : '#94a3b8'};
          border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.4);
        "></span>
      </span>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
    popupAnchor: [0, -14],
  })

const escapeHtml = (value: unknown): string =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const spotPopup = (spot: MapSpot): string => `
  <div style="min-width:190px;font-family:inherit">
    <div style="font-weight:700;font-size:13px;margin-bottom:2px">${escapeHtml(spot.name)}</div>
    <div style="font-size:11px;color:#64748b;margin-bottom:6px">${escapeHtml(spot.area ?? 'Siargao Island')}</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;font-size:10px;text-transform:uppercase;letter-spacing:.04em">
      <span style="background:#f1f5f9;border-radius:9999px;padding:2px 7px;color:#334155">${escapeHtml(spot.difficulty ?? 'n/a')}</span>
      ${spot.is_safe_zone ? '<span style="background:#d1fae5;border-radius:9999px;padding:2px 7px;color:#065f46">safe zone</span>' : ''}
    </div>
    ${spot.hazard_notes ? `<div style="font-size:11px;color:#b45309;margin-top:6px">${escapeHtml(spot.hazard_notes)}</div>` : ''}
  </div>`

const sessionPopup = (session: MapSession): string => {
  const age = session.location?.age_seconds
  const distance = session.distance_from_spot_m
  return `
    <div style="min-width:200px;font-family:inherit">
      <div style="font-weight:700;font-size:13px">${escapeHtml(session.instructor?.name ?? 'Instructor')}</div>
      <div style="font-size:11px;color:#64748b;margin-bottom:6px">${escapeHtml(session.booking_reference ?? '')}</div>
      <div style="font-size:12px;color:#0f172a">${escapeHtml(session.spot?.label ?? session.spot?.name ?? 'No break set')}</div>
      <div style="font-size:11px;margin-top:6px;color:${session.on_site ? '#047857' : '#b45309'}">
        ${distance !== null && distance !== undefined ? `${Math.round(distance)} m from the break` : 'Distance unavailable'}
        ${session.on_site ? ' · on site' : ''}
      </div>
      ${age !== undefined && age !== null ? `<div style="font-size:10px;color:#94a3b8;margin-top:4px">updated ${age}s ago</div>` : ''}
    </div>`
}

const renderSpots = () => {
  if (!map.value || !spotLayer.value) return
  spotLayer.value.clearLayers()

  validSpots.value.forEach((spot) => {
    L.marker([Number(spot.latitude), Number(spot.longitude)], {
      icon: spotIcon(spot.difficulty, spot.is_safe_zone),
      title: spot.label ?? spot.name,
    })
      .bindPopup(spotPopup(spot))
      .addTo(spotLayer.value as L.LayerGroup)
  })
}

const renderSessions = () => {
  if (!map.value || !sessionLayer.value) return
  sessionLayer.value.clearLayers()

  locatedSessions.value.forEach((session) => {
    const loc = session.location as MapLocation
    const fresh = loc.is_fresh !== false

    L.marker([loc.latitude, loc.longitude], {
      icon: instructorIcon(fresh),
      zIndexOffset: 1000,
      title: session.instructor?.name ?? 'Instructor',
    })
      .bindPopup(sessionPopup(session))
      .addTo(sessionLayer.value as L.LayerGroup)
  })
}

const renderPicked = () => {
  if (!map.value) return

  if (pickedMarker.value) {
    pickedMarker.value.remove()
    pickedMarker.value = null
  }

  if (!props.picked) return

  pickedMarker.value = L.marker([props.picked.latitude, props.picked.longitude], {
    icon: L.divIcon({
      className: 'siasurf-picked-pin',
      html: pinHtml('#7c3aed', true),
      iconSize: [18, 18],
      iconAnchor: [9, 9],
    }),
    zIndexOffset: 2000,
  }).addTo(map.value)
}

const fitToData = (force = false) => {
  if (!map.value) return

  const points: Array<[number, number]> = [
    ...validSpots.value.map((s) => [Number(s.latitude), Number(s.longitude)] as [number, number]),
    ...locatedSessions.value.map(
      (s) => [Number(s.location!.latitude), Number(s.location!.longitude)] as [number, number],
    ),
  ]

  if (props.focusBookingId !== null) {
    const focus = locatedSessions.value.find((s) => s.booking_id === props.focusBookingId)
    if (focus?.location) {
      map.value.setView([focus.location.latitude, focus.location.longitude], 14)
      return
    }
  }

  if (points.length === 0) return

  if (points.length === 1) {
    map.value.setView(points[0], 14)
    return
  }

  if (force || !hasFitOnce.value) {
    map.value.fitBounds(L.latLngBounds(points).pad(0.18))
    hasFitOnce.value = true
  }
}

const init = async () => {
  await nextTick()
  if (!container.value || map.value) return

  const center: [number, number] = props.center
    ? [props.center.latitude, props.center.longitude]
    : DEFAULT_CENTER

  map.value = L.map(container.value, {
    center,
    zoom: props.zoom,
    zoomControl: true,
    scrollWheelZoom: false,
    attributionControl: true,
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(map.value)

  spotLayer.value = L.layerGroup().addTo(map.value)
  sessionLayer.value = L.layerGroup().addTo(map.value)

  if (props.editable) {
    map.value.on('click', (event: L.LeafletMouseEvent) => {
      emit('pick', {
        latitude: Number(event.latlng.lat.toFixed(7)),
        longitude: Number(event.latlng.lng.toFixed(7)),
      })
    })
  }

  renderSpots()
  renderSessions()
  renderPicked()
  fitToData(true)

  // The container may have been laid out after Leaflet measured it.
  setTimeout(() => map.value?.invalidateSize(), 200)
}

watch(validSpots, () => {
  renderSpots()
  fitToData()
}, { deep: true })

watch(locatedSessions, () => {
  renderSessions()
  fitToData()
}, { deep: true })

watch(() => props.picked, renderPicked, { deep: true })
watch(() => props.focusBookingId, () => fitToData(true))

onMounted(init)

onBeforeUnmount(() => {
  map.value?.remove()
  map.value = null
  spotLayer.value = null
  sessionLayer.value = null
  pickedMarker.value = null
})
</script>

<template>
  <div class="relative isolate">
    <div
      ref="container"
      :style="{ height }"
      :class="['w-full overflow-hidden rounded-2xl ring-1 ring-slate-900/10', className]"
      role="application"
      aria-label="Siargao surf map"
    />

    <div class="pointer-events-none absolute bottom-3 left-3 z-[400] flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wider">
      <span class="rounded-full bg-white/90 px-2.5 py-1 text-emerald-700 shadow-sm ring-1 ring-emerald-200">Beginner</span>
      <span class="rounded-full bg-white/90 px-2.5 py-1 text-amber-700 shadow-sm ring-1 ring-amber-200">Intermediate</span>
      <span class="rounded-full bg-white/90 px-2.5 py-1 text-rose-700 shadow-sm ring-1 ring-rose-200">Advanced</span>
      <span class="rounded-full bg-white/90 px-2.5 py-1 text-sky-700 shadow-sm ring-1 ring-sky-200">Live instructor</span>
    </div>
  </div>
</template>

<style>
/* Scoped styles cannot reach Leaflet's DOM, so these are global by design. */
@keyframes siasurf-pulse {
  0% { transform: scale(0.7); opacity: 0.5; }
  70% { transform: scale(1.6); opacity: 0; }
  100% { transform: scale(1.6); opacity: 0; }
}

.siasurf-pin,
.siasurf-live-pin,
.siasurf-picked-pin {
  background: transparent;
  border: 0;
}

.leaflet-container {
  font-family: inherit;
  background: #e2e8f0;
}

.leaflet-popup-content-wrapper {
  border-radius: 0.9rem;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.18);
}

.leaflet-control-attribution {
  font-size: 9px;
}
</style>
