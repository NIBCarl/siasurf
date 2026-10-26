<script setup lang="ts">
import { ref } from 'vue'
import { useNotifications } from '@/Composables/useNotifications'

const props = defineProps<{
  userId: number
  userRole: string
}>()

const {
  notifications,
  unreadCount,
  markAsRead,
  markAllAsRead,
  removeNotification,
  clearAll,
  initializeEcho,
} = useNotifications()

const isOpen = ref(false)

// FR 1.9.1 - start listening as soon as the layout mounts.
initializeEcho(props.userId, props.userRole)

const icons: Record<string, string> = {
  success: '✓',
  warning: '⚠',
  error: '✕',
  info: 'ℹ',
}

const getIcon = (type: string) => icons[type] ?? '•'

const iconClasses: Record<string, string> = {
  success: 'bg-emerald-100 text-emerald-600',
  warning: 'bg-amber-100 text-amber-600',
  error: 'bg-rose-100 text-rose-600',
  info: 'bg-ocean-100 text-ocean-600',
}

const getIconClass = (type: string) => iconClasses[type] ?? 'bg-slate-100 text-slate-500'

const formatTime = (date: Date) => {
  const diff = Math.floor((Date.now() - new Date(date).getTime()) / 1000)

  if (diff < 60) return 'Just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`

  return new Date(date).toLocaleDateString()
}
</script>

<template>
  <div class="relative">
    <!-- Bell -->
    <button
      type="button"
      @click="isOpen = !isOpen"
      class="relative p-2 rounded-xl text-slate-400 hover:bg-white hover:text-ocean-600 transition-all duration-200"
      :aria-label="`Notifications${unreadCount > 0 ? ` (${unreadCount} unread)` : ''}`"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
      </svg>

      <!-- Unread badge -->
      <span
        v-if="unreadCount > 0"
        class="absolute -top-1 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-black leading-none text-white bg-rose-600 rounded-full"
      >
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <!-- Backdrop -->
    <div v-if="isOpen" class="fixed inset-0 z-40" @click="isOpen = false"></div>

    <!-- Panel -->
    <div
      v-if="isOpen"
      class="absolute right-0 mt-2 w-80 bg-white rounded-3xl shadow-2xl shadow-slate-300/40 border border-slate-100 z-50 overflow-hidden"
    >
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-xs font-black uppercase tracking-widest text-slate-900">Notifications</h3>
        <button
          v-if="notifications.length > 0"
          type="button"
          @click="unreadCount > 0 ? markAllAsRead() : clearAll()"
          class="text-[10px] font-black uppercase tracking-widest text-ocean-600 hover:text-ocean-800 transition-colors"
        >
          {{ unreadCount > 0 ? 'Mark all read' : 'Clear all' }}
        </button>
      </div>

      <div class="max-h-96 overflow-y-auto">
        <div
          v-for="notification in notifications"
          :key="notification.id"
          @click="markAsRead(notification.id)"
          :class="[
            'px-5 py-4 border-b border-slate-50 hover:bg-slate-50 cursor-pointer transition-colors',
            !notification.read ? 'bg-ocean-50/60' : ''
          ]"
        >
          <div class="flex items-start gap-3">
            <span
              :class="['flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-black', getIconClass(notification.type)]"
            >
              {{ getIcon(notification.type) }}
            </span>

            <div class="flex-1 min-w-0">
              <p class="text-sm font-bold text-slate-900">{{ notification.title }}</p>
              <p class="text-xs text-slate-500 mt-0.5 leading-relaxed">{{ notification.message }}</p>
              <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase tracking-widest">
                {{ formatTime(notification.timestamp) }}
              </p>
            </div>

            <button
              type="button"
              @click.stop="removeNotification(notification.id)"
              class="flex-shrink-0 text-slate-300 hover:text-slate-600 transition-colors"
              aria-label="Dismiss notification"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div v-if="notifications.length === 0" class="px-5 py-10 text-center">
          <p class="text-xs font-black uppercase tracking-widest text-slate-400">No notifications yet</p>
          <p class="text-xs text-slate-400 mt-2">Booking updates will appear here in real time.</p>
        </div>
      </div>
    </div>
  </div>
</template>
