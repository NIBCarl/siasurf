<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppSidebarLayout from './AppSidebarLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

// Use the unified Sidebar layout for all authenticated roles
const layout = AppSidebarLayout;
</script>

<template>
    <component :is="layout">
        <template v-if="$slots.header" #header>
            <slot name="header" />
        </template>
        <slot />
    </component>
</template>

