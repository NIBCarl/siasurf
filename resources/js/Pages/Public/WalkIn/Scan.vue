<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import { formatPhilippineDateTime } from '@/utils/philippineTime'
import {
  CheckBadgeIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  StarIcon,
} from '@heroicons/vue/24/outline'

interface Certification {
  id: number
  type: string
  label: string
  expires_at: string | null
  expired: boolean
}

interface Verification {
  status: string
  label: string
  message: string
  tone: string
  is_verified: boolean
  can_book: boolean
  suspended_until: string | null
  checked_at: string
  certifications: Certification[]
}

interface Instructor {
  id: number
  name: string
  avatar_path: string | null
  avatar_url?: string | null
  bio: string | null
  level: number | null
  level_label: string | null
  rate_per_hour: number
  average_rating: number
  reviews_count: number
}

interface Props {
  instructor: Instructor
  verification: Verification
  recentReviews: Array<{
    id: number
    rating: number | null
    comment: string
    student_name: string
  }>
}

const props = defineProps<Props>()

const page = usePage()
const isAuthenticated = computed(() => Boolean((page.props.auth as any)?.user))

const statusIcon = computed(() => {
  if (props.verification.status === 'verified') return ShieldCheckIcon
  if (props.verification.status === 'suspended') return ShieldExclamationIcon
  if (props.verification.status === 'pending') return ClockIcon
  return ExclamationTriangleIcon
})

const statusPanelClass = computed(() => {
  if (props.verification.status === 'verified') {
    return 'bg-emerald-700 text-white'
  }
  if (props.verification.status === 'suspended') {
    return 'bg-rose-700 text-white'
  }
  return 'bg-amber-600 text-white'
})

const checkedAt = computed(() =>
  formatPhilippineDateTime(
    props.verification.checked_at,
    {
      dateStyle: 'medium',
      timeStyle: 'short',
    },
    'en-PH',
  ),
)

const initials = computed(() =>
  props.instructor.name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join(''),
)

const avatarUrl = computed(() => resolveAvatarUrl(props.instructor))

const formatMoney = (value: number) =>
  new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(value)

const bookLabel = computed(() => (isAuthenticated.value ? 'Book Lesson Now' : 'Book Lesson (Sign in)'))
</script>

