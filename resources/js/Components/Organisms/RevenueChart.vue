<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

const props = withDefaults(defineProps<{ period?: 'monthly' | 'yearly' }>(), {
    period: 'monthly',
})

const revenueData = ref({
    labels: [] as string[],
    data: [] as number[],
})

const isLoading = ref(true)
const hasError = ref(false)

const activeIndex = ref<number | null>(null)

const loadRevenue = async () => {
    isLoading.value = true
    hasError.value = false
    activeIndex.value = null

    try {
        const response = await fetch(`/admin/api/dashboard/revenue?period=${encodeURIComponent(props.period)}`)
        if (!response.ok) throw new Error('Request failed')
        const result = await response.json()
        revenueData.value = result.revenue ?? { labels: [], data: [] }
    } catch {
        hasError.value = true
    } finally {
        isLoading.value = false
    }
}

watch(() => props.period, loadRevenue)
onMounted(loadRevenue)

const maxValue = computed(() => Math.max(...revenueData.value.data.map(Number), 1))
const hasData = computed(() => revenueData.value.data.some((value) => Number(value) > 0))

const resolvedActiveIndex = computed<number | null>(() => {
    const len = revenueData.value.data.length
    if (len === 0) return null
    if (activeIndex.value !== null && activeIndex.value >= 0 && activeIndex.value < len) {
        return activeIndex.value
    }
    const lastNonZero = revenueData.value.data
        .map(Number)
        .reduce((acc, value, idx) => (value > 0 ? idx : acc), -1)
    return lastNonZero >= 0 ? lastNonZero : len - 1
})

const activeLabel = computed(() =>
    resolvedActiveIndex.value === null ? '' : (revenueData.value.labels[resolvedActiveIndex.value] ?? ''),
)
const activeValue = computed(() =>
    resolvedActiveIndex.value === null ? 0 : Number(revenueData.value.data[resolvedActiveIndex.value] ?? 0),
)

const barHeight = (value: number) => `${(Number(value) / maxValue.value) * 100}%`

const formatCurrency = (value: number) => {
    const amount = Number(value ?? 0)
    if (amount >= 1000) return '₱' + (amount / 1000).toFixed(1) + 'k'
    return '₱' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
    <div class="flex h-64 flex-col">
        <div v-if="isLoading" class="flex h-full items-center justify-center text-body-sm text-stone-500">
            Loading revenue…
        </div>

        <div
            v-else-if="hasError || !hasData"
            class="flex h-full flex-col items-center justify-center gap-1 text-center"
        >
            <p class="text-body-sm font-semibold text-deep-900">No revenue recorded yet</p>
            <p class="text-label text-stone-500">Collected service fees from paid bookings will appear here.</p>
        </div>

        <div v-else class="flex h-full flex-col">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <span class="text-label text-stone-500">Tap any month bar to inspect</span>
                <span
                    v-if="activeLabel"
                    class="rounded-control bg-deep-900 px-2.5 py-1 font-mono text-label font-semibold text-white"
                >
                    {{ activeLabel }}: {{ formatCurrency(activeValue) }}
                </span>
            </div>

            <div class="flex flex-1 items-end gap-1.5 sm:gap-2">
                <button
                    v-for="(value, index) in revenueData.data"
                    :key="index"
                    type="button"
                    class="group/bar flex h-full min-w-0 flex-1 flex-col justify-end rounded-t focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600"
                    :aria-label="`${revenueData.labels[index] ?? 'Month'}: ${formatCurrency(value)}`"
                    @click="activeIndex = index"
                >
                    <div
                        class="relative w-full min-h-[6px] rounded-t transition-colors"
                        :class="resolvedActiveIndex === index ? 'bg-deep-900' : 'bg-lagoon-500 hover:bg-lagoon-600'"
                        :style="{ height: barHeight(value) }"
                    >
                        <div
                            class="pointer-events-none absolute -top-7 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded-control bg-deep-950 px-2 py-1 text-label font-semibold text-white opacity-0 transition-opacity sm:block sm:group-hover/bar:opacity-100"
                        >
                            {{ formatCurrency(value) }}
                        </div>
                    </div>
                </button>
            </div>

            <div class="mt-2 flex gap-1.5 border-t border-sand-200 pt-2 sm:gap-2">
                <span
                    v-for="(label, index) in revenueData.labels"
                    :key="index"
                    class="flex-1 truncate text-center text-label"
                    :class="resolvedActiveIndex === index ? 'font-bold text-deep-900' : 'text-stone-500'"
                    :title="label"
                >
                    {{ label.split(' ')[0] }}
                </span>
            </div>
        </div>
    </div>
</template>
