<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import Countdown from '@/Components/UI/Countdown.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { resolveAvatarUrl } from '@/utils/avatar'
import {
  CalendarDaysIcon,
  ChatBubbleLeftRightIcon,
  ChevronRightIcon,
  CloudIcon,
  MagnifyingGlassIcon,
  MapPinIcon,
  SunIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'

interface Instructor {
  id: number
  name: string
  avatar_path?: string | null
  instructor_profile?: {
    level: number
    rate_per_hour: number
  }
}

interface SurfSpot {
  id: number
  name: string
  difficulty: string
}

interface Booking {
  id: number
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  status: string
  total_amount?: number
  payment_status?: string
  instructor: Instructor
  surf_spot: SurfSpot
  started_at: string | null
  duration_hours?: number
}

interface Props {
  activeSession: Booking | null
  upcomingBookings: Booking[]
  pastInstructors: Instructor[]
  surfReport: {
    spot: string
    height: string
    condition: string
    wind: string
    tide: string
    temp: string
    icon: string
  }
}

const props = defineProps<Props>()

const page = usePage()
const firstName = computed(() => (page.props.auth.user as any)?.name?.split(' ')[0] ?? 'Surfer')
const chatUnread = computed(() => Number((page.props as any).nav?.chatUnread ?? 0))

const formatDate = (dateString: string) =>
  new Date(dateString).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    weekday: 'short',
  })

/**
 * "9:00 AM – 11:00 AM" for the booking cards, so the time is visible without
 * opening the detail page. Falls back to the legacy period label.
 */
const slotLabel = (booking: Booking) => {
  const clock = (value?: string | null) => {
    if (!value) return null

    const time = value.includes('T') ? value.split('T')[1] : value
    const [hour, minute] = time.slice(0, 5).split(':').map(Number)

    if (Number.isNaN(hour) || Number.isNaN(minute)) return null

    const suffix = hour >= 12 ? 'PM' : 'AM'
    const displayHour = hour % 12 === 0 ? 12 : hour % 12

    return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
  }

  const start = clock(booking.start_time)
  const end = clock(booking.end_time)

  if (start && end) return `${start} – ${end}`
  if (start) return start

  return booking.time_period.charAt(0).toUpperCase() + booking.time_period.slice(1)
}

const formatMoney = (value?: number) =>
  typeof value === 'number'
    ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value)
    : null

const initials = (name: string) =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')

const avatarUrl = (instructor: Instructor) => resolveAvatarUrl(instructor)

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusVariant = (status: string): PillVariant => {
  if (status === 'confirmed') return 'verified'
  if (status === 'pending') return 'pending'
  if (status === 'in_progress') return 'live'
  if (status === 'completed') return 'completed'
  if (status === 'cancelled') return 'cancelled'
  return 'neutral'
}

