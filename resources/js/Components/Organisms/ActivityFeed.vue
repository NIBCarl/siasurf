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
        calendar: 'text-lagoon-700 bg-lagoon-100',
        alert: 'text-rose-700 bg-rose-100',
        star: 'text-amber-700 bg-amber-100',
    }
    return classes[icon] || 'text-stone-600 bg-sand-100'
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
    <div>
        <div v-if="activities.length" class="divide-y divide-sand-200">
            <div
                v-for="(activity, index) in activities"
                :key="index"
                class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
            >
                <div :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-control', getIconClass(activity.icon)]">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getIconPath(activity.icon)" />
                    </svg>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-body-sm font-semibold text-deep-900">{{ activity.message }}</p>
                    <p class="text-body-sm text-stone-600">{{ activity.details }}</p>
                </div>

                <span class="shrink-0 text-label text-stone-500">{{ formatTime(activity.time) }}</span>
            </div>
        </div>

        <p v-else class="py-8 text-center text-body-sm text-stone-500">No recent activity.</p>
    </div>
</template>
