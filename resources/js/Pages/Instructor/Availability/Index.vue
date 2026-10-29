<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppCard from '@/Components/UI/AppCard.vue'
import AppSwitch from '@/Components/UI/AppSwitch.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import StatusPill from '@/Components/UI/StatusPill.vue'
import {
  ArrowPathIcon,
  CloudIcon,
  SunIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'

interface Availability {
  id: number
  day_of_week: number
  time_period: string
  start_time?: string | null
  end_time?: string | null
  open_slots?: string[] | null
  slot_duration_minutes?: number
  is_available: boolean
}

interface PeriodSetting {
  open: boolean
  start_time: string
  end_time: string
  slots: Record<string, boolean>
}

interface BlockSummary {
  id: number
  date: string | null
  date_label: string | null
  time_period?: string | null
  time_period_label?: string | null
  booking_ref?: string | null
  student_name?: string | null
}

interface Props {
  availabilities: Record<number, Availability[]>
  daysOfWeek: { name: string; value: number; label: string }[]
  timePeriods: { name: string; value: string; label: string }[]
  profile: {
    id: number
    status: string
  }
  manualBlocks?: BlockSummary[]
  bookingBlocks?: BlockSummary[]
  // FR 1.9.3 - Instant Book toggle state.
  autoAcceptBookings?: boolean
  // Platform standard lesson length (minutes); 120 = 2 hours.
  standardSessionMinutes?: number
}

const props = defineProps<Props>()

// UI-First Reactive State for Weekly Grid
const gridState = reactive<Record<string, boolean>>({})
const initialGridState = ref<Record<string, boolean>>({})
const periodSettings = reactive<Record<string, PeriodSetting>>({})
const initialPeriodSettings = ref<string>('')

const defaultStart = (period: string) => (period === 'morning' ? '06:00' : '12:00')
const defaultEnd = (period: string) => (period === 'morning' ? '12:00' : '18:00')

const normalizeTime = (value?: string | null) => {
  if (!value) return ''
  const string = String(value)
  if (string.includes('T')) return string.slice(11, 16)
  return string.slice(0, 5)
}

const generateSlots = (start: string, end: string) => {
  const [startHour, startMinute] = start.split(':').map(Number)
  const [endHour, endMinute] = end.split(':').map(Number)
  const startTotal = startHour * 60 + startMinute
  const endTotal = endHour * 60 + endMinute

  if (!Number.isFinite(startTotal) || !Number.isFinite(endTotal) || startTotal >= endTotal) {
    return [] as string[]
  }

  const slots: string[] = []
  for (let minute = startTotal; minute + 60 <= endTotal; minute += 60) {
    const hour = Math.floor(minute / 60)
    const mins = minute % 60
    slots.push(`${String(hour).padStart(2, '0')}:${String(mins).padStart(2, '0')}`)
  }

  return slots
}

const settingKey = (dayValue: number, periodValue: string) => `${dayValue}-${periodValue}`

// Mobile Segmented Tab
const activeMobileTab = ref<'weekly' | 'exceptions'>('weekly')

// Initialize gridState and custom working-hour settings from props.
onMounted(() => {
  const snapshot: Record<string, boolean> = {}

  props.daysOfWeek.forEach(day => {
    props.timePeriods.forEach(period => {
      const key = settingKey(day.value, period.value)
      const dayAvailabilities = props.availabilities[day.value] || []
      const existing = dayAvailabilities.find(a => a.time_period === period.value)
      const isOpen = existing ? (existing.is_available === true || (existing.is_available as any) === 1) : false

      const start = normalizeTime(existing?.start_time) || defaultStart(period.value)
      const end = normalizeTime(existing?.end_time) || defaultEnd(period.value)
      const savedSlots = Array.isArray(existing?.open_slots) ? existing?.open_slots : null
      const slots: Record<string, boolean> = {}

      generateSlots(start, end).forEach(slot => {
        slots[slot] = savedSlots === null ? true : savedSlots.includes(slot)
      })

      gridState[key] = isOpen
      snapshot[key] = isOpen
      periodSettings[key] = {
        open: isOpen,
        start_time: start,
        end_time: end,
        slots,
      }
    })
  })

  initialGridState.value = snapshot
  initialPeriodSettings.value = JSON.stringify(periodSettings)
})

const syncSlots = (key: string) => {
  const setting = periodSettings[key]
  if (!setting) return

  const nextSlots = generateSlots(setting.start_time, setting.end_time)
  const previous = setting.slots
  setting.slots = Object.fromEntries(nextSlots.map(slot => [slot, previous[slot] ?? true]))
}

const toggleSlot = (key: string, slot: string) => {
  const setting = periodSettings[key]
  if (!setting) return
  setting.slots[slot] = !setting.slots[slot]
}

const setAllSlots = (key: string, open: boolean) => {
  const setting = periodSettings[key]
  if (!setting) return
  Object.keys(setting.slots).forEach(slot => {
    setting.slots[slot] = open
  })
}

const slotsFor = (key: string) => Object.keys(periodSettings[key]?.slots ?? {})

const slotLabel = (slot: string) => {
  const [hour, minute] = slot.split(':').map(Number)
  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12
  return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
}

const rangeError = (key: string) => {
  const setting = periodSettings[key]
  if (!setting) return ''
  if (!setting.start_time || !setting.end_time) return 'Add a start and end time.'
  if (setting.start_time >= setting.end_time) return 'End time must be after start time.'

  const period = key.split('-').slice(1).join('-')
  const lower = period === 'morning' ? '06:00' : '12:00'
  const upper = period === 'morning' ? '12:00' : '18:00'

  if (setting.start_time < lower || setting.end_time > upper) {
    return `Keep this ${period} inside ${lower}–${upper}.`
  }

  return ''
}

const formatRange = (dayValue: number, periodValue: string) => {
  const setting = periodSettings[settingKey(dayValue, periodValue)]
  if (!setting) return defaultStart(periodValue) + '–' + defaultEnd(periodValue)
  return `${setting.start_time}–${setting.end_time}`
}

const setPeriodOpen = (dayValue: number, periodValue: string, open: boolean) => {
  const key = settingKey(dayValue, periodValue)
  gridState[key] = open

  if (!periodSettings[key]) {
    const start = defaultStart(periodValue)
    const end = defaultEnd(periodValue)
    periodSettings[key] = {
      open,
      start_time: start,
      end_time: end,
      slots: Object.fromEntries(generateSlots(start, end).map(slot => [slot, true])),
    }
    return
  }

  periodSettings[key].open = open

  if (open && !Object.values(periodSettings[key].slots).some(Boolean)) {
    syncSlots(key)
    Object.keys(periodSettings[key].slots).forEach(slot => {
      periodSettings[key].slots[slot] = true
    })
  }
}

const openPeriods = computed(() =>
  props.daysOfWeek.flatMap(day =>
    props.timePeriods
      .filter(period => gridState[settingKey(day.value, period.value)])
      .map(period => ({
        key: settingKey(day.value, period.value),
        day,
        period,
        setting: periodSettings[settingKey(day.value, period.value)],
      })),
  ),
)

const bookableSlotsCount = computed(() =>
  openPeriods.value.reduce(
    (total, item) => total + Object.values(item.setting?.slots ?? {}).filter(Boolean).length,
    0,
  ),
)

const weeklyForm = useForm({
  availabilities: [] as any[],
})

const blockForm = useForm({
  dates: [] as string[],
  time_period: '' as string,
})

const toggleAvailability = (dayValue: number, periodValue: string) => {
  const key = settingKey(dayValue, periodValue)
  setPeriodOpen(dayValue, periodValue, !gridState[key])
}

const isDayFullyAvailable = (dayValue: number) => {
  return props.timePeriods.every(p => gridState[`${dayValue}-${p.value}`])
}

const isDayPartiallyAvailable = (dayValue: number) => {
  const activeCount = props.timePeriods.filter(p => gridState[`${dayValue}-${p.value}`]).length
  return activeCount > 0 && activeCount < props.timePeriods.length
}

const toggleDay = (dayValue: number) => {
  const allOn = isDayFullyAvailable(dayValue)
  props.timePeriods.forEach(p => {
    setPeriodOpen(dayValue, p.value, !allOn)
  })
}

const activeSlotsCount = computed(() => {
  return Object.values(gridState).filter(Boolean).length
})

const totalSlotsCount = computed(() => {
  return (props.daysOfWeek?.length || 7) * (props.timePeriods?.length || 2)
})

const hasUnsavedChanges = computed(() => {
  const gridChanged = Object.keys(gridState).some(key => gridState[key] !== initialGridState.value[key])
  const settingsChanged = JSON.stringify(periodSettings) !== initialPeriodSettings.value
  return gridChanged || settingsChanged
})

const saveWeekly = () => {
  // Keep the slot list in sync with the latest start/end inputs before saving.
  props.daysOfWeek.forEach(day => {
    props.timePeriods.forEach(period => {
      const key = settingKey(day.value, period.value)
      if (gridState[key]) syncSlots(key)
    })
  })

  const invalidRange = openPeriods.value.find(item => rangeError(item.key))
  if (invalidRange) {
    weeklyForm.setError('availabilities', rangeError(invalidRange.key))
    return
  }

  const emptyPeriod = openPeriods.value.find(
    item => !Object.values(item.setting?.slots ?? {}).some(Boolean),
  )
  if (emptyPeriod) {
    weeklyForm.setError('availabilities', 'Choose at least one open slot for every open period.')
    return
  }

  weeklyForm.clearErrors()

  weeklyForm.availabilities = props.daysOfWeek.flatMap(day =>
    props.timePeriods.map(period => {
      const key = settingKey(day.value, period.value)
      const setting = periodSettings[key]
      const open = gridState[key] === true

      return {
        day_of_week: day.value,
        time_period: period.value,
        is_available: open ? 1 : 0,
        start_time: setting?.start_time ?? defaultStart(period.value),
        end_time: setting?.end_time ?? defaultEnd(period.value),
        open_slots: setting
          ? Object.keys(setting.slots).filter(slot => setting.slots[slot])
          : [],
        slot_duration_minutes: props.standardSessionMinutes ?? 120,
      }
    }),
  )

  weeklyForm.post(route('instructor.availability.weekly'), {
    preserveScroll: true,
    onSuccess: () => {
      initialGridState.value = { ...gridState }
      initialPeriodSettings.value = JSON.stringify(periodSettings)
    },
  })
}

const resetToSaved = () => {
  Object.keys(initialGridState.value).forEach(key => {
    gridState[key] = initialGridState.value[key]
  })

  const savedSettings = JSON.parse(initialPeriodSettings.value || '{}') as Record<string, PeriodSetting>
  Object.keys(savedSettings).forEach(key => {
    periodSettings[key] = JSON.parse(JSON.stringify(savedSettings[key]))
  })
}

const isVacationMode = computed(() => props.profile?.status === 'inactive')

const vacationForm = useForm({})
const toggleVacationMode = () => {
  vacationForm.post(route('instructor.availability.toggle-status'), {
    preserveScroll: true,
  })
}

const instantBookForm = useForm({})
const toggleInstantBook = () => {
  instantBookForm.post(route('instructor.availability.toggle-instant-book'), {
    preserveScroll: true,
  })
}

const openCount = (dayValue: number) =>
  props.timePeriods.filter((period) => gridState[`${dayValue}-${period.value}`]).length

const blockDate = ref('')
const addBlockDate = () => {
  if (blockDate.value && !blockForm.dates.includes(blockDate.value)) {
    blockForm.dates.push(blockDate.value)
    blockDate.value = ''
  }
}

const quickAddDate = (daysAhead: number) => {
  const d = new Date()
  d.setDate(d.getDate() + daysAhead)
  const dateStr = d.toISOString().split('T')[0]
  if (!blockForm.dates.includes(dateStr)) {
    blockForm.dates.push(dateStr)
  }
}

const removeBlockDate = (index: number) => {
  blockForm.dates.splice(index, 1)
}

const submitBlocks = () => {
  blockForm.post(route('instructor.availability.block'), {
    preserveScroll: true,
    onSuccess: () => blockForm.reset('dates', 'time_period'),
  })
}

const unblock = (block: BlockSummary) => {
  if (!block.date) return

  router.delete(route('instructor.availability.unblock'), {
    data: {
      date: block.date,
      time_period: block.time_period ?? undefined,
    },
    preserveScroll: true,
  })
}

const manualBlockCount = computed(() => props.manualBlocks?.length ?? 0)

/**
 * FR 1.9.3 - Instant Book. When on, requests on this calendar skip the 2-hour
 * acceptance queue and confirm immediately, so the student only waits once.
 */
const isInstantBook = computed(() => props.autoAcceptBookings === true)

const bookingBlockCount = computed(() => props.bookingBlocks?.length ?? 0)

// Presets & Bulk Actions
const setAllAvailable = (periodValue?: string) => {
  props.daysOfWeek.forEach(day => {
    if (periodValue) {
      setPeriodOpen(day.value, periodValue, true)
    } else {
      props.timePeriods.forEach(p => {
        setPeriodOpen(day.value, p.value, true)
      })
    }
  })
}

const setWeekdaysOnly = () => {
  props.daysOfWeek.forEach(day => {
    const isWeekday = day.value >= 1 && day.value <= 5
    props.timePeriods.forEach(p => {
      setPeriodOpen(day.value, p.value, isWeekday)
    })
  })
}

const setWeekendsOnly = () => {
  props.daysOfWeek.forEach(day => {
    const isWeekend = day.value === 0 || day.value === 6
    props.timePeriods.forEach(p => {
      setPeriodOpen(day.value, p.value, isWeekend)
    })
  })
}

const clearAll = () => {
  props.daysOfWeek.forEach(day => {
    props.timePeriods.forEach(period => {
      setPeriodOpen(day.value, period.value, false)
    })
  })
}

const isOpen = (dayValue: number, periodValue: string) =>
  gridState[`${dayValue}-${periodValue}`] === true
</script>

<template>
    <Head title="Weekly schedule" />

    <AppLayout>
        <template #header>Weekly schedule</template>

        <div class="space-y-6 pb-24">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <SectionHeader
                    eyebrow="Availability"
                    title="Weekly schedule builder"
                    description="Set recurring morning and afternoon windows. Tourists and walk-ins can only book published slots."
                />

                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill :variant="bookableSlotsCount > 0 ? 'verified' : 'pending'">
                        {{ bookableSlotsCount }} bookable hours
                    </StatusPill>
                    <StatusPill v-if="isVacationMode" variant="pending" :dot="false">
                        Vacation mode
                    </StatusPill>
                    <AppButton
                        variant="secondary"
                        :disabled="!hasUnsavedChanges || weeklyForm.processing"
                        @click="resetToSaved"
                    >
                        <ArrowPathIcon class="h-4 w-4" aria-hidden="true" />
                        Reset
                    </AppButton>
                    <AppButton :loading="weeklyForm.processing" @click="saveWeekly">
                        Publish schedule
                    </AppButton>
                </div>
            </div>

            <!-- Presets -->
            <AppCard padding="md">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="eyebrow">One-tap presets</p>
                        <p class="mt-1 text-body-sm text-stone-600">
                            Presets change the grid only. Publish to save them.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control border border-amber-300 bg-amber-100 px-3 text-body-sm font-semibold text-amber-950 transition-colors hover:border-amber-500"
                            @click="setAllAvailable('morning')"
                        >
                            <SunIcon class="h-4 w-4" aria-hidden="true" />
                            All mornings
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control border border-lagoon-300 bg-lagoon-100 px-3 text-body-sm font-semibold text-lagoon-900 transition-colors hover:border-lagoon-500"
                            @click="setAllAvailable('afternoon')"
                        >
                            <CloudIcon class="h-4 w-4" aria-hidden="true" />
                            All afternoons
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control border border-sand-200 bg-white px-3 text-body-sm font-semibold text-stone-700 transition-colors hover:border-lagoon-600"
                            @click="setWeekdaysOnly"
                        >
                            Mon–Fri
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control border border-sand-200 bg-white px-3 text-body-sm font-semibold text-stone-700 transition-colors hover:border-lagoon-600"
                            @click="setWeekendsOnly"
                        >
                            Weekends
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control border border-deep-800 bg-deep-900 px-3 text-body-sm font-semibold text-white transition-colors hover:bg-deep-800"
                            @click="setAllAvailable()"
                        >
                            Full week
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center gap-2 rounded-control px-3 text-body-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50"
                            @click="clearAll"
                        >
                            <TrashIcon class="h-4 w-4" aria-hidden="true" />
                            Clear all
                        </button>
                    </div>
                </div>
            </AppCard>

            <div class="space-y-4">
                <div class="space-y-4">
                    <!-- Legend: state colours are the primary cue -->
                    <div class="flex flex-wrap items-center gap-4 text-body-sm text-stone-600">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-lagoon-500" aria-hidden="true" />
                            Open
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-stone-300" aria-hidden="true" />
                            Off
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-500" aria-hidden="true" />
                            Personal block
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-deep-900" aria-hidden="true" />
                            Locked by a lesson
                        </span>
                    </div>

                    <!-- Desktop: seven-day board -->
                    <div class="hidden gap-3 md:grid md:grid-cols-7">
                        <AppCard v-for="day in daysOfWeek" :key="day.value" padding="none" class="overflow-hidden">
                            <div class="border-b border-sand-200 bg-sand-100 px-3 py-3 text-center">
                                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                    {{ day.label.slice(0, 3) }}
                                </p>
                                <p class="mt-0.5 text-body font-semibold text-deep-900">{{ day.label }}</p>
                                <p class="mt-0.5 text-label text-stone-500">
                                    {{ openCount(day.value) }} of {{ timePeriods.length }} open
                                </p>
                                <button
                                    type="button"
                                    class="mt-1.5 text-label font-semibold text-lagoon-700 hover:underline"
                                    @click="toggleDay(day.value)"
                                >
                                    {{ isDayFullyAvailable(day.value) ? 'Set off' : 'Set all' }}
                                </button>
                            </div>

                            <div class="space-y-2 p-2.5">
                                <button
                                    v-for="period in timePeriods"
                                    :key="period.value"
                                    type="button"
                                    :aria-pressed="isOpen(day.value, period.value)"
                                    class="flex min-h-[88px] w-full flex-col justify-between rounded-control border p-3 text-left transition-colors"
                                    :class="
                                        isOpen(day.value, period.value)
                                            ? 'border-lagoon-400 bg-lagoon-100 hover:border-lagoon-600'
                                            : 'border-dashed border-sand-300 bg-sand-100 hover:border-sand-400'
                                    "
                                    @click="toggleAvailability(day.value, period.value)"
                                >
                                    <span class="flex w-full items-center justify-between">
                                        <SunIcon
                                            v-if="period.value === 'morning'"
                                            class="h-4 w-4"
                                            :class="isOpen(day.value, period.value) ? 'text-amber-600' : 'text-stone-400'"
                                            aria-hidden="true"
                                        />
                                        <CloudIcon
                                            v-else
                                            class="h-4 w-4"
                                            :class="isOpen(day.value, period.value) ? 'text-lagoon-600' : 'text-stone-400'"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="text-label font-semibold uppercase tracking-[0.06em]"
                                            :class="isOpen(day.value, period.value) ? 'text-lagoon-800' : 'text-stone-500'"
                                        >
                                            {{ period.value === 'morning' ? 'Morning' : 'Afternoon' }}
                                        </span>
                                    </span>
                                    <span
                                        class="mt-2 text-label"
                                        :class="isOpen(day.value, period.value) ? 'text-lagoon-700' : 'text-stone-500'"
                                    >
                                        {{ formatRange(day.value, period.value) }}
                                    </span>
                                    <span
                                        class="mt-1 text-body-sm font-semibold"
                                        :class="isOpen(day.value, period.value) ? 'text-lagoon-900' : 'text-stone-600'"
                                    >
                                        {{ isOpen(day.value, period.value) ? 'Open' : 'Off' }}
                                    </span>
                                </button>
                            </div>
                        </AppCard>
                    </div>

                    <!-- Mobile: one compact row per day -->
                    <div class="space-y-2 md:hidden">
                        <AppCard v-for="day in daysOfWeek" :key="day.value" padding="sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-body font-semibold text-deep-900">{{ day.label }}</p>
                                    <p class="text-label text-stone-500">
                                        {{ openCount(day.value) }} of {{ timePeriods.length }} open
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="text-body-sm font-semibold text-lagoon-700 hover:underline"
                                    @click="toggleDay(day.value)"
                                >
                                    {{ isDayFullyAvailable(day.value) ? 'Set off' : 'Set all' }}
                                </button>
                            </div>

                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button
                                    v-for="period in timePeriods"
                                    :key="period.value"
                                    type="button"
                                    :aria-pressed="isOpen(day.value, period.value)"
                                    class="flex h-12 items-center justify-center gap-2 rounded-control border text-body-sm font-semibold transition-colors"
                                    :class="
                                        isOpen(day.value, period.value)
                                            ? 'border-lagoon-400 bg-lagoon-100 text-lagoon-900'
                                            : 'border-sand-300 bg-sand-100 text-stone-600'
                                    "
                                    @click="toggleAvailability(day.value, period.value)"
                                >
                                    <SunIcon v-if="period.value === 'morning'" class="h-4 w-4" aria-hidden="true" />
                                    <CloudIcon v-else class="h-4 w-4" aria-hidden="true" />
                                    {{ period.value === 'morning' ? 'Morning' : 'Afternoon' }} · {{ isOpen(day.value, period.value) ? 'Open' : 'Off' }}
                                </button>
                            </div>
                        </AppCard>
                    </div>

                    <!-- Fine-tune custom hours and individual slots -->
                    <AppCard padding="md">
                        <SectionHeader
                            eyebrow="Working hours"
                            title="Fine-tune each open period"
                            description="Set the real start and end of your day, then switch individual one-hour slots on or off."
                        />

                        <p
                            v-if="weeklyForm.errors.availabilities"
                            class="mt-3 rounded-control border border-rose-200 bg-rose-50 px-3 py-2 text-body-sm font-semibold text-rose-800"
                            role="alert"
                        >
                            {{ weeklyForm.errors.availabilities }}
                        </p>

                        <div v-if="openPeriods.length" class="mt-4 space-y-3">
                            <div
                                v-for="item in openPeriods"
                                :key="item.key"
                                class="rounded-control border border-sand-200 bg-sand-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-body font-semibold text-deep-900">
                                            {{ item.day.label }} · {{ item.period.label.split(' (')[0] }}
                                        </p>
                                        <p class="mt-0.5 text-label text-stone-500">
                                            {{ Object.values(item.setting?.slots ?? {}).filter(Boolean).length }}
                                            of {{ slotsFor(item.key).length }} slots open
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <label class="sr-only" :for="`start-${item.key}`">Start time</label>
                                        <input
                                            :id="`start-${item.key}`"
                                            v-model="item.setting.start_time"
                                            type="time"
                                            step="1800"
                                            class="h-10 rounded-control border border-sand-300 bg-white px-2 text-body-sm font-semibold text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                                            @change="syncSlots(item.key)"
                                        />
                                        <span class="text-label text-stone-500">to</span>
                                        <label class="sr-only" :for="`end-${item.key}`">End time</label>
                                        <input
                                            :id="`end-${item.key}`"
                                            v-model="item.setting.end_time"
                                            type="time"
                                            step="1800"
                                            class="h-10 rounded-control border border-sand-300 bg-white px-2 text-body-sm font-semibold text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                                            @change="syncSlots(item.key)"
                                        />
                                    </div>
                                </div>

                                <p v-if="rangeError(item.key)" class="mt-2 text-body-sm font-semibold text-rose-700">
                                    {{ rangeError(item.key) }}
                                </p>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <button
                                        v-for="slot in slotsFor(item.key)"
                                        :key="`${item.key}-${slot}`"
                                        type="button"
                                        :aria-pressed="item.setting.slots[slot]"
                                        class="inline-flex h-9 items-center rounded-full border px-3 text-label font-semibold transition-colors"
                                        :class="
                                            item.setting.slots[slot]
                                                ? 'border-lagoon-600 bg-lagoon-700 text-white'
                                                : 'border-sand-300 bg-white text-stone-500 hover:border-lagoon-400'
                                        "
                                        @click="toggleSlot(item.key, slot)"
                                    >
                                        {{ slotLabel(slot) }}
                                    </button>

                                    <button
                                        type="button"
                                        class="ml-auto text-label font-semibold text-lagoon-700 hover:underline"
                                        @click="setAllSlots(item.key, true)"
                                    >
                                        All slots
                                    </button>
                                    <button
                                        type="button"
                                        class="text-label font-semibold text-stone-500 hover:underline"
                                        @click="setAllSlots(item.key, false)"
                                    >
                                        Clear slots
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p v-else class="mt-4 rounded-control border border-dashed border-sand-300 bg-sand-50 px-3 py-4 text-body-sm text-stone-600">
                            Switch on a morning or afternoon above to set custom hours and individual slots.
                        </p>
                    </AppCard>

                    <!-- Blocks currently in effect -->
                    <AppCard padding="md">
                        <SectionHeader
                            eyebrow="Exceptions"
                            title="Blocks in effect"
                            description="Personal blocks override your weekly pattern; lesson locks are created by confirmed bookings."
                        />

                        <div v-if="manualBlocks?.length" class="mt-4 space-y-2">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Your date blocks
                            </p>
                            <div
                                v-for="block in manualBlocks"
                                :key="block.id"
                                class="flex items-center justify-between gap-3 rounded-control border border-rose-300 bg-rose-100 px-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-body-sm font-semibold text-rose-900">
                                        {{ block.date_label }}
                                    </p>
                                    <p class="text-label text-rose-700">{{ block.time_period_label ?? 'Full day' }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="text-body-sm font-semibold text-rose-700 hover:underline"
                                    @click="unblock(block)"
                                >
                                    Unblock
                                </button>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-body-sm text-stone-600">No personal date blocks.</p>

                        <div v-if="bookingBlocks?.length" class="mt-4 space-y-2">
                            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Locked by confirmed lessons
                            </p>
                            <div
                                v-for="block in bookingBlocks"
                                :key="block.id"
                                class="flex items-center justify-between gap-3 rounded-control border border-sand-300 bg-sand-50 px-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-body-sm font-semibold text-deep-900">
                                        {{ block.date_label }}
                                    </p>
                                    <p class="truncate text-label text-stone-500">
                                        {{ block.time_period_label ?? 'Full day' }}
                                        <span v-if="block.booking_ref"> · {{ block.booking_ref }}</span>
                                    </p>
                                </div>
                                <StatusPill variant="completed" :dot="false">Locked</StatusPill>
                            </div>
                        </div>
                    </AppCard>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- Booking controls -->
                    <AppCard padding="md">
                        <SectionHeader eyebrow="Controls" title="Booking settings" />

                        <div class="mt-3 space-y-2">
                            <AppSwitch
                                :model-value="isVacationMode"
                                label="Vacation mode"
                                :description="
                                    isVacationMode
                                        ? 'Your profile is hidden and bookings are paused.'
                                        : 'Your profile is visible and bookable.'
                                "
                                @update:model-value="toggleVacationMode"
                            />
                            <AppSwitch
                                :model-value="isInstantBook"
                                label="Instant book"
                                :description="
                                    isInstantBook
                                        ? 'New requests confirm automatically.'
                                        : 'You review every request before confirming.'
                                "
                                @update:model-value="toggleInstantBook"
                            />
                        </div>

                        <p class="mt-3 text-label text-stone-500">
                            {{
                                isVacationMode
                                    ? 'Vacation mode overrides the weekly grid.'
                                    : isInstantBook
                                      ? 'Auto-confirm is on.'
                                      : 'Requests wait for your answer.'
                            }}
                        </p>
                    </AppCard>

                    <!-- Date exceptions -->
                    <AppCard padding="md">
                        <SectionHeader eyebrow="Exceptions" title="Block specific dates" />
                        <p class="mt-1 text-body-sm text-stone-600">
                            Overrides recurring slots for a full day or half-day.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-control border border-sand-200 bg-white px-3 text-body-sm font-semibold text-stone-700 transition-colors hover:border-lagoon-600"
                                @click="quickAddDate(0)"
                            >
                                + Today
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-control border border-sand-200 bg-white px-3 text-body-sm font-semibold text-stone-700 transition-colors hover:border-lagoon-600"
                                @click="quickAddDate(1)"
                            >
                                + Tomorrow
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-control border border-sand-200 bg-white px-3 text-body-sm font-semibold text-stone-700 transition-colors hover:border-lagoon-600"
                                @click="quickAddDate(2)"
                            >
                                + 2 days
                            </button>
                        </div>

                        <div class="mt-3">
                            <label for="block-date" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Custom date
                            </label>
                            <div class="mt-2 flex gap-2">
                                <input
                                    id="block-date"
                                    v-model="blockDate"
                                    type="date"
                                    class="w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                                />
                                <AppButton variant="secondary" :disabled="!blockDate" @click="addBlockDate">
                                    Add
                                </AppButton>
                            </div>
                        </div>

                        <ul v-if="blockForm.dates.length" class="mt-3 flex flex-wrap gap-2">
                            <li
                                v-for="(date, index) in blockForm.dates"
                                :key="date"
                                class="inline-flex items-center gap-2 rounded-full bg-sand-100 px-3 py-1 text-label font-semibold text-stone-700"
                            >
                                {{ date }}
                                <button
                                    type="button"
                                    class="text-stone-500 hover:text-rose-700"
                                    :aria-label="`Remove ${date}`"
                                    @click="removeBlockDate(index)"
                                >
                                    ×
                                </button>
                            </li>
                        </ul>

                        <div class="mt-3">
                            <label for="block-period" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                                Block window
                            </label>
                            <select
                                id="block-period"
                                v-model="blockForm.time_period"
                                class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
                            >
                                <option value="">Full day</option>
                                <option value="morning">Morning only</option>
                                <option value="afternoon">Afternoon only</option>
                            </select>
                        </div>

                        <p v-if="blockForm.errors.dates" class="mt-2 text-body-sm font-semibold text-rose-700">
                            {{ blockForm.errors.dates }}
                        </p>
                        <p v-if="blockForm.errors.time_period" class="mt-2 text-body-sm font-semibold text-rose-700">
                            {{ blockForm.errors.time_period }}
                        </p>

                        <AppButton
                            block
                            class="mt-3"
                            :loading="blockForm.processing"
                            :disabled="!blockForm.dates.length"
                            @click="submitBlocks"
                        >
                            Block selected dates
                        </AppButton>
                    </AppCard>
                </div>
            </div>

            <!-- Sticky publish bar: only while there is something to publish -->
            <div
                v-if="hasUnsavedChanges"
                class="fixed inset-x-4 bottom-20 z-40 lg:bottom-6 lg:left-auto lg:right-8 lg:w-[360px]"
            >
                <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 rounded-card border border-deep-800 bg-deep-950 px-4 py-3 text-white shadow-lift">
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="h-2.5 w-2.5 shrink-0 rounded-full"
                            :class="hasUnsavedChanges ? 'bg-amber-400' : 'bg-lagoon-400'"
                            aria-hidden="true"
                        />
                        <div class="min-w-0">
                            <p class="truncate text-body-sm font-semibold">
                                {{ bookableSlotsCount }} bookable hours · {{ activeSlotsCount }} periods open
                            </p>
                            <p class="text-label text-lagoon-200">
                                {{ hasUnsavedChanges ? 'Unsaved changes' : 'Schedule in sync' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="hasUnsavedChanges"
                            type="button"
                            class="text-body-sm font-semibold text-lagoon-200 hover:text-white"
                            @click="resetToSaved"
                        >
                            Discard
                        </button>
                        <AppButton size="sm" :loading="weeklyForm.processing" @click="saveWeekly">
                            Publish
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>


