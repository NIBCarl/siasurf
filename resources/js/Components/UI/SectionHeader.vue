<script setup lang="ts">
withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        description?: string;
    }>(),
    {},
);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
            <h2 class="mt-1 text-headline font-semibold tracking-tight text-deep-900">{{ title }}</h2>
            <p v-if="description" class="mt-1 max-w-2xl text-body-sm text-stone-600">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.action" class="shrink-0">
            <slot name="action" />
        </div>
    </div>
</template>
