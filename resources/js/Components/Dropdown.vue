<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

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

const closeOnEscape = (e: KeyboardEvent) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

const widthClass = computed(() => {
    return {
        48: 'w-48',
        56: 'w-56',
        64: 'w-64',
    }[props.width.toString()];
});

const alignmentClasses = computed(() => {
    switch (props.align) {
        case 'left':
            return 'ltr:origin-top-left rtl:origin-top-right start-0 mt-2';
        case 'right':
            return 'ltr:origin-top-right rtl:origin-top-left end-0 mt-2';
        case 'top':
            return 'origin-bottom bottom-full mb-2 end-0';
        case 'top-left':
            return 'origin-bottom-left bottom-full start-0 mb-2';
        case 'top-right':
            return 'origin-bottom-right bottom-full end-0 mb-2';
        case 'right-top':
            return 'origin-left start-full top-0 ml-2';
        case 'right-bottom':
            return 'origin-bottom-left start-full bottom-0 ml-2';
        case 'left-top':
            return 'origin-right end-full top-0 mr-2';
        case 'left-bottom':
            return 'origin-bottom-right end-full bottom-0 mr-2';
        default:
            return 'origin-top mt-2';
    }
});


const open = ref(false);
</script>

<template>
    <div class="relative">
        <div @click="open = !open" class="cursor-pointer">
            <slot name="trigger" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <div
            v-show="open"
            class="fixed inset-0 z-40"
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
                class="absolute z-50 shadow-lg"
                :class="[widthClass, alignmentClasses]"
                style="display: none"
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
    </div>
</template>

