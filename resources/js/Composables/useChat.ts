import { ref } from 'vue'
import axios from 'axios'
import { getEcho } from './useEcho'

export interface ChatMessage {
    id: number
    conversation_id: number
    sender_id: number
    sender_name: string
    body: string
    mine: boolean
    read_at: string | null
    created_at: string
    created_at_label: string
}

export interface ChatParticipant {
    id: number | null
    name: string
    avatar_path: string | null
}

/**
 * Booking-scoped chat state. The server owns authorization and read state;
 * this composable only keeps the open panel in sync.
 */
export function useChat(bookingId: number, currentUserId: number) {
    const messages = ref<ChatMessage[]>([])
    const otherParty = ref<ChatParticipant | null>(null)
    const loading = ref(true)
    const sending = ref(false)
    const canSend = ref(false)
    const readOnlyReason = ref<string | null>(null)
    const unreadCount = ref(0)
    const error = ref<string | null>(null)
    const validationError = ref<string | null>(null)

    const channelName = `booking.${bookingId}`
    let subscribed = false

    const load = async () => {
        loading.value = true
        error.value = null

        try {
            const response = await axios.get(route('chat.index', bookingId))
            const data = response.data

            messages.value = (data.messages ?? []).map((message: ChatMessage) => ({
                ...message,
                mine: message.sender_id === currentUserId,
            }))
            otherParty.value = data.other_party ?? null
            canSend.value = Boolean(data.conversation?.can_send)
            readOnlyReason.value = data.conversation?.read_only_reason ?? null
            unreadCount.value = 0
        } catch (exception: any) {
            error.value =
                exception?.response?.data?.message ??
                'Could not load this conversation. Please refresh and try again.'
        } finally {
            loading.value = false
        }
    }

    const markRead = async () => {
        if (unreadCount.value === 0) return

        try {
            await axios.post(route('chat.read', bookingId))
            unreadCount.value = 0
        } catch {
            // Read state is a convenience; a failed marker must not break chat.
        }
    }

    const send = async (body: string): Promise<boolean> => {
        const trimmed = body.trim()
        if (!trimmed || sending.value || !canSend.value) return false

        sending.value = true
        validationError.value = null
        error.value = null

        try {
            const response = await axios.post(route('chat.store', bookingId), {
                body: trimmed,
            })

            const message = response.data.message as ChatMessage
            if (!messages.value.some((existing) => existing.id === message.id)) {
                messages.value.push({ ...message, mine: true })
            }

            canSend.value = Boolean(response.data.can_send)
            return true
        } catch (exception: any) {
            const response = exception?.response
            if (response?.status === 422) {
                validationError.value =
                    response.data?.errors?.body?.[0] ??
                    response.data?.message ??
                    'That message could not be sent.'
            } else {
                error.value =
                    response?.data?.message ??
                    'Message could not be sent. Check your connection and try again.'
            }

            return false
        } finally {
            sending.value = false
        }
    }

    const appendFromBroadcast = (payload: { message?: ChatMessage }) => {
        const message = payload?.message
        if (!message) return

        if (messages.value.some((existing) => existing.id === message.id)) {
            return
        }

        const isMine = message.sender_id === currentUserId
        messages.value.push({ ...message, mine: isMine })

        if (!isMine) {
            unreadCount.value += 1
            void markRead()
        }
    }

    const subscribe = () => {
        if (subscribed) return

        const echo = getEcho()
        if (!echo) return

        subscribed = true
        echo.private(channelName).listen('.chat.message', appendFromBroadcast)
    }

    const unsubscribe = () => {
        if (!subscribed) return

        const echo = getEcho()
        echo?.leave(channelName)
        subscribed = false
    }

    return {
        messages,
        otherParty,
        loading,
        sending,
        canSend,
        readOnlyReason,
        unreadCount,
        error,
        validationError,
        load,
        markRead,
        send,
        subscribe,
        unsubscribe,
    }
}
