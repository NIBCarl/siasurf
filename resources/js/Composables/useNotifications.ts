import { ref, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { getEcho } from './useEcho'

export interface AppNotification {
    id: string
    type: 'success' | 'warning' | 'error' | 'info'
    title: string
    message: string
    data?: any
    timestamp: Date
    read: boolean
    /** Server-resolved destination for this recipient's role. */
    url?: string | null
    /** Present for notifications that exist in the database. */
    serverId?: string
}

/**
 * Persisted notifications carry a machine type (e.g. `booking_cancelled`),
 * while the dropdown renders a severity. Unknown types fall back to `info`.
 */
const SEVERITY_BY_TYPE: Record<string, AppNotification['type']> = {
    incident_report: 'error',
    incident_outcome: 'error',
    critical_incident_notice: 'error',
    certificate_expired: 'error',
    sisa_id_expired: 'error',
    student_account_banned: 'error',
    booking_awaiting_payment: 'warning',
    booking_cancelled: 'warning',
    booking_declined: 'warning',
    booking_expired: 'warning',
    booking_no_show: 'warning',
    booking_spot_changed: 'warning',
    instructor_rejected: 'warning',
    instructor_pending_verification: 'warning',
    certificate_rejected: 'warning',
    certificate_expiring_soon: 'warning',
    sisa_id_expiring_soon: 'warning',
    skill_upgrade_rejected: 'warning',
    gcash_receipt_rejected: 'warning',
    cash_underpayment_flagged: 'warning',
    review_hidden: 'warning',
    review_deleted: 'warning',
    account_suspended: 'warning',
    instructor_suspended: 'warning',
    strike_recorded: 'warning',
    suspension_awaiting_reinstatement: 'warning',
    reschedule_proposed: 'warning',
    cash_remittance_overdue: 'warning',
    booking_confirmed: 'success',
    payment_received: 'success',
    session_completed: 'success',
    instructor_verified: 'success',
    certificate_verified: 'success',
    skill_upgrade_approved: 'success',
    student_account_unbanned: 'success',
    review_received: 'success',
    review_restored: 'success',
    fine_settled: 'success',
    strike_revoked: 'success',
    account_reactivated: 'success',
    appeal_resolved: 'success',
    payout_recorded: 'success',
}

const TITLE_BY_TYPE: Record<string, string> = {
    incident_report: 'Safety Incident Reported',
    incident_outcome: 'Incident Outcome',
    critical_incident_notice: 'Critical Safety Notice',
    new_user_registered: 'New User Registered',
    instructor_pending_verification: 'Instructor Verification Needed',
    instructor_level_updated: 'Certification Level Updated',
    certificate_submitted: 'Certificate Submitted',
    certificate_verified: 'Certificate Verified',
    certificate_rejected: 'Certificate Rejected',
    certificate_expired: 'Certificate Expired',
    certificate_expiring_soon: 'Certificate Expiring Soon',
    sisa_id_expired: 'SISA ID Expired',
    sisa_id_expiring_soon: 'SISA ID Expiring Soon',
    skill_upgrade_requested: 'Skill Upgrade Request',
    skill_upgrade_approved: 'Skill Level Upgraded',
    skill_upgrade_rejected: 'Skill Upgrade Declined',
    gcash_receipt_uploaded: 'GCash Receipt Uploaded',
    gcash_receipt_rejected: 'GCash Receipt Rejected',
    cash_underpayment_flagged: 'Cash Underpayment Flagged',
    student_account_banned: 'Account Restricted',
    student_account_unbanned: 'Account Reinstated',
    review_received: 'New Lesson Review',
    review_response: 'Instructor Replied to Review',
    review_hidden: 'Review Hidden',
    review_restored: 'Review Restored',
    review_deleted: 'Review Removed',
    fine_settled: 'Fine Settled',
    strike_revoked: 'Strike Revoked',
    booking_requested: 'New Booking Request',
    booking_awaiting_payment: 'Payment Required',
    booking_confirmed: 'Booking Confirmed',
    booking_cancelled: 'Booking Cancelled',
    booking_declined: 'Request Declined',
    booking_expired: 'Request Expired',
    booking_no_show: 'Booking No Show',
    booking_spot_changed: 'Surf Spot Changed',
    booking_rescheduled: 'Booking Rescheduled',
    reschedule_proposed: 'Reschedule Proposed',
    reschedule_response: 'Reschedule Response',
    booking_change_requested: 'Change Request Filed',
    booking_change_resolved: 'Change Request Update',
    payment_received: 'Payment Received',
    payout_recorded: 'Payout Recorded',
    cash_remittance_overdue: 'Cash Remittance Overdue',
    session_attendance: 'Attendance Recorded',
    session_completed: 'Session Completed',
    instructor_rejected: 'Application Rejected',
    instructor_verified: 'Instructor Verified',
    account_suspended: 'Account Suspended',
    account_reactivated: 'Account Reactivated',
    instructor_suspended: 'Account Suspended',
    strike_recorded: 'Strike Recorded',
    appeal_submitted: 'Appeal Submitted',
    appeal_resolved: 'Appeal Resolved',
    refund_status_updated: 'Refund Update',
    review_request: 'Leave a Review',
    suspension_awaiting_reinstatement: 'Reinstatement Required',
}

/**
 * Channel subscriptions belong to this component; the underlying socket is
 * shared through useEcho so ChatBox can use the same connection.
 */
const subscribedChannels: string[] = []
let notificationsInitialized = false

// Shared across every caller so a broadcast listener and the dropdown that
// renders it always see the same feed.
const notifications = ref<AppNotification[]>([])
const unreadCount = ref(0)

export function useNotifications() {

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

        if (!notification || notification.read) {
            return
        }

        notification.read = true
        syncUnreadCount()

        // Broadcast-only entries have no serverId; nothing to persist.
        if (notification.serverId) {
            axios
                .post(route('notifications.read', notification.serverId))
                .catch(() => {
                    notification.read = false
                    syncUnreadCount()
                })
        }
    }

    /**
     * Mark read, then open the page the notification is about. The destination
     * comes from the server, so it always matches the recipient's role.
     */
    const openNotification = (notification: AppNotification) => {
        markAsRead(notification.id)

        if (notification.url) {
            router.visit(notification.url)
        }
    }

    const markAllAsRead = () => {
        const previouslyUnread = notifications.value.filter((n) => !n.read)
        notifications.value.forEach((n) => (n.read = true))
        unreadCount.value = 0

        if (!previouslyUnread.some((n) => n.serverId)) {
            return
        }

        axios.post(route('notifications.read-all')).catch(() => {
            previouslyUnread.forEach((n) => (n.read = false))
            syncUnreadCount()
        })
    }

    const removeNotification = (id: string) => {
        const notification = notifications.value.find((n) => n.id === id)
        notifications.value = notifications.value.filter((n) => n.id !== id)
        syncUnreadCount()

        if (notification?.serverId) {
            axios.delete(route('notifications.destroy', notification.serverId)).catch(() => {})
        }
    }

    const clearAll = () => {
        const hadPersisted = notifications.value.some((n) => n.serverId)
        notifications.value = []
        unreadCount.value = 0

        if (hadPersisted) {
            axios.delete(route('notifications.clear')).catch(() => {})
        }
    }

    /**
     * Hydrate the feed from the database so history survives navigation. The
     * bell lives in a layout that remounts on every Inertia visit, so without
     * this the panel would always open empty.
     */
    const loadNotifications = async () => {
        try {
            const response = await axios.get(route('notifications.index'))
            const items = response.data?.items ?? []

            notifications.value = items.map((item: any) => ({
                id: item.id,
                serverId: item.id,
                type:
                    SEVERITY_BY_TYPE[item.type] ??
                    (item.type === 'success' ? 'success' : 'info'),
                title: TITLE_BY_TYPE[item.type] ?? 'Notification',
                message: item.message ?? 'You have a new update.',
                data: item.data,
                url: item.url ?? null,
                timestamp: new Date(item.created_at ?? Date.now()),
                read: Boolean(item.read),
            }))

            syncUnreadCount()
        } catch {
            // The feed is additive: a failed fetch must not break the page.
        }
    }

    /**
     * Broadcasts are only a nudge: the persisted row is written server-side, so
     * reload the feed instead of trusting the payload. That keeps read state,
     * dedup and multi-tab views consistent, and a reconnecting client cannot
     * lose an event.
     */
    let pendingRefresh: ReturnType<typeof setTimeout> | null = null
    const scheduleRefresh = () => {
        if (pendingRefresh) return

        pendingRefresh = setTimeout(() => {
            pendingRefresh = null
            void loadNotifications()
        }, 400)
    }

    /**
     * Every listener funnels here. The payload is intentionally ignored: the
     * persisted row is written server-side, so we reload the feed instead of
     * trusting the broadcast. That keeps read state, dedup and multi-tab views
     * consistent, and a reconnecting client cannot lose an event.
     */
    const toNotification = (_event?: unknown, _title?: string, _type?: string) => {
        scheduleRefresh()
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

        // Load history even when Reverb is unavailable: persisted rows are the
        // only record of what happened while the user was offline.
        void loadNotifications()

        const echo = getEcho()
        if (!echo) return

        notificationsInitialized = true

        if (userRole === 'student') {
            const channel = `student.${userId}`
            subscribedChannels.push(channel)

            echo.private(channel)
                .listen('.notification.created', () => toNotification())
                .listen('.booking.confirmed', (e: any) =>
                    toNotification(e, 'Booking Confirmed', 'success'))
                .listen('.booking.awaiting_payment', (e: any) =>
                    toNotification(e, 'Payment Required', 'warning'))
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
                .listen('.refund.updated', (e: any) =>
                    toNotification(e, 'Refund Update', 'info'))
                .listen('.chat.message', () => refreshChatBadge())
        }

        if (userRole === 'instructor') {
            const channel = `instructor.${userId}`
            subscribedChannels.push(channel)

            echo.private(channel)
                .listen('.notification.created', () => toNotification())
                // FR 1.9.1 - real-time booking request notification.
                .listen('.booking.created', (e: any) =>
                    toNotification(e, 'New Booking Request', 'info'))
                .listen('.booking.confirmed', (e: any) =>
                    toNotification(e, 'Booking Confirmed', 'success'))
                .listen('.booking.cancelled', (e: any) =>
                    toNotification(e, 'Booking Cancelled', 'warning'))
                .listen('.booking.declined', (e: any) =>
                    toNotification(e, e?.expired ? 'Request Expired' : 'Request Declined', 'warning'))
                // FR 1.9.4 - outcome of a cancellation or reschedule request.
                .listen('.booking.change_requested', (e: any) =>
                    toNotification(e, 'Change Request Filed', 'info'))
                .listen('.booking.change_resolved', (e: any) =>
                    toNotification(e, 'Change Request Update', 'info'))
                .listen('.payment.received', (e: any) =>
                    toNotification(e, 'Payment Received', 'success'))
                .listen('.refund.updated', (e: any) =>
                    toNotification(e, 'Refund Update', 'info'))
                .listen('.chat.message', () => refreshChatBadge())
                .listen('.instructor.status.changed', (e: any) =>
                    toNotification(e, 'Status Update', e?.new_status === 'active' ? 'success' : 'warning'))
        }

        if (userRole === 'admin') {
            const channel = 'admin.notifications'
            subscribedChannels.push(channel)

            echo.private(channel)
                .listen('.notification.created', () => toNotification())
                .listen('.booking.created', (e: any) =>
                    toNotification(e, 'New Booking', 'info'))
                .listen('.booking.confirmed', (e: any) =>
                    toNotification(e, 'Booking Confirmed', 'success'))
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
                .listen('.refund.updated', (e: any) =>
                    toNotification(e, 'Refund Update', 'info'))
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
        loadNotifications,
        openNotification,
        markAsRead,
        markAllAsRead,
        removeNotification,
        clearAll,
        initializeEcho,
        disconnectEcho,
    }
}
