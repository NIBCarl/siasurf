<script setup lang="ts">
import { ref, onMounted } from 'vue'

const revenueData = ref({
  labels: [],
  data: []
})

const isLoading = ref(true)

onMounted(async () => {
  try {
    const response = await fetch('/admin/api/dashboard/revenue')
    const result = await response.json()
    revenueData.value = result.revenue
  } catch (error) {
    console.error('Failed to load revenue data:', error)
  } finally {
    isLoading.value = false
  }
})

const maxValue = () => {
  return Math.max(...revenueData.value.data, 1)
}

const formatCurrency = (value: number): string => {
  return '₱' + (value / 1000).toFixed(0) + 'k'
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Revenue Over Time</h2>

    <div v-if="isLoading" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">Loading...</p>
    </div>

    <div v-else-if="revenueData.data.length === 0" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">No revenue data available</p>
    </div>

    <div v-else class="h-64">
      <!-- Simple bar chart -->
      <div class="flex items-end justify-between h-48 space-x-2">
        <div
          v-for="(value, index) in revenueData.data"
          :key="index"
          class="flex-1 flex flex-col items-center"
        >
          <!-- Bar -->
          <div
            class="w-full bg-blue-500 rounded-t transition-all duration-500 hover:bg-blue-600 relative group"
            :style="{ height: `${(value / maxValue()) * 100}%` }"
          >
            <!-- Tooltip -->
            <div class="absolute -top-10 left-1/2 transform -translate-x-1/2 bg-gray-900 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
              {{ formatCurrency(value) }}
            </div>
          </div>
          
          <!-- Label -->
          <span class="text-xs text-gray-500 mt-2 truncate w-full text-center">
            {{ revenueData.labels[index] }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>