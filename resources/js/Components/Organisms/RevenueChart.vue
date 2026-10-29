<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

const revenueData = ref({
    labels: [] as string[],
    data: [] as number[],
})

const isLoading = ref(true)
const hasError = ref(false)

onMounted(async () => {
    try {
        const response = await fetch('/admin/api/dashboard/revenue')
        if (!response.ok) throw new Error('Request failed')
        const result = await response.json()
        revenueData.value = result.revenue ?? { labels: [], data: [] }
    } catch {
        hasError.value = true
    } finally {
        isLoading.value = false
    }
})

const maxValue = computed(() => Math.max(...revenueData.value.data.map(Number), 1))
const hasData = computed(() => revenueData.value.data.some((value) => Number(value) > 0))

const barHeight = (value: number) => `${(Number(value) / maxValue.value) * 100}%`

const formatCurrency = (value: number) => {
    const amount = Number(value ?? 0)
    if (amount >= 1000) return '₱' + (amount / 1000).toFixed(1) + 'k'
    return '₱' + amount.toLocaleString()
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
            <p class="text-label text-stone-500">Paid bookings will appear here.</p>
        </div>

        <div v-else class="flex h-full flex-col">
            <div class="flex flex-1 items-end gap-2">
                <div
                    v-for="(value, index) in revenueData.data"
                    :key="index"
                    class="group flex h-full flex-1 flex-col justify-end"
                >
                    <div
                        class="relative w-full rounded-t bg-lagoon-500 transition-colors hover:bg-lagoon-600"
                        :style="{ height: barHeight(value) }"
                    >
                        <div
                            class="pointer-events-none absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-control bg-deep-950 px-2 py-1 text-label font-semibold text-white opacity-0 transition-opacity group-hover:opacity-100"
                        >
                            {{ formatCurrency(value) }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex gap-2 border-t border-sand-200 pt-2">
                <span
                    v-for="(label, index) in revenueData.labels"
                    :key="index"
                    class="flex-1 truncate text-center text-label text-stone-500"
                    :title="label"
                >
                    {{ label.split(' ')[0] }}
                </span>
            </div>
        </div>
    </div>
</template>
