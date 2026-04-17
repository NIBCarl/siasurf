<script setup lang="ts">
interface Activity {
  type: string
  message: string
  details: string
  time: string
  icon: string
  color: string
}

interface Props {
  activities: Activity[]
}

const props = defineProps<Props>()

const getIconClass = (icon: string): string => {
  const classes: Record<string, string> = {
    calendar: 'text-blue-500 bg-blue-100',
    alert: 'text-red-500 bg-red-100',
    star: 'text-yellow-500 bg-yellow-100',
  }
  return classes[icon] || 'text-gray-500 bg-gray-100'
}

const getIconPath = (icon: string): string => {
  const paths: Record<string, string> = {
    calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    alert: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
    star: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
  }
  return paths[icon] || paths.calendar
}

const formatTime = (time: string): string => {
  const date = new Date(time)
  const now = new Date()
  const diff = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diff < 60) return 'Just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold text-gray-900">Recent Activity</h2>
      <a href="#" class="text-sm text-blue-600 hover:text-blue-800">View all</a>
    </div>

    <div class="space-y-4">
      <div
        v-for="(activity, index) in activities"
        :key="index"
        class="flex items-start space-x-3 pb-4 border-b border-gray-100 last:border-0 last:pb-0"
      >
        <div :class="['flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center', getIconClass(activity.icon)]">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getIconPath(activity.icon)" />
          </svg>
        </div>
        
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-900">{{ activity.message }}</p>
          <p class="text-sm text-gray-500">{{ activity.details }}</p>
        </div>
        
        <span class="text-xs text-gray-400 flex-shrink-0">{{ formatTime(activity.time) }}</span>
      </div>

      <div v-if="activities.length === 0" class="text-center py-8 text-gray-500">
        No recent activity
      </div>
    </div>
  </div>
</template>