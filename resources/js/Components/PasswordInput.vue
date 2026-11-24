<script setup lang="ts">
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline';
import { computed, onMounted, ref, useAttrs } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const model = defineModel<string>({ required: true });

const attrs = useAttrs();
const input = ref<HTMLInputElement | null>(null);
const visible = ref(false);

const wrapperClass = computed(() => ['relative', attrs.class]);

const inputAttrs = computed(() => {
    const { class: _class, type: _type, ...rest } = attrs;
    return rest;
});

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div :class="wrapperClass">
        <input
            ref="input"
            v-model="model"
            v-bind="inputAttrs"
            :type="visible ? 'text' : 'password'"
            class="w-full rounded-lg border-sand-300 bg-white/80 pr-10 shadow-sm transition-all duration-200 focus:border-ocean-500 focus:ring-ocean-500 [&::-ms-reveal]:hidden [&::-ms-clear]:hidden"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-slate-400 transition-colors hover:text-ocean-600 focus:outline-none focus-visible:text-ocean-600 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ocean-500"
            :aria-label="visible ? 'Hide password' : 'Show password'"
            :aria-pressed="visible"
            @click="visible = !visible"
        >
            <EyeSlashIcon v-if="visible" class="h-5 w-5" aria-hidden="true" />
            <EyeIcon v-else class="h-5 w-5" aria-hidden="true" />
        </button>
    </div>
</template>
