<script setup lang="ts">
defineProps<{
    icon?: any;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="surface flex flex-col items-center px-6 py-10 text-center">
        <div
            v-if="icon"
            class="flex h-12 w-12 items-center justify-center rounded-card border border-lagoon-100 bg-lagoon-50 text-lagoon-700"
        >
            <component :is="icon" class="h-6 w-6" aria-hidden="true" />
        </div>
        <h3 class="mt-4 text-title font-semibold tracking-tight text-deep-900">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-body-sm text-stone-600">
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap items-center justify-center gap-3">
            <slot />
        </div>
    </div>
</template>
