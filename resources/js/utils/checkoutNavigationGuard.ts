export const CHECKOUT_BASE_STATE_KEY = '__siasurfCheckoutBase';

export interface CheckoutPopstateGuard {
    guardedPathname: string;
    isActive: () => boolean;
    isBypassing: () => boolean;
    promptLeave: (targetUrl: string | null) => void;
}

interface CheckoutNavGuardStore {
    installed: boolean;
    activeGuard: CheckoutPopstateGuard | null;
    reversingPopstate: boolean;
    reverseResetTimer: ReturnType<typeof setTimeout> | null;
}

declare global {
    interface Window {
        __siasurfCheckoutNavGuard?: CheckoutNavGuardStore;
    }
}

function getStore(): CheckoutNavGuardStore {
    if (!window.__siasurfCheckoutNavGuard) {
        window.__siasurfCheckoutNavGuard = {
            installed: false,
            activeGuard: null,
            reversingPopstate: false,
            reverseResetTimer: null,
        };
    }

    return window.__siasurfCheckoutNavGuard;
}

function handlePopstate(event: PopStateEvent): void {
    const store = getStore();

    if (store.reversingPopstate) {
        if (store.reverseResetTimer) {
            clearTimeout(store.reverseResetTimer);
            store.reverseResetTimer = null;
        }
        store.reversingPopstate = false;
        event.stopImmediatePropagation();
        return;
    }

    const guard = store.activeGuard;
    if (!guard || !guard.isActive() || guard.isBypassing()) {
        return;
    }

    const state = (event.state ?? null) as Record<string, unknown> | null;
    const landedOnBaseSentinel = Boolean(
        state && state[CHECKOUT_BASE_STATE_KEY] === true,
    );

    if (landedOnBaseSentinel) {
        event.stopImmediatePropagation();

        const { [CHECKOUT_BASE_STATE_KEY]: _ignored, ...guardState } = state!;
        window.history.pushState(guardState, '', window.location.href);

        guard.promptLeave(null);
        return;
    }

    const currentPath = window.location.pathname;
    if (currentPath === guard.guardedPathname) {
        return;
    }

    const targetUrl =
        window.location.pathname +
        window.location.search +
        window.location.hash;

    event.stopImmediatePropagation();

    store.reversingPopstate = true;
    store.reverseResetTimer = setTimeout(() => {
        store.reversingPopstate = false;
        store.reverseResetTimer = null;
    }, 300);

    window.history.go(1);
    guard.promptLeave(targetUrl);
}

/**
 * Registers a capture-phase popstate listener before Inertia initializes so
 * checkout navigation guards intercept Back/Forward before Inertia swaps pages.
 */
export function installCheckoutPopstateGuard(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const store = getStore();
    if (store.installed) {
        return;
    }

    store.installed = true;
    window.addEventListener('popstate', handlePopstate, true);
}

/**
 * Registers the currently mounted checkout guard and returns an unregister callback.
 */
export function registerCheckoutPopstateGuard(
    guard: CheckoutPopstateGuard,
): () => void {
    installCheckoutPopstateGuard();

    const store = getStore();
    store.activeGuard = guard;

    return () => {
        if (store.activeGuard === guard) {
            store.activeGuard = null;
        }
        if (store.reverseResetTimer) {
            clearTimeout(store.reverseResetTimer);
            store.reverseResetTimer = null;
        }
        store.reversingPopstate = false;
    };
}
