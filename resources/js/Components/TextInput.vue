<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string | number>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="rounded-lg border-sand-300 bg-white/80 shadow-sm transition-all duration-200 focus:border-ocean-500 focus:ring-ocean-500"
        v-model="model"
        ref="input"
    />
</template>
