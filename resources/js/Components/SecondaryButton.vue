<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        type: 'button',
    },
);
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-lg border border-sand-300 bg-sand-50 px-6 py-2.5 text-xs font-semibold uppercase tracking-widest text-sand-700 shadow-sm transition-all duration-200 ease-in-out hover:bg-sand-100 focus:outline-none focus:ring-2 focus:ring-ocean-500 focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
