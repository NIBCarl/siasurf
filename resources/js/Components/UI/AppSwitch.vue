<script setup lang="ts">
defineProps<{
    modelValue: boolean;
    label: string;
    description?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        class="flex w-full items-start justify-between gap-4 rounded-control border border-sand-200 bg-white p-4 text-left transition-colors hover:border-lagoon-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        @click="emit('update:modelValue', !modelValue)"
    >
        <span class="min-w-0">
            <span class="block text-body font-semibold text-deep-900">{{ label }}</span>
            <span v-if="description" class="mt-0.5 block text-body-sm text-stone-600">
                {{ description }}
            </span>
        </span>

        <span
            class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition-colors duration-150"
            :class="modelValue ? 'bg-lagoon-600' : 'bg-stone-300'"
            aria-hidden="true"
        >
            <span
                class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-150"
                :class="modelValue ? 'translate-x-5' : 'translate-x-0.5'"
            />
        </span>
    </button>
</template>
