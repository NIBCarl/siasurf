<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'neutral' | 'verified' | 'live' | 'pending' | 'completed' | 'cancelled' | 'critical';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        dot?: boolean;
    }>(),
    {
        variant: 'neutral',
        dot: true,
    },
);

const variants: Record<Variant, string> = {
    neutral: 'border-sand-300 bg-white text-stone-700',
    verified: 'border-lagoon-300 bg-lagoon-100 text-lagoon-900',
    live: 'border-lagoon-300 bg-lagoon-100 text-lagoon-900',
    pending: 'border-amber-300 bg-amber-100 text-amber-950',
    completed: 'border-sand-300 bg-sand-100 text-stone-700',
    cancelled: 'border-stone-300 bg-stone-100 text-stone-700',
    critical: 'border-rose-600 bg-rose-600 text-white',
};

const dots: Record<Variant, string> = {
    neutral: 'bg-stone-500',
    verified: 'bg-lagoon-700',
    live: 'bg-lagoon-600',
    pending: 'bg-amber-600',
    completed: 'bg-stone-500',
    cancelled: 'bg-stone-500',
    critical: 'bg-white',
};

const classes = computed(() => [
    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-label font-semibold',
    variants[props.variant],
]);
</script>

<template>
    <span :class="classes">
        <span
            v-if="dot"
            class="h-1.5 w-1.5 shrink-0 rounded-full"
            :class="[dots[variant], variant === 'live' ? 'animate-pulse' : '']"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
