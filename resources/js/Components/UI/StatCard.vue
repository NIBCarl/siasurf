<script setup lang="ts">
import { computed } from 'vue';

type Tone = 'neutral' | 'positive' | 'attention' | 'critical';

const props = withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        meta?: string;
        tone?: Tone;
        icon?: any;
    }>(),
    {
        tone: 'neutral',
    },
);

const tones: Record<Tone, { surface: string; rule: string; value: string }> = {
    neutral: {
        surface: 'border-sand-200 bg-white',
        rule: 'border-t-sand-300',
        value: 'text-deep-900',
    },
    positive: {
        surface: 'border-sand-200 bg-white',
        rule: 'border-t-lagoon-600',
        value: 'text-deep-900',
    },
    attention: {
        surface: 'border-amber-200 bg-amber-50',
        rule: 'border-t-amber-500',
        value: 'text-amber-950',
    },
    critical: {
        surface: 'border-rose-200 bg-rose-50',
        rule: 'border-t-rose-600',
        value: 'text-rose-950',
    },
};

const classes = computed(() => tones[props.tone]);
</script>

<template>
    <div
        class="flex flex-col justify-between rounded-card border border-t-[3px] p-4 shadow-card"
        :class="[classes.surface, classes.rule]"
    >
        <div class="flex items-start justify-between gap-2">
            <span class="text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
                {{ label }}
            </span>
            <component :is="icon" v-if="icon" class="h-4 w-4 shrink-0 text-stone-500" aria-hidden="true" />
            <slot name="badge" />
        </div>

        <div class="mt-3">
            <p class="metric text-title font-semibold tracking-tight sm:text-headline" :class="classes.value">
                {{ value }}
            </p>
            <p v-if="meta" class="mt-1 text-body-sm text-stone-600">{{ meta }}</p>
        </div>

        <div v-if="$slots.footer" class="mt-3 border-t border-sand-200 pt-2 text-body-sm text-stone-600">
            <slot name="footer" />
        </div>
    </div>
</template>
