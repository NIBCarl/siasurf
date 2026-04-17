<script setup lang="ts">
import { ref } from 'vue'
import { useNotifications } from '@/Composables/useNotifications'

const props = defineProps<{
  userId: number
  userRole: string
}>()

const { notifications, unreadCount, markAsRead, markAllAsRead, removeNotification, initializeEcho } = useNotifications()
const isOpen = ref(false)

// Initialize Echo when component mounts
initializeEcho(props.userId, props.userRole)

const getIcon = (type: string) => {
  const icons: Record<string, string> = {
    success: '✓',
    warning: '⚠',
    error: '✕',
    info: 'ℹ',
  }
  return icons[type] || '•'
}

const getIconColor = (type: string) => {
  const colors: Record<string, string> = {
    success: 'text-green-500',
    warning: 'text-yellow-500',
    error: 'text-red-500',
    info: 'text-blue-500',
  }
  return colors[type] || 'text-gray-500'
}

const formatTime = (date: Date) => {
  const now = new Date()
  const diff = Math.floor((now.getTime() - date.getTime()) / 1000)
  
  if (diff < 60) return 'Just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return date.toLocaleDateString()
}
</script>

<template>
  <div class="relative">
    <!-- Bell Icon -->
    <button
      @click="isOpen = !isOpen"
      class="relative p-2 text-gray-600 hover:text-gray-900 focus:outline-none"
    >
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
      </svg>
      
      <!-- Badge -->
      <span
        v-if="unreadCount > 0"
        class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/4 -translate-y-1/4 bg-red-600 rounded-full"
      >
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <!-- Dropdown -->
    <div
      v-if="isOpen"
      class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 z-50"
    >
      <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
        <button
          v-if="unreadCount > 0"
          @click="markAllAsRead"
          class="text-xs text-blue-600 hover:text-blue-800"
        >
          Mark all read
        </button>
      </div>

      <div class="max-h-96 overflow-y-auto">
        <div
          v-for="notification in notifications"
          :key="notification.id"
          @click="markAsRead(notification.id)"
          :class="[
            'p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors',
            !notification.read ? 'bg-blue-50' : ''
          ]"
        >
          <div class="flex items-start">
            <span :class="['text-lg mr-3', getIconColor(notification.type)]">
              {{ getIcon(notification.type) }}
            </span>
            
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900">{{ notification.title }}</p>
              <p class="text-sm text-gray-600 truncate">{{ notification.message }}</p>
              <p class="text-xs text-gray-400 mt-1">{{ formatTime(notification.timestamp) }}</p>
            </div>
            
            <button
              @click.stop="removeNotification(notification.id)"
              class="ml-2 text-gray-400 hover:text-gray-600"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div v-if="notifications.length === 0" class="p-4 text-center text-gray-500">
          No notifications
        </div>
      </div>
    </div>
  </div>
</template>