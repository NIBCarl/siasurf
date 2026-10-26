<script setup lang="ts">
import { Head, Link, usePage, useForm } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import {
  CalendarDaysIcon,
  BanknotesIcon,
  ClockIcon,
  MapPinIcon,
  UserIcon,
  ArrowRightIcon,
  ExclamationTriangleIcon,
  QrCodeIcon,
  ShieldCheckIcon,
  CheckBadgeIcon,
  PlayIcon,
  UserGroupIcon,
  SparklesIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid'

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
    ongoing_session: {
      id: number
      student_name: string
      spot_name: string
      started_at: string
      duration_hours: number
      time_remaining: number
    } | null
    upcoming_bookings: Array<{
      id: number
      student_name: string
      date: string
      time_period: string
      spot_name: string
    }>
    recent_reviews: Array<{
      id: number
      student_name: string
      rating: number
      comment: string
      date: string
    }>
    pending_request_list?: Array<{
      id: number
      student_name: string
      date: string
      time_period: string
      skill_level: string
      accept_time_remaining: number
    }>
    attendance_due?: number
  }
}

const props = defineProps<Props>()

const page = usePage()
const user = computed(() => page.props.auth.user as any)
const firstName = computed(() => user.value?.name?.split(' ')[0] || 'Instructor')

// Vacation Mode state
const isVacationMode = computed(() => props.profile.status === 'inactive')
const vacationForm = useForm({})
const toggleVacationMode = () => {
  vacationForm.post(route('instructor.availability.toggle-status'), {
    preserveScroll: true,
  })
}

// Ongoing Session Timer
const ongoingTimeLeft = ref(props.activities.ongoing_session?.time_remaining || 0)
let ongoingTimer: ReturnType<typeof setInterval> | null = null

