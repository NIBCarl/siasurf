<script setup lang="ts">
import { computed } from 'vue';

type LogoVariant = 'mark' | 'badge' | 'horizontal' | 'horizontal-dark';

const props = withDefaults(
    defineProps<{
        variant?: LogoVariant;
        alt?: string;
    }>(),
    {
        variant: 'mark',
        alt: 'SiaSurf',
    },
);

const LOGO_SOURCES: Record<LogoVariant, string> = {
    mark: '/images/logo-mark.png',
    badge: '/images/logo-badge.png',
    horizontal: '/images/logo-horizontal.png',
    'horizontal-dark': '/images/logo-horizontal-dark.png',
};

const src = computed(() => LOGO_SOURCES[props.variant]);
</script>

<template>
    <img
        :src="src"
        :alt="alt"
        :class="['block select-none', variant === 'badge' ? 'object-cover' : 'object-contain']"
        decoding="async"
    />
</template>
