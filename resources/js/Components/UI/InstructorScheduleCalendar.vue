<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import Modal from '@/Components/Modal.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'
import {
  CalendarDaysIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClockIcon,
  MapPinIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'

export interface ScheduleBooking {
  id: number
  booking_ref: string
  student_name: string
  surf_spot_name: string
  skill_level: string
  session_type: string
  student_count: number
  total_amount: number
  date: string
  time_period: string
  start_time: string
  end_time: string
  duration_hours: number
  status: string
  is_reserved: boolean
  waiver_signed: boolean
  payment_method: string | null
  payment_status: string | null
  hold_remaining_seconds: number
  accept_remaining_seconds: number
  can_accept: boolean
  show_url: string
}

export interface ScheduleDay {
  date: string
  day_short: string
  day_num: string
  day_label: string
  is_today: boolean
  manual_blocks: Record<string, boolean>
  open_hours: string[]
  bookings: ScheduleBooking[]
}

export interface WeekSchedule {
  week_start: string
  week_end: string
  week_label: string
  prev_week: string
  next_week: string
  hours: string[]
  instructor_level: number
  slot_capacity: number
  summary: {
    reserved: number
    pending: number
    awaiting_payment: number
    confirmed: number
    in_progress: number
  }
  days: ScheduleDay[]
}

const props = defineProps<{
  initialSchedule: WeekSchedule
  focusDate?: string | null
}>()

const schedule = ref<WeekSchedule>(props.initialSchedule)
const loading = ref(false)
const loadError = ref<string | null>(null)
const selectedBooking = ref<ScheduleBooking | null>(null)

const loadedAt = ref(Date.now())
const nowTick = ref(Date.now())
const refreshedExpiredSnapshot = ref<number | null>(null)
let ticker: ReturnType<typeof setInterval> | null = null

const pickInitialMobileDay = (data: WeekSchedule, targetDate?: string | null): string => {
  if (targetDate && data.days.some((d) => d.date === targetDate)) {
    return targetDate
  }
  const todayDay = data.days.find((d) => d.is_today)
  if (todayDay) return todayDay.date

  const busyDay = data.days.find((d) => d.bookings.length > 0)
  if (busyDay) return busyDay.date

  return data.days[0]?.date ?? ''
}

const selectedDayDate = ref<string>(pickInitialMobileDay(props.initialSchedule, props.focusDate))

watch(
  () => props.initialSchedule,
  (next) => {
    schedule.value = next
    loadedAt.value = Date.now()
    refreshedExpiredSnapshot.value = null
    selectedDayDate.value = pickInitialMobileDay(next, props.focusDate)
  },
)

const hasActiveCountdowns = computed(() =>
  schedule.value.days.some((day) =>
    day.bookings.some((b) => b.hold_remaining_seconds > 0 || b.accept_remaining_seconds > 0),
  ),
)

const remainingSeconds = (booking: ScheduleBooking): number => {
  const base = booking.is_reserved
    ? booking.hold_remaining_seconds
    : booking.status === 'pending'
      ? booking.accept_remaining_seconds
      : 0

  if (base <= 0) return 0

  const elapsed = Math.floor((nowTick.value - loadedAt.value) / 1000)
  return Math.max(0, base - elapsed)
}

const loadWeek = async (weekAnchor?: string, preferredDate?: string | null) => {
  loading.value = true
  loadError.value = null

  try {
    const { data } = await axios.get<WeekSchedule>(route('instructor.availability.schedule'), {
      params: weekAnchor ? { week: weekAnchor } : {},
    })
    schedule.value = data
    loadedAt.value = Date.now()
    refreshedExpiredSnapshot.value = null
    selectedDayDate.value = pickInitialMobileDay(data, preferredDate)

    if (selectedBooking.value) {
      const updated = data.days
        .flatMap((d) => d.bookings)
        .find((b) => b.id === selectedBooking.value?.id)
      selectedBooking.value = updated ?? null
    }
  } catch {
    loadError.value = 'Could not load that week. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  ticker = setInterval(() => {
    if (!hasActiveCountdowns.value) return
    nowTick.value = Date.now()

    const hasJustExpiredHold = schedule.value.days.some((day) =>
      day.bookings.some(
        (b) => b.is_reserved && b.hold_remaining_seconds > 0 && remainingSeconds(b) === 0,
      ),
    )

    if (hasJustExpiredHold && refreshedExpiredSnapshot.value !== loadedAt.value && !loading.value) {
      refreshedExpiredSnapshot.value = loadedAt.value
      loadWeek(schedule.value.week_start, selectedDayDate.value)
    }
  }, 1000)
})

