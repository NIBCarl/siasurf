<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

interface Spot {
    name: string
    total_bookings: number
}

const spots = ref<Spot[]>([])
const isLoading = ref(true)
const hasError = ref(false)

onMounted(async () => {
    try {
        const response = await fetch('/admin/api/dashboard/popular-spots?limit=5')
        if (!response.ok) throw new Error('Request failed')
        const result = await response.json()
        spots.value = result.spots ?? []
    } catch {
        hasError.value = true
    } finally {
        isLoading.value = false
    }
})

const maxBookings = computed(() => Math.max(...spots.value.map((spot) => Number(spot.total_bookings)), 1))
</script>

<template>
    <div>
        <div v-if="isLoading" class="flex h-40 items-center justify-center text-body-sm text-stone-500">
            Loading spots…
        </div>

        <div
            v-else-if="hasError || spots.length === 0"
            class="flex h-40 flex-col items-center justify-center gap-1 text-center"
        >
            <p class="text-body-sm font-semibold text-deep-900">No spot demand yet</p>
            <p class="text-label text-stone-500">Bookings will rank surf spots here.</p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="(spot, index) in spots" :key="spot.name" class="flex items-start gap-3">
                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sand-100 text-label font-semibold text-stone-600">
                    {{ index + 1 }}
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-body-sm font-semibold text-deep-900">{{ spot.name }}</span>
                        <span class="shrink-0 text-label text-stone-500">
                            {{ spot.total_bookings }} booking{{ spot.total_bookings === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-sand-200">
                        <div
                            class="h-full rounded-full bg-lagoon-500 transition-all duration-500"
                            :style="{ width: `${(spot.total_bookings / maxBookings) * 100}%` }"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