const formatOngoingTime = (seconds: number) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  return `${h > 0 ? h + ':' : ''}${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}

// Pending Request Expiry Countdown
const mountedAt = Date.now()
const nowTick = ref(Date.now())
let acceptTicker: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  if (ongoingTimeLeft.value > 0) {
    ongoingTimer = setInterval(() => {
      if (ongoingTimeLeft.value > 0) {
        ongoingTimeLeft.value--
      } else {
        if (ongoingTimer) clearInterval(ongoingTimer)
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

const acceptSecondsLeft = (request: { accept_time_remaining: number }) => {
  const elapsed = Math.floor((nowTick.value - mountedAt) / 1000)
  return Math.max(0, (request.accept_time_remaining ?? 0) - elapsed)
}

const formatCountdown = (seconds: number) => {
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) {
    return `${h}h ${m}m`
  }
  return `${m}m ${s}s`
}

const getStrikeBadge = (strikes: number) => {
  if (strikes === 0) return { label: 'Clean Record (0 Strikes)', variant: 'success' as const }
  if (strikes === 1) return { label: '1 Warning Strike', variant: 'warning' as const }
  return { label: `${strikes} Strikes`, variant: 'danger' as const }
}
</script>

<template>
  <Head title="Instructor Dashboard — SiaSurf" />

  <AppLayout>
    <div class="max-w-7xl mx-auto space-y-6 pb-12">

      <!-- Top Greeting & Duty Status: Friendly, Clear, Non-Technical -->
      <div class="bg-white rounded-3xl p-5 sm:p-7 border border-slate-100 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2.5 flex-wrap">
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
              Kumusta, {{ firstName }}! 🏄‍♂️
            </h1>
            <Badge :variant="isVacationMode ? 'warning' : 'ocean'" size="sm">
              Level {{ profile.level }} Certified
            </Badge>
          </div>
          <p class="text-sm font-medium text-slate-500 mt-1">
            Welcome to your surf hub. Check your lessons, review new tourist requests, and track your daily pay.
          </p>
        </div>

        <!-- Duty Status & 1-Tap Vacation Switch -->
        <div class="flex items-center gap-3 bg-slate-50 p-2.5 rounded-2xl border border-slate-100 shrink-0">
          <div class="flex items-center gap-2 pl-2">
            <span
              :class="[
                'w-3 h-3 rounded-full shrink-0',
                isVacationMode ? 'bg-amber-400' : 'bg-emerald-500 animate-pulse'
              ]"
            ></span>
            <span class="text-xs font-black text-slate-900">
              {{ isVacationMode ? 'Taking a Break' : 'Ready for Lessons' }}
            </span>
          </div>

          <button
            type="button"
            @click="toggleVacationMode"
            :disabled="vacationForm.processing"
            :class="[
              'px-3.5 py-2 text-xs font-black rounded-xl transition-all min-h-[44px] cursor-pointer shadow-sm',
              isVacationMode
                ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                : 'bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-200'
            ]"
          >
            {{ isVacationMode ? 'Turn On (Start Teaching)' : 'Go on Vacation (Pause)' }}
          </button>
        </div>
      </div>

      <!-- URGENT: Pending Requests Waiting for Acceptance (Unmissable Amber Alert) -->
      <div
        v-if="activities.pending_request_list && activities.pending_request_list.length > 0"
        class="bg-gradient-to-r from-amber-50 to-orange-50 border-2 border-amber-300 rounded-3xl p-5 sm:p-6 shadow-sm space-y-4 animate-in fade-in"
      >
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shrink-0 shadow-sm animate-bounce">
              <ExclamationTriangleIcon class="w-6 h-6" />
            </div>
            <div>
              <h2 class="text-base sm:text-lg font-black text-amber-950 uppercase tracking-tight leading-tight">
                New Tourist Lesson Request!
              </h2>
              <p class="text-xs font-bold text-amber-800 mt-0.5">
                Respond within 2 hours so tourists know you are ready for them.
              </p>
            </div>
          </div>
          <Badge variant="warning" size="sm">
            {{ activities.pending_request_list.length }} Request Waiting
          </Badge>
        </div>

        <div class="space-y-2.5">
          <div
            v-for="request in activities.pending_request_list"
            :key="request.id"
            class="bg-white rounded-2xl p-4 sm:p-5 border border-amber-200/80 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4"
          >
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-base font-black text-slate-900">{{ request.student_name }}</p>
                <span class="text-xs font-black uppercase text-ocean-600 bg-ocean-50 px-2 py-0.5 rounded-md">
                  {{ request.skill_level }}
                </span>
              </div>
              <p class="text-xs font-bold text-slate-500 mt-1">
                📅 {{ request.date }} &bull; <span class="uppercase text-slate-700 font-extrabold">{{ request.time_period }}</span>
              </p>
            </div>

            <div class="flex items-center gap-3 shrink-0">
              <div class="text-right">
                <span class="text-[10px] font-black uppercase tracking-wider text-amber-700 block">Time to accept</span>
                <span class="text-sm font-mono font-black text-amber-900">
                  {{ acceptSecondsLeft(request) > 0 ? formatCountdown(acceptSecondsLeft(request)) : 'EXPIRED' }}
                </span>
              </div>

              <Link :href="route('instructor.bookings.show', request.id)">
                <BaseButton
                  variant="primary"
                  size="sm"
                  class="min-h-[44px] px-5 font-black text-xs rounded-2xl shadow-sm"
                >
                  Review & Accept
                </BaseButton>
              </Link>
            </div>
          </div>
        </div>
      </div>

      <!-- ATTENDANCE REMINDER: If roll call is due -->
      <div
        v-if="(activities.attendance_due ?? 0) > 0"
        class="bg-sky-50 border border-sky-200 rounded-3xl p-4 sm:p-5 flex flex-col sm:flex-row items-center justify-between gap-3"
      >
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-sky-600 text-white flex items-center justify-center shrink-0">
            <CheckBadgeIcon class="w-5 h-5" />
          </div>
          <div>
            <p class="text-xs sm:text-sm font-black text-sky-950">
              Roll Call Needed: You have {{ activities.attendance_due }} completed lesson(s) waiting for attendance.
            </p>
            <p class="text-[11px] font-medium text-sky-700">
              Marking attendance confirms student completion and releases your payout.
            </p>
          </div>
        </div>
        <Link :href="route('instructor.bookings.index')">
          <BaseButton variant="secondary" size="sm" class="min-h-[44px] px-4 font-bold text-xs rounded-xl shrink-0">
            Mark Attendance
          </BaseButton>
        </Link>
      </div>

      <!-- ONGOING SESSION BANNER: Water Mode -->
      <div
        v-if="activities.ongoing_session"
        class="bg-gradient-to-r from-ocean-600 to-ocean-800 rounded-3xl p-6 text-white shadow-xl shadow-ocean-500/20 flex flex-col lg:flex-row items-center justify-between gap-6"
      >
        <div class="flex items-center gap-4 w-full lg:w-auto">
          <div class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center shrink-0">
            <PlayIcon class="w-8 h-8 text-white fill-current animate-pulse" />
          </div>
          <div>
            <span class="text-[10px] font-black uppercase tracking-widest text-ocean-200 block">
              Active Water Session
            </span>
            <h2 class="text-xl sm:text-2xl font-black text-white mt-0.5">
              {{ activities.ongoing_session.student_name }}
            </h2>
            <p class="text-xs font-bold text-ocean-100 flex items-center gap-1.5 mt-1">
              <MapPinIcon class="w-4 h-4 shrink-0 text-ocean-200" />
              <span>{{ activities.ongoing_session.spot_name }}</span>
              <span>&bull;</span>
              <span>{{ activities.ongoing_session.duration_hours }}h Lesson</span>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-6 w-full lg:w-auto justify-between lg:justify-end">
          <div class="text-center lg:text-right">
            <span class="text-[10px] font-black uppercase tracking-widest text-ocean-200 block">
              Time Left in Water
            </span>
            <span class="text-3xl sm:text-4xl font-black font-mono tracking-tight text-white mt-0.5 block">
              {{ formatOngoingTime(ongoingTimeLeft) }}
            </span>
          </div>

          <Link :href="route('instructor.bookings.show', activities.ongoing_session.id)">
            <BaseButton
              variant="success"
              size="md"
              class="min-h-[48px] px-6 font-black text-sm rounded-2xl shadow-lg"
            >
              Open Lesson Details
            </BaseButton>
          </Link>
        </div>
      </div>

      <!-- THE 3 BIG BEACH LAUNCHPAD TILES: Never Get Lost (Foolproof Navigation) -->
      <div>
        <div class="flex items-center justify-between mb-3 px-1">
          <h2 class="text-xs font-black uppercase tracking-widest text-slate-400">
            Quick Actions
          </h2>
          <span class="text-xs font-bold text-slate-400">Tap to open</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <!-- Tile 1: My Schedule -->
          <Link
            :href="route('instructor.availability.index')"
            class="group bg-white hover:bg-ocean-50/50 p-5 rounded-3xl border border-slate-100 hover:border-ocean-200 shadow-sm transition-all duration-200 flex items-center sm:flex-col sm:items-start gap-4"
          >
            <div class="w-12 h-12 rounded-2xl bg-ocean-50 text-ocean-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
              <CalendarDaysIcon class="w-6 h-6" />
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="text-base font-black text-slate-900 group-hover:text-ocean-700 transition-colors">
                My Schedule
              </h3>
              <p class="text-xs font-medium text-slate-500 mt-1">
                Open or close days you can teach.
              </p>
            </div>
            <ArrowRightIcon class="w-5 h-5 text-slate-300 group-hover:text-ocean-600 group-hover:translate-x-1 transition-all shrink-0" />
          </Link>

          <!-- Tile 2: My Lessons -->
          <Link
            :href="route('instructor.bookings.index')"
            class="group bg-white hover:bg-emerald-50/50 p-5 rounded-3xl border border-slate-100 hover:border-emerald-200 shadow-sm transition-all duration-200 flex items-center sm:flex-col sm:items-start gap-4"
          >
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
              <UserGroupIcon class="w-6 h-6" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <h3 class="text-base font-black text-slate-900 group-hover:text-emerald-700 transition-colors">
                  My Lessons
                </h3>
                <span class="text-[10px] font-black px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full">
                  {{ stats.total_bookings }}
                </span>
              </div>
              <p class="text-xs font-medium text-slate-500 mt-1">
                See confirmed students and past history.
              </p>
            </div>
            <ArrowRightIcon class="w-5 h-5 text-slate-300 group-hover:text-emerald-600 group-hover:translate-x-1 transition-all shrink-0" />
          </Link>

          <!-- Tile 3: My Beach QR Code -->
          <Link
            :href="route('walkin.scan', user.id)"
            class="group bg-white hover:bg-purple-50/50 p-5 rounded-3xl border border-slate-100 hover:border-purple-200 shadow-sm transition-all duration-200 flex items-center sm:flex-col sm:items-start gap-4"
          >
            <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
              <QrCodeIcon class="w-6 h-6" />
            </div>
            <div class="flex-1 min-w-0">
              <h3 class="text-base font-black text-slate-900 group-hover:text-purple-700 transition-colors">
                My Beach QR Code
              </h3>
              <p class="text-xs font-medium text-slate-500 mt-1">
                Show tourists to book you directly on the spot.
              </p>
            </div>
            <ArrowRightIcon class="w-5 h-5 text-slate-300 group-hover:text-purple-600 group-hover:translate-x-1 transition-all shrink-0" />
          </Link>
        </div>
      </div>

      <!-- Main 2-Column Section: Upcoming Students & Earnings/Standing -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left Column (7 cols): Next Upcoming Lessons -->
        <div class="lg:col-span-7 space-y-4">
          <div class="flex items-center justify-between px-1">
            <h2 class="text-xs font-black uppercase tracking-widest text-slate-400">
              Who is Next in the Water?
            </h2>
            <Link
              :href="route('instructor.bookings.index')"
              class="text-xs font-black text-ocean-600 hover:text-ocean-700 hover:underline uppercase tracking-wider"
            >
              See All Schedule &rarr;
            </Link>
          </div>

          <!-- List of Confirmed Upcoming Lessons -->
          <div v-if="activities.upcoming_bookings && activities.upcoming_bookings.length > 0" class="space-y-3">
            <div
              v-for="booking in activities.upcoming_bookings"
              :key="booking.id"
              class="bg-white rounded-3xl p-5 border border-slate-100 shadow-sm hover:border-ocean-200 transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-4"
            >
              <div class="flex items-start gap-3.5">
                <div class="w-11 h-11 rounded-2xl bg-ocean-50 text-ocean-600 font-black text-xs flex flex-col items-center justify-center shrink-0 border border-ocean-100">
                  <span class="text-[9px] uppercase tracking-wider opacity-70">{{ booking.date.split(' ')[0] }}</span>
                  <span class="text-base leading-none mt-0.5">{{ booking.date.split(' ')[1]?.replace(',', '') }}</span>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <p class="text-base font-black text-slate-900">{{ booking.student_name }}</p>
                    <span class="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md">
                      {{ booking.time_period }}
                    </span>
                  </div>
                  <p class="text-xs font-bold text-slate-500 flex items-center gap-1.5 mt-1">
                    <MapPinIcon class="w-3.5 h-3.5 text-ocean-500" />
                    <span>{{ booking.spot_name }}</span>
                  </p>
                </div>
              </div>

              <Link :href="route('instructor.bookings.show', booking.id)" class="self-end sm:self-center">
                <BaseButton
                  variant="secondary"
                  size="sm"
                  class="min-h-[44px] px-4 font-bold text-xs rounded-xl"
                >
                  View Details
                </BaseButton>
              </Link>
            </div>
          </div>

          <!-- Empty State: No Upcoming Lessons -->
          <div
            v-else
            class="bg-white rounded-3xl p-8 border border-slate-100 shadow-sm text-center flex flex-col items-center justify-center"
          >
            <div class="w-14 h-14 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-400 mb-3">
              <CalendarDaysIcon class="w-7 h-7" />
            </div>
            <h3 class="text-base font-black text-slate-900">
              No Upcoming Lessons Right Now
            </h3>
            <p class="text-xs font-medium text-slate-500 mt-1 max-w-sm">
              Enjoy the waves, or open more slots on your schedule so tourists can book you.
            </p>
            <Link :href="route('instructor.availability.index')" class="mt-4">
              <BaseButton variant="primary" size="sm" class="min-h-[44px] px-5 font-bold text-xs rounded-xl">
                Open Time Slots
              </BaseButton>
            </Link>
          </div>
        </div>

        <!-- Right Column (5 cols): Earnings, Standing & Reviews -->
        <div class="lg:col-span-5 space-y-6">
          
          <!-- Earnings & Standing Section -->
          <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm space-y-4">
            <h2 class="text-xs font-black uppercase tracking-widest text-slate-400">
              Your Earnings & Standing
            </h2>

            <div class="grid grid-cols-2 gap-3">
              <!-- This Month's Pay -->
              <div class="p-4 bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 rounded-2xl">
                <p class="text-[10px] font-black uppercase tracking-wider text-emerald-700">This Month's Pay</p>
                <p class="text-xl font-black font-mono text-emerald-950 mt-1">
                  {{ stats.this_month_earnings }}
                </p>
                <p class="text-[10px] font-bold text-emerald-700 mt-1">
                  Total: {{ stats.total_earnings }}
                </p>
              </div>

              <!-- Completed Lessons -->
              <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Lessons Taught</p>
                <p class="text-xl font-black font-mono text-slate-900 mt-1">
                  {{ stats.completed_lessons }}
                </p>
                <p class="text-[10px] font-bold text-slate-500 mt-1">
                  Completed sessions
                </p>
              </div>

              <!-- Rating -->
              <div class="p-4 bg-amber-50/70 border border-amber-100 rounded-2xl">
                <p class="text-[10px] font-black uppercase tracking-wider text-amber-800">Tourist Rating</p>
                <div class="flex items-center gap-1.5 mt-1">
                  <p class="text-xl font-black text-amber-950">{{ profile.average_rating }}</p>
                  <div class="flex text-amber-400">
                    <StarIconSolid class="w-4 h-4" />
                  </div>
                </div>
                <p class="text-[10px] font-bold text-amber-700 mt-1">Verified reviews</p>
              </div>

              <!-- Safety Record -->
              <div class="p-4 bg-slate-50 border border-slate-100 rounded-2xl">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Safety Standing</p>
                <p class="text-xs font-black mt-1.5" :class="profile.strike_count === 0 ? 'text-emerald-700' : 'text-rose-600'">
                  {{ profile.strike_count === 0 ? 'Clean Record (0 Strikes)' : `${profile.strike_count} Strikes` }}
                </p>
                <p class="text-[10px] font-bold text-slate-400 mt-1">Level {{ profile.level }} Certified</p>
              </div>
            </div>
          </div>

          <!-- Recent Tourist Feedback -->
          <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-xs font-black uppercase tracking-widest text-slate-400">
                Recent Tourist Feedback
              </h3>
              <span class="text-xs font-bold text-slate-400">From Surfers</span>
            </div>

            <div v-if="activities.recent_reviews && activities.recent_reviews.length > 0" class="space-y-3">
              <div
                v-for="review in activities.recent_reviews"
                :key="review.id"
                class="p-3.5 bg-slate-50 rounded-2xl border border-slate-100 text-xs space-y-1.5"
              >
                <div class="flex items-center justify-between">
                  <span class="font-black text-slate-900">{{ review.student_name }}</span>
                  <div class="flex text-amber-400">
                    <StarIconSolid
                      v-for="i in 5"
                      :key="i"
                      class="w-3 h-3"
                      :class="i <= review.rating ? 'text-amber-400' : 'text-slate-200'"
                    />
                  </div>
                </div>
                <p class="text-slate-600 italic font-medium">
                  "{{ review.comment }}"
                </p>
                <p class="text-[10px] font-bold text-slate-400 text-right">
                  {{ review.date }}
                </p>
              </div>
            </div>

            <div v-else class="py-6 text-center text-xs font-bold text-slate-400">
              No student reviews received yet.
            </div>
          </div>

        </div>

      </div>

    </div>
  </AppLayout>
</template>
