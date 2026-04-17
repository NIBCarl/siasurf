<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    href: string;
    active?: boolean;
    icon?: any;
}>();

const classes = computed(() =>
    props.active
        ? 'flex items-center px-4 py-3 text-sm font-bold transition-all duration-300 rounded-2xl bg-ocean-500 text-white shadow-lg shadow-ocean-200 group'
        : 'flex items-center px-4 py-3 text-sm font-medium transition-all duration-300 rounded-2xl text-slate-500 hover:bg-ocean-50 hover:text-ocean-600 group'
);

const iconClasses = computed(() =>
    props.active
        ? 'w-5 h-5 mr-3 text-white transition-colors duration-300'
        : 'w-5 h-5 mr-3 text-slate-400 group-hover:text-ocean-500 transition-colors duration-300'
);
</script>

<template>
    <Link :href="href" :class="classes">
        <component 
            v-if="icon" 
            :is="icon" 
            :class="iconClasses" 
        />
        <slot />
        
        <!-- Active Indicator Dot -->
        <div 
            v-if="active" 
            class="ml-auto w-1.5 h-1.5 rounded-full bg-white shadow-[0_0_8px_rgba(255,255,255,0.8)]"
        />
    </Link>
</template>
