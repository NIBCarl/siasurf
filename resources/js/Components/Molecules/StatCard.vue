<script setup lang="ts">interface Props {
  title: string
  value: number | string
  subtitle: string
  trend?: number
  icon: string
  color: 'blue' | 'green' | 'yellow' | 'red' | 'purple'
}

const props = defineProps<Props>()

const colorClasses: Record<string, { bg: string; icon: string; text: string }> = {
  blue: { bg: 'bg-blue-50', icon: 'text-blue-600', text: 'text-blue-900' },
  green: { bg: 'bg-green-50', icon: 'text-green-600', text: 'text-green-900' },
  yellow: { bg: 'bg-yellow-50', icon: 'text-yellow-600', text: 'text-yellow-900' },
  red: { bg: 'bg-red-50', icon: 'text-red-600', text: 'text-red-900' },
  purple: { bg: 'bg-purple-50', icon: 'text-purple-600', text: 'text-purple-900' },
}

const getIcon = () => {
  const icons: Record<string, string> = {
    calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    users: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    currency: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    alert: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
  }
  return icons[props.icon] || icons.calendar
}

const trendText = () => {
  if (props.trend === undefined) return ''
  return props.trend >= 0 ? `+${props.trend}%` : `${props.trend}%`
}

const trendColor = () => {
  if (props.trend === undefined) return ''
  return props.trend >= 0 ? 'text-green-600' : 'text-red-600'
}
</script>

<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <div class="flex items-center">
      <div :class="['p-3 rounded-lg', colorClasses[color].bg]">
        <svg :class="['w-6 h-6', colorClasses[color].icon]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getIcon()" />
        </svg>
      </div>
      
      <div class="ml-4 flex-1">
        <p class="text-sm font-medium text-gray-500">{{ title }}</p>
        <div class="flex items-baseline">
          <p :class="['text-2xl font-bold', colorClasses[color].text]">{{ value }}</p>
          <span v-if="trend !== undefined" :class="['ml-2 text-sm font-medium', trendColor()]">
            {{ trendText() }}
          </span>
        </div>
      </div>
    </div>
    
    <p class="mt-3 text-sm text-gray-600">{{ subtitle }}</p>
  </div>
</template>