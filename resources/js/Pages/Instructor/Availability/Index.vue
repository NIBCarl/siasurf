<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import {
  CalendarDaysIcon,
  SunIcon,
  ClockIcon,
  CheckCircleIcon,
  LockClosedIcon,
  PlusIcon,
  TrashIcon,
  ArrowPathIcon,
  SparklesIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'
import { CheckIcon } from '@heroicons/vue/24/solid'

interface Availability {
  id: number
  day_of_week: number
  time_period: string
  is_available: boolean
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
}

const props = defineProps<Props>()

// UI-First Reactive State for Weekly Grid
const gridState = reactive<Record<string, boolean>>({})
const initialGridState = ref<Record<string, boolean>>({})

// Mobile Segmented Tab
const activeMobileTab = ref<'weekly' | 'exceptions'>('weekly')

// Initialize gridState from props
onMounted(() => {
  const snapshot: Record<string, boolean> = {}
  props.daysOfWeek.forEach(day => {
    props.timePeriods.forEach(period => {
      const key = `${day.value}-${period.value}`
      const dayAvailabilities = props.availabilities[day.value] || []
      const existing = dayAvailabilities.find(a => a.time_period === period.value)
      const val = existing ? (existing.is_available === true || (existing.is_available as any) === 1) : false
      gridState[key] = val
      snapshot[key] = val
    })
  })
  initialGridState.value = snapshot
})

const weeklyForm = useForm({
  availabilities: [] as any[],
})

const blockForm = useForm({
  dates: [] as string[],
  time_period: '' as string,
})

const toggleAvailability = (dayValue: number, periodValue: string) => {
  const key = `${dayValue}-${periodValue}`
  gridState[key] = !gridState[key]
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
    gridState[`${dayValue}-${p.value}`] = !allOn
  })
}

const activeSlotsCount = computed(() => {
  return Object.values(gridState).filter(Boolean).length
})

const totalSlotsCount = computed(() => {
  return (props.daysOfWeek?.length || 7) * (props.timePeriods?.length || 2)
})

const hasUnsavedChanges = computed(() => {
  return Object.keys(gridState).some(key => gridState[key] !== initialGridState.value[key])
})

const saveWeekly = () => {
  weeklyForm.availabilities = props.daysOfWeek.flatMap(day =>
    props.timePeriods.map(period => ({
      day_of_week: day.value,
      time_period: period.value,
      is_available: gridState[`${day.value}-${period.value}`] ? 1 : 0,
    })),
  )

  weeklyForm.post(route('instructor.availability.weekly'), {
    preserveScroll: true,
    onSuccess: () => {
      initialGridState.value = { ...gridState }
    },
  })
}

const resetToSaved = () => {
  Object.keys(initialGridState.value).forEach(key => {
    gridState[key] = initialGridState.value[key]
  })
}

const toggleVacationMode = () => {
  const form = useForm({})
  form.post(route('instructor.availability.toggle-status'), {
    preserveScroll: true,
  })
}

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

const toggleInstantBook = () => {
  const form = useForm({})
  form.post(route('instructor.availability.toggle-instant-book'), {
    preserveScroll: true
  })
}
const bookingBlockCount = computed(() => props.bookingBlocks?.length ?? 0)

// Presets & Bulk Actions
const setAllAvailable = (periodValue?: string) => {
  props.daysOfWeek.forEach(day => {
    if (periodValue) {
      gridState[`${day.value}-${periodValue}`] = true
    } else {
      props.timePeriods.forEach(p => {
        gridState[`${day.value}-${p.value}`] = true
      })
    }
  })
}

const setWeekdaysOnly = () => {
  props.daysOfWeek.forEach(day => {
    const isWeekday = day.value >= 1 && day.value <= 5
    props.timePeriods.forEach(p => {
      gridState[`${day.value}-${p.value}`] = isWeekday
    })
  })
}

const setWeekendsOnly = () => {
  props.daysOfWeek.forEach(day => {
    const isWeekend = day.value === 0 || day.value === 6
    props.timePeriods.forEach(p => {
      gridState[`${day.value}-${p.value}`] = isWeekend
    })
  })
}

