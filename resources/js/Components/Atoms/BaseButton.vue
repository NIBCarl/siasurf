<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'outline';
    size?: 'sm' | 'md' | 'lg';
    disabled?: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'click'): void;
}>();

const baseClasses = 'inline-flex items-center justify-center rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2';

const variantClasses = computed(() => {
    switch (props.variant) {
        case 'secondary':
            return 'bg-slate-200 text-slate-900 hover:bg-slate-300 focus:ring-slate-500';
        case 'danger':
            return 'bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-500';
        case 'success':
            return 'bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500';
        case 'outline':
            return 'border-2 border-teal-600 text-teal-600 hover:bg-teal-50 focus:ring-teal-500';
        case 'primary':
        default:
            return 'bg-teal-600 text-white hover:bg-teal-700 focus:ring-teal-500';
    }
});

const sizeClasses = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'px-3 py-1.5 text-sm';
        case 'lg':
            return 'px-6 py-3 text-lg';
        case 'md':
        default:
            return 'px-4 py-2 text-base';
    }
});

const classes = computed(() => {
    return [
        baseClasses,
        variantClasses.value,
        sizeClasses.value,
        (props.disabled || props.loading) ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
    ].join(' ');
});
</script>

<template>
    <button
        :class="classes"
        :disabled="disabled || loading"
        @click="emit('click')"
    >
        <span v-if="loading" class="mr-2">
            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </span>
        <slot />
    </button>
</template>
