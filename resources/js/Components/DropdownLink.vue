<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    href: string;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    as?: string;
}>();
</script>


<template>
    <Link
        :href="href"
        :method="method"
        :as="as"
        class="flex items-center w-full px-4 py-2 text-start text-sm font-semibold tracking-tight text-slate-700 transition duration-200 ease-in-out hover:bg-slate-50 focus:outline-none group"
    >
        <slot name="icon" />
        <slot />
    </Link>
</template>