const clearAll = () => {
  Object.keys(gridState).forEach(key => {
    gridState[key] = false
  })
}

const isVacationMode = computed(() => props.profile?.status === 'inactive')
</script>

<template>
  <Head title="Weekly Schedule & Availability — SiaSurf" />

  <AppLayout>
    <div class="max-w-7xl mx-auto space-y-6 pb-24">
      
      <!-- Top Command Bar: Glanceable Header & Publishing State -->
      <div class="bg-white rounded-3xl p-4 sm:p-6 border border-slate-100 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
              Weekly Schedule <span class="text-ocean-600">Builder</span>
            </h1>
            <Badge :variant="activeSlotsCount > 0 ? 'ocean' : 'warning'" size="sm">
              {{ activeSlotsCount }} / {{ totalSlotsCount }} Sessions Open
            </Badge>
            <span
              v-if="isVacationMode"
              class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-100 text-amber-800 border border-amber-200"
            >
              Vacation Mode Active
            </span>
          </div>
          <p class="text-xs sm:text-sm font-medium text-slate-500 mt-1 max-w-2xl">
            Set your recurring morning & afternoon slots. Tourists and walk-ins can only book sessions when your slots are published.
          </p>
        </div>

        <!-- Desktop Action Buttons -->
        <div class="hidden sm:flex items-center gap-2.5 shrink-0">
          <BaseButton
            variant="secondary"
            size="sm"
            @click="resetToSaved"
            :disabled="!hasUnsavedChanges || weeklyForm.processing"
            class="min-h-[44px] px-4 font-bold rounded-2xl text-xs"
          >
            <ArrowPathIcon class="w-4 h-4 mr-1.5 text-slate-500" />
            Reset
          </BaseButton>

          <BaseButton
            variant="primary"
            size="sm"
            @click="saveWeekly"
            :loading="weeklyForm.processing"
            class="min-h-[44px] px-6 font-extrabold rounded-2xl text-xs shadow-md shadow-ocean-500/20"
          >
            <CheckIcon class="w-4 h-4 mr-1.5" />
            {{ hasUnsavedChanges ? 'Publish Changes' : 'Schedule Published' }}
          </BaseButton>
        </div>
      </div>

      <!-- Mobile Tab Switcher: Zero clutter between schedule & date overrides -->
      <div class="xl:hidden flex items-center p-1.5 bg-slate-100 rounded-2xl gap-1">
        <button
          type="button"
          @click="activeMobileTab = 'weekly'"
          :class="[
            'flex-1 py-2.5 px-3 rounded-xl text-xs font-black uppercase tracking-wider transition-all min-h-[44px] flex items-center justify-center gap-2',
            activeMobileTab === 'weekly'
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-900'
          ]"
        >
          <CalendarDaysIcon class="w-4 h-4 text-ocean-600" />
          <span>Weekly Routine</span>
          <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-ocean-50 text-ocean-700 font-bold">
            {{ activeSlotsCount }}
          </span>
        </button>

        <button
          type="button"
          @click="activeMobileTab = 'exceptions'"
          :class="[
            'flex-1 py-2.5 px-3 rounded-xl text-xs font-black uppercase tracking-wider transition-all min-h-[44px] flex items-center justify-center gap-2',
            activeMobileTab === 'exceptions'
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-900'
          ]"
        >
          <LockClosedIcon class="w-4 h-4 text-amber-600" />
          <span>Date Blocks & Vacation</span>
          <span v-if="manualBlockCount > 0" class="text-[10px] px-1.5 py-0.5 rounded-full bg-rose-50 text-rose-700 font-bold">
            {{ manualBlockCount }}
          </span>
        </button>
      </div>

      <!-- Main Layout: 12 Columns on Desktop (8 Weekly Schedule, 4 Overrides & Vacation) -->
      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">

        <!-- Weekly Schedule Section (Left 8 Columns) -->
        <div
          :class="[
            'xl:col-span-8 space-y-5',
            activeMobileTab !== 'weekly' ? 'hidden xl:block' : ''
          ]"
        >
          <!-- Quick Presets Toolbar -->
          <div class="bg-white rounded-3xl p-4 sm:p-5 border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between gap-3 mb-3">
              <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                1-Tap Quick Presets
              </span>
              <button
                type="button"
                @click="clearAll"
                class="text-[10px] font-black uppercase tracking-wider text-rose-600 hover:underline min-h-[32px] px-2 flex items-center"
              >
                Clear All Days
              </button>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
              <button
                type="button"
                @click="setAllAvailable('morning')"
                class="px-3.5 py-2 rounded-2xl bg-amber-50 hover:bg-amber-100 text-amber-900 text-xs font-bold transition-colors border border-amber-200/70 min-h-[40px] flex items-center gap-1.5"
              >
                <span>☀️</span> All Mornings
              </button>

              <button
                type="button"
                @click="setAllAvailable('afternoon')"
                class="px-3.5 py-2 rounded-2xl bg-sky-50 hover:bg-sky-100 text-sky-900 text-xs font-bold transition-colors border border-sky-200/70 min-h-[40px] flex items-center gap-1.5"
              >
                <span>🌊</span> All Afternoons
              </button>

              <button
                type="button"
                @click="setWeekdaysOnly"
                class="px-3.5 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition-colors border border-slate-200 min-h-[40px] flex items-center gap-1.5"
              >
                <span>⚡</span> Mon – Fri Only
              </button>

              <button
                type="button"
                @click="setWeekendsOnly"
                class="px-3.5 py-2 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition-colors border border-slate-200 min-h-[40px] flex items-center gap-1.5"
              >
                <span>🏖️</span> Weekends Only
              </button>

              <button
                type="button"
                @click="setAllAvailable()"
                class="px-3.5 py-2 rounded-2xl bg-ocean-600 hover:bg-ocean-700 text-white text-xs font-black transition-colors shadow-sm shadow-ocean-300 min-h-[40px] flex items-center gap-1.5 ml-auto"
              >
                <span>🔥</span> Full Week (All 14)
              </button>
            </div>
          </div>

          <!-- MOBILE / COMPACT VIEW: 7-Row Ledger (Zero Scroll: All 7 days fit on 1 phone screen!) -->
          <div class="md:hidden bg-white rounded-3xl border border-slate-100 shadow-sm divide-y divide-slate-100 overflow-hidden">
            <div
              v-for="day in daysOfWeek"
              :key="day.value"
              class="p-3.5 flex items-center justify-between gap-2.5 transition-colors hover:bg-slate-50/70"
            >
              <!-- Day info + 1-tap All Day toggle -->
              <div class="w-24 shrink-0">
                <button
                  type="button"
                  @click="toggleDay(day.value)"
                  class="text-left group/day focus:outline-none"
                  :title="'Click to toggle entire ' + day.label"
                >
                  <p class="text-xs font-black uppercase tracking-tight text-slate-900 group-hover/day:text-ocean-600 transition-colors flex items-center gap-1">
                    <span>{{ day.label.slice(0, 3) }}</span>
                    <span
                      v-if="isDayFullyAvailable(day.value)"
                      class="w-1.5 h-1.5 rounded-full bg-emerald-500"
                    ></span>
                    <span
                      v-else-if="isDayPartiallyAvailable(day.value)"
                      class="w-1.5 h-1.5 rounded-full bg-amber-500"
                    ></span>
                  </p>
                  <p class="text-[10px] font-bold text-slate-400 group-hover/day:text-ocean-500">
                    {{ isDayFullyAvailable(day.value) ? 'Full Day' : (isDayPartiallyAvailable(day.value) ? 'Half Day' : 'Off Duty') }}
                  </p>
                </button>
              </div>

              <!-- Two Slot Tap Chips (44px touch targets) -->
              <div class="flex items-center gap-2 flex-1 justify-end">
                <!-- Morning Slot -->
                <button
                  type="button"
                  @click="toggleAvailability(day.value, 'morning')"
                  :class="[
                    'flex-1 py-2.5 px-2 rounded-2xl text-[11px] font-black uppercase tracking-tight transition-all active:scale-95 min-h-[44px] border flex items-center justify-center gap-1.5',
                    gridState[`${day.value}-morning`]
                      ? 'bg-ocean-600 border-ocean-600 text-white shadow-sm shadow-ocean-200'
                      : 'bg-slate-50 border-slate-200 text-slate-500 hover:bg-slate-100 hover:border-slate-300'
                  ]"
                >
                  <span>☀️</span>
                  <span>Morn <span class="hidden xs:inline">(6A–12P)</span></span>
                  <span
                    v-if="gridState[`${day.value}-morning`]"
                    class="w-1.5 h-1.5 rounded-full bg-white ml-0.5"
                  ></span>
                </button>

                <!-- Afternoon Slot -->
                <button
                  type="button"
                  @click="toggleAvailability(day.value, 'afternoon')"
                  :class="[
                    'flex-1 py-2.5 px-2 rounded-2xl text-[11px] font-black uppercase tracking-tight transition-all active:scale-95 min-h-[44px] border flex items-center justify-center gap-1.5',
                    gridState[`${day.value}-afternoon`]
                      ? 'bg-ocean-600 border-ocean-600 text-white shadow-sm shadow-ocean-200'
                      : 'bg-slate-50 border-slate-200 text-slate-500 hover:bg-slate-100 hover:border-slate-300'
                  ]"
                >
                  <span>🌊</span>
                  <span>Aftn <span class="hidden xs:inline">(12P–6P)</span></span>
                  <span
                    v-if="gridState[`${day.value}-afternoon`]"
                    class="w-1.5 h-1.5 rounded-full bg-white ml-0.5"
                  ></span>
                </button>
              </div>
            </div>
          </div>

          <!-- TABLET & DESKTOP VIEW: Sleek 7-Column Visual Board -->
          <div class="hidden md:grid grid-cols-7 gap-3">
            <div
              v-for="day in daysOfWeek"
              :key="day.value"
              class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden flex flex-col transition-all hover:border-ocean-200 group"
            >
              <!-- Day Header with 1-click All Day toggle -->
              <div class="p-3 text-center border-b border-slate-50 bg-slate-50/60">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 block">
                  {{ day.label?.slice(0, 3) }}
                </span>
                <p class="text-sm font-black text-slate-900 mt-0.5">{{ day.label }}</p>

                <!-- Quick All-Day toggle chip -->
                <button
                  type="button"
                  @click="toggleDay(day.value)"
                  class="mt-1.5 text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-md transition-colors bg-white border border-slate-200 text-slate-600 hover:bg-ocean-50 hover:text-ocean-700 hover:border-ocean-200 cursor-pointer"
                >
                  {{ isDayFullyAvailable(day.value) ? 'Set Off' : 'Set All' }}
                </button>
              </div>

              <!-- Morning & Afternoon interactive tiles -->
              <div class="p-2.5 space-y-2 flex-1 flex flex-col justify-between">
                <div
                  v-for="period in timePeriods"
                  :key="period.value"
                  @click="toggleAvailability(day.value, period.value)"
                  :class="[
                    'relative p-3 rounded-2xl cursor-pointer transition-all duration-200 border-2 flex flex-col items-center justify-center gap-1 min-h-[76px] select-none',
                    gridState[`${day.value}-${period.value}`]
                      ? 'bg-ocean-600 border-ocean-500 shadow-sm text-white scale-[1.02]'
                      : 'bg-slate-50 border-slate-100 text-slate-500 hover:border-slate-300 hover:bg-slate-100'
                  ]"
                >
                  <span class="text-lg leading-none">
                    {{ period.value === 'morning' ? '☀️' : '🌊' }}
                  </span>

                  <span class="text-[10px] font-black uppercase tracking-tight text-center leading-tight">
                    {{ period.label }}
                  </span>

                  <span
                    :class="[
                      'text-[9px] font-mono tracking-tighter opacity-80',
                      gridState[`${day.value}-${period.value}`] ? 'text-ocean-100 font-bold' : 'text-slate-400'
                    ]"
                  >
                    {{ period.value === 'morning' ? '6AM–12PM' : '12PM–6PM' }}
                  </span>

                  <div
                    :class="[
                      'w-1.5 h-1.5 rounded-full mt-0.5 transition-colors',
                      gridState[`${day.value}-${period.value}`] ? 'bg-white shadow-[0_0_6px_white]' : 'bg-slate-200'
                    ]"
                  ></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Schedule Legend & Notice -->
          <div class="bg-white rounded-2xl p-4 border border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
            <div class="flex items-center gap-5">
              <div class="flex items-center gap-2">
                <div class="w-3.5 h-3.5 rounded-md bg-ocean-600"></div>
                <span class="font-bold text-slate-700">Open for Bookings</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-3.5 h-3.5 rounded-md bg-slate-100 border border-slate-200"></div>
                <span class="font-bold text-slate-700">Off Duty / Rest</span>
              </div>
            </div>

            <div class="flex items-center gap-1.5 text-ocean-700 text-xs italic">
              <InformationCircleIcon class="w-4 h-4 shrink-0 text-ocean-500" />
              <span>Recurring schedule updates apply immediately to future booking searches.</span>
            </div>
          </div>
        </div>

        <!-- Overrides & Exceptions Sidebar (Right 4 Columns) -->
        <div
          :class="[
            'xl:col-span-4 space-y-5',
            activeMobileTab !== 'exceptions' ? 'hidden xl:block' : ''
          ]"
        >
          <!-- Vacation Mode Card -->
          <div
            :class="[
              'rounded-3xl p-5 text-white shadow-sm relative overflow-hidden transition-all duration-500 border',
              isVacationMode
                ? 'bg-slate-900 border-slate-800'
                : 'bg-gradient-to-br from-amber-600 to-amber-700 border-amber-500/30'
            ]"
          >
            <div class="relative z-10 flex items-center justify-between gap-4">
              <div>
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-200 block">
                  Quick Availability Switch
                </span>
                <h3 class="text-base sm:text-lg font-black uppercase tracking-tight mt-0.5">
                  Vacation Mode
                </h3>
                <p class="text-xs text-white/80 mt-0.5">
                  {{ isVacationMode ? 'Profile currently hidden from search' : 'Profile is visible and bookable' }}
                </p>
              </div>

              <!-- Toggle switch: 44px touch target -->
              <button
                type="button"
                @click="toggleVacationMode"
                :class="[
                  'w-16 h-9 rounded-full p-1 relative transition-colors duration-300 min-h-[44px] shrink-0 cursor-pointer flex items-center',
                  isVacationMode ? 'bg-rose-600' : 'bg-white/20'
                ]"
                aria-label="Toggle Vacation Mode"
              >
                <div
                  :class="[
                    'w-7 h-7 bg-white rounded-full shadow-md transition-transform duration-300',
                    isVacationMode ? 'translate-x-7' : 'translate-x-0'
                  ]"
                ></div>
              </button>
            </div>
          </div>

          <!-- FR 1.9.3 - Instant Book Card -->
          <div
            :class="[
              'rounded-3xl p-5 text-white shadow-sm relative overflow-hidden transition-all duration-500 border',
              isInstantBook
                ? 'bg-gradient-to-br from-emerald-600 to-emerald-700 border-emerald-500/30'
                : 'bg-slate-900 border-slate-800'
            ]"
          >
            <div class="relative z-10 flex items-center justify-between gap-4">
              <div>
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-200 block">
                  Booking Mode
                </span>
                <h3 class="text-base sm:text-lg font-black uppercase tracking-tight mt-0.5">
                  Instant Book
                </h3>
                <p class="text-xs text-white/80 mt-0.5">
                  {{ isInstantBook
                    ? 'Requests are confirmed automatically - students pay right away'
                    : 'You review each request before it is confirmed' }}
                </p>
              </div>

              <button
                type="button"
                @click="toggleInstantBook"
                :class="[
                  'w-16 h-9 rounded-full p-1 relative transition-colors duration-300 min-h-[44px] shrink-0 cursor-pointer flex items-center',
                  isInstantBook ? 'bg-emerald-500' : 'bg-white/20'
                ]"
                aria-label="Toggle Instant Book"
              >
                <div
                  :class="[
                    'w-7 h-7 bg-white rounded-full shadow-md transition-transform duration-300',
                    isInstantBook ? 'translate-x-7' : 'translate-x-0'
                  ]"
                ></div>
              </button>
            </div>
          </div>

          <!-- Specific Date Blocking Card -->
          <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-100 shadow-sm">
            <div class="flex items-center gap-3 pb-3 mb-4 border-b border-slate-100">
              <div class="w-9 h-9 rounded-xl bg-slate-900 text-white flex items-center justify-center shrink-0">
                <LockClosedIcon class="w-5 h-5" />
              </div>
              <div>
                <h2 class="text-sm font-black text-slate-900 uppercase tracking-tight leading-tight">
                  Block Specific Dates
                </h2>
                <p class="text-[11px] font-medium text-slate-400">
                  Override recurring slots for personal appointments or storms
                </p>
              </div>
            </div>

            <div class="space-y-4">
              <!-- Quick Select Shortcut Chips -->
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">
                  Quick Select
                </label>
                <div class="flex items-center gap-1.5 flex-wrap">
                  <button
                    type="button"
                    @click="quickAddDate(0)"
                    class="px-2.5 py-1 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 text-[11px] font-bold border border-slate-200"
                  >
                    + Today
                  </button>
                  <button
                    type="button"
                    @click="quickAddDate(1)"
                    class="px-2.5 py-1 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 text-[11px] font-bold border border-slate-200"
                  >
                    + Tomorrow
                  </button>
                  <button
                    type="button"
                    @click="quickAddDate(2)"
                    class="px-2.5 py-1 rounded-xl bg-slate-50 hover:bg-slate-100 text-slate-700 text-[11px] font-bold border border-slate-200"
                  >
                    + 2 Days Ahead
                  </button>
                </div>
              </div>

              <!-- Date Input Picker -->
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">
                  Choose Custom Date
                </label>
                <div class="flex gap-2">
                  <input
                    type="date"
                    v-model="blockDate"
                    class="flex-1 rounded-2xl border-slate-200 text-xs font-bold text-slate-900 focus:border-ocean-500 focus:ring-ocean-500 min-h-[44px]"
                    :min="new Date().toISOString().split('T')[0]"
                  />
                  <button
                    type="button"
                    @click="addBlockDate"
                    class="px-4 bg-slate-900 hover:bg-slate-800 text-white rounded-2xl font-black text-sm min-h-[44px] transition-colors cursor-pointer"
                  >
                    Add
                  </button>
                </div>
              </div>

              <!-- Selected Dates Queue -->
              <div v-if="blockForm.dates.length > 0" class="space-y-2 p-3 bg-slate-50 rounded-2xl border border-slate-200/70 max-h-36 overflow-y-auto custom-scrollbar">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">
                  Staged for Blocking ({{ blockForm.dates.length }})
                </p>
                <div
                  v-for="(d, index) in blockForm.dates"
                  :key="d"
                  class="flex items-center justify-between gap-2 p-2 bg-white rounded-xl border border-slate-100 text-xs font-bold text-slate-800"
                >
                  <span>{{ d }}</span>
                  <button
                    type="button"
                    @click="removeBlockDate(index)"
                    class="text-rose-500 hover:text-rose-700 p-1 rounded-md"
                    title="Remove date"
                  >
                    <TrashIcon class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>

              <!-- Time Period Selector -->
              <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">
                  Block Window
                </label>
                <select
                  v-model="blockForm.time_period"
                  class="w-full rounded-2xl border-slate-200 text-xs font-bold text-slate-900 focus:border-ocean-500 focus:ring-ocean-500 min-h-[44px]"
                >
                  <option value="">Full Day (Morning & Afternoon)</option>
                  <option v-for="period in timePeriods" :key="period.value" :value="period.value">
                    {{ period.label }} Only
                  </option>
                </select>
              </div>

              <BaseButton
                variant="primary"
                @click="submitBlocks"
                :disabled="blockForm.dates.length === 0 || blockForm.processing"
                :loading="blockForm.processing"
                class="w-full min-h-[44px] rounded-2xl font-bold text-xs justify-center"
              >
                Confirm Block on {{ blockForm.dates.length }} {{ blockForm.dates.length === 1 ? 'Date' : 'Dates' }}
              </BaseButton>
            </div>
          </div>

          <!-- Existing Blocked Slots List -->
          <div class="bg-white rounded-3xl p-5 border border-slate-100 shadow-sm">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h3 class="text-sm font-black uppercase tracking-tight text-slate-900">
                  Blocked Slots
                </h3>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                  {{ manualBlockCount }} personal &bull; {{ bookingBlockCount }} booking-held
                </p>
              </div>
              <Badge variant="ocean" size="sm">Active Overrides</Badge>
            </div>

            <!-- Personal Blocks List -->
            <div v-if="manualBlocks && manualBlocks.length > 0" class="space-y-2 mb-4">
              <div
                v-for="block in manualBlocks"
                :key="block.id"
                class="flex items-center justify-between gap-3 p-3 bg-slate-50 border border-slate-100 rounded-2xl"
              >
                <div class="min-w-0">
                  <p class="text-xs font-black text-slate-900 leading-tight">{{ block.date_label }}</p>
                  <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">
                    {{ block.time_period_label ?? 'Full Day' }}
                  </p>
                </div>
                <button
                  type="button"
                  @click="unblock(block)"
                  class="text-[10px] font-black uppercase tracking-wider text-ocean-600 hover:text-ocean-800 hover:underline px-2 py-1 cursor-pointer"
                >
                  Unblock
                </button>
              </div>
            </div>

            <div v-else class="py-4 text-center border-b border-slate-50">
              <p class="text-xs font-bold text-slate-400">No personal date blocks</p>
            </div>

            <!-- Booking Locked Slots -->
            <div v-if="bookingBlocks && bookingBlocks.length > 0" class="pt-3">
              <p class="text-[10px] font-black uppercase tracking-widest text-amber-700 mb-2">
                Locked by Confirmed Lessons
              </p>
              <div class="space-y-2">
                <div
                  v-for="block in bookingBlocks"
                  :key="block.id"
                  class="p-2.5 bg-amber-50/70 border border-amber-100 rounded-xl flex items-center justify-between gap-2"
                >
                  <div class="min-w-0">
                    <p class="text-xs font-black text-amber-950 truncate">{{ block.date_label }}</p>
                    <p class="text-[10px] text-amber-800 font-bold truncate">
                      {{ block.time_period_label ?? 'Full Day' }}
                      <span v-if="block.booking_ref"> &bull; {{ block.booking_ref }}</span>
                    </p>
                  </div>
                  <span class="text-[9px] font-black uppercase tracking-wider text-amber-700 shrink-0">
                    Locked
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Sticky Floating Action Bar (Essential for Mobile & Easy Desktop Access) -->
      <div class="fixed bottom-4 left-4 right-4 sm:left-auto sm:right-8 sm:w-auto z-40">
        <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl p-3 sm:px-5 sm:py-3.5 text-white shadow-2xl flex items-center justify-between gap-4 max-w-md mx-auto sm:max-w-none">
          <div class="flex items-center gap-3">
            <div
              :class="[
                'w-2.5 h-2.5 rounded-full shrink-0',
                hasUnsavedChanges ? 'bg-amber-400 animate-pulse' : 'bg-emerald-400'
              ]"
            ></div>
            <div>
              <p class="text-xs font-black tracking-tight leading-none text-white">
                {{ activeSlotsCount }} of {{ totalSlotsCount }} Sessions Open
              </p>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">
                {{ hasUnsavedChanges ? 'Unsaved schedule changes' : 'Schedule in sync' }}
              </p>
            </div>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <button
              v-if="hasUnsavedChanges"
              type="button"
              @click="resetToSaved"
              :disabled="weeklyForm.processing"
              class="px-3 py-2 text-xs font-bold text-slate-300 hover:text-white rounded-xl min-h-[44px] transition-colors"
            >
              Discard
            </button>

            <BaseButton
              variant="primary"
              size="sm"
              @click="saveWeekly"
              :loading="weeklyForm.processing"
              class="px-5 font-black text-xs min-h-[44px] rounded-2xl shadow-lg shadow-ocean-500/30"
            >
              Publish
            </BaseButton>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
  border-radius: 8px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(0, 0, 0, 0.1);
  border-radius: 8px;
}
</style>
