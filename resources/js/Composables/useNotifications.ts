import { ref, onMounted, onUnmounted } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Make Pusher available globally for Laravel Echo
(window as any).Pusher = Pusher

export function useNotifications() {
    const notifications = ref<Array<{
        id: string
        type: string
        title: string
        message: string
        data?: any
        timestamp: Date
        read: boolean
    }>>([])
    
    const unreadCount = ref(0)
    let echo: Echo<any> | null = null

    const addNotification = (notification: any) => {
        notifications.value.unshift({
            id: Math.random().toString(36).substr(2, 9),
            type: notification.type || 'info',
            title: notification.title || 'Notification',
            message: notification.message || '',
            data: notification.data,
            timestamp: new Date(),
            read: false,
        })
        unreadCount.value++
    }

    const markAsRead = (id: string) => {
        const notification = notifications.value.find(n => n.id === id)
        if (notification && !notification.read) {
            notification.read = true
            unreadCount.value = Math.max(0, unreadCount.value - 1)
        }
    }

    const markAllAsRead = () => {
        notifications.value.forEach(n => n.read = true)
        unreadCount.value = 0
    }

    const removeNotification = (id: string) => {
        const index = notifications.value.findIndex(n => n.id === id)
        if (index > -1) {
            if (!notifications.value[index].read) {
                unreadCount.value = Math.max(0, unreadCount.value - 1)
            }
            notifications.value.splice(index, 1)
        }
    }

    const initializeEcho = (userId: number, userRole: string) => {
        if (echo) return

        echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            auth: {
                headers: {
                    Authorization: `Bearer ${localStorage.getItem('token')}`,
                },
            },
        })

        // Listen to user-specific channel
        if (userRole === 'student') {
            echo.private(`student.${userId}`)
                .listen('.booking.confirmed', (e: any) => {
                    addNotification({
                        type: 'success',
                        title: 'Booking Confirmed',
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.booking.cancelled', (e: any) => {
                    addNotification({
                        type: 'warning',
                        title: 'Booking Cancelled',
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.payment.received', (e: any) => {
                    addNotification({
                        type: 'success',
                        title: 'Payment Received',
                        message: e.message,
                        data: e,
                    })
                })
        }

        if (userRole === 'instructor') {
            echo.private(`instructor.${userId}`)
                .listen('.booking.created', (e: any) => {
                    addNotification({
                        type: 'info',
                        title: 'New Booking Request',
                        message: `New booking from ${e.student.name} on ${e.booking.date}`,
                        data: e,
                    })
                })
                .listen('.booking.cancelled', (e: any) => {
                    addNotification({
                        type: 'warning',
                        title: 'Booking Cancelled',
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.instructor.status.changed', (e: any) => {
                    addNotification({
                        type: e.new_status === 'active' ? 'success' : 'warning',
                        title: 'Status Update',
                        message: e.message,
                        data: e,
                    })
                })
        }

        if (userRole === 'admin') {
            echo.private('admin.notifications')
                .listen('.booking.created', (e: any) => {
                    addNotification({
                        type: 'info',
                        title: 'New Booking',
                        message: `New booking: ${e.student.name} → ${e.instructor.name}`,
                        data: e,
                    })
                })
                .listen('.booking.cancelled', (e: any) => {
                    addNotification({
                        type: 'warning',
                        title: 'Booking Cancelled',
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.payment.received', (e: any) => {
                    addNotification({
                        type: 'success',
                        title: 'Payment Received',
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.incident.reported', (e: any) => {
                    addNotification({
                        type: 'error',
                        title: `Incident: ${e.incident.severity_label}`,
                        message: e.message,
                        data: e,
                    })
                })
                .listen('.instructor.status.changed', (e: any) => {
                    addNotification({
                        type: 'info',
                        title: 'Instructor Status Changed',
                        message: `${e.instructor.name} is now ${e.new_status_label}`,
                        data: e,
                    })
                })
        }
    }

    const disconnectEcho = () => {
        if (echo) {
            echo.disconnect()
            echo = null
        }
    }

    onUnmounted(() => {
        disconnectEcho()
    })

    return {
        notifications,
        unreadCount,
        addNotification,
        markAsRead,
        markAllAsRead,
        removeNotification,
        initializeEcho,
        disconnectEcho,
    }
}
