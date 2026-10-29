<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
type Size = 'sm' | 'md' | 'lg';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        size?: Size;
        href?: string;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    {
        variant: 'primary',
        size: 'md',
        type: 'button',
        disabled: false,
        loading: false,
        block: false,
    },
);

const base =
    'inline-flex items-center justify-center gap-2 rounded-control font-semibold ' +
    'transition-colors duration-150 ease-out focus:outline-none focus-visible:ring-2 ' +
    'focus-visible:ring-lagoon-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed';

const variants: Record<Variant, string> = {
    primary:
        'bg-coral-700 text-white shadow-card hover:bg-coral-800 hover:shadow-lift ' +
        'disabled:bg-stone-200 disabled:text-stone-500 disabled:shadow-none',
    secondary:
        'border border-sand-300 bg-white text-deep-900 shadow-sm hover:border-lagoon-600 hover:bg-sand-50 ' +
        'disabled:border-stone-200 disabled:bg-stone-100 disabled:text-stone-400 disabled:shadow-none',
    ghost: 'text-deep-800 hover:bg-sand-100 disabled:text-stone-400',
    danger:
        'bg-rose-600 text-white shadow-sm hover:bg-rose-700 ' +
        'disabled:bg-stone-200 disabled:text-stone-500 disabled:shadow-none',
    link: 'px-0 text-lagoon-700 underline underline-offset-4 hover:text-lagoon-800 disabled:text-stone-400',
};

const sizes: Record<Size, string> = {
    sm: 'h-9 px-3 text-body-sm',
    md: 'h-11 px-4 text-body',
    lg: 'h-12 px-6 text-body-lg',
};

const classes = computed(() => [
    base,
    variants[props.variant],
    props.variant === 'link' ? '' : sizes[props.size],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : type"
        :disabled="href ? undefined : disabled || loading"
        :aria-busy="loading || undefined"
        :class="classes"
    >
        <svg
            v-if="loading"
            class="h-4 w-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <slot />
    </component>
</template>
