<script setup lang="ts">
const props = defineProps<{
    variant?: 'default' | 'success' | 'warning' | 'danger' | 'info' | 'ocean' | 'wave' | 'reef';
    size?: 'sm' | 'md' | 'lg';
}>();

const variantClasses = {
    default: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-50 text-emerald-700 border border-emerald-100',
    warning: 'bg-amber-50 text-amber-700 border border-amber-100',
    danger: 'bg-rose-50 text-rose-700 border border-rose-100',
    info: 'bg-sky-50 text-sky-700 border border-sky-100',
    ocean: 'bg-ocean-50 text-ocean-700 border border-ocean-100',
    wave: 'bg-wave-50 text-wave-700 border border-wave-100',
    reef: 'bg-reef-50 text-reef-700 border border-reef-100',
};

const sizeClasses = {
    sm: 'px-2 py-0.5 text-[10px] uppercase font-black tracking-wider',
    md: 'px-2.5 py-0.5 text-xs font-bold',
    lg: 'px-3 py-1 text-sm font-bold',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-full',
            variantClasses[variant || 'default'],
            sizeClasses[size || 'md'],
        ]"
    >
        <slot />
    </span>
</template>
