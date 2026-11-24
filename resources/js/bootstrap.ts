import axios from 'axios';
import { getSocketId } from './Composables/useEcho';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Every broadcast in this app uses `toOthers()`, which tells Reverb to skip the
 * socket that triggered the action. Laravel reads that socket id from this
 * header, so without it a user is notified about their own action.
 */
window.axios.interceptors.request.use((config) => {
    const socketId = getSocketId();

    if (socketId) {
        config.headers.set('X-Socket-ID', socketId);
    }

    return config;
});
