<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { formatDatePartsKey, getPhilippineDateParts } from '@/utils/philippineTime'

/**
 * Month-grid availability calendar.
 *
 * Built in-house rather than pulled from a date-picker library because the
 * booking API returns PER-PERIOD availability with reasons ("blocked as
 * personal time", "only 1 spot left"). A generic picker consumes that as a
 * single boolean, so we would discard our own data and still write a custom
 * cell renderer.
 *
 * Availability is fetched one month at a time (see
 * AvailabilityController::getCalendar) instead of one request per day.
 */

export interface DayPeriod {
    available: boolean
    reason: string | null
    remaining_capacity: number
}

export type DayMap = Record<string, Record<string, DayPeriod>>

const props = withDefaults(
    defineProps<{
        instructorId: number | string
        modelValue: string
        students?: number
        horizonDays?: number
        skillLevel?: string
        sessionType?: string
        exceptBookingId?: number | null
    }>(),
    {
        students: 1,
        horizonDays: 60,
        skillLevel: undefined,
        sessionType: undefined,
        exceptBookingId: undefined,
    },
)

const emit = defineEmits<{
    'update:modelValue': [value: string]
}>()

const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const MONTH_LABELS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
]

const { year: todayYear, month: todayMonth, day: todayDay } = getPhilippineDateParts()
const today = new Date(Date.UTC(todayYear, todayMonth - 1, todayDay))

/** The first day of the month currently shown. */
const cursor = ref(startOfMonth(parseDate(props.modelValue) ?? today))

const days = ref<DayMap>({})
const loading = ref(false)
const failed = ref(false)

function startOfMonth(date: Date): Date {
    return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1))
}

function parseDate(value: string): Date | null {
    if (!value) return null
    const [y, m, d] = value.split('-').map(Number)
    if (!y || !m || !d) return null
    return new Date(Date.UTC(y, m - 1, d))
}

function toKey(date: Date): string {
    return formatDatePartsKey(
        date.getUTCFullYear(),
        date.getUTCMonth() + 1,
        date.getUTCDate(),
    )
}

/** The last date a student may book, derived from the horizon. */
const lastBookableDate = computed(() => {
    const d = new Date(today.getTime())
    d.setUTCDate(d.getUTCDate() + props.horizonDays)
    return d
})

const canGoBack = computed(() => {
    const thisMonth = startOfMonth(today)
    return cursor.value > thisMonth
})

const canGoForward = computed(() => {
    const nextMonth = new Date(Date.UTC(cursor.value.getUTCFullYear(), cursor.value.getUTCMonth() + 1, 1))
    return nextMonth <= lastBookableDate.value
})

const monthLabel = computed(
    () => `${MONTH_LABELS[cursor.value.getUTCMonth()]} ${cursor.value.getUTCFullYear()}`,
)

/**
 * The grid, Monday-first, padded with nulls so the first weekday lines up.
 */
const cells = computed<(Date | null)[]>(() => {
    const year = cursor.value.getUTCFullYear()
    const month = cursor.value.getUTCMonth()
    const first = new Date(Date.UTC(year, month, 1))
    const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate()

    // getUTCDay(): 0 = Sunday. Convert to a Monday-first offset.
    const offset = (first.getUTCDay() + 6) % 7

    const out: (Date | null)[] = Array.from({ length: offset }, () => null)

    for (let d = 1; d <= daysInMonth; d++) {
        out.push(new Date(Date.UTC(year, month, d)))
    }

    return out
})

type DayState = 'unavailable' | 'partial' | 'available' | 'past' | 'unknown'

function stateFor(date: Date): DayState {
    if (date < today || date > lastBookableDate.value) return 'past'

    const key = toKey(date)
    const entry = days.value[key]

    if (!entry) return 'unknown'

    const openCount = Object.values(entry).filter((p) => p.available).length

    if (openCount === 0) return 'unavailable'
    if (openCount < Object.keys(entry).length) return 'partial'

    return 'available'
}

function isSelected(date: Date): boolean {
    return props.modelValue === toKey(date)
}

function periodKeysFor(date: Date): string[] {
    return Object.keys(days.value[toKey(date)] ?? {})
}

/** Tooltip: combine the per-period reasons so the block is explained. */
function titleFor(date: Date): string {
    const state = stateFor(date)

    if (state === 'past') return 'Outside the booking window'

    const entry = days.value[toKey(date)]
    if (!entry) return ''

    const parts = Object.entries(entry)
        .filter(([, p]) => !p.available && p.reason)
        .map(([period, p]) => `${capitalise(period)}: ${p.reason}`)

    return parts.join(' · ')
}

function capitalise(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1)
}

