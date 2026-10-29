<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        seconds: number;
        label?: string;
        variant?: 'chip' | 'inline';
    }>(),
    {
        variant: 'chip',
    },
);

const pad = (value: number) => value.toString().padStart(2, '0');

const formatted = computed(() => {
    const safe = Math.max(0, Math.floor(props.seconds || 0));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(secs)}`
        : `${pad(minutes)}:${pad(secs)}`;
});
</script>

<template>
    <div
        v-if="variant === 'chip'"
        class="inline-flex items-center gap-3 rounded-control bg-deep-950 px-3.5 py-2 text-white"
        role="timer"
        aria-live="off"
        :aria-label="`${label ?? 'Time remaining'}: ${formatted}`"
    >
        <span v-if="label" class="text-label font-semibold uppercase tracking-[0.08em] text-lagoon-300">
            {{ label }}
        </span>
        <span class="font-mono text-title font-semibold tabular-nums tracking-tight">{{ formatted }}</span>
    </div>
    <span
        v-else
        class="font-mono font-semibold tabular-nums text-deep-900"
        role="timer"
        aria-live="off"
        :aria-label="`${label ?? 'Time remaining'}: ${formatted}`"
    >
        {{ formatted }}
    </span>
</template>
