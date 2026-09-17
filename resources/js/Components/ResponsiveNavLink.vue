<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    href: string;
    active?: boolean;
}>();

const classes = computed(() =>
    props.active
        ? 'block w-full rounded-lg px-3 py-2 text-start text-base font-medium text-ocean-700 bg-ocean-50 focus:outline-none focus:bg-ocean-100 focus:text-ocean-800 transition duration-150 ease-in-out'
        : 'block w-full rounded-lg px-3 py-2 text-start text-base font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-50 focus:outline-none focus:text-slate-900 focus:bg-slate-100 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
