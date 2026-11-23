<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import {
  ArrowsPointingOutIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  MapPinIcon,
  PhotoIcon,
  ShieldCheckIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { SurfSpotImage, SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue'

interface Props {
  spot: SurfZoneSpot
}

const props = defineProps<Props>()

const activeIndex = ref(0)
const isLightboxOpen = ref(false)
const failedUrls = ref<Record<string, true>>({})

const DIFFICULTY_BADGE_CLASSES: Record<string, string> = {
  beginner: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  intermediate: 'bg-amber-50 text-amber-800 ring-amber-200',
  advanced: 'bg-rose-50 text-rose-700 ring-rose-200',
}

const images = computed<SurfSpotImage[]>(() =>
  Array.isArray(props.spot.images) ? props.spot.images.filter((img) => Boolean(img?.url)) : [],
)

const activeImage = computed<SurfSpotImage | null>(() => {
  if (images.value.length === 0) return null
  return images.value[activeIndex.value] ?? images.value[0] ?? null
})

const spotLocation = computed(() =>
  [props.spot.barangay, props.spot.area].filter(Boolean).join(', ') || 'Siargao Island',
)

const difficultyClass = computed(
  () =>
    DIFFICULTY_BADGE_CLASSES[(props.spot.difficulty ?? '').toLowerCase()]
    ?? 'bg-slate-100 text-slate-700 ring-slate-200',
)

const accuracyBadgeLabel = (accuracy?: string | null): string | null => {
  if (accuracy === 'nearby_coastline') return 'Nearby coastline'
  if (accuracy === 'satellite_reference') return 'Satellite reference'
  return null
}

const activeAccuracyLabel = computed(() => accuracyBadgeLabel(activeImage.value?.accuracy))

const isImageFailed = (url?: string | null): boolean => Boolean(url && failedUrls.value[url])

const markImageFailed = (url?: string | null) => {
  if (!url) return
  failedUrls.value = { ...failedUrls.value, [url]: true }
}

const selectPhoto = (index: number) => {
  if (index < 0 || index >= images.value.length) return
  activeIndex.value = index
}

const prevPhoto = () => {
  if (images.value.length <= 1) return
  activeIndex.value = (activeIndex.value - 1 + images.value.length) % images.value.length
}

const nextPhoto = () => {
  if (images.value.length <= 1) return
  activeIndex.value = (activeIndex.value + 1) % images.value.length
}

const openLightbox = () => {
  if (!activeImage.value || isImageFailed(activeImage.value.url)) return
  isLightboxOpen.value = true
}

const closeLightbox = () => {
  isLightboxOpen.value = false
}

const handleKeydown = (event: KeyboardEvent) => {
  if (!isLightboxOpen.value) return

  if (event.key === 'Escape') {
    event.preventDefault()
    closeLightbox()
  } else if (event.key === 'ArrowLeft' && images.value.length > 1) {
    event.preventDefault()
    prevPhoto()
  } else if (event.key === 'ArrowRight' && images.value.length > 1) {
    event.preventDefault()
    nextPhoto()
  }
}

let isKeyListenerAttached = false
let previousBodyOverflow: string | null = null

const attachLightboxEffects = () => {
  if (typeof window === 'undefined' || isKeyListenerAttached) return
  window.addEventListener('keydown', handleKeydown)
  isKeyListenerAttached = true
  previousBodyOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
}

const detachLightboxEffects = () => {
  if (typeof window === 'undefined' || !isKeyListenerAttached) return
  window.removeEventListener('keydown', handleKeydown)
  isKeyListenerAttached = false
  if (previousBodyOverflow !== null) {
    document.body.style.overflow = previousBodyOverflow
    previousBodyOverflow = null
  }
}

watch(isLightboxOpen, (open) => {
  if (open) {
    attachLightboxEffects()
  } else {
    detachLightboxEffects()
  }
})

watch(
  () => props.spot.id,
  () => {
    activeIndex.value = 0
    isLightboxOpen.value = false
  },
)

watch(
  () => images.value.length,
  (length) => {
    if (length === 0) {
      activeIndex.value = 0
      isLightboxOpen.value = false
    } else if (activeIndex.value >= length) {
      activeIndex.value = 0
    }
  },
)

onBeforeUnmount(() => {
  detachLightboxEffects()
})
</script>

<template>
  <section
    class="overflow-hidden rounded-2xl border border-sand-200 bg-white shadow-card"
    :aria-label="`${spot.name} photo gallery and details`"
  >
    <!-- Header -->
    <div class="border-b border-sand-100 bg-sand-50/70 px-4 py-3.5 sm:px-5">
      <div class="flex flex-wrap items-start justify-between gap-2.5">
        <div class="min-w-0">
          <div class="flex items-center gap-1.5">
            <MapPinIcon class="h-4 w-4 shrink-0 text-lagoon-700" aria-hidden="true" />
            <h4 class="truncate text-sm font-extrabold text-deep-900 sm:text-base">
              {{ spot.name }}
            </h4>
          </div>
          <p class="mt-0.5 pl-5 text-xs text-stone-500">
            {{ spotLocation }}
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
          <span
            v-if="spot.difficulty"
            class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1"
            :class="difficultyClass"
          >
            {{ spot.difficulty }}
          </span>
          <span
            v-if="spot.is_safe_zone"
            class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-emerald-200"
          >
            <ShieldCheckIcon class="h-3 w-3" aria-hidden="true" />
            Safe zone
          </span>
        </div>
      </div>

      <!-- Quick metadata -->
      <div
        v-if="spot.best_tide || spot.best_season || spot.access"
        class="mt-2.5 flex flex-wrap gap-1.5 text-[11px]"
      >
        <span
          v-if="spot.best_tide"
          class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-0.5 text-stone-600 ring-1 ring-sand-200"
        >
          <span class="font-semibold text-deep-900">Best tide:</span>
          {{ spot.best_tide }}
        </span>
        <span
          v-if="spot.best_season"
          class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-0.5 text-stone-600 ring-1 ring-sand-200"
        >
          <span class="font-semibold text-deep-900">Best season:</span>
          {{ spot.best_season }}
        </span>
        <span
          v-if="spot.access"
          class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-0.5 text-stone-600 ring-1 ring-sand-200"
        >
          <span class="font-semibold text-deep-900">Access:</span>
          {{ spot.access }}
        </span>
      </div>
    </div>

    <!-- Gallery & Spot Notes Body -->
    <div class="space-y-3.5 p-4 sm:p-5">
      <!-- Hero photo stage -->
      <div class="overflow-hidden rounded-xl border border-sand-200 bg-sand-100">
        <div class="group relative aspect-[16/10] w-full overflow-hidden bg-sand-100">
          <img
            v-if="activeImage && !isImageFailed(activeImage.url)"
            :key="activeImage.url"
            :src="activeImage.url"
            :alt="activeImage.caption || spot.name"
            class="h-full w-full cursor-zoom-in object-cover transition duration-300 group-hover:scale-[1.01]"
            @click="openLightbox"
            @error="markImageFailed(activeImage?.url)"
          />

          <!-- Fallback when empty or image fails -->
          <div
            v-else
            class="flex h-full w-full flex-col items-center justify-center gap-2 bg-gradient-to-br from-sand-50 via-sand-100 to-ocean-50/70 px-6 text-center"
          >
            <span
              class="flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-lagoon-700 shadow-sm ring-1 ring-sand-200"
            >
              <PhotoIcon class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="text-xs font-semibold text-deep-900">
              {{ images.length === 0 ? 'No photos available yet' : 'Photo could not be loaded' }}
            </p>
            <p class="max-w-xs text-[11px] text-stone-500">
              {{
                images.length === 0
                  ? `Surf spot photos for ${spot.name} are coming soon.`
                  : 'Try another photo below or pick a different spot on the map.'
              }}
            </p>
          </div>

          <!-- Subtle accuracy badge (only for nearby_coastline or satellite_reference) -->
          <span
            v-if="activeAccuracyLabel && activeImage && !isImageFailed(activeImage.url)"
            class="pointer-events-none absolute left-3 top-3 inline-flex items-center rounded-full bg-deep-950/75 px-2.5 py-1 text-[11px] font-medium text-white ring-1 ring-white/15 backdrop-blur-sm"
          >
            {{ activeAccuracyLabel }}
          </span>

          <!-- Counter & Expand button -->
          <div
            v-if="images.length > 0"
            class="absolute right-3 top-3 flex items-center gap-1.5"
          >
            <span
              class="rounded-full bg-deep-950/75 px-2.5 py-1 text-[11px] font-semibold tabular-nums text-white ring-1 ring-white/15 backdrop-blur-sm"
            >
              {{ activeIndex + 1 }} / {{ images.length }}
            </span>
            <button
              v-if="activeImage && !isImageFailed(activeImage.url)"
              type="button"
              class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-deep-950/75 text-white ring-1 ring-white/15 backdrop-blur-sm transition hover:bg-deep-900 focus:outline-none focus:ring-2 focus:ring-lagoon-400"
              aria-label="Expand photo"
              title="View full-size photo"
              @click.stop="openLightbox"
            >
              <ArrowsPointingOutIcon class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>

          <!-- Prev / Next buttons -->
          <template v-if="images.length > 1">
            <button
              type="button"
              class="absolute left-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-deep-900 shadow-sm ring-1 ring-deep-900/10 backdrop-blur-sm transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-lagoon-600"
              aria-label="Previous photo"
              @click.stop="prevPhoto"
            >
              <ChevronLeftIcon class="h-4 w-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-deep-900 shadow-sm ring-1 ring-deep-900/10 backdrop-blur-sm transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-lagoon-600"
              aria-label="Next photo"
              @click.stop="nextPhoto"
            >
              <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
            </button>
          </template>
        </div>

        <!-- Caption strip -->
        <div
          v-if="activeImage?.caption"
          class="border-t border-sand-200/80 bg-sand-50/90 px-3.5 py-2 text-xs text-stone-700"
        >
          {{ activeImage.caption }}
        </div>
      </div>

      <!-- Thumbnail strip -->
      <div
        v-if="images.length > 1"
        class="flex gap-2 overflow-x-auto pb-1 pt-0.5"
        role="tablist"
        :aria-label="`${spot.name} photos`"
      >
        <button
          v-for="(img, idx) in images"
          :key="`${spot.id}-${img.url}-${idx}`"
          type="button"
          role="tab"
          :aria-selected="idx === activeIndex"
          :aria-label="`Show photo ${idx + 1} of ${images.length}`"
          class="relative h-14 w-20 shrink-0 overflow-hidden rounded-lg bg-sand-100 transition focus:outline-none focus:ring-2 focus:ring-lagoon-600 sm:h-16 sm:w-24"
          :class="
            idx === activeIndex
              ? 'opacity-100 ring-2 ring-lagoon-600 ring-offset-2 ring-offset-white'
              : 'opacity-75 ring-1 ring-sand-200 hover:opacity-100'
          "
          @click="selectPhoto(idx)"
        >
          <img
            v-if="!isImageFailed(img.url)"
            :src="img.url"
            :alt="img.caption || `${spot.name} photo ${idx + 1}`"
            class="h-full w-full object-cover"
            loading="lazy"
            @error="markImageFailed(img.url)"
          />
          <span
            v-else
            class="flex h-full w-full items-center justify-center bg-sand-100 text-stone-400"
          >
            <PhotoIcon class="h-4 w-4" aria-hidden="true" />
          </span>
        </button>
      </div>

      <!-- Description -->
      <p
        v-if="spot.description"
        class="text-xs leading-relaxed text-stone-600"
      >
        {{ spot.description }}
      </p>

      <!-- Hazard notes callout -->
      <div
        v-if="spot.hazard_notes"
        class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/80 px-3.5 py-2.5 text-xs text-amber-900"
      >
        <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
        <p class="leading-relaxed">
          <span class="font-bold">Reef &amp; safety note:</span>
          {{ spot.hazard_notes }}
        </p>
      </div>
    </div>

    <!-- Full-screen Lightbox Modal -->
    <Teleport to="body">
      <div
        v-if="isLightboxOpen && activeImage"
        class="fixed inset-0 z-[1200] flex flex-col bg-deep-950/95 p-4 backdrop-blur-sm sm:p-6"
        role="dialog"
        aria-modal="true"
        :aria-label="`${spot.name} photo viewer`"
        @click.self="closeLightbox"
      >
        <!-- Top bar -->
        <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 pb-3 text-white">
          <div class="min-w-0">
            <h3 class="truncate text-sm font-bold sm:text-base">
              {{ spot.name }}
            </h3>
            <p class="truncate text-xs text-sand-200">
              {{ spotLocation }}
            </p>
          </div>

          <div class="flex shrink-0 items-center gap-2">
            <span
              v-if="activeAccuracyLabel && !isImageFailed(activeImage.url)"
              class="hidden rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-medium text-sand-100 ring-1 ring-white/15 sm:inline-flex"
            >
              {{ activeAccuracyLabel }}
            </span>
            <span
              class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold tabular-nums text-white ring-1 ring-white/15"
            >
              {{ activeIndex + 1 }} / {{ images.length }}
            </span>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/15 transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-lagoon-400"
              aria-label="Close photo viewer"
              @click="closeLightbox"
            >
              <XMarkIcon class="h-5 w-5" aria-hidden="true" />
            </button>
          </div>
        </div>

        <!-- Center image -->
        <div
          class="relative mx-auto flex min-h-0 w-full max-w-5xl flex-1 items-center justify-center"
          @click.self="closeLightbox"
        >
          <button
            v-if="images.length > 1"
            type="button"
            class="absolute left-0 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus:ring-2 focus:ring-lagoon-400 sm:left-2"
            aria-label="Previous photo"
            @click.stop="prevPhoto"
          >
            <ChevronLeftIcon class="h-5 w-5" aria-hidden="true" />
          </button>

          <img
            v-if="!isImageFailed(activeImage.url)"
            :key="`lightbox-${activeImage.url}`"
            :src="activeImage.url"
            :alt="activeImage.caption || spot.name"
            class="max-h-[78vh] max-w-full rounded-xl object-contain shadow-2xl"
            @error="markImageFailed(activeImage?.url)"
          />

          <div
            v-else
            class="flex flex-col items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-8 py-12 text-center text-sand-100"
          >
            <PhotoIcon class="h-8 w-8 text-sand-200" aria-hidden="true" />
            <p class="text-sm font-semibold">Photo could not be loaded</p>
          </div>

          <button
            v-if="images.length > 1"
            type="button"
            class="absolute right-0 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/20 backdrop-blur-sm transition hover:bg-white/25 focus:outline-none focus:ring-2 focus:ring-lagoon-400 sm:right-2"
            aria-label="Next photo"
            @click.stop="nextPhoto"
          >
            <ChevronRightIcon class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <!-- Bottom caption -->
        <div
          v-if="activeImage.caption || (activeAccuracyLabel && !isImageFailed(activeImage.url))"
          class="mx-auto mt-3 flex max-w-3xl flex-col items-center gap-1.5 text-center"
        >
          <span
            v-if="activeAccuracyLabel && !isImageFailed(activeImage.url)"
            class="inline-flex rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-medium text-sand-100 ring-1 ring-white/15 sm:hidden"
          >
            {{ activeAccuracyLabel }}
          </span>
          <p v-if="activeImage.caption" class="text-xs leading-relaxed text-sand-100 sm:text-sm">
            {{ activeImage.caption }}
          </p>
        </div>
      </div>
    </Teleport>
  </section>
</template>