const statusLabel = (status: string) => {
  if (status === 'pending') return 'Awaiting instructor'
  if (status === 'in_progress') return 'In progress'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

const nextBooking = computed(() => props.upcomingBookings[0] ?? null)
const remainingUpcoming = computed(() => props.upcomingBookings.slice(1, 4))
const conditionsUnavailable = computed(() => props.surfReport.condition === 'Data Unavailable')

const initialRemaining = props.activeSession?.started_at
  ? Math.max(
      0,
      Math.floor(
        (new Date(new Date(props.activeSession.started_at).getTime() + (props.activeSession.duration_hours ?? 2) * 3600000).getTime() -
          Date.now()) /
          1000,
      ),
    )
  : 0

const timeRemaining = ref(initialRemaining)
let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  if (timeRemaining.value > 0) {
    timer = setInterval(() => {
      if (timeRemaining.value > 0) {
        timeRemaining.value -= 1
      } else if (timer) {
        clearInterval(timer)
      }
    }, 1000)
  }
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
    <Head title="Overview" />

    <AppLayout>
        <template #header>Student overview</template>

        <div class="space-y-6">
            <div class="grid gap-6 lg:grid-cols-12">
                <!-- Hero -->
                <div class="lg:col-span-7">
                    <AppCard variant="chrome" padding="lg" class="relative h-full overflow-hidden">
                        <svg
                            class="pointer-events-none absolute -bottom-6 -right-6 h-48 w-72 text-white/5"
                            viewBox="0 0 300 200"
                            fill="currentColor"
                            aria-hidden="true"
                        >
                            <path d="M0 140 C60 100, 120 180, 180 130 C240 80, 270 150, 300 120 L300 200 L0 200 Z" />
                            <path
                                d="M40 165 C100 130, 160 190, 220 150 C260 120, 280 160, 300 145 L300 200 L40 200 Z"
                                opacity="0.6"
                            />
                        </svg>

                        <div class="relative">
                            <p class="text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">
                                Siargao surf booking
                            </p>
                            <h1 class="mt-2 text-headline font-semibold tracking-tight text-white sm:text-display">
                                Welcome back, {{ firstName }}
                            </h1>
                            <p class="mt-2 max-w-xl text-body text-lagoon-100">
                                Find a SISA-verified instructor, follow your next lesson and check the conditions
                                before you paddle out.
                            </p>

                            <div class="mt-6 flex flex-wrap items-center gap-3">
                                <AppButton :href="route('instructors.search')" size="lg">
                                    Find an instructor
                                </AppButton>
                                <AppButton
                                    :href="route('student.bookings.index')"
                                    variant="secondary"
                                    size="lg"
                                >
                                    My bookings
                                </AppButton>
                            </div>
                        </div>
                    </AppCard>
                </div>

                <!-- Conditions -->
                <div class="lg:col-span-5">
                    <AppCard padding="lg" class="flex h-full flex-col">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="eyebrow">Conditions</p>
                                <h2 class="mt-1 text-title font-semibold text-deep-950">{{ surfReport.spot }}</h2>
                            </div>
                            <span class="flex h-10 w-10 items-center justify-center rounded-control bg-lagoon-50 text-lagoon-700">
                                <SunIcon v-if="surfReport.icon === 'sun'" class="h-5 w-5" aria-hidden="true" />
                                <CloudIcon v-else class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </div>

                        <template v-if="!conditionsUnavailable">
                            <div class="mt-4 flex items-end gap-3">
                                <span class="metric text-hero font-semibold leading-none text-deep-950">
                                    {{ surfReport.temp }}
                                </span>
                                <span class="pb-1 text-body-sm text-stone-600">{{ surfReport.condition }}</span>
                            </div>

                            <div class="surface-well mt-4 p-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Waves</p>
                                        <p class="mt-1 text-body font-semibold text-deep-900">{{ surfReport.height }}</p>
                                    </div>
                                    <div>
                                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Wind</p>
                                        <p class="mt-1 flex items-center gap-1.5 text-body font-semibold text-deep-900">
                                            <svg
                                                class="h-4 w-4 text-stone-500"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.5"
                                                aria-hidden="true"
                                            >
                                                <path stroke-linecap="round" d="M3 8h11a3 3 0 1 0-3-3M3 12h15a3 3 0 1 1-3 3M3 16h7" />
                                            </svg>
                                            {{ surfReport.wind }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-3 border-t border-sand-200 pt-3">
                                    <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Tide</p>
                                    <p class="mt-1 text-body font-semibold text-deep-900">{{ surfReport.tide }}</p>
                                </div>
                            </div>

                            <p class="mt-auto pt-3 text-label text-stone-500">
                                Wave and weather data from Open-Meteo · tide extremes from Stormglass.
                            </p>
                        </template>

                        <AppPanel v-else tone="attention" label="Conditions" class="mt-4">
                            Live conditions are temporarily unavailable. Check the horizon before you paddle out.
                        </AppPanel>
                    </AppCard>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-12">
                <section class="lg:col-span-7">
                    <SectionHeader eyebrow="Your next lesson" title="On the schedule">
                        <template #action>
                            <Link
                                :href="route('student.bookings.index')"
                                class="inline-flex items-center gap-1 text-body-sm font-semibold text-lagoon-700 hover:text-lagoon-800"
                            >
                                All bookings
                                <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                            </Link>
                        </template>
                    </SectionHeader>

                    <!-- Live session -->
                    <AppCard v-if="activeSession" variant="chrome" padding="lg" class="mt-4">
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-4">
                                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-white/10 text-lagoon-300">
                                    <UserGroupIcon class="h-6 w-6" aria-hidden="true" />
                                </span>
                                <div>
                                    <StatusPill variant="live">In progress</StatusPill>
                                    <p class="mt-2 text-title font-semibold text-white">
                                        {{ activeSession.instructor.name }}
                                    </p>
                                    <p class="mt-0.5 text-body-sm text-lagoon-100">
                                        {{ activeSession.surf_spot.name }} · {{ formatDate(activeSession.date) }} ·
                                        <span>{{ slotLabel(activeSession) }}</span>
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <Countdown :seconds="timeRemaining" label="Time left" />
                                <AppButton
                                    :href="route('student.bookings.show', activeSession.id)"
                                    variant="secondary"
                                    size="md"
                                >
                                    View session
                                </AppButton>
                            </div>
                        </div>
                    </AppCard>

                    <!-- Next booking -->
                    <Link
                        v-else-if="nextBooking"
                        :href="route('student.bookings.show', nextBooking.id)"
                        class="group mt-4 block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                    >
                        <AppCard padding="lg" interactive>
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex items-start gap-4">
                                    <img
                                        v-if="avatarUrl(nextBooking.instructor)"
                                        :src="avatarUrl(nextBooking.instructor) || undefined"
                                        alt=""
                                        class="h-12 w-12 shrink-0 rounded-control object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-deep-900 text-body font-semibold text-lagoon-200"
                                    >
                                        {{ initials(nextBooking.instructor.name) }}
                                    </span>

                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-body-lg font-semibold text-deep-900">
                                                {{ nextBooking.instructor.name }}
                                            </p>
                                            <StatusPill :variant="statusVariant(nextBooking.status)">
                                                {{ statusLabel(nextBooking.status) }}
                                            </StatusPill>
                                        </div>
                                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-stone-600">
                                            <CalendarDaysIcon class="h-4 w-4" aria-hidden="true" />
                                            {{ formatDate(nextBooking.date) }}
                                            <span aria-hidden="true">·</span>
                                            <span class="font-semibold text-deep-900">{{ slotLabel(nextBooking) }}</span>
                                        </p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-body-sm text-stone-600">
                                            <MapPinIcon class="h-4 w-4" aria-hidden="true" />
                                            {{ nextBooking.surf_spot.name }}
                                        </p>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <p
                                        v-if="formatMoney(nextBooking.total_amount)"
                                        class="metric text-title font-semibold text-deep-900"
                                    >
                                        {{ formatMoney(nextBooking.total_amount) }}
                                    </p>
                                    <p class="mt-1 flex items-center justify-end gap-1 text-body-sm font-semibold text-lagoon-700">
                                        View booking
                                        <ChevronRightIcon
                                            class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                                            aria-hidden="true"
                                        />
                                    </p>
                                </div>
                            </div>
                        </AppCard>
                    </Link>

                    <div v-else class="mt-4">
                        <EmptyState
                            :icon="CalendarDaysIcon"
                            title="No lessons booked"
                            description="Find a SISA-verified instructor and book your next session."
                        >
                            <AppButton :href="route('instructors.search')">Find an instructor</AppButton>
                            <AppButton :href="route('surf-spots.index')" variant="secondary">
                                Browse surf spots
                            </AppButton>
                        </EmptyState>
                    </div>

                    <ul v-if="remainingUpcoming.length" class="mt-3 space-y-2">
                        <li v-for="booking in remainingUpcoming" :key="booking.id">
                            <Link
                                :href="route('student.bookings.show', booking.id)"
                                class="flex items-center justify-between gap-3 rounded-control border border-sand-200 bg-white px-4 py-3 transition-colors hover:border-lagoon-600"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-body-sm font-semibold text-deep-900">
                                        {{ booking.instructor.name }}
                                    </span>
                                    <span class="block truncate text-label text-stone-500">
                                        {{ formatDate(booking.date) }} · {{ slotLabel(booking) }} ·
                                        {{ booking.surf_spot.name }}
                                    </span>
                                </span>
                                <StatusPill :variant="statusVariant(booking.status)">
                                    {{ statusLabel(booking.status) }}
                                </StatusPill>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="lg:col-span-5">
                    <SectionHeader eyebrow="Your instructors" title="Book again" />

                    <div v-if="pastInstructors.length" class="mt-4 space-y-3">
                        <Link
                            v-for="instructor in pastInstructors"
                            :key="instructor.id"
                            :href="route('bookings.create', instructor.id)"
                            class="group block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                        >
                            <AppCard padding="md" interactive>
                                <div class="flex items-center gap-4">
                                    <img
                                        v-if="avatarUrl(instructor)"
                                        :src="avatarUrl(instructor) || undefined"
                                        alt=""
                                        class="h-12 w-12 shrink-0 rounded-control object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-lagoon-50 text-body font-semibold text-lagoon-700"
                                    >
                                        {{ initials(instructor.name) }}
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-body font-semibold text-deep-900">
                                            {{ instructor.name }}
                                        </p>
                                        <p class="mt-0.5 text-body-sm text-stone-600">
                                            Level {{ instructor.instructor_profile?.level }} ·
                                            ₱{{ instructor.instructor_profile?.rate_per_hour }} per hour
                                        </p>
                                    </div>

                                    <ChevronRightIcon
                                        class="h-4 w-4 shrink-0 text-stone-400 transition-transform group-hover:translate-x-0.5"
                                        aria-hidden="true"
                                    />
                                </div>
                            </AppCard>
                        </Link>
                    </div>

                    <div v-else class="mt-4">
                        <AppPanel label="Book again">
                            Instructors you have surfed with will appear here after your first completed lesson.
                        </AppPanel>
                    </div>

                    <AppCard padding="md" class="mt-4">
                        <h3 class="text-body-lg font-semibold text-deep-900">Quick links</h3>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <Link
                                :href="route('instructors.search')"
                                class="flex items-center justify-between rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm font-semibold text-deep-900 transition-colors hover:border-lagoon-600"
                            >
                                Find instructor
                                <MagnifyingGlassIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                            </Link>
                            <Link
                                :href="route('surf-spots.index')"
                                class="flex items-center justify-between rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm font-semibold text-deep-900 transition-colors hover:border-lagoon-600"
                            >
                                Surf spots
                                <MapPinIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                            </Link>
                            <Link
                                :href="route('student.bookings.index')"
                                class="flex items-center justify-between rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm font-semibold text-deep-900 transition-colors hover:border-lagoon-600"
                            >
                                My bookings
                                <CalendarDaysIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                            </Link>
                            <Link
                                :href="route('student.reviews.index')"
                                class="flex items-center justify-between rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm font-semibold text-deep-900 transition-colors hover:border-lagoon-600"
                            >
                                My reviews
                                <ChevronRightIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                            </Link>
                            <Link
                                :href="route('student.bookings.index')"
                                class="flex items-center justify-between rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm font-semibold text-deep-900 transition-colors hover:border-lagoon-600"
                            >
                                <span class="flex items-center gap-2">
                                    Messages
                                    <span
                                        v-if="chatUnread > 0"
                                        class="rounded-full bg-coral-700 px-2 py-0.5 text-label font-bold text-white"
                                    >
                                        {{ chatUnread }}
                                    </span>
                                </span>
                                <ChatBubbleLeftRightIcon class="h-4 w-4 text-stone-500" aria-hidden="true" />
                            </Link>
                        </div>
                    </AppCard>
                </section>
            </div>
        </div>
    </AppLayout>
</template>


