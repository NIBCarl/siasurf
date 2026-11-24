<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import {
  CheckCircleIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
  MapPinIcon,
  PhotoIcon,
  ShieldCheckIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import SurfSpotGallery from '@/Components/Molecules/SurfSpotGallery.vue'

export interface SurfSpotImage {
  url: string
  caption?: string | null
  accuracy?: 'exact_spot' | 'nearby_coastline' | 'satellite_reference' | string
  width?: number | null
  height?: number | null
}

export interface SurfZoneSpot {
  id: number
  name: string
  area?: string | null
  barangay?: string | null
  side?: string | null
  side_label?: string | null
  section?: string | null
  section_label?: string | null
  difficulty?: string | null
  break_type?: string | null
  best_tide?: string | null
  best_season?: string | null
  access?: string | null
  description?: string | null
  hazard_notes?: string | null
  latitude: number | string | null
  longitude: number | string | null
  is_safe_zone?: boolean
  images?: SurfSpotImage[]
}

interface Props {
  spots: SurfZoneSpot[]
  mode?: 'multi' | 'single' | 'view'
  modelValue?: number[] | number | string | null
  height?: string
  emptyMessage?: string
  showGallery?: boolean
  locked?: boolean
  stacked?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  mode: 'multi',
  modelValue: null,
  height: '380px',
  emptyMessage: 'No surf zones match your current filter.',
  showGallery: false,
  locked: false,
  stacked: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: any): void
  (e: 'select', spot: SurfZoneSpot): void
}>()

const DEFAULT_CENTER: [number, number] = [9.815, 126.155]

const container = ref<HTMLDivElement | null>(null)
const map = shallowRef<L.Map | null>(null)
const markerLayer = shallowRef<L.LayerGroup | null>(null)
const markers = new Map<number, L.Marker>()
const markerCoords = new Map<number, [number, number]>()
const hasFitOnce = ref(false)

const search = ref('')
const difficultyFilter = ref<'all' | 'beginner' | 'intermediate' | 'advanced'>('all')
const failedThumbUrls = ref<Record<string, true>>({})
const showAllSelectedChips = ref(false)

const markThumbFailed = (url?: string | null) => {
  if (!url) return
  failedThumbUrls.value = { ...failedThumbUrls.value, [url]: true }
}

const DIFFICULTY_COLORS: Record<string, string> = {
  beginner: '#10b981',
  intermediate: '#f59e0b',
  advanced: '#f43f5e',
}

const DIFFICULTY_BADGE_CLASSES: Record<string, string> = {
  beginner: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  intermediate: 'bg-amber-50 text-amber-800 ring-amber-200',
  advanced: 'bg-rose-50 text-rose-700 ring-rose-200',
}

const selectedIds = computed<number[]>(() => {
  if (props.mode === 'multi') {
    return Array.isArray(props.modelValue)
      ? props.modelValue.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
      : []
  }

  if (props.mode === 'single') {
    if (
      props.modelValue !== null
      && props.modelValue !== undefined
      && props.modelValue !== ''
      && !Array.isArray(props.modelValue)
    ) {
      const id = Number(props.modelValue)
      return Number.isFinite(id) && id > 0 ? [id] : []
    }
  }

  return []
})

const selectedSet = computed(() => new Set(selectedIds.value))

const isSelected = (spotId: number): boolean => selectedSet.value.has(spotId)

const availableDifficulties = computed(() => {
  const present = new Set(
    props.spots
      .map((s) => (s.difficulty ?? '').toLowerCase())
      .filter((d) => ['beginner', 'intermediate', 'advanced'].includes(d)),
  )

  return (['beginner', 'intermediate', 'advanced'] as const).filter((d) => present.has(d))
})

const validSpots = computed(() =>
  props.spots.filter((spot) => {
    const lat = Number(spot.latitude)
    const lng = Number(spot.longitude)
    return spot.latitude !== null && spot.longitude !== null && Number.isFinite(lat) && Number.isFinite(lng)
  }),
)

/**
 * Fan out coincident or near-coincident coordinates (e.g. Inner/Outer breaks
 * sharing one pin) radially by ~60 meters so every spot is individually clickable.
 */
