<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import Countdown from '@/Components/UI/Countdown.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import {
  CalendarDaysIcon,
  ChatBubbleLeftRightIcon,
  CheckBadgeIcon,
  ChevronRightIcon,
  ExclamationTriangleIcon,
  MapPinIcon,
  PlayIcon,
  QrCodeIcon,
  ShieldCheckIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

interface PendingRequest {
  id: number
  student_name: string
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  skill_level: string
  accept_time_remaining: number
}

interface OngoingSession {
  id: number
  student_name: string
  spot_name: string
  started_at: string
  duration_hours: number
  time_remaining: number
}

interface UpcomingBooking {
  id: number
  student_name: string
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  spot_name: string
}

interface RecentReview {
  id: number
  student_name: string
  rating: number
  comment: string
  date: string
}

interface QuickAction {
  title: string
  description: string
  cta: string
  href: string
  icon: any
  count?: number
  featured?: boolean
}

interface Props {
  stats: {
    total_bookings: number
    completed_lessons: number
    pending_requests: number
    total_earnings: string
    this_month_earnings: string
  }
  profile: {
    status: string
    strike_count: number
    average_rating: number
    level: number
  }
  activities: {
    ongoing_session: OngoingSession | null
    upcoming_bookings: UpcomingBooking[]
    recent_reviews: RecentReview[]
    pending_request_list?: PendingRequest[]
    attendance_due?: number
  }
}

const props = defineProps<Props>()

const page = usePage()
const user = computed(() => page.props.auth.user as any)
const firstName = computed(() => user.value?.name?.split(' ')[0] ?? 'Instructor')
const chatUnread = computed(() => Number((page.props as any).nav?.chatUnread ?? 0))

const isVacationMode = computed(() => props.profile.status === 'inactive')
const vacationForm = useForm({})

const toggleVacationMode = () => {
  vacationForm.post(route('instructor.availability.toggle-status'), {
    preserveScroll: true,
  })
}

const levelLabel = computed(() => {
  if (props.profile.level === 1) return 'Level 1 · beginners only'
  if (props.profile.level === 2) return 'Level 2 · one-on-one'
  return 'Level 3 · groups up to five'
})

const pendingRequests = computed(() => props.activities.pending_request_list ?? [])
const attendanceDue = computed(() => props.activities.attendance_due ?? 0)
const upcomingBookings = computed(() => props.activities.upcoming_bookings ?? [])
const recentReviews = computed(() => props.activities.recent_reviews ?? [])

/**
 * "9:00 AM – 11:00 AM" for the queue/upcoming cards, so the exact lesson time is
 * visible without opening the booking.
 */
const slotLabel = (start?: string | null, end?: string | null, fallback?: string) => {
  const clock = (value?: string | null) => {
    if (!value) return null

    const time = value.includes('T') ? value.split('T')[1] : value
    const [hour, minute] = time.slice(0, 5).split(':').map(Number)

    if (Number.isNaN(hour) || Number.isNaN(minute)) return null

    const suffix = hour >= 12 ? 'PM' : 'AM'
    const displayHour = hour % 12 === 0 ? 12 : hour % 12

    return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
  }

  const from = clock(start)
  const to = clock(end)

  if (from && to) return `${from} – ${to}`
  if (from) return from

  return fallback ? fallback.charAt(0).toUpperCase() + fallback.slice(1) : ''
}

const ongoingTimeLeft = ref(props.activities.ongoing_session?.time_remaining ?? 0)
let ongoingTimer: ReturnType<typeof setInterval> | null = null

const mountedAt = Date.now()
const nowTick = ref(Date.now())
let acceptTicker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  if (ongoingTimeLeft.value > 0) {
    ongoingTimer = setInterval(() => {
      if (ongoingTimeLeft.value > 0) {
        ongoingTimeLeft.value -= 1
      } else if (ongoingTimer) {
        clearInterval(ongoingTimer)
      }
    }, 1000)
  }

  acceptTicker = setInterval(() => {
    nowTick.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (ongoingTimer) clearInterval(ongoingTimer)
  if (acceptTicker) clearInterval(acceptTicker)
})

const acceptSecondsLeft = (request: PendingRequest) => {
  const elapsed = Math.floor((nowTick.value - mountedAt) / 1000)
  return Math.max(0, (request.accept_time_remaining ?? 0) - elapsed)
}

