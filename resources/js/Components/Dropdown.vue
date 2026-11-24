<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        align?: 'left' | 'right' | 'top' | 'top-left' | 'top-right' | 'right-top' | 'right-bottom' | 'left-top' | 'left-bottom';
        width?: '48' | '56' | '64';
        contentClasses?: string;
    }>(),
    {
        align: 'right',
        width: '48',
        contentClasses: 'py-1 bg-white',
    },
);

const open = ref(false);
const triggerEl = ref<HTMLElement | null>(null);
const menuEl = ref<HTMLElement | null>(null);
const menuStyle = ref<Record<string, string>>({
    position: 'fixed',
    top: '-9999px',
    left: '-9999px',
    zIndex: '70',
});

const widthPx = computed(() => {
    return {
        '48': 192,
        '56': 224,
        '64': 256,
    }[props.width.toString()] ?? 192;
});

const widthClass = computed(() => {
    return {
        48: 'w-48',
        56: 'w-56',
        64: 'w-64',
    }[props.width.toString()];
});

const updatePosition = () => {
    if (!open.value || !triggerEl.value) {
        return;
    }

    const rect = triggerEl.value.getBoundingClientRect();
    const menuWidth = menuEl.value?.offsetWidth || widthPx.value;
    const menuHeight = menuEl.value?.offsetHeight || 160;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const gap = 8;
    const pad = 8;

    let top = rect.bottom + gap;
    let left = rect.right - menuWidth;

    switch (props.align) {
        case 'left':
            left = rect.left;
            top = rect.bottom + gap;
            break;
        case 'right':
            left = rect.right - menuWidth;
            top = rect.bottom + gap;
            break;
        case 'top':
        case 'top-right':
            left = rect.right - menuWidth;
            top = rect.top - menuHeight - gap;
            break;
        case 'top-left':
            left = rect.left;
            top = rect.top - menuHeight - gap;
            break;
        case 'right-top':
            left = rect.right + gap;
            top = rect.top;
            break;
        case 'right-bottom':
            left = rect.right + gap;
            top = rect.bottom - menuHeight;
            break;
        case 'left-top':
            left = rect.left - menuWidth - gap;
            top = rect.top;
            break;
        case 'left-bottom':
            left = rect.left - menuWidth - gap;
            top = rect.bottom - menuHeight;
            break;
    }

    if (top + menuHeight > vh - pad && rect.top - menuHeight - gap >= pad) {
        top = rect.top - menuHeight - gap;
    } else if (top < pad && rect.bottom + gap + menuHeight <= vh - pad) {
        top = rect.bottom + gap;
    }

    left = Math.max(pad, Math.min(left, vw - menuWidth - pad));
    top = Math.max(pad, Math.min(top, vh - menuHeight - pad));

    menuStyle.value = {
        position: 'fixed',
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        zIndex: '70',
    };
};

const toggle = () => {
    open.value = !open.value;
    if (open.value) {
        nextTick(updatePosition);
    }
};

watch(open, (isOpen) => {
    if (isOpen) {
        nextTick(updatePosition);
    }
});

const closeOnEscape = (e: KeyboardEvent) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

const repositionIfOpen = () => {
    if (open.value) {
        updatePosition();
    }
};

onMounted(() => {
    document.addEventListener('keydown', closeOnEscape);
    window.addEventListener('resize', repositionIfOpen);
    window.addEventListener('scroll', repositionIfOpen, true);
});

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    window.removeEventListener('resize', repositionIfOpen);
    window.removeEventListener('scroll', repositionIfOpen, true);
});
</script>

<template>
    <div class="relative inline-block">
        <div
            ref="triggerEl"
            :aria-expanded="open"
            class="cursor-pointer"
            @click="toggle"
        >
            <slot name="trigger" />
        </div>

        <Teleport to="body">
            <!-- Full Screen Dropdown Overlay -->
            <div
                v-show="open"
                class="fixed inset-0 z-[60]"
                @click="open = false"
            ></div>

            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-show="open"
                    ref="menuEl"
                    role="menu"
                    class="fixed shadow-lg"
                    :class="[widthClass]"
                    :style="menuStyle"
                    @click="open = false"
                >
                    <div
                        class="rounded-md ring-1 ring-black ring-opacity-5"
                        :class="contentClasses"
                    >
                        <slot name="content" />
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