<template>
  <Head :title="`Verify ${instructor.name} · SiaSurf`" />

  <div class="min-h-screen bg-sand-50 px-4 py-8 text-deep-950 sm:px-6">
    <main class="mx-auto w-full max-w-lg">
      <div class="mb-5 flex items-center justify-between">
        <Link href="/" class="inline-flex items-center">
          <ApplicationLogo variant="horizontal" class="h-8 w-auto" />
        </Link>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-white px-3 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-emerald-800 shadow-sm">
          <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" aria-hidden="true"></span>
          Live SISA check
        </span>
      </div>

      <section class="overflow-hidden rounded-[2rem] border border-sand-200 bg-white shadow-lift">
        <div :class="['flex items-start gap-3 px-6 py-5', statusPanelClass]">
          <component :is="statusIcon" class="mt-0.5 h-6 w-6 shrink-0" aria-hidden="true" />
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-sm font-black uppercase tracking-[0.12em]">{{ verification.label }}</p>
              <span class="rounded-full bg-white/20 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wider text-white">
                {{ verification.status === 'verified' ? 'Authorised' : 'Not authorised' }}
              </span>
            </div>
            <p class="mt-1.5 text-sm leading-relaxed text-white/95">{{ verification.message }}</p>
          </div>
        </div>

        <div class="p-6 sm:p-8">
          <div class="flex flex-col items-center text-center">
            <div class="relative">
              <img
                v-if="avatarUrl"
                :src="avatarUrl"
                :alt="instructor.name"
                class="h-28 w-28 rounded-[2rem] object-cover ring-4 ring-sand-100 shadow-card"
              />
              <span
                v-else
                class="flex h-28 w-28 items-center justify-center rounded-[2rem] bg-deep-900 text-3xl font-black text-lagoon-300 ring-4 ring-sand-100 shadow-card"
                aria-hidden="true"
              >
                {{ initials }}
              </span>
              <span
                class="absolute -bottom-2 -right-2 flex h-9 w-9 items-center justify-center rounded-xl ring-4 ring-white"
                :class="verification.is_verified ? 'bg-emerald-600' : 'bg-rose-600'"
                aria-hidden="true"
              >
                <CheckBadgeIcon v-if="verification.is_verified" class="h-5 w-5 text-white" />
                <ExclamationTriangleIcon v-else class="h-5 w-5 text-white" />
              </span>
            </div>

            <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-deep-950">{{ instructor.name }}</h1>
            <div class="mt-2 flex flex-wrap items-center justify-center gap-2 text-xs font-semibold text-stone-600">
              <span v-if="instructor.level_label">{{ instructor.level_label }}</span>
              <span v-if="instructor.level_label" aria-hidden="true">·</span>
              <span class="font-bold text-deep-900">{{ formatMoney(instructor.rate_per_hour) }}/hour</span>
              <span v-if="instructor.reviews_count" aria-hidden="true">·</span>
              <span v-if="instructor.reviews_count" class="inline-flex items-center gap-1 text-amber-700 font-bold">
                <StarIcon class="h-3.5 w-3.5 text-amber-500" aria-hidden="true" />
                {{ instructor.average_rating.toFixed(1) }} ({{ instructor.reviews_count }})
              </span>
            </div>

            <p v-if="instructor.bio" class="mt-4 text-sm leading-relaxed text-stone-600">
              "{{ instructor.bio.length > 140 ? `${instructor.bio.slice(0, 140)}…` : instructor.bio }}"
            </p>
          </div>

          <div v-if="verification.certifications.length" class="mt-6 rounded-2xl border border-sand-200 bg-sand-50 p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-stone-500">
              Verified certifications
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
              <span
                v-for="certificate in verification.certifications"
                :key="certificate.id"
                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-bold"
                :class="
                  certificate.expired
                    ? 'border-rose-200 bg-rose-50 text-rose-800'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                "
              >
                {{ certificate.label }}
                <span v-if="certificate.expired" class="text-rose-700">· expired</span>
              </span>
            </div>
          </div>

          <div class="mt-6 space-y-2.5">
            <template v-if="verification.can_book">
              <Link
                :href="route('bookings.create', instructor.id)"
                class="flex w-full items-center justify-center rounded-xl bg-coral-700 px-6 py-3.5 text-base font-bold text-white shadow-card transition-colors hover:bg-coral-800"
              >
                {{ bookLabel }} &rarr;
              </Link>
              <Link
                v-if="!isAuthenticated"
                :href="route('register')"
                class="block pt-1 text-center text-xs font-bold uppercase tracking-[0.14em] text-lagoon-700 transition-colors hover:text-lagoon-800"
              >
                New to SiaSurf? Create a tourist account
              </Link>
              <Link
                :href="route('instructors.profile', instructor.id)"
                class="block text-center text-xs font-bold uppercase tracking-[0.14em] text-stone-500 transition-colors hover:text-deep-950"
              >
                View full profile &amp; reviews
              </Link>
            </template>

            <div
              v-else
              class="rounded-2xl border border-sand-200 bg-sand-50 px-4 py-3 text-center text-xs font-semibold text-stone-600"
            >
              Booking is disabled until SISA restores this instructor to verified status.
            </div>
          </div>

          <div v-if="verification.can_book && recentReviews.length" class="mt-6 border-t border-sand-200 pt-5">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-stone-500">Recent reviews</p>
            <ul class="mt-3 space-y-3">
              <li v-for="review in recentReviews" :key="review.id" class="rounded-2xl border border-sand-200 bg-sand-50 p-3">
                <div class="flex items-center justify-between gap-2">
                  <span class="truncate text-xs font-bold text-deep-900">{{ review.student_name }}</span>
                  <span v-if="review.rating" class="inline-flex items-center gap-0.5 text-[11px] font-bold text-amber-700">
                    {{ review.rating }} <StarIcon class="h-3 w-3 text-amber-500" aria-hidden="true" />
                  </span>
                </div>
                <p class="mt-1 max-h-12 overflow-hidden text-xs leading-relaxed text-stone-600">{{ review.comment }}</p>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <div class="mt-5 flex items-center justify-center gap-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-stone-600">
        <ShieldCheckIcon class="h-3.5 w-3.5 text-lagoon-700" aria-hidden="true" />
        Status checked live at {{ checkedAt }}
      </div>
      <p class="mt-2 text-center text-[10px] leading-relaxed text-stone-500">
        The QR is permanent. This page reads the current SISA record every time it is scanned.
      </p>
    </main>
  </div>
</template>