onUnmounted(() => {
  if (ticker) clearInterval(ticker)
})

const formatClock = (time: string): string => {
  const [hour, minute] = time.slice(0, 5).split(':').map(Number)
  if (Number.isNaN(hour) || Number.isNaN(minute)) return time
  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12
  return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
}

const nextHour = (time: string): string => {
  const [hour, minute] = time.slice(0, 5).split(':').map(Number)
  return `${String((hour + 1) % 24).padStart(2, '0')}:${String(minute || 0).padStart(2, '0')}`
}

const formatCompactTimer = (seconds: number): string => {
  if (seconds <= 0) return 'expired'
  const hours = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  if (hours > 0) return `${hours}h ${mins}m`
  if (mins > 0) return `${mins}m ${String(secs).padStart(2, '0')}s`
  return `${secs}s`
}

const formatLocalDateLong = (isoDate: string): string => {
  return formatPhilippineDate(
    isoDate.slice(0, 10),
    {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: 'numeric',
    },
    'en-US',
    isoDate,
  )
}

const periodForHour = (hour: string): 'morning' | 'afternoon' =>
  parseInt(hour.slice(0, 2), 10) < 12 ? 'morning' : 'afternoon'

const isManuallyBlocked = (day: ScheduleDay, hour: string): boolean =>
  Boolean(day.manual_blocks?.[periodForHour(hour)])

const isHourOpen = (day: ScheduleDay, hour: string): boolean =>
  !isManuallyBlocked(day, hour) && day.open_hours.includes(hour)

const bookingsInHour = (day: ScheduleDay, hour: string): ScheduleBooking[] =>
  day.bookings.filter((b) => b.start_time <= hour && b.end_time > hour)

const seatsUsedInHour = (day: ScheduleDay, hour: string): number =>
  bookingsInHour(day, hour).reduce((sum, b) => sum + (b.student_count || 1), 0)

type PillVariant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical'

const statusInfo = (status: string): {
  label: string
  pillVariant: PillVariant
  cardClass: string
  dotClass: string
} => {
  switch (status) {
    case 'reserved':
      return {
        label: 'Reserved',
        pillVariant: 'pending',
        cardClass: 'border-dashed border-amber-400 bg-amber-50 text-amber-950 hover:border-amber-600',
        dotClass: 'bg-amber-500',
      }
    case 'pending':
      return {
        label: 'Pending approval',
        pillVariant: 'pending',
        cardClass: 'border-amber-400 bg-amber-100 text-amber-950 hover:border-amber-600',
        dotClass: 'bg-amber-600',
      }
    case 'awaiting_payment':
      return {
        label: 'Awaiting payment',
        pillVariant: 'pending',
        cardClass: 'border-lagoon-400 bg-amber-50 text-deep-950 hover:border-lagoon-600',
        dotClass: 'bg-amber-500',
      }
    case 'confirmed':
      return {
        label: 'Confirmed',
        pillVariant: 'verified',
        cardClass: 'border-lagoon-500 bg-lagoon-100 text-lagoon-950 hover:border-lagoon-700',
        dotClass: 'bg-lagoon-600',
      }
    case 'in_progress':
      return {
        label: 'In progress',
        pillVariant: 'live',
        cardClass: 'border-deep-900 bg-deep-900 text-white hover:bg-deep-800',
        dotClass: 'bg-lagoon-400',
      }
    default:
      return {
        label: status.replace(/_/g, ' '),
        pillVariant: 'neutral',
        cardClass: 'border-sand-300 bg-sand-100 text-stone-800',
        dotClass: 'bg-stone-500',
      }
  }
}

