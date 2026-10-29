<script setup lang="ts">
import { computed } from 'vue';

type Tone = 'neutral' | 'positive' | 'attention' | 'critical';

const props = withDefaults(
    defineProps<{
        label?: string;
        tone?: Tone;
    }>(),
    {
        tone: 'neutral',
    },
);

const tones: Record<Tone, string> = {
    neutral: 'border-sand-300 bg-sand-50 text-stone-700',
    positive: 'border-lagoon-300 bg-lagoon-100 text-lagoon-900',
    attention: 'border-amber-300 bg-amber-100 text-amber-950',
    critical: 'border-rose-300 bg-rose-100 text-rose-950',
};

const classes = computed(() => ['rounded-control border p-4', tones[props.tone]]);
</script>

<template>
    <div :class="classes">
        <p v-if="label" class="text-label font-semibold uppercase tracking-[0.06em] opacity-80">
            {{ label }}
        </p>
        <div :class="label ? 'mt-1.5 text-body-sm' : 'text-body-sm'">
            <slot />
        </div>
    </div>
</template>