const cellClasses = (date: Date): string => {
    const state = stateFor(date)
    const base =
        'relative flex h-10 sm:h-11 w-full items-center justify-center rounded-lg sm:rounded-control border text-xs sm:text-body-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-500 focus-visible:ring-offset-1'

    if (isSelected(date)) {
        return `${base} border-lagoon-700 bg-lagoon-700 text-white shadow-sm`
    }

    switch (state) {
        case 'available':
            return `${base} cursor-pointer border-sand-300 bg-white text-deep-950 hover:border-lagoon-500 hover:bg-lagoon-50`
        case 'partial':
            return `${base} cursor-pointer border-amber-300 bg-amber-50 text-amber-950 hover:border-amber-500`
        case 'unavailable':
            return `${base} cursor-not-allowed border-sand-200 bg-sand-100 text-stone-400 line-through`
        case 'past':
            return `${base} cursor-not-allowed border-transparent bg-transparent text-stone-300`
        default:
            return `${base} cursor-not-allowed border-transparent bg-transparent text-stone-400`
    }
}

function select(date: Date) {
    if (isSelected(date)) return

    const state = stateFor(date)
    if (state !== 'available' && state !== 'partial') return

    emit('update:modelValue', toKey(date))
}

async function load() {
    loading.value = true
    failed.value = false

    try {
        const params: Record<string, string | number> = {
            instructor: props.instructorId,
            start: toKey(cursor.value),
            days: 31,
            students: props.students,
        }
        if (props.skillLevel) params.skill_level = props.skillLevel
        if (props.sessionType) params.session_type = props.sessionType
        if (props.exceptBookingId) params.except_booking_id = props.exceptBookingId

        const response = await fetch(
            route('api.availability.calendar', params),
            { headers: { Accept: 'application/json' } },
        )

        if (!response.ok) throw new Error(`HTTP ${response.status}`)

        const payload = await response.json()
        days.value = { ...days.value, ...(payload.days_map ?? {}) }
    } catch {
        failed.value = true
    } finally {
        loading.value = false
    }
}

watch(cursor, load, { immediate: true })
watch(
    [() => props.students, () => props.skillLevel, () => props.sessionType, () => props.exceptBookingId],
    () => {
        days.value = {}
        load()
    },
)

function shiftMonth(delta: number) {
    cursor.value = new Date(Date.UTC(cursor.value.getUTCFullYear(), cursor.value.getUTCMonth() + delta, 1))
}
</script>

<template>
    <div class="rounded-card border border-sand-300 bg-white p-2.5 sm:p-4">
        <div class="mb-3 flex items-center justify-between">
            <button
                type="button"
                class="rounded-control p-2 text-stone-600 transition hover:bg-sand-100 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canGoBack"
                aria-label="Previous month"
                @click="shiftMonth(-1)"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd" />
                </svg>
            </button>

            <p class="text-body font-semibold text-deep-950" aria-live="polite">
                {{ monthLabel }}
            </p>

            <button
                type="button"
                class="rounded-control p-2 text-stone-600 transition hover:bg-sand-100 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canGoForward"
                aria-label="Next month"
                @click="shiftMonth(1)"
            >
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <div class="grid grid-cols-7 gap-0.5 sm:gap-1" role="grid" :aria-label="monthLabel">
            <div
                v-for="label in WEEKDAY_LABELS"
                :key="label"
                class="pb-1 text-center text-[10px] sm:text-label font-semibold uppercase tracking-normal sm:tracking-[0.04em] text-stone-500"
                role="columnheader"
            >
                {{ label }}
            </div>

            <div v-for="(date, index) in cells" :key="index" role="gridcell">
                <!--
                    The title lives on the wrapper because a disabled <button>
                    does not reliably fire hover events in all browsers, so a
                    title on the button itself would hide the reason for exactly
                    the days that need explaining.
                -->
                <span v-if="date" :title="titleFor(date)" class="block">
                    <button
                        type="button"
                        :class="cellClasses(date)"
                        :disabled="stateFor(date) !== 'available' && stateFor(date) !== 'partial'"
                        :aria-label="`${toKey(date)}${titleFor(date) ? ' — ' + titleFor(date) : ''}`"
                        :aria-pressed="isSelected(date)"
                        @click="select(date)"
                    >
                        {{ date.getUTCDate() }}

                    <!-- One dot per open period, so a half-free day is obvious. -->
                    <span
                        v-if="days[toKey(date)]"
                        class="absolute bottom-1 left-1/2 flex -translate-x-1/2 gap-0.5"
                        aria-hidden="true"
                    >
                        <span
                            v-for="period in periodKeysFor(date)"
                            :key="period"
                            class="h-1 w-1 rounded-full"
                            :class="days[toKey(date)][period].available
                                ? (isSelected(date) ? 'bg-white' : 'bg-lagoon-600')
                                : 'bg-stone-300'"
                        />
                    </span>
                    </button>
                </span>
            </div>
        </div>

        <p v-if="loading" class="mt-3 text-body-sm text-stone-500">Loading availability…</p>
        <p v-else-if="failed" class="mt-3 text-body-sm text-rose-700">
            Could not load availability. Pick a date to retry.
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-4 border-t border-sand-200 pt-3 text-label text-stone-600">
            <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border border-sand-300 bg-white" aria-hidden="true"></span>
                Open
            </span>
            <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border border-amber-300 bg-amber-50" aria-hidden="true"></span>
                Partly open
            </span>
            <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border border-sand-200 bg-sand-100" aria-hidden="true"></span>
                Unavailable
            </span>
        </div>
    </div>
</template>
