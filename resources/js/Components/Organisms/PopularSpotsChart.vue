<script setup lang="ts">
import { ref, onMounted } from 'vue'

interface Spot {
  name: string
  total_bookings: number
}

const spots = ref<Spot[]>([])
const isLoading = ref(true)

onMounted(async () => {
  try {
    const response = await fetch('/admin/api/dashboard/popular-spots?limit=5')
    const result = await response.json()
    spots.value = result.spots
  } catch (error) {
    console.error('Failed to load spots:', error)
  } finally {
    isLoading.value = false
  }
})

const maxBookings = () => {
  return Math.max(...spots.value.map(s => s.total_bookings), 1)
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Popular Surf Spots</h2>

    <div v-if="isLoading" class="h-48 flex items-center justify-center">
      <p class="text-gray-500">Loading...</p>
    </div>

    <div v-else-if="spots.length === 0" class="h-48 flex items-center justify-center">
      <p class="text-gray-500">No data available</p>
    </div>

    <div v-else class="space-y-3">
      <div
        v-for="(spot, index) in spots"
        :key="spot.name"
        class="flex items-center"
      >
        <span class="text-sm font-medium text-gray-700 w-8">{{ index + 1 }}</span>
        
        <div class="flex-1 mx-3">
          <div class="flex items-center justify-between mb-1">
            <span class="text-sm text-gray-900 truncate">{{ spot.name }}</span>
            <span class="text-sm text-gray-500">{{ spot.total_bookings }} bookings</span>
          </div>
          
          <div class="w-full bg-gray-200 rounded-full h-2">
            <div
              class="bg-blue-500 h-2 rounded-full transition-all duration-500"
              :style="{ width: `${(spot.total_bookings / maxBookings()) * 100}%` }"
            ></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>