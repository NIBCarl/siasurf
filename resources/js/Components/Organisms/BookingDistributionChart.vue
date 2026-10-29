<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

const distribution = ref<Record<string, number>>({})
const isLoading = ref(true)
const hasError = ref(false)

onMounted(async () => {
    try {
        const response = await fetch('/admin/api/dashboard/booking-distribution')
        if (!response.ok) throw new Error('Request failed')
        const result = await response.json()
        distribution.value = result.distribution ?? {}
    } catch {
        hasError.value = true
    } finally {
        isLoading.value = false
    }
})

const order = ['pending', 'confirmed', 'completed', 'cancelled'] as const

const labels: Record<string, string> = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    completed: 'Completed',
    cancelled: 'Cancelled',
}

const colours: Record<string, string> = {
    pending: '#F59E0B',
    confirmed: '#0D9488',
    completed: '#155E75',
    cancelled: '#E11D48',
}

const total = computed(() =>
    Object.values(distribution.value).reduce((sum, value) => sum + Number(value ?? 0), 0),
)

const rows = computed(() =>
    order
        .map((key) => {
            const value = Number(distribution.value[key] ?? 0)
            return {
                key,
                label: labels[key],
                value,
                pct: total.value > 0 ? Math.round((value / total.value) * 100) : 0,
                colour: colours[key],
            }
        })
        .filter((row) => row.value > 0),
)

const cumulativeOffset = (index: number) =>
    rows.value.slice(0, index).reduce((sum, row) => sum + row.pct, 0)
</script>

<template>
    <div class="flex h-64 flex-col">
        <div v-if="isLoading" class="flex h-full items-center justify-center text-body-sm text-stone-500">
            Loading distribution…
        </div>

        <div
            v-else-if="hasError || total === 0"
            class="flex h-full flex-col items-center justify-center gap-1 text-center"
        >
            <p class="text-body-sm font-semibold text-deep-900">No bookings yet</p>
            <p class="text-label text-stone-500">Status distribution will appear here.</p>
        </div>

        <div v-else class="flex h-full flex-col justify-between">
            <div class="flex items-center justify-center">
                <div class="relative h-32 w-32">
                    <svg viewBox="0 0 36 36" class="h-full w-full -rotate-90">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#E7E5E4" stroke-width="3" />
                        <circle
                            v-for="(row, index) in rows"
                            :key="row.key"
                            cx="18"
                            cy="18"
                            r="15.9"
                            fill="none"
                            :stroke="row.colour"
                            stroke-width="3"
                            pathLength="100"
                            :stroke-dasharray="`${row.pct} ${100 - row.pct}`"
                            :stroke-dashoffset="-cumulativeOffset(index)"
                        />
                    </svg>

                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="metric text-headline font-semibold text-deep-900">{{ total }}</span>
                        <span class="text-label text-stone-500">bookings</span>
                    </div>
                </div>
            </div>

            <div class="space-y-2 border-t border-sand-200 pt-3">
                <div v-for="row in rows" :key="row.key" class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2 text-body-sm font-semibold text-stone-700">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: row.colour }" />
                        {{ row.label }}
                    </span>
                    <span class="text-body-sm font-semibold text-deep-900">
                        {{ row.value }} <span class="text-label font-normal text-stone-500">({{ row.pct }}%)</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