const dayIndicatorDot = (day: ScheduleDay): string | null => {
  if (day.bookings.some((b) => b.status === 'in_progress')) return 'bg-deep-900'
  if (day.bookings.some((b) => b.is_reserved || b.status === 'pending' || b.status === 'awaiting_payment')) {
    return 'bg-amber-500'
  }
  if (day.bookings.some((b) => b.status === 'confirmed')) return 'bg-lagoon-600'
  if (day.manual_blocks.morning && day.manual_blocks.afternoon) return 'bg-rose-500'
  return null
}

const selectedDay = computed<ScheduleDay | undefined>(() =>
  schedule.value.days.find((d) => d.date === selectedDayDate.value) ?? schedule.value.days[0],
)

interface MobileTimelineBlock {
  key: string
  kind: 'booking' | 'available' | 'blocked' | 'off'
  start_time: string
  end_time: string
  booking?: ScheduleBooking
  remainingSeats?: number
}

const mobileTimeline = computed<MobileTimelineBlock[]>(() => {
  const day = selectedDay.value
  if (!day) return []

  const blocks: MobileTimelineBlock[] = []
  const seenBookings = new Set<number>()
  let freeRunStart: string | null = null
  let freeRunEnd: string | null = null
  let freeRunKind: 'available' | 'blocked' | 'off' | null = null

  const flushFreeRun = () => {
    if (freeRunStart && freeRunEnd && freeRunKind) {
      blocks.push({
        key: `${freeRunKind}-${freeRunStart}-${freeRunEnd}`,
        kind: freeRunKind,
        start_time: freeRunStart,
        end_time: freeRunEnd,
      })
    }
    freeRunStart = null
    freeRunEnd = null
    freeRunKind = null
  }

  for (const hour of schedule.value.hours) {
    const active = bookingsInHour(day, hour)

    if (active.length > 0) {
      flushFreeRun()
      for (const booking of active) {
        if (seenBookings.has(booking.id)) continue
        seenBookings.add(booking.id)
        const used = seatsUsedInHour(day, booking.start_time)
        const remainingSeats = Math.max(0, schedule.value.slot_capacity - used)
        blocks.push({
          key: `booking-${booking.id}`,
          kind: 'booking',
          start_time: booking.start_time,
          end_time: booking.end_time,
          booking,
          remainingSeats,
        })
      }
      continue
    }

    const slotKind: 'available' | 'blocked' | 'off' = isManuallyBlocked(day, hour)
      ? 'blocked'
      : isHourOpen(day, hour)
        ? 'available'
        : 'off'

    if (freeRunKind === slotKind && freeRunEnd === hour) {
      freeRunEnd = nextHour(hour)
    } else {
      flushFreeRun()
      freeRunKind = slotKind
      freeRunStart = hour
      freeRunEnd = nextHour(hour)
    }
  }

  flushFreeRun()
  return blocks
})

const bookingStatusSentence = (booking: ScheduleBooking): string => {
  if (booking.is_reserved) {
    return booking.waiver_signed
      ? 'Waiver signed — awaiting tourist payment method selection'
      : 'Awaiting waiver completion'
  }
  switch (booking.status) {
    case 'pending':
      return 'Waiver signed — pending your approval'
    case 'awaiting_payment':
      return 'Accepted — awaiting student GCash payment'
    case 'confirmed':
      return 'Confirmed and scheduled'
    case 'in_progress':
      return 'Lesson currently in progress'
    default:
      return booking.status.replace(/_/g, ' ')
  }
}

const paymentSummarySentence = (booking: ScheduleBooking): string => {
  if (booking.is_reserved || !booking.payment_method) {
    return 'Not paid (payment method not selected yet)'
  }
  if (booking.payment_status === 'completed') {
    return booking.payment_method === 'cash' ? 'Paid in cash (Verified)' : 'Paid via GCash'
  }
  if (booking.payment_method === 'cash') {
    return 'Cash on hand (Collect at beach)'
  }
  if (booking.payment_status === 'pending_verification') {
    return 'GCash receipt uploaded — awaiting verification'
  }
  return 'GCash payment pending'
}

const instructorActionSentence = (booking: ScheduleBooking): string => {
  if (booking.is_reserved) {
    const secs = remainingSeconds(booking)
    return secs > 0
      ? `Wait (hold expires in ${formatCompactTimer(secs)})`
      : 'Wait (hold expiring shortly)'
  }
  if (booking.status === 'pending') {
    const secs = remainingSeconds(booking)
    return secs > 0
      ? `Accept / Decline (${formatCompactTimer(secs)} left)`
      : 'Response window closed'
  }
  if (booking.status === 'awaiting_payment') return 'Wait for student payment'
  if (booking.status === 'confirmed') return 'Accepted — ready to start on lesson day'
  if (booking.status === 'in_progress') return 'Session active'
  return 'View details'
}
</script>

<template>
  <div class="space-y-4">
    <!-- Week controls & status summary -->
    <AppCard padding="md">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="eyebrow">Booking calendar</p>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <h2 class="text-title font-semibold text-deep-950">{{ schedule.week_label }}</h2>
            <span v-if="loading" class="text-label text-stone-500">Refreshing…</span>
          </div>
          <p class="mt-1 text-body-sm text-stone-600">
            Reserved slots show live 15-minute tourist checkout holds so you can see occupied hours right away.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <AppButton
            variant="secondary"
            size="sm"
            :disabled="loading"
            aria-label="Previous week"
            @click="loadWeek(schedule.prev_week)"
          >
            <ChevronLeftIcon class="h-4 w-4" aria-hidden="true" />
            Prev
          </AppButton>
          <AppButton
            variant="secondary"
            size="sm"
            :disabled="loading"
            @click="loadWeek(undefined)"
          >
            Today
          </AppButton>
          <AppButton
            variant="secondary"
            size="sm"
            :disabled="loading"
            aria-label="Next week"
            @click="loadWeek(schedule.next_week)"
          >
            Next
            <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
          </AppButton>
        </div>
      </div>

      <!-- Summary badges & Legend -->
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-sand-200 pt-3">
        <div class="flex flex-wrap items-center gap-2">
          <StatusPill :variant="schedule.summary.reserved > 0 ? 'pending' : 'neutral'">
            {{ schedule.summary.reserved }} Reserved
          </StatusPill>
          <StatusPill :variant="schedule.summary.pending > 0 ? 'pending' : 'neutral'">
            {{ schedule.summary.pending }} Pending approval
          </StatusPill>
          <StatusPill v-if="schedule.summary.awaiting_payment > 0" variant="pending">
            {{ schedule.summary.awaiting_payment }} Awaiting payment
          </StatusPill>
          <StatusPill :variant="schedule.summary.confirmed > 0 ? 'verified' : 'neutral'">
            {{ schedule.summary.confirmed }} Confirmed
          </StatusPill>
          <StatusPill v-if="schedule.summary.in_progress > 0" variant="live">
            {{ schedule.summary.in_progress }} Live
          </StatusPill>
        </div>

        <div class="flex flex-wrap items-center gap-3 text-label text-stone-600">
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm border border-sand-300 bg-white" aria-hidden="true" />
            Available
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm border border-dashed border-amber-500 bg-amber-50" aria-hidden="true" />
            Reserved (Hold)
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm border border-amber-500 bg-amber-100" aria-hidden="true" />
            Pending approval
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm border border-lagoon-400 bg-amber-50" aria-hidden="true" />
            Awaiting payment
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm bg-lagoon-600" aria-hidden="true" />
            Confirmed
          </span>
          <span class="inline-flex items-center gap-1.5">
            <span class="h-2.5 w-2.5 rounded-sm bg-rose-400" aria-hidden="true" />
            Blocked
          </span>
        </div>
      </div>

      <p
        v-if="loadError"
        class="mt-3 rounded-control border border-rose-200 bg-rose-50 px-3 py-2 text-body-sm font-semibold text-rose-800"
      >
        {{ loadError }}
      </p>
    </AppCard>

    <!-- Desktop: Dated Week Grid (Mon–Sun × 6:00 AM–6:00 PM) -->
    <AppCard padding="none" class="hidden overflow-hidden md:block">
      <div class="overflow-x-auto">
        <div class="min-w-[940px]">
          <!-- Day column headers -->
          <div class="grid grid-cols-[76px_repeat(7,minmax(0,1fr))] border-b border-sand-200 bg-sand-100">
            <div class="px-2 py-3 text-center text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              Time
            </div>
            <div
              v-for="day in schedule.days"
              :key="day.date"
              class="border-l border-sand-200 px-2 py-2.5 text-center"
              :class="day.is_today ? 'bg-lagoon-50' : ''"
            >
              <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                {{ day.day_short }}
              </p>
              <p
                class="mt-0.5 inline-flex h-7 w-7 items-center justify-center rounded-full text-body font-semibold"
                :class="day.is_today ? 'bg-lagoon-700 text-white' : 'text-deep-950'"
              >
                {{ day.day_num }}
              </p>
            </div>
          </div>

          <!-- Hourly rows -->
          <div
            v-for="hour in schedule.hours"
            :key="hour"
            class="grid grid-cols-[76px_repeat(7,minmax(0,1fr))] border-b border-sand-200/80 last:border-b-0"
          >
            <div class="flex items-start justify-center px-2 py-2.5 text-label font-semibold tabular-nums text-stone-500">
              {{ formatClock(hour) }}
            </div>

            <div
              v-for="day in schedule.days"
              :key="`${day.date}-${hour}`"
              class="min-h-[60px] border-l border-sand-200/80 p-1"
              :class="day.is_today ? 'bg-lagoon-50/25' : ''"
            >
              <!-- Bookings occupying this hour -->
              <div v-if="bookingsInHour(day, hour).length > 0" class="space-y-1">
                <button
                  v-for="booking in bookingsInHour(day, hour)"
                  :key="`${booking.id}-${hour}`"
                  type="button"
                  class="w-full rounded-control border px-2 py-1.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600"
                  :class="statusInfo(booking.status).cardClass"
                  @click="selectedBooking = booking"
                >
                  <template v-if="booking.start_time === hour">
                    <div class="flex flex-wrap items-center justify-between gap-x-1 gap-y-0.5">
                      <span
                        class="text-[10px] font-bold uppercase leading-tight tracking-[0.03em]"
                        :title="statusInfo(booking.status).label"
                      >
                        {{ statusInfo(booking.status).label }}
                      </span>
                      <span
                        v-if="booking.is_reserved && remainingSeconds(booking) > 0"
                        class="shrink-0 font-mono text-[10px] font-bold"
                      >
                        {{ formatCompactTimer(remainingSeconds(booking)) }}
                      </span>
                    </div>
                    <p class="truncate text-label font-semibold" :title="booking.student_name">
                      {{ booking.student_name }}
                    </p>
                    <p class="truncate text-[10px] opacity-80">
                      {{ formatClock(booking.start_time) }}–{{ formatClock(booking.end_time) }}
                      <template v-if="schedule.slot_capacity > 1">
                        · {{ booking.student_count }}/{{ schedule.slot_capacity }}
                      </template>
                    </p>
                  </template>
                  <template v-else>
                    <p class="truncate text-[10px] font-semibold opacity-90">
                      ↳ {{ statusInfo(booking.status).label }} · {{ booking.student_name }}
                    </p>
                  </template>
                </button>

                <p
                  v-if="schedule.slot_capacity > 1 && isHourOpen(day, hour) && seatsUsedInHour(day, hour) < schedule.slot_capacity"
                  class="px-1 text-[10px] font-semibold text-lagoon-700"
                >
                  +{{ schedule.slot_capacity - seatsUsedInHour(day, hour) }}
                  {{ schedule.slot_capacity - seatsUsedInHour(day, hour) === 1 ? 'seat' : 'seats' }} open
                </p>
              </div>

              <!-- Empty slot states -->
              <div
                v-else-if="isManuallyBlocked(day, hour)"
                class="flex h-full min-h-[52px] items-center justify-center rounded-control border border-rose-200 bg-rose-50 px-1.5 text-center text-[11px] font-semibold text-rose-700"
              >
                Blocked
              </div>
              <div
                v-else-if="isHourOpen(day, hour)"
                class="flex h-full min-h-[52px] items-center justify-center rounded-control border border-sand-200/70 bg-white transition-colors hover:bg-lagoon-50/40"
                title="Available"
                aria-label="Available"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-sand-300/70" aria-hidden="true" />
              </div>
              <div
                v-else
                class="flex h-full min-h-[52px] items-center justify-center rounded-control bg-sand-100/70 px-1.5 text-center text-[11px] text-stone-400"
              >
                Off
              </div>
            </div>
          </div>
        </div>
      </div>
    </AppCard>

    <!-- Mobile: 7-day strip + Chronological Day Timeline -->
    <div class="space-y-3 md:hidden">
      <div class="grid grid-cols-7 gap-1.5">
        <button
          v-for="day in schedule.days"
          :key="day.date"
          type="button"
          class="flex flex-col items-center rounded-control border py-2 text-center transition-colors"
          :class="
            day.date === selectedDayDate
              ? 'border-deep-950 bg-deep-950 text-white'
              : 'border-sand-200 bg-white text-deep-900 hover:border-lagoon-600'
          "
          @click="selectedDayDate = day.date"
        >
          <span class="text-[10px] font-semibold uppercase opacity-75">{{ day.day_short }}</span>
          <span class="mt-0.5 text-body-sm font-bold">{{ day.day_num }}</span>
          <span
            v-if="dayIndicatorDot(day)"
            class="mt-1 h-1.5 w-1.5 rounded-full"
            :class="day.date === selectedDayDate ? 'bg-amber-300' : dayIndicatorDot(day)"
            aria-hidden="true"
          />
          <span v-else class="mt-1 h-1.5 w-1.5" aria-hidden="true" />
        </button>
      </div>

      <AppCard v-if="selectedDay" padding="md">
        <div class="flex items-center justify-between gap-2 border-b border-sand-200 pb-3">
          <div>
            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              Schedule management
            </p>
            <h3 class="text-title font-semibold text-deep-950">
              {{ formatLocalDateLong(selectedDay.date) }}
            </h3>
          </div>
          <StatusPill v-if="selectedDay.bookings.length > 0" variant="pending" :dot="false">
            {{ selectedDay.bookings.length }} {{ selectedDay.bookings.length === 1 ? 'booking' : 'bookings' }}
          </StatusPill>
        </div>

        <div class="mt-3 space-y-2.5">
          <template v-for="block in mobileTimeline" :key="block.key">
            <button
              v-if="block.kind === 'booking' && block.booking"
              type="button"
              class="w-full rounded-control border p-3.5 text-left transition-colors"
              :class="statusInfo(block.booking.status).cardClass"
              @click="selectedBooking = block.booking"
            >
              <div class="flex items-start justify-between gap-2">
                <div>
                  <p class="text-body-sm font-bold tabular-nums">
                    {{ formatClock(block.start_time) }} – {{ formatClock(block.end_time) }}
                  </p>
                  <p class="mt-0.5 text-body font-semibold">
                    {{ block.booking.student_name }} · {{ block.booking.surf_spot_name }}
                  </p>
                </div>
                <StatusPill :variant="statusInfo(block.booking.status).pillVariant">
                  {{ statusInfo(block.booking.status).label }}
                </StatusPill>
              </div>
              <p class="mt-2 text-label opacity-90">
                {{ bookingStatusSentence(block.booking) }}
                <template v-if="block.booking.is_reserved && remainingSeconds(block.booking) > 0">
                  · {{ formatCompactTimer(remainingSeconds(block.booking)) }} hold left
                </template>
                <template v-else-if="block.booking.status === 'pending' && remainingSeconds(block.booking) > 0">
                  · {{ formatCompactTimer(remainingSeconds(block.booking)) }} to respond
                </template>
              </p>
              <p
                v-if="schedule.slot_capacity > 1 && (block.remainingSeats ?? 0) > 0"
                class="mt-1 text-label font-semibold"
              >
                {{ block.remainingSeats }} of {{ schedule.slot_capacity }} seats still open for same-level surfers
              </p>
            </button>

            <div
              v-else-if="block.kind === 'available'"
              class="flex items-center justify-between rounded-control border border-sand-200 bg-white px-3.5 py-3"
            >
              <span class="text-body-sm font-semibold tabular-nums text-deep-900">
                {{ formatClock(block.start_time) }} – {{ formatClock(block.end_time) }}
              </span>
              <StatusPill variant="verified">Available</StatusPill>
            </div>

            <div
              v-else-if="block.kind === 'blocked'"
              class="flex items-center justify-between rounded-control border border-rose-200 bg-rose-50 px-3.5 py-3"
            >
              <span class="text-body-sm font-semibold tabular-nums text-rose-900">
                {{ formatClock(block.start_time) }} – {{ formatClock(block.end_time) }}
              </span>
              <StatusPill variant="critical" :dot="false">Blocked</StatusPill>
            </div>

            <div
              v-else
              class="flex items-center justify-between rounded-control border border-dashed border-sand-300 bg-sand-50 px-3.5 py-2.5 text-stone-500"
            >
              <span class="text-label font-semibold tabular-nums">
                {{ formatClock(block.start_time) }} – {{ formatClock(block.end_time) }}
              </span>
              <span class="text-label">Off schedule</span>
            </div>
          </template>
        </div>
      </AppCard>
    </div>

    <!-- Booking Slot Detail Modal -->
    <Modal :show="selectedBooking !== null" @close="selectedBooking = null" maxWidth="md">
      <div v-if="selectedBooking" class="p-6">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              {{ selectedBooking.booking_ref }}
            </p>
            <h3 class="mt-1 text-title font-semibold text-deep-950">
              {{ selectedBooking.student_name }}
            </h3>
          </div>
          <StatusPill :variant="statusInfo(selectedBooking.status).pillVariant">
            {{ statusInfo(selectedBooking.status).label }}
          </StatusPill>
        </div>

        <div class="mt-4 space-y-2 rounded-control border border-sand-200 bg-sand-50 p-3.5 text-body-sm text-deep-900">
          <p class="flex items-center gap-2">
            <CalendarDaysIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
            <span>{{ formatLocalDateLong(selectedBooking.date) }}</span>
          </p>
          <p class="flex items-center gap-2">
            <ClockIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
            <span class="font-semibold tabular-nums">
              {{ formatClock(selectedBooking.start_time) }} – {{ formatClock(selectedBooking.end_time) }}
            </span>
          </p>
          <p class="flex items-center gap-2">
            <MapPinIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
            <span>{{ selectedBooking.surf_spot_name }}</span>
          </p>
          <p class="flex items-center gap-2 capitalize">
            <UserGroupIcon class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
            <span>
              {{ selectedBooking.skill_level }} · {{ selectedBooking.session_type }} ·
              {{ selectedBooking.student_count }} {{ selectedBooking.student_count === 1 ? 'student' : 'students' }} ·
              ₱{{ Number(selectedBooking.total_amount).toLocaleString() }}
            </span>
          </p>
        </div>

        <dl class="mt-4 space-y-2 text-body-sm">
          <div class="flex justify-between gap-3">
            <dt class="text-stone-500">Booking status</dt>
            <dd class="text-right font-semibold text-deep-900">{{ bookingStatusSentence(selectedBooking) }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-stone-500">Payment status</dt>
            <dd class="text-right font-semibold text-deep-900">{{ paymentSummarySentence(selectedBooking) }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-stone-500">Instructor action</dt>
            <dd class="text-right font-semibold text-deep-900">{{ instructorActionSentence(selectedBooking) }}</dd>
          </div>
        </dl>

        <div
          v-if="selectedBooking.is_reserved"
          class="mt-4 rounded-control border border-amber-300 bg-amber-50 p-3 text-label text-amber-950"
        >
          This schedule is temporarily held for 15 minutes while the tourist completes the liability waiver and selects a payment method. You cannot accept or decline until they finish.
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
          <template v-if="selectedBooking.is_reserved">
            <AppButton variant="secondary" size="sm" disabled>
              Accept
            </AppButton>
            <AppButton variant="secondary" size="sm" disabled>
              Decline
            </AppButton>
          </template>
          <AppButton variant="secondary" size="sm" @click="selectedBooking = null">
            Close
          </AppButton>
          <AppButton :href="selectedBooking.show_url" size="sm">
            Open lesson →
          </AppButton>
        </div>
      </div>
    </Modal>
  </div>
</template>