const displayCoordinates = computed(() => {
  const result = new Map<number, [number, number]>()
  const groups: Array<{ anchorLat: number; anchorLng: number; spots: SurfZoneSpot[] }> = []
  const threshold = 0.00025

  validSpots.value.forEach((spot) => {
    const lat = Number(spot.latitude)
    const lng = Number(spot.longitude)
    const existing = groups.find(
      (g) => Math.abs(g.anchorLat - lat) <= threshold && Math.abs(g.anchorLng - lng) <= threshold,
    )

    if (existing) {
      existing.spots.push(spot)
    } else {
      groups.push({ anchorLat: lat, anchorLng: lng, spots: [spot] })
    }
  })

  groups.forEach((group) => {
    if (group.spots.length === 1) {
      const only = group.spots[0]
      result.set(only.id, [Number(only.latitude), Number(only.longitude)])
      return
    }

    const radius = 0.00055
    group.spots.forEach((spot, index) => {
      const angle = (2 * Math.PI * index) / group.spots.length - Math.PI / 2
      const lat = Number(spot.latitude) + radius * Math.sin(angle)
      const lng = Number(spot.longitude) + radius * Math.cos(angle)
      result.set(spot.id, [lat, lng])
    })
  })

  return result
})

const filteredSpots = computed(() => {
  const q = search.value.trim().toLowerCase()

  return props.spots.filter((spot) => {
    const diffOk =
      difficultyFilter.value === 'all'
      || (spot.difficulty ?? '').toLowerCase() === difficultyFilter.value

    if (!diffOk) return false
    if (!q) return true

    const haystack = [spot.name, spot.area, spot.barangay, spot.side_label, spot.difficulty]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(q)
  })
})

const selectedSpots = computed(() =>
  props.spots.filter((spot) => selectedSet.value.has(spot.id)),
)

const singleSelectedSpot = computed(() =>
  props.mode === 'single' ? selectedSpots.value[0] ?? null : null,
)

const escapeHtml = (value: unknown): string =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')

