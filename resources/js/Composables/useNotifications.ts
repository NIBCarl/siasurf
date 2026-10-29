import { ref, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { getEcho } from './useEcho'

export interface AppNotification {
    id: string
    type: 'success' | 'warning' | 'error' | 'info'
    title: string
    message: string
    data?: any
    timestamp: Date
    read: boolean
}

/**
 * Channel subscriptions belong to this component; the underlying socket is
 * shared through useEcho so ChatBox can use the same connection.
 */
const subscribedChannels: string[] = []
let notificationsInitialized = false

export function useNotifications() {
    const notifications = ref<AppNotification[]>([])
    const unreadCount = ref(0)

    const syncUnreadCount = () => {
        unreadCount.value = notifications.value.filter((n) => !n.read).length
    }

    const addNotification = (notification: Partial<AppNotification> & { message: string }) => {
        notifications.value.unshift({
            id: Math.random().toString(36).substring(2, 11),
            type: notification.type ?? 'info',
            title: notification.title ?? 'Notification',
            message: notification.message,
            data: notification.data,
            timestamp: new Date(),
            read: false,
        })

        // Cap the in-memory feed so a long session cannot grow unbounded.
        if (notifications.value.length > 50) {
            notifications.value = notifications.value.slice(0, 50)
        }

        syncUnreadCount()
    }

    const markAsRead = (id: string) => {
        const notification = notifications.value.find((n) => n.id === id)

        if (notification && !notification.read) {
            notification.read = true
            syncUnreadCount()
        }
    }

    const markAllAsRead = () => {
        notifications.value.forEach((n) => (n.read = true))
        unreadCount.value = 0
    }

    const removeNotification = (id: string) => {
        notifications.value = notifications.value.filter((n) => n.id !== id)
        syncUnreadCount()
    }

    const clearAll = () => {
        notifications.value = []
        unreadCount.value = 0
    }

    /**
     * Map a broadcast payload onto what the dropdown renders. Every SiaSurf
     * event carries a `message`; some also send a machine-readable `type`.
     */
    const toNotification = (event: any, fallbackTitle: string, fallbackType: AppNotification['type']) => {
        addNotification({
            title: event?.title ?? fallbackTitle,
            message: event?.message ?? 'You have a new update.',
            type: (event?.type as AppNotification['type']) ?? fallbackType,
            data: event,
        })
    }

    // A chat message should refresh the shared `nav.chatUnread` value even when
    // the booking page (and its ChatBox) is not open. Debounce so a burst of
    // messages does not trigger a reload per message.
    let lastChatRefreshAt = 0
    const refreshChatBadge = () => {
        const now = Date.now()
        if (now - lastChatRefreshAt < 3000) return
        lastChatRefreshAt = now
        router.reload({ only: ['nav'] })
    }

    const initializeEcho = (userId: number, userRole: string) => {
        if (notificationsInitialized) return

        const echo = getEcho()
        if (!echo) return

        notificationsInitialized = true

        if (userRole === 'student') {
            const channel = `student.${userId}`
            subscribedChannels.push(channel)

            echo.private(channel)
                .listen('.booking.confirmed', (e: any) =>
                    toNotification(e, 'Booking Confirmed', 'success'))
                .listen('.booking.cancelled', (e: any) =>
                    toNotification(e, 'Booking Cancelled', 'warning'))
                .listen('.booking.declined', (e: any) =>
                    toNotification(e, e?.expired ? 'Request Expired' : 'Request Declined', 'warning'))
                .listen('.booking.change_requested', (e: any) =>
                    toNotification(e, 'Change Request Filed', 'info'))
                .listen('.booking.change_resolved', (e: any) =>
                    toNotification(e, 'Change Request Update', 'info'))
                .listen('.booking.attendance', (e: any) =>
                    toNotification(e, 'Attendance Recorded', 'info'))
                .listen('.payment.received', (e: any) =>
                    toNotification(e, 'Payment Received', 'success'))
                .listen('.chat.message', (e: any) => {
                    toNotification(
                        {
                            title: 'New message',
                            message: `${e?.message?.sender_name ?? 'SiaSurf'}: ${e?.message?.body ?? 'Open your booking to read it.'}`,
                            type: 'info',
                        },
                        'New message',
                        'info',
                    )
                    refreshChatBadge()
                })
        }

        if (userRole === 'instructor') {
            const channel = `instructor.${userId}`
            subscribedChannels.push(channel)

            echo.private(channel)
                // FR 1.9.1 - real-time booking request notification.
                .listen('.booking.created', (e: any) =>
                    toNotification(e, 'New Booking Request', 'info'))
                .listen('.booking.cancelled', (e: any) =>
                    toNotification(e, 'Booking Cancelled', 'warning'))
                .listen('.booking.declined', (e: any) =>
                    toNotification(e, e?.expired ? 'Request Expired' : 'Request Declined', 'warning'))
                // FR 1.9.4 - outcome of a cancellation or reschedule request.
                .listen('.booking.change_requested', (e: any) =>
                    toNotification(e, 'Change Request Filed', 'info'))
                .listen('.booking.change_resolved', (e: any) =>
                    toNotification(e, 'Change Request Update', 'info'))
                .listen('.booking.attendance', (e: any) =>
                    toNotification(e, 'Attendance Recorded', 'info'))
                .listen('.payment.received', (e: any) =>
                    toNotification(e, 'Payment Received', 'success'))
                .listen('.chat.message', (e: any) => {
                    toNotification(
                        {
                            title: 'New message',
                            message: `${e?.message?.sender_name ?? 'SiaSurf'}: ${e?.message?.body ?? 'Open your booking to read it.'}`,
                            type: 'info',
                        },
                        'New message',
                        'info',
                    )
                    refreshChatBadge()
                })
                .listen('.instructor.status.changed', (e: any) =>
                    toNotification(e, 'Status Update', e?.new_status === 'active' ? 'success' : 'warning'))
        }

        if (userRole === 'admin') {
            const channel = 'admin.notifications'
            subscribedChannels.push(channel)

            echo.private(channel)
                .listen('.booking.created', (e: any) =>
                    toNotification(e, 'New Booking', 'info'))
                .listen('.booking.cancelled', (e: any) =>
                    toNotification(e, 'Booking Cancelled', 'warning'))
                .listen('.booking.declined', (e: any) =>
                    toNotification(e, e?.expired ? 'Request Expired' : 'Request Declined', 'warning'))
                // FR 1.9.4 - a late change needs SISA sign-off.
                .listen('.booking.change_requested', (e: any) =>
                    toNotification(e, 'Approval Required', 'warning'))
                .listen('.booking.change_resolved', (e: any) =>
                    toNotification(e, 'Change Request Resolved', 'info'))
                .listen('.booking.attendance', (e: any) =>
                    toNotification(e, 'Attendance Recorded', 'info'))
                .listen('.payment.received', (e: any) =>
                    toNotification(e, 'Payment Received', 'success'))
                .listen('.incident.reported', (e: any) =>
                    toNotification(e, 'Safety Incident Reported', 'error'))
                .listen('.instructor.status.changed', (e: any) =>
                    toNotification(e, 'Instructor Status Changed', 'info'))
        }
    }

    const disconnectEcho = () => {
        const echo = getEcho()
        if (!echo) return

        subscribedChannels.forEach((channel) => echo.leave(channel))
        subscribedChannels.length = 0
        notificationsInitialized = false
    }

    onUnmounted(() => {
        // Leave only this component's channels. ChatBox may still be using the
        // shared socket, so the connection itself stays open.
        disconnectEcho()
    })

    return {
        notifications,
        unreadCount,
        addNotification,
        markAsRead,
        markAllAsRead,
        removeNotification,
        clearAll,
        initializeEcho,
        disconnectEcho,
    }
}
