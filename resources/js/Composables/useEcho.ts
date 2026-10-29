import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import axios from 'axios'

/**
 * One Echo socket for the whole Inertia app.
 *
 * NotificationDropdown and ChatBox both need private channels. Creating one
 * Echo instance per component opens two WebSocket connections and delivers
 * every event twice, so all composables share this singleton.
 */
let echo: Echo<'reverb'> | null = null

export function getEcho(): Echo<'reverb'> | null {
    if (typeof window === 'undefined') return null
    if (echo) return echo

    // Local builds without Reverb configured still render; they simply do not
    // receive real-time events.
    if (!import.meta.env.VITE_REVERB_APP_KEY) return null

    ;(window as any).Pusher = Pusher

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: any) => ({
            authorize: (socketId: string, callback: (error: Error | null, data: any) => void) => {
                axios
                    .post('/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channel.name,
                    })
                    .then((response) => callback(null, response.data))
                    .catch((error) => callback(error, null))
            },
        }),
    })

    ;(window as any).Echo = echo

    return echo
}

/**
 * Used on logout. Normal component unmounts should only leave their channels,
 * not tear down the shared socket another component may still be using.
 */
export function disconnectEcho(): void {
    if (!echo) return

    echo.disconnect()
    echo = null
    delete (window as any).Echo
}
