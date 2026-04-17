<script setup lang="ts">
import { ref, onMounted } from 'vue'

const distribution = ref<Record<string, number>>({})
const isLoading = ref(true)

onMounted(async () => {
  try {
    const response = await fetch('/admin/api/dashboard/booking-distribution')
    const result = await response.json()
    distribution.value = result.distribution
  } catch (error) {
    console.error('Failed to load distribution:', error)
  } finally {
    isLoading.value = false
  }
})

const total = () => {
  return Object.values(distribution.value).reduce((sum, val) => sum + val, 0)
}

const getPercentage = (value: number): number => {
  const t = total()
  return t > 0 ? Math.round((value / t) * 100) : 0
}

const statusLabels: Record<string, string> = {
  pending: 'Pending',
  confirmed: 'Confirmed',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

const statusColors: Record<string, string> = {
  pending: 'bg-yellow-500',
  confirmed: 'bg-blue-500',
  completed: 'bg-green-500',
  cancelled: 'bg-red-500',
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Booking Status Distribution</h2>

    <div v-if="isLoading" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">Loading...</p>
    </div>

    <div v-else-if="total() === 0" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">No booking data available</p>
    </div>

    <div v-else class="space-y-4">
      <!-- Donut chart representation -->
      <div class="flex items-center justify-center h-32">
        <div class="relative w-24 h-24">
          <svg viewBox="0 0 36 36" class="w-full h-full transform -rotate-90">
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#E5E7EB" stroke-width="3" />
            <circle
              v-for="(value, key, index) in distribution"
              :key="key"
              cx="18"
              cy="18"
              r="15.9"
              fill="none"
              :stroke="['#F59E0B', '#3B82F6', '#10B981', '#EF4444'][index]"
              stroke-width="3"
              :stroke-dasharray="`${getPercentage(value)} ${100 - getPercentage(value)}`"
              :stroke-dashoffset="index > 0 
                ? -Object.entries(distribution).slice(0, index).reduce((acc, [, v]) => acc + getPercentage(v), 0) 
                : 0"
              class="transition-all duration-1000"
            />
          </svg>
          
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-lg font-bold text-gray-900">{{ total() }}</span>
          </div>
        </div>
      </div>

      <!-- Legend -->
      <div class="grid grid-cols-2 gap-2">
        <div
          v-for="(value, key) in distribution"
          :key="key"
          class="flex items-center text-sm"
        >
          <div :class="['w-3 h-3 rounded-full mr-2', statusColors[key]]"></div>
          <span class="text-gray-600">{{ statusLabels[key] }}: {{ value }} ({{ getPercentage(value) }}%)</span>
        </div>
      </div>
    </div>
  </div>
</template>