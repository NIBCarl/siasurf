<script setup lang="ts">
import { onMounted, ref } from 'vue'
import StatusPill from '@/Components/UI/StatusPill.vue'

interface Instructor {
    id: number
    name: string
    level: number
    completed_bookings: number
    status: string
}

const instructors = ref<Instructor[]>([])
const isLoading = ref(true)
const hasError = ref(false)

onMounted(async () => {
    try {
        const response = await fetch('/admin/api/dashboard/top-instructors?limit=10')
        if (!response.ok) throw new Error('Request failed')
        const result = await response.json()
        instructors.value = result.instructors ?? []
    } catch {
        hasError.value = true
    } finally {
        isLoading.value = false
    }
})

const initials = (name: string) =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('')

const statusVariant = (status: string) => {
    if (status === 'active') return 'verified' as const
    if (status === 'pending_verification') return 'pending' as const
    if (status === 'suspended') return 'critical' as const
    return 'neutral' as const
}

const formatStatus = (status: string) =>
    status.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
</script>

<template>
    <div>
        <div v-if="isLoading" class="flex h-48 items-center justify-center text-body-sm text-stone-500">
            Loading leaderboard…
        </div>

        <div
            v-else-if="hasError || instructors.length === 0"
            class="flex h-48 flex-col items-center justify-center gap-1 text-center"
        >
            <p class="text-body-sm font-semibold text-deep-900">No instructor activity yet</p>
            <p class="text-label text-stone-500">Completed lessons will rank instructors here.</p>
        </div>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left">
                <thead>
                    <tr class="border-b border-sand-200">
                        <th class="py-2 pr-4 text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Instructor</th>
                        <th class="px-4 py-2 text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Level</th>
                        <th class="px-4 py-2 text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Status</th>
                        <th class="py-2 pl-4 text-right text-label font-semibold uppercase tracking-[0.06em] text-stone-500">Completed</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-sand-200">
                    <tr v-for="instructor in instructors" :key="instructor.id" class="transition-colors hover:bg-sand-50">
                        <td class="py-3 pr-4">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-deep-950 text-label font-semibold text-lagoon-200">
                                    {{ initials(instructor.name) }}
                                </span>
                                <span class="truncate text-body-sm font-semibold text-deep-900">{{ instructor.name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <StatusPill variant="neutral" :dot="false">Level {{ instructor.level }}</StatusPill>
                        </td>
                        <td class="px-4 py-3">
                            <StatusPill :variant="statusVariant(instructor.status)">
                                {{ formatStatus(instructor.status) }}
                            </StatusPill>
                        </td>
                        <td class="py-3 pl-4 text-right">
                            <span class="metric text-body font-semibold text-deep-900">{{ instructor.completed_bookings }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
