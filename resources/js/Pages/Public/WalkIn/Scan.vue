<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import Badge from '@/Components/Atoms/Badge.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
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

const statusVariant = computed(() => {
  if (props.verification.status === 'verified') return 'success'
  if (props.verification.status === 'suspended') return 'danger'
  if (props.verification.status === 'pending') return 'warning'
  return 'default'
})

const statusIcon = computed(() => {
  if (props.verification.status === 'verified') return ShieldCheckIcon
  if (props.verification.status === 'suspended') return ShieldExclamationIcon
  if (props.verification.status === 'pending') return ClockIcon
  return ExclamationTriangleIcon
})

const statusPanelClass = computed(() => {
  if (props.verification.status === 'verified') {
    return 'border-emerald-400/30 bg-emerald-500/10 text-emerald-100'
  }
  if (props.verification.status === 'suspended') {
    return 'border-rose-400/30 bg-rose-500/10 text-rose-100'
  }
  return 'border-amber-400/30 bg-amber-500/10 text-amber-100'
})

const checkedAt = computed(() =>
  new Date(props.verification.checked_at).toLocaleString('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }),
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

const bookLabel = computed(() => (isAuthenticated.value ? 'Book this session' : 'Log in to book'))
</script>

<template>
  <Head :title="`Verify ${instructor.name} · SiaSurf`" />

  <div class="relative min-h-screen overflow-hidden bg-slate-950 px-4 py-10 text-white sm:px-6">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
      <div class="absolute -right-24 top-0 h-72 w-72 rounded-full bg-ocean-600/20 blur-[120px]"></div>
      <div class="absolute -left-24 bottom-0 h-72 w-72 rounded-full bg-teal-500/15 blur-[120px]"></div>
    </div>

    <main class="relative z-10 mx-auto w-full max-w-lg">
      <div class="mb-6 flex items-center justify-between">
        <Link href="/" class="text-sm font-bold uppercase tracking-[0.2em] text-white/60 hover:text-white">
          SiaSurf
        </Link>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[10px] font-black uppercase tracking-[0.16em] text-white/70">
          <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" aria-hidden="true"></span>
          Live SISA check
        </span>
      </div>

      <section class="rounded-[2.25rem] border border-white/10 bg-white/[0.07] p-6 shadow-2xl backdrop-blur-xl sm:p-8">
        <div :class="['flex items-start gap-3 rounded-2xl border p-4', statusPanelClass]">
          <component :is="statusIcon" class="mt-0.5 h-6 w-6 shrink-0" aria-hidden="true" />
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <p class="text-sm font-black uppercase tracking-[0.12em]">{{ verification.label }}</p>
              <Badge :variant="statusVariant">
                {{ verification.status === 'verified' ? 'Authorised' : 'Not authorised' }}
              </Badge>
            </div>
            <p class="mt-1.5 text-sm leading-relaxed opacity-90">{{ verification.message }}</p>
          </div>
        </div>

        <div class="mt-6 flex flex-col items-center text-center">
          <div class="relative">
            <img
              v-if="avatarUrl"
              :src="avatarUrl"
              :alt="instructor.name"
              class="h-28 w-28 rounded-[2rem] object-cover ring-4 ring-white/10"
            />
            <span
              v-else
              class="flex h-28 w-28 items-center justify-center rounded-[2rem] bg-ocean-600 text-3xl font-black text-white ring-4 ring-white/10"
              aria-hidden="true"
            >
              {{ initials }}
            </span>
            <span
              class="absolute -bottom-2 -right-2 flex h-9 w-9 items-center justify-center rounded-xl ring-4 ring-slate-950"
              :class="verification.is_verified ? 'bg-emerald-500' : 'bg-rose-500'"
              aria-hidden="true"
            >
              <CheckBadgeIcon v-if="verification.is_verified" class="h-5 w-5 text-white" />
              <ExclamationTriangleIcon v-else class="h-5 w-5 text-white" />
            </span>
          </div>

          <h1 class="mt-4 text-2xl font-extrabold tracking-tight">{{ instructor.name }}</h1>
          <div class="mt-2 flex flex-wrap items-center justify-center gap-2 text-xs font-semibold text-white/70">
            <span v-if="instructor.level_label">{{ instructor.level_label }}</span>
            <span v-if="instructor.level_label" aria-hidden="true">·</span>
            <span>{{ formatMoney(instructor.rate_per_hour) }}/hour</span>
            <span v-if="instructor.reviews_count" aria-hidden="true">·</span>
            <span v-if="instructor.reviews_count" class="inline-flex items-center gap-1">
              <StarIcon class="h-3.5 w-3.5 text-amber-300" aria-hidden="true" />
              {{ instructor.average_rating.toFixed(1) }} ({{ instructor.reviews_count }})
            </span>
          </div>

          <p v-if="instructor.bio" class="mt-4 text-sm leading-relaxed text-white/60">
            "{{ instructor.bio.length > 140 ? `${instructor.bio.slice(0, 140)}…` : instructor.bio }}"
          </p>
        </div>


        <div v-if="verification.certifications.length" class="mt-6">
          <p class="text-[10px] font-black uppercase tracking-[0.16em] text-white/40">
            Verified certifications
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <span
              v-for="certificate in verification.certifications"
              :key="certificate.id"
              class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-semibold"
              :class="
                certificate.expired
                  ? 'border-rose-400/30 bg-rose-500/10 text-rose-200'
                  : 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200'
              "
            >
              {{ certificate.label }}
              <span v-if="certificate.expired" class="text-rose-300">· expired</span>
            </span>
          </div>
        </div>

        <div class="mt-6 space-y-2">
          <template v-if="verification.can_book">
            <Link
              :href="route('bookings.create', instructor.id)"
              class="flex w-full items-center justify-center rounded-lg bg-teal-600 px-6 py-3 text-lg font-medium text-white shadow-xl shadow-ocean-600/20 transition-colors hover:bg-teal-700"
            >
              {{ bookLabel }} &rarr;
            </Link>
            <Link
              :href="route('instructors.profile', instructor.id)"
              class="block text-center text-xs font-bold uppercase tracking-[0.14em] text-white/50 transition-colors hover:text-white"
            >
              View full profile &amp; reviews
            </Link>
          </template>

          <div
            v-else
            class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-center text-xs font-semibold text-white/60"
          >
            Booking is disabled until SISA restores this instructor to verified status.
          </div>
        </div>

        <div v-if="verification.can_book && recentReviews.length" class="mt-6 border-t border-white/10 pt-5">
          <p class="text-[10px] font-black uppercase tracking-[0.16em] text-white/40">Recent reviews</p>
          <ul class="mt-3 space-y-3">
            <li v-for="review in recentReviews" :key="review.id" class="rounded-2xl bg-white/5 p-3">
              <div class="flex items-center justify-between gap-2">
                <span class="truncate text-xs font-bold text-white/80">{{ review.student_name }}</span>
                <span v-if="review.rating" class="inline-flex items-center gap-0.5 text-[11px] font-bold text-amber-200">
                  {{ review.rating }} <StarIcon class="h-3 w-3" aria-hidden="true" />
                </span>
              </div>
              <p class="mt-1 max-h-12 overflow-hidden text-xs leading-relaxed text-white/55">{{ review.comment }}</p>
            </li>
          </ul>
        </div>
      </section>

      <div class="mt-5 flex items-center justify-center gap-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">
        <ShieldCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
        Status checked live at {{ checkedAt }}
      </div>
      <p class="mt-2 text-center text-[10px] leading-relaxed text-white/30">
        The QR is permanent. This page reads the current SISA record every time it is scanned.
      </p>
    </main>
  </div>
</template>