const buildPinIcon = (spot: SurfZoneSpot, selected: boolean) => {
  const color = DIFFICULTY_COLORS[(spot.difficulty ?? '').toLowerCase()] ?? '#0284c7'

  if (selected && props.mode !== 'view') {
    return L.divIcon({
      className: 'siasurf-zone-pin',
      html: `
        <span style="
          display:flex;align-items:center;justify-content:center;
          width:28px;height:28px;border-radius:9999px;
          background:#0f766e;color:#fff;border:3px solid #fff;
          box-shadow:0 0 0 4px rgba(15,118,110,0.28), 0 4px 12px rgba(15,23,42,0.35);
          font-size:13px;font-weight:900;line-height:1;
          transition:transform 150ms ease;
        ">✓</span>`,
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      popupAnchor: [0, -16],
    })
  }

  return L.divIcon({
    className: 'siasurf-zone-pin',
    html: `
      <span style="
        display:block;width:18px;height:18px;border-radius:9999px;
        background:${color};border:3px solid #fff;
        box-shadow:0 0 0 2px ${color}33, 0 2px 6px rgba(15,23,42,0.35);
        transition:transform 150ms ease;
      "></span>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
    popupAnchor: [0, -12],
  })
}

const buildTooltipText = (spot: SurfZoneSpot, selected: boolean): string => {
  const diff = spot.difficulty ? ` · ${spot.difficulty}` : ''
  const state = props.mode === 'multi'
    ? (selected ? ' (Selected — click to remove)' : ' (Click to select)')
    : props.mode === 'single'
      ? (selected ? ' (Selected)' : ' (Click to choose)')
      : ''

  return `${spot.name}${diff}${state}`
}

const buildPopupHtml = (spot: SurfZoneSpot, selected: boolean): string => {
  const diff = (spot.difficulty ?? 'n/a').toUpperCase()
  const area = [spot.barangay, spot.area].filter(Boolean).join(', ') || 'Siargao Island'
  const coverUrl = props.showGallery ? spot.images?.[0]?.url : null
  const coverHtml = coverUrl
    ? `<div style="margin:0 0 8px 0;overflow:hidden;border-radius:8px;background:#f2eade"><img src="${escapeHtml(coverUrl)}" alt="${escapeHtml(spot.name)}" style="display:block;width:100%;height:108px;object-fit:cover" loading="lazy" /></div>`
    : ''
  const statusLine = props.mode === 'multi'
    ? `<div style="margin-top:8px;font-size:11px;font-weight:700;color:${selected ? '#0f766e' : '#475569'}">${selected ? '✓ Included in your surf zones' : 'Click pin to include this zone'}</div>`
    : props.mode === 'single'
      ? `<div style="margin-top:8px;font-size:11px;font-weight:700;color:${selected ? '#0f766e' : '#475569'}">${selected ? '✓ Selected surf spot' : 'Click pin to choose this spot'}</div>`
      : ''

  return `
    <div style="min-width:190px;max-width:230px;font-family:inherit">
      ${coverHtml}
      <div style="font-weight:800;font-size:13px;color:#0f172a">${escapeHtml(spot.name)}</div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">${escapeHtml(area)}</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">
        <span style="background:#f1f5f9;border-radius:9999px;padding:2px 7px;color:#334155">${escapeHtml(diff)}</span>
        ${spot.is_safe_zone ? '<span style="background:#d1fae5;border-radius:9999px;padding:2px 7px;color:#065f46">Safe zone</span>' : ''}
      </div>
      ${spot.best_tide ? `<div style="font-size:11px;color:#475569;margin-top:6px"><strong>Best tide:</strong> ${escapeHtml(spot.best_tide)}</div>` : ''}
      ${spot.hazard_notes ? `<div style="font-size:11px;color:#b45309;margin-top:4px">${escapeHtml(spot.hazard_notes)}</div>` : ''}
      ${statusLine}
    </div>`
}

const toggleOrSelectSpot = (spot: SurfZoneSpot, panToMarker = false) => {
  if (!props.locked) {
    if (props.mode === 'multi') {
      const next = new Set(selectedIds.value)
      if (next.has(spot.id)) {
        next.delete(spot.id)
      } else {
        next.add(spot.id)
      }
      emit('update:modelValue', Array.from(next))
    } else if (props.mode === 'single') {
      emit('update:modelValue', spot.id)
    }

    emit('select', spot)
  }

  if (panToMarker && map.value) {
    const coords = markerCoords.get(spot.id)
    const marker = markers.get(spot.id)
    if (coords) {
      map.value.setView(coords, Math.max(map.value.getZoom(), 14), { animate: true })
    }
    if (marker && props.mode !== 'multi') {
      marker.openPopup()
    }
  }
}

const removeSpot = (spotId: number) => {
  if (props.mode !== 'multi') return
  emit('update:modelValue', selectedIds.value.filter((id) => id !== spotId))
}

const clearAll = () => {
  if (props.mode !== 'multi') return
  emit('update:modelValue', [])
}

const rebuildMarkers = () => {
  if (!map.value || !markerLayer.value) return

  markerLayer.value.clearLayers()
  markers.clear()
  markerCoords.clear()

  const coordsMap = displayCoordinates.value

  filteredSpots.value.forEach((spot) => {
    const coords = coordsMap.get(spot.id)
    if (!coords) return

    const selected = isSelected(spot.id)
    const marker = L.marker(coords, {
      icon: buildPinIcon(spot, selected),
      title: spot.name,
      zIndexOffset: selected && props.mode !== 'view' ? 1000 : 0,
    })

    marker.bindTooltip(buildTooltipText(spot, selected), {
      direction: 'top',
      offset: [0, -10],
    })

    if (props.mode !== 'multi') {
      marker.bindPopup(buildPopupHtml(spot, selected))
    }

    marker.on('click', () => {
      toggleOrSelectSpot(spot, false)
    })

    marker.addTo(markerLayer.value as L.LayerGroup)
    markers.set(spot.id, marker)
    markerCoords.set(spot.id, coords)
  })
}

const updateSelectionVisualsInPlace = () => {
  filteredSpots.value.forEach((spot) => {
    const marker = markers.get(spot.id)
    if (!marker) return

    const selected = isSelected(spot.id)
    marker.setIcon(buildPinIcon(spot, selected))
    marker.setZIndexOffset(selected && props.mode !== 'view' ? 1000 : 0)
    marker.setTooltipContent(buildTooltipText(spot, selected))

    if (props.mode !== 'multi') {
      marker.setPopupContent(buildPopupHtml(spot, selected))
    }
  })
}

const fitAllZones = (force = false) => {
  if (!map.value) return

  const points = Array.from(markerCoords.values())
  if (points.length === 0) {
    if (force) {
      map.value.setView(DEFAULT_CENTER, 11)
    }
    return
  }

  if (points.length === 1) {
    map.value.setView(points[0], 14)
    hasFitOnce.value = true
    return
  }

  if (force || !hasFitOnce.value) {
    map.value.fitBounds(L.latLngBounds(points).pad(0.18), { maxZoom: 14 })
    hasFitOnce.value = true
  }
}

let resizeObserver: ResizeObserver | null = null

const initMap = async () => {
  await nextTick()
  if (!container.value || map.value) return

  map.value = L.map(container.value, {
    center: DEFAULT_CENTER,
    zoom: 11,
    zoomControl: true,
    scrollWheelZoom: false,
    attributionControl: true,
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(map.value)

  markerLayer.value = L.layerGroup().addTo(map.value)

  rebuildMarkers()
  fitAllZones(true)

  window.setTimeout(() => map.value?.invalidateSize(), 180)

  if (typeof ResizeObserver !== 'undefined' && container.value) {
    resizeObserver = new ResizeObserver(() => {
      map.value?.invalidateSize()
    })
    resizeObserver.observe(container.value)
  }
}

watch(
  () => props.spots,
  () => {
    if (!availableDifficulties.value.includes(difficultyFilter.value as any)) {
      difficultyFilter.value = 'all'
    }
    rebuildMarkers()
    fitAllZones(true)
  },
  { deep: true },
)

watch(
  [search, difficultyFilter],
  () => {
    rebuildMarkers()
  },
)

watch(
  () => props.modelValue,
  () => {
    updateSelectionVisualsInPlace()
  },
  { deep: true },
)

onMounted(initMap)

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  resizeObserver = null
  map.value?.remove()
  map.value = null
  markerLayer.value = null
  markers.clear()
  markerCoords.clear()
})
</script>

<template>
  <div class="space-y-3">
    <div
      :class="stacked ? 'grid grid-cols-1 gap-4' : 'grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]'"
    >
      <!-- Interactive Leaflet Map -->
      <div class="relative isolate min-w-0 overflow-hidden rounded-2xl border border-sand-200 bg-sand-100 shadow-sm">
        <div
          ref="container"
          :style="{ height }"
          class="w-full"
          role="application"
          aria-label="Interactive Siargao surf zones map"
        />

        <!-- Fit all button -->
        <button
          type="button"
          class="absolute right-3 top-3 z-[400] rounded-full bg-white/95 px-3 py-1.5 text-[11px] font-bold text-deep-900 shadow-sm ring-1 ring-slate-900/10 backdrop-blur transition hover:bg-white"
          @click="fitAllZones(true)"
        >
          Fit all zones
        </button>

        <!-- Difficulty legend -->
        <div
          v-if="availableDifficulties.length > 0"
          class="pointer-events-none absolute bottom-3 left-3 z-[400] flex flex-wrap gap-1.5 text-[10px] font-bold uppercase tracking-wider"
        >
          <span
            v-if="availableDifficulties.includes('beginner')"
            class="inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-emerald-700 shadow-sm ring-1 ring-emerald-200"
          >
            <span class="h-2 w-2 rounded-full bg-emerald-500" />
            Beginner
          </span>
          <span
            v-if="availableDifficulties.includes('intermediate')"
            class="inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-amber-800 shadow-sm ring-1 ring-amber-200"
          >
            <span class="h-2 w-2 rounded-full bg-amber-500" />
            Intermediate
          </span>
          <span
            v-if="availableDifficulties.includes('advanced')"
            class="inline-flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-rose-700 shadow-sm ring-1 ring-rose-200"
          >
            <span class="h-2 w-2 rounded-full bg-rose-500" />
            Advanced
          </span>
        </div>

        <div
          v-if="validSpots.length === 0"
          class="pointer-events-none absolute inset-0 z-[400] flex items-center justify-center bg-sand-100/85 p-6 text-center"
        >
          <p class="max-w-xs text-body-sm font-medium text-stone-600">
            {{ emptyMessage }}
          </p>
        </div>
      </div>

      <!-- Paired Spot Search & Selection List -->
      <div class="flex min-w-0 flex-col rounded-2xl border border-sand-200 bg-white p-3.5 shadow-sm" :style="{ maxHeight: height }">
        <div class="space-y-2.5 border-b border-sand-100 pb-3">
          <div class="flex items-center justify-between gap-2">
            <span class="text-xs font-bold uppercase tracking-wider text-stone-600">
              <template v-if="mode === 'multi'">
                {{ selectedSpots.length }} zone{{ selectedSpots.length === 1 ? '' : 's' }} selected
              </template>
              <template v-else-if="mode === 'single'">
                {{ locked ? 'Surf spot locked for shared session' : (singleSelectedSpot ? '1 spot chosen' : 'Choose 1 surf spot') }}
              </template>
              <template v-else>
                {{ spots.length }} surf zone{{ spots.length === 1 ? '' : 's' }}
              </template>
            </span>

            <button
              v-if="mode === 'multi' && selectedSpots.length > 0"
              type="button"
              class="text-[11px] font-semibold text-rose-600 hover:text-rose-800"
              @click="clearAll"
            >
              Clear all
            </button>
          </div>

          <div class="relative">
            <MagnifyingGlassIcon
              class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-stone-400"
              aria-hidden="true"
            />
            <input
              v-model="search"
              type="search"
              placeholder="Filter surf spots or municipality…"
              class="h-9 w-full rounded-lg border border-sand-200 bg-sand-50/50 pl-8 pr-3 text-xs text-deep-900 placeholder:text-stone-400 focus:border-lagoon-600 focus:bg-white focus:ring-lagoon-600"
            />
          </div>

          <div v-if="availableDifficulties.length > 1" class="flex flex-wrap gap-1.5">
            <button
              type="button"
              class="rounded-full px-2.5 py-1 text-[11px] font-semibold transition"
              :class="difficultyFilter === 'all' ? 'bg-deep-900 text-white' : 'bg-sand-100 text-stone-600 hover:bg-sand-200'"
              @click="difficultyFilter = 'all'"
            >
              All ({{ spots.length }})
            </button>
            <button
              v-for="diff in availableDifficulties"
              :key="diff"
              type="button"
              class="rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize transition"
              :class="difficultyFilter === diff ? 'bg-lagoon-600 text-white' : 'bg-sand-100 text-stone-600 hover:bg-sand-200'"
              @click="difficultyFilter = diff"
            >
              {{ diff }}
            </button>
          </div>
        </div>

        <div class="mt-2.5 flex-1 space-y-1.5 overflow-y-auto pr-1">
          <button
            v-for="spot in filteredSpots"
            :key="spot.id"
            type="button"
            class="flex w-full items-start justify-between gap-2.5 rounded-xl border p-2.5 text-left transition"
            :class="
              isSelected(spot.id) && mode !== 'view'
                ? 'border-lagoon-600 bg-lagoon-50/70 ring-1 ring-lagoon-600/30'
                : 'border-sand-200 bg-white hover:border-lagoon-400 hover:bg-sand-50/50'
            "
            @click="toggleOrSelectSpot(spot, true)"
          >
            <img
              v-if="showGallery && spot.images?.[0]?.url && !failedThumbUrls[spot.images[0].url]"
              :src="spot.images?.[0]?.url"
              :alt="spot.images?.[0]?.caption || spot.name"
              class="h-11 w-11 shrink-0 rounded-lg bg-sand-100 object-cover ring-1 ring-sand-200"
              loading="lazy"
              @error="markThumbFailed(spot.images?.[0]?.url)"
            />

            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-1.5">
                <MapPinIcon
                  class="h-3.5 w-3.5 shrink-0"
                  :class="isSelected(spot.id) && mode !== 'view' ? 'text-lagoon-700' : 'text-stone-400'"
                  aria-hidden="true"
                />
                <span class="truncate text-xs font-bold text-deep-900">{{ spot.name }}</span>
              </div>
              <p class="mt-0.5 truncate pl-5 text-[11px] text-stone-500">
                {{ spot.barangay ? `${spot.barangay}, ` : '' }}{{ spot.area ?? 'Siargao Island' }}
              </p>
              <p
                v-if="showGallery && (spot.images?.length ?? 0) > 0"
                class="mt-0.5 flex items-center gap-1 pl-5 text-[10px] font-medium text-lagoon-700"
              >
                <PhotoIcon class="h-3 w-3 shrink-0" aria-hidden="true" />
                <span>{{ spot.images!.length }} {{ spot.images!.length === 1 ? 'photo' : 'photos' }}</span>
              </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
              <span
                v-if="spot.difficulty"
                class="rounded-full px-2 py-0.5 text-[10px] font-bold capitalize ring-1"
                :class="DIFFICULTY_BADGE_CLASSES[(spot.difficulty ?? '').toLowerCase()] ?? 'bg-slate-100 text-slate-700 ring-slate-200'"
              >
                {{ spot.difficulty }}
              </span>
              <CheckCircleIcon
                v-if="isSelected(spot.id) && mode !== 'view'"
                class="h-4 w-4 text-lagoon-700"
                aria-hidden="true"
              />
            </div>
          </button>

          <p v-if="filteredSpots.length === 0" class="py-6 text-center text-xs text-stone-500">
            {{ emptyMessage }}
          </p>
        </div>
      </div>
    </div>

    <!-- Selected chips (multi mode) -->
    <div v-if="mode === 'multi' && selectedSpots.length > 0" class="flex flex-wrap gap-1.5">
      <span
        v-for="spot in (selectedSpots.length > 6 && !showAllSelectedChips ? selectedSpots.slice(0, 6) : selectedSpots)"
        :key="`chip-${spot.id}`"
        class="inline-flex items-center gap-1.5 rounded-full border border-lagoon-200 bg-lagoon-50 px-3 py-1 text-xs font-semibold text-lagoon-900"
      >
        <span>{{ spot.name }}</span>
        <button
          type="button"
          class="rounded-full p-0.5 text-lagoon-700 hover:bg-lagoon-200/70"
          :aria-label="`Remove ${spot.name}`"
          @click="removeSpot(spot.id)"
        >
          <XMarkIcon class="h-3.5 w-3.5" />
        </button>
      </span>

      <button
        v-if="selectedSpots.length > 6"
        type="button"
        class="inline-flex items-center rounded-full border border-sand-300 bg-white px-3 py-1 text-xs font-bold text-deep-900 transition-colors hover:border-lagoon-600 hover:bg-sand-50"
        @click="showAllSelectedChips = !showAllSelectedChips"
      >
        {{ showAllSelectedChips ? 'Show fewer' : `+${selectedSpots.length - 6} more (Show all)` }}
      </button>
    </div>

    <!-- Selected spot photo gallery or compact details strip (single mode) -->
    <SurfSpotGallery
      v-if="mode === 'single' && singleSelectedSpot && showGallery"
      :spot="singleSelectedSpot"
    />
    <div
      v-else-if="mode === 'single' && singleSelectedSpot"
      class="rounded-xl border border-lagoon-200 bg-lagoon-50/50 p-3.5"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-xs font-bold text-deep-900">
            Selected break: {{ singleSelectedSpot.name }}
          </p>
          <p class="text-[11px] text-stone-600">
            {{ singleSelectedSpot.barangay ? `${singleSelectedSpot.barangay}, ` : '' }}{{ singleSelectedSpot.area ?? 'Siargao Island' }}
            <template v-if="singleSelectedSpot.best_tide"> · Best tide: {{ singleSelectedSpot.best_tide }}</template>
            <template v-if="singleSelectedSpot.access"> · {{ singleSelectedSpot.access }}</template>
          </p>
        </div>
        <div class="flex items-center gap-1.5">
          <span
            v-if="singleSelectedSpot.difficulty"
            class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase ring-1"
            :class="DIFFICULTY_BADGE_CLASSES[(singleSelectedSpot.difficulty ?? '').toLowerCase()] ?? 'bg-slate-100 text-slate-700 ring-slate-200'"
          >
            {{ singleSelectedSpot.difficulty }}
          </span>
          <span
            v-if="singleSelectedSpot.is_safe_zone"
            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[10px] font-bold uppercase text-emerald-800"
          >
            <ShieldCheckIcon class="h-3 w-3" />
            Safe zone
          </span>
        </div>
      </div>

      <p
        v-if="singleSelectedSpot.hazard_notes"
        class="mt-2 flex items-start gap-1.5 text-[11px] text-amber-800"
      >
        <ExclamationTriangleIcon class="mt-0.5 h-3.5 w-3.5 shrink-0" />
        {{ singleSelectedSpot.hazard_notes }}
      </p>
    </div>
  </div>
</template>

<style>
.siasurf-zone-pin {
  background: transparent;
  border: 0;
}

.siasurf-zone-pin:hover > span {
  transform: scale(1.15);
}
</style>