const quickActions = computed<QuickAction[]>(() => [
  {
    title: 'Schedule & slots',
    description: 'Open or block the mornings and afternoons you can teach.',
    cta: 'Manage availability',
    href: route('instructor.availability.index'),
    icon: CalendarDaysIcon,
  },
  {
    title: 'Lessons & requests',
    description: 'Review requests, student details and past sessions.',
    cta: 'Open my lessons',
    href: route('instructor.bookings.index'),
    icon: UserGroupIcon,
    count: props.stats.total_bookings,
  },
  {
    title: 'Messages',
    description: 'Answer student questions about meeting points, time changes and gear.',
    cta: 'Open messages',
    href: route('instructor.bookings.index'),
    icon: ChatBubbleLeftRightIcon,
    count: chatUnread.value > 0 ? chatUnread.value : undefined,
  },
  {
    title: 'Beach QR pass',
    description: 'Let tourists check your SISA record and book you on the spot.',
    cta: 'Show my QR',
    href: route('instructor.qr.show'),
    icon: QrCodeIcon,
    featured: true,
  },
])
</script>

<template>
    <Head title="Instructor dashboard" />

    <AppLayout>
        <template #header>Instructor overview</template>

        <div class="space-y-6">
            <!-- Identity and duty status -->
            <AppCard padding="lg" class="border-t-[3px] border-t-lagoon-700">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-headline font-semibold tracking-tight text-deep-950 sm:text-display">
                                Kumusta, {{ firstName }}
                            </h1>
                            <StatusPill variant="verified">{{ levelLabel }}</StatusPill>
                        </div>
                        <p class="max-w-2xl text-body text-stone-600">
                            Your lessons, availability and safety standing at a glance. Every number here comes from
                            your SiaSurf record.
                        </p>
                    </div>

                    <div class="surface-well flex shrink-0 flex-wrap items-center gap-3 p-3">
                        <span class="flex items-center gap-2 px-1">
                            <span
                                class="h-2.5 w-2.5 rounded-full"
                                :class="isVacationMode ? 'bg-amber-500' : 'bg-lagoon-500'"
                                aria-hidden="true"
                            />
                            <span class="text-body-sm font-semibold text-deep-900">
                                {{ isVacationMode ? 'Bookings paused' : 'Ready for lessons' }}
                            </span>
                        </span>
                        <AppButton
                            :variant="isVacationMode ? 'primary' : 'secondary'"
                            size="sm"
                            :loading="vacationForm.processing"
                            @click="toggleVacationMode"
                        >
                            {{ isVacationMode ? 'Start teaching' : 'Pause bookings' }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <!-- Directory location prompt -->
            <AppCard
                v-if="user && (!user.instructorProfile?.latitude || !user.instructorProfile?.longitude)"
                padding="md"
                class="border-ocean-200 bg-ocean-50"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-label font-semibold uppercase tracking-[0.06em] text-ocean-700">Directory visibility</p>
                        <h2 class="mt-1 text-title font-semibold text-deep-950">Add your teaching pin</h2>
                        <p class="mt-1 max-w-xl text-body-sm text-stone-600">
                            Tourists can already find you in the list. Add an area and coordinates to appear on the public instructor map.
                        </p>
                    </div>
                    <AppButton :href="route('instructor.profile.edit')" size="md">Set location</AppButton>
                </div>
            </AppCard>

            <!-- Requests waiting for an answer -->
            <AppCard v-if="pendingRequests.length" padding="md" class="border-amber-200 bg-amber-50">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-amber-500 text-white">
                            <ExclamationTriangleIcon class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-amber-900">
                                Action needed
                            </p>
                            <h2 class="mt-1 text-title font-semibold text-amber-950">
                                {{ pendingRequests.length }} lesson request{{ pendingRequests.length === 1 ? '' : 's' }}
                                waiting for you
                            </h2>
                            <p class="mt-1 text-body-sm text-amber-900">
                                Each request expires automatically. Review it to accept or decline.
                            </p>
                        </div>
                    </div>
                    <StatusPill variant="pending" :dot="false">Respond within 2 hours</StatusPill>
                </div>

                <ul class="mt-4 divide-y divide-amber-200/80 border-t border-amber-200/80">
                    <li
                        v-for="request in pendingRequests"
                        :key="request.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-body font-semibold text-amber-950">{{ request.student_name }}</p>
                            <p class="mt-0.5 text-body-sm capitalize text-amber-900">
                                {{ request.date }} · {{ slotLabel(request.start_time, request.end_time, request.time_period) }} · {{ request.skill_level }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="flex items-center gap-1.5 text-body-sm text-amber-900">
                                Respond in
                                <Countdown
                                    :seconds="acceptSecondsLeft(request)"
                                    variant="inline"
                                    label="Time to respond"
                                />
                            </span>
                            <AppButton :href="route('instructor.bookings.show', request.id)" size="sm">
                                Review request
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <!-- Attendance due -->
            <AppCard v-if="attendanceDue > 0" padding="md" class="border-lagoon-200 bg-lagoon-50">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-lagoon-600 text-white">
                            <CheckBadgeIcon class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-lagoon-800">
                                Roll call
                            </p>
                            <h2 class="mt-1 text-title font-semibold text-lagoon-900">
                                {{ attendanceDue }} completed lesson{{ attendanceDue === 1 ? '' : 's' }} awaiting attendance
                            </h2>
                            <p class="mt-1 text-body-sm text-lagoon-800">
                                Mark attendance to close out these sessions.
                            </p>
                        </div>
                    </div>
                    <AppButton :href="route('instructor.bookings.index')" variant="secondary" size="md">
                        Mark attendance
                    </AppButton>
                </div>
            </AppCard>

            <!-- Live session -->
            <AppCard v-if="activities.ongoing_session" variant="chrome" padding="lg">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-white/10 text-lagoon-300">
                            <PlayIcon class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div>
                            <StatusPill variant="live">In the water</StatusPill>
                            <p class="mt-2 text-title font-semibold text-white">
                                {{ activities.ongoing_session.student_name }}
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-lagoon-100">
                                <MapPinIcon class="h-4 w-4" aria-hidden="true" />
                                {{ activities.ongoing_session.spot_name }}
                                <span aria-hidden="true">·</span>
                                {{ activities.ongoing_session.duration_hours }}h lesson
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <Countdown :seconds="ongoingTimeLeft" label="Time left" />
                        <AppButton
                            :href="route('instructor.bookings.show', activities.ongoing_session.id)"
                            variant="secondary"
                            size="md"
                        >
                            Open lesson
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <!-- Quick actions -->
            <section>
                <SectionHeader
                    eyebrow="Shoreline tools"
                    title="Quick actions"
                    description="The three things you reach for most often."
                />

                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Link
                        v-for="action in quickActions"
                        :key="action.title"
                        :href="action.href"
                        class="group block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                    >
                        <AppCard :variant="action.featured ? 'chrome' : 'paper'" padding="md" class="h-full">
                            <div class="flex items-start justify-between gap-3">
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-control"
                                    :class="action.featured ? 'bg-white/10 text-lagoon-300' : 'bg-lagoon-50 text-lagoon-700'"
                                >
                                    <component :is="action.icon" class="h-5 w-5" aria-hidden="true" />
                                </span>

                                <ChevronRightIcon
                                    class="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                                    :class="action.featured ? 'text-lagoon-200' : 'text-stone-400'"
                                    aria-hidden="true"
                                />
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <h3
                                    class="text-body-lg font-semibold"
                                    :class="action.featured ? 'text-white' : 'text-deep-900'"
                                >
                                    {{ action.title }}
                                </h3>
                                <span
                                    v-if="action.count !== undefined"
                                    class="rounded-full px-2 py-0.5 text-label font-semibold"
                                    :class="action.featured ? 'bg-white/10 text-lagoon-200' : 'bg-sand-100 text-stone-700'"
                                >
                                    {{ action.count }}
                                </span>
                            </div>
                            <p
                                class="mt-1 text-body-sm"
                                :class="action.featured ? 'text-lagoon-100' : 'text-stone-600'"
                            >
                                {{ action.description }}
                            </p>
                            <p
                                class="mt-4 text-body-sm font-semibold"
                                :class="action.featured ? 'text-lagoon-300' : 'text-lagoon-700'"
                            >
                                {{ action.cta }}
                            </p>
                        </AppCard>
                    </Link>
                </div>
            </section>

            <!-- Next lessons and standing -->
            <div class="grid gap-6 lg:grid-cols-12">
                <section class="lg:col-span-7">
                    <SectionHeader eyebrow="Schedule" title="Next in the water">
                        <template #action>
                            <Link
                                :href="route('instructor.bookings.index')"
                                class="inline-flex items-center gap-1 text-body-sm font-semibold text-lagoon-700 hover:text-lagoon-800"
                            >
                                All lessons
                                <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
                            </Link>
                        </template>
                    </SectionHeader>

                    <div v-if="upcomingBookings.length" class="mt-4 space-y-3">
                        <Link
                            v-for="booking in upcomingBookings"
                            :key="booking.id"
                            :href="route('instructor.bookings.show', booking.id)"
                            class="group block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                        >
                            <AppCard padding="md" interactive class="h-full">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div class="flex min-w-0 items-center gap-4">
                                        <span
                                            class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-control border border-sand-200 bg-sand-50"
                                        >
                                            <span class="text-label font-semibold uppercase text-stone-500">
                                                {{ booking.date.split(' ')[0] }}
                                            </span>
                                            <span class="text-body-lg font-semibold leading-none text-deep-900">
                                                {{ booking.date.split(' ')[1]?.replace(',', '') }}
                                            </span>
                                        </span>

                                        <div class="min-w-0">
                                            <p class="truncate text-body-lg font-semibold text-deep-900">
                                                {{ booking.student_name }}
                                            </p>
                                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm text-stone-600">
                                                <MapPinIcon class="h-4 w-4" aria-hidden="true" />
                                                {{ booking.spot_name }}
                                                <span aria-hidden="true">·</span>
                                                <span>{{ slotLabel(booking.start_time, booking.end_time, booking.time_period) }}</span>
                                            </p>
                                        </div>
                                    </div>

                                    <span class="flex items-center gap-1 text-body-sm font-semibold text-lagoon-700">
                                        View lesson
                                        <ChevronRightIcon
                                            class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </div>
                            </AppCard>
                        </Link>
                    </div>

                    <div v-else class="mt-4">
                        <EmptyState
                            :icon="CalendarDaysIcon"
                            title="No upcoming lessons"
                            description="Open more availability so tourists can book you, or share your beach QR with walk-ins."
                        >
                            <AppButton :href="route('instructor.availability.index')" size="md">
                                Open time slots
                            </AppButton>
                            <AppButton :href="route('instructor.qr.show')" variant="secondary" size="md">
                                Show my QR
                            </AppButton>
                        </EmptyState>
                    </div>
                </section>

                <section class="lg:col-span-5">
                    <SectionHeader eyebrow="Record" title="Earnings & standing" />

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <StatCard
                            label="This month"
                            :value="stats.this_month_earnings"
                            tone="positive"
                            :meta="`Total ${stats.total_earnings}`"
                        />
                        <StatCard label="Lessons taught" :value="stats.completed_lessons" meta="Completed sessions" />
                        <StatCard
                            label="Tourist rating"
                            :value="Number(profile.average_rating).toFixed(1)"
                            meta="Average from verified reviews"
                        />
                        <StatCard
                            label="Safety standing"
                            :value="`${profile.strike_count} strike${profile.strike_count === 1 ? '' : 's'}`"
                            :tone="profile.strike_count === 0 ? 'positive' : 'critical'"
                            :meta="`Level ${profile.level} certified`"
                        />
                    </div>

                    <AppCard padding="md" class="mt-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-body-lg font-semibold text-deep-900">Recent reviews</h3>
                            <StatusPill variant="neutral" :dot="false">{{ recentReviews.length }} shown</StatusPill>
                        </div>

                        <ul v-if="recentReviews.length" class="mt-3 space-y-3">
                            <li v-for="review in recentReviews" :key="review.id" class="surface-well p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="truncate text-body-sm font-semibold text-deep-900">
                                        {{ review.student_name }}
                                    </span>
                                    <span class="flex items-center gap-0.5" :aria-label="`${review.rating} out of 5`">
                                        <StarIconSolid
                                            v-for="i in 5"
                                            :key="i"
                                            class="h-3.5 w-3.5"
                                            :class="i <= review.rating ? 'text-amber-500' : 'text-stone-300'"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </div>
                                <p class="mt-1.5 text-body-sm text-stone-600">"{{ review.comment }}"</p>
                                <p class="mt-1 text-label text-stone-500">{{ review.date }}</p>
                            </li>
                        </ul>

                        <p v-else class="mt-3 text-body-sm text-stone-600">
                            No reviews yet. Reviews appear after a completed lesson.
                        </p>
                    </AppCard>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

