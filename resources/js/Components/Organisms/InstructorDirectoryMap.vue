<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

export interface DirectoryInstructor {
  id: number
  name: string
  area?: string | null
  meeting_point?: string | null
  latitude: number | null
  longitude: number | null
  level?: number | null
  level_label?: string | null
  rate_per_hour?: number | null
  average_rating?: number | null
  total_reviews?: number | null
  profile_url?: string
  avatar_url?: string | null
  verification_label?: string | null
}

interface Props {
  instructors: DirectoryInstructor[]
  selectedId?: number | null
  center?: { latitude: number; longitude: number; zoom?: number } | null
  zoom?: number
  height?: string
}

const props = withDefaults(defineProps<Props>(), {
  selectedId: null,
  center: null,
  zoom: 11,
  height: '480px',
})

const emit = defineEmits<{
  (e: 'select', id: number): void
}>()

const DEFAULT_CENTER: [number, number] = [9.795, 126.155]
const container = ref<HTMLDivElement | null>(null)
const map = shallowRef<L.Map | null>(null)
const markerLayer = shallowRef<L.LayerGroup | null>(null)
const markers = new Map<number, L.Marker>()
const hasFitted = ref(false)

const validInstructors = computed(() =>
  props.instructors.filter(
    (instructor) =>
      instructor.latitude !== null &&
      instructor.longitude !== null &&
      Number.isFinite(instructor.latitude) &&
      Number.isFinite(instructor.longitude),
  ),
)

const escapeHtml = (value: unknown): string =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const initials = (name: string) =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

const pinIcon = (name: string) =>
  L.divIcon({
    className: 'siasurf-directory-pin',
    html: `
      <span style="
        display:flex;align-items:center;justify-content:center;
        width:34px;height:34px;border-radius:9999px;
        background:#0f766e;color:#fff;border:3px solid #fff;
        box-shadow:0 3px 10px rgba(15,23,42,.35);
        font-size:11px;font-weight:800;letter-spacing:.02em;
        font-family:inherit;
      ">${escapeHtml(initials(name))}</span>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
    popupAnchor: [0, -20],
  })

const popupHtml = (instructor: DirectoryInstructor): string => {
  const area = instructor.area ?? 'Siargao Island'
  const level = instructor.level_label ?? (instructor.level ? `Level ${instructor.level}` : 'Surf instructor')
  const rate = instructor.rate_per_hour ? `₱${Number(instructor.rate_per_hour).toLocaleString()}/hour` : ''
  const rating = instructor.average_rating
    ? `${Number(instructor.average_rating).toFixed(1)} ★${instructor.total_reviews ? ` (${instructor.total_reviews})` : ''}`
    : 'New on SiaSurf'
  const profileUrl = instructor.profile_url ?? `/instructors/${instructor.id}`

  return `
    <div style="min-width:220px;font-family:inherit">
      <div style="font-weight:800;font-size:14px;color:#0f172a">${escapeHtml(instructor.name)}</div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">${escapeHtml(area)}${instructor.meeting_point ? ` · ${escapeHtml(instructor.meeting_point)}` : ''}</div>
      <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;font-size:10px;font-weight:700">
        <span style="background:#ccfbf1;color:#115e59;border-radius:9999px;padding:2px 7px">${escapeHtml(level)}</span>
        <span style="background:#f1f5f9;color:#334155;border-radius:9999px;padding:2px 7px">${escapeHtml(rating)}</span>
        ${rate ? `<span style="background:#fef3c7;color:#92400e;border-radius:9999px;padding:2px 7px">${escapeHtml(rate)}</span>` : ''}
      </div>
      <a href="${escapeHtml(profileUrl)}" style="display:block;margin-top:10px;font-size:11px;font-weight:800;color:#0f766e;text-decoration:none">
        View profile &amp; availability →
      </a>
    </div>`
}

const rebuildMarkers = async () => {
  if (!map.value || !markerLayer.value) return

  hasFitted.value = false
  markerLayer.value.clearLayers()
  markers.clear()

  props.instructors.forEach((instructor) => {
    const latitude = instructor.latitude
    const longitude = instructor.longitude

    if (latitude === null || longitude === null) return
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return

    const marker = L.marker([latitude, longitude], {
      icon: pinIcon(instructor.name),
      title: instructor.name,
    })

    marker.bindPopup(popupHtml(instructor))
    marker.on('click', () => emit('select', instructor.id))

    marker.addTo(markerLayer.value as L.LayerGroup)
    markers.set(instructor.id, marker)
  })

  await nextTick()

  if (!hasFitted.value && validInstructors.value.length) {
    hasFitted.value = true
    const bounds = L.latLngBounds(
      validInstructors.value.map((instructor) => [instructor.latitude as number, instructor.longitude as number]),
    )

    if (validInstructors.value.length === 1) {
      map.value.setView(bounds.getCenter(), 13)
    } else {
      map.value.fitBounds(bounds.pad(0.18), { maxZoom: 13 })
    }
  }

  if (props.selectedId) {
    const selected = markers.get(props.selectedId)
    if (selected) {
      map.value.panTo(selected.getLatLng())
      selected.openPopup()
    }
  }
}

onMounted(async () => {
  await nextTick()
  if (!container.value) return

  const center = props.center ?? { latitude: DEFAULT_CENTER[0], longitude: DEFAULT_CENTER[1] }
  map.value = L.map(container.value, {
    zoomControl: true,
    attributionControl: true,
  }).setView([center.latitude, center.longitude], props.center?.zoom ?? props.zoom)

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map.value)

  markerLayer.value = L.layerGroup().addTo(map.value)
  await rebuildMarkers()

  window.setTimeout(() => map.value?.invalidateSize(), 80)
})

watch(() => props.instructors, rebuildMarkers, { deep: true })

watch(
  () => props.selectedId,
  (id) => {
    if (!id || !map.value) return
    const marker = markers.get(id)
    if (!marker) return
    map.value.panTo(marker.getLatLng())
    marker.openPopup()
  },
)

onBeforeUnmount(() => {
  map.value?.remove()
  map.value = null
  markerLayer.value = null
  markers.clear()
})
</script>

<template>
  <div class="relative overflow-hidden rounded-card border border-sand-200 bg-sand-100 shadow-card">
    <div ref="container" :style="{ height }" class="w-full" aria-label="Map of surf instructors" />

    <div
      v-if="validInstructors.length === 0"
      class="pointer-events-none absolute inset-0 flex items-center justify-center bg-sand-100/80 p-6 text-center"
    >
      <div>
        <p class="text-body font-semibold text-deep-900">No instructors on the map yet</p>
        <p class="mt-1 text-body-sm text-stone-600">
          Instructors appear here after they add teaching coordinates.
        </p>
      </div>
    </div>

    <div class="pointer-events-none absolute bottom-3 left-3 rounded-full bg-white/90 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-stone-600 shadow-sm backdrop-blur">
      {{ validInstructors.length }} mapped
    </div>
  </div>
</template>
