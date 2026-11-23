import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    CHECKOUT_BASE_STATE_KEY,
    registerCheckoutPopstateGuard,
} from '@/utils/checkoutNavigationGuard';

interface UseCheckoutHoldGuardOptions {
    bookingId: () => number;
    isHoldActive: () => boolean;
    allowedPostPaths: () => string[];
}

export function useCheckoutHoldGuard(options: UseCheckoutHoldGuardOptions) {
    const showLeaveModal = ref(false);
    const isReleasing = ref(false);
    const pendingTargetUrl = ref<string | null>(null);
    const bypassGuard = ref(false);

    let guardedPathname = '';
    let sentinelArmed = false;
    let gestureCleanup: (() => void) | null = null;
    let removePopstateGuard: (() => void) | null = null;
    let removeBeforeListener: (() => void) | null = null;

    const toPathname = (url: URL | string): string => {
        try {
            return new URL(String(url), window.location.origin).pathname;
        } catch {
            return '';
        }
    };

    const sanitizeRelativeTarget = (url: string | null): string | null => {
        if (!url || !url.startsWith('/') || url.startsWith('//')) {
            return null;
        }

        if (toPathname(url) === guardedPathname) {
            return null;
        }

        return url;
    };

    const armHistorySentinel = (): void => {
        if (
            sentinelArmed ||
            !options.isHoldActive() ||
            bypassGuard.value ||
            isReleasing.value
        ) {
            return;
        }

        sentinelArmed = true;

        const currentState = (
            window.history.state && typeof window.history.state === 'object'
                ? window.history.state
                : {}
        ) as Record<string, unknown>;

        window.history.replaceState(
            { ...currentState, [CHECKOUT_BASE_STATE_KEY]: true },
            '',
            window.location.href,
        );

        const { [CHECKOUT_BASE_STATE_KEY]: _ignored, ...guardState } =
            currentState;
        window.history.pushState(guardState, '', window.location.href);
    };

    const requestLeave = (targetUrl: string | null = null): void => {
        if (isReleasing.value) {
            return;
        }

        pendingTargetUrl.value = sanitizeRelativeTarget(targetUrl);
        showLeaveModal.value = true;
    };

    const stayOnPage = (): void => {
        if (isReleasing.value) {
            return;
        }

        showLeaveModal.value = false;
        pendingTargetUrl.value = null;
    };

    const beginAllowedNavigation = (): void => {
        bypassGuard.value = true;
    };

    const endAllowedNavigation = (): void => {
        if (!isReleasing.value) {
            bypassGuard.value = false;
        }
    };

    const confirmLeaveAndRelease = (): void => {
        if (isReleasing.value) {
            return;
        }

        isReleasing.value = true;
        bypassGuard.value = true;
        gestureCleanup?.();
        gestureCleanup = null;

        const redirectTo = sanitizeRelativeTarget(pendingTargetUrl.value);

        router.post(
            route('bookings.abandon', options.bookingId()),
            redirectTo ? { redirect_to: redirectTo } : {},
            {
                replace: true,
                onError: () => {
                    isReleasing.value = false;
                    bypassGuard.value = false;
                },
                onFinish: () => {
                    isReleasing.value = false;
                },
            },
        );
    };

    const handleBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (!options.isHoldActive() || bypassGuard.value || isReleasing.value) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    };

    onMounted(() => {
        guardedPathname = window.location.pathname;

        removePopstateGuard = registerCheckoutPopstateGuard({
            guardedPathname,
            isActive: () => options.isHoldActive(),
            isBypassing: () => bypassGuard.value || isReleasing.value,
            promptLeave: (targetUrl) => requestLeave(targetUrl),
        });

        const navWithActivation = navigator as Navigator & {
            userActivation?: { hasBeenActive: boolean };
        };
        const hasActivation =
            typeof navigator !== 'undefined' &&
            navWithActivation.userActivation !== undefined
                ? navWithActivation.userActivation.hasBeenActive
                : true;

        if (hasActivation) {
            armHistorySentinel();
        } else {
            const onFirstGesture = () => {
                gestureCleanup?.();
                gestureCleanup = null;
                armHistorySentinel();
            };

            const eventTypes = ['pointerdown', 'keydown', 'touchstart'] as const;
            eventTypes.forEach((type) =>
                window.addEventListener(type, onFirstGesture, {
                    capture: true,
                    passive: true,
                }),
            );

            gestureCleanup = () => {
                eventTypes.forEach((type) =>
                    window.removeEventListener(type, onFirstGesture, {
                        capture: true,
                    }),
                );
            };
        }

        removeBeforeListener = router.on('before', (event) => {
            if (
                !options.isHoldActive() ||
                bypassGuard.value ||
                isReleasing.value
            ) {
                return true;
            }

            const visit = event.detail.visit;
            const method = visit.method.toLowerCase();
            const pathname = toPathname(visit.url);

            if (
                method === 'post' &&
                options.allowedPostPaths().includes(pathname)
            ) {
                return true;
            }

            let relativeTarget: string | null = null;
            try {
                const parsed = new URL(
                    String(visit.url),
                    window.location.origin,
                );
                if (parsed.origin === window.location.origin) {
                    relativeTarget =
                        parsed.pathname + parsed.search + parsed.hash;
                }
            } catch {
                relativeTarget = null;
            }

            requestLeave(relativeTarget);
            event.preventDefault();
            return false;
        });

        window.addEventListener('beforeunload', handleBeforeUnload);
    });

    onUnmounted(() => {
        gestureCleanup?.();
        gestureCleanup = null;

        removePopstateGuard?.();
        removePopstateGuard = null;

        removeBeforeListener?.();
        removeBeforeListener = null;

        window.removeEventListener('beforeunload', handleBeforeUnload);
    });

    return {
        showLeaveModal,
        isReleasing,
        requestLeave,
        stayOnPage,
        confirmLeaveAndRelease,
        beginAllowedNavigation,
        endAllowedNavigation,
    };
}
