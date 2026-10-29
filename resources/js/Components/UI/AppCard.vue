<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'paper' | 'well' | 'chrome';
type Padding = 'none' | 'sm' | 'md' | 'lg';

const props = withDefaults(
    defineProps<{
        as?: string;
        variant?: Variant;
        padding?: Padding;
        interactive?: boolean;
    }>(),
    {
        as: 'div',
        variant: 'paper',
        padding: 'md',
        interactive: false,
    },
);

const surfaces: Record<Variant, string> = {
    paper: 'border border-sand-200 bg-white shadow-card',
    well: 'border border-sand-300 bg-sand-50',
    chrome: 'border border-deep-800 bg-deep-900 text-white',
};

const paddings: Record<Padding, string> = {
    none: '',
    sm: 'p-4',
    md: 'p-5 sm:p-6',
    lg: 'p-6 sm:p-8',
};

const classes = computed(() => [
    props.variant === 'well' ? 'rounded-control' : 'rounded-card',
    surfaces[props.variant],
    paddings[props.padding],
    props.interactive
        ? 'transition-colors duration-150 ease-out hover:border-lagoon-600 focus-within:border-lagoon-600'
        : '',
]);
</script>

<template>
    <component :is="as" :class="classes">
        <slot />
    </component>
</template>
