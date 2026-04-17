<script setup lang="ts">
import { ref, onMounted } from 'vue'

interface Instructor {
  id: number
  name: string
  level: number
  completed_bookings: number
  status: string
}

const instructors = ref<Instructor[]>([])
const isLoading = ref(true)

onMounted(async () => {
  try {
    const response = await fetch('/admin/api/dashboard/top-instructors?limit=10')
    const result = await response.json()
    instructors.value = result.instructors
  } catch (error) {
    console.error('Failed to load instructors:', error)
  } finally {
    isLoading.value = false
  }
})

const getLevelBadgeColor = (level: number): string => {
  const colors: Record<number, string> = {
    1: 'bg-gray-100 text-gray-800',
    2: 'bg-blue-100 text-blue-800',
    3: 'bg-purple-100 text-purple-800',
  }
  return colors[level] || 'bg-gray-100 text-gray-800'
}

const getStatusBadgeColor = (status: string): string => {
  const colors: Record<string, string> = {
    active: 'bg-green-100 text-green-800',
    suspended: 'bg-red-100 text-red-800',
    pending_verification: 'bg-yellow-100 text-yellow-800',
    inactive: 'bg-gray-100 text-gray-800',
  }
  return colors[status] || 'bg-gray-100 text-gray-800'
}

const formatStatus = (status: string): string => {
  return status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold text-gray-900">Top Performing Instructors</h2>
      <a :href="route('admin.instructors.index')" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
    </div>

    <div v-if="isLoading" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">Loading...</p>
    </div>

    <div v-else-if="instructors.length === 0" class="h-64 flex items-center justify-center">
      <p class="text-gray-500">No instructor data available</p>
    </div>

    <div v-else class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Instructor</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Level</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</th>
          </tr>
        </thead>
        
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="instructor in instructors" :key="instructor.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="flex items-center">
                <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-medium">
                  {{ instructor.name.charAt(0) }}
                </div>
                <div class="ml-3">
                  <div class="text-sm font-medium text-gray-900">{{ instructor.name }}</div>
                </div>
              </div>
            </td>
            
            <td class="px-4 py-3 whitespace-nowrap">
              <span :class="['px-2 py-1 text-xs font-medium rounded-full', getLevelBadgeColor(instructor.level)]">
                Level {{ instructor.level }}
              </span>
            </td>
            
            <td class="px-4 py-3 whitespace-nowrap">
              <span :class="['px-2 py-1 text-xs font-medium rounded-full', getStatusBadgeColor(instructor.status)]">
                {{ formatStatus(instructor.status) }}
              </span>
            </td>
            
            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
              {{ instructor.completed_bookings }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>