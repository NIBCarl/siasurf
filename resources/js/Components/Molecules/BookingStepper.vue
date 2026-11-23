<script setup lang="ts">
withDefaults(
    defineProps<{
        /** The step number the user is currently on (1-based). */
        current: number;
        /** Total number of steps. */
        total?: number;
        /** Optional step labels rendered under the stepper. Must match `total` in length. */
        labels?: string[];
        /** Wide layout (max-w-3xl) used by the booking form step. */
        wide?: boolean;
    }>(),
    { total: 4, labels: () => ['Details', 'Waiver', 'Payment', 'Confirmation'], wide: false },
);
</script>

<template>
    <div :class="labels.length === total ? 'mb-14 pb-5' : 'mb-12'">
        <div class="flex items-center justify-between mx-auto" :class="wide ? 'max-w-3xl' : 'max-w-2xl'">
            <div v-for="i in total" :key="i" class="flex items-center flex-1 last:flex-none">
                <div class="relative flex flex-col items-center">
                    <div
                        :class="[
                            'w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all duration-300 shadow-sm border-2',
                            i === current
                                ? 'bg-ocean-600 text-white border-ocean-600 ring-4 ring-ocean-100'
                                : i < current
                                  ? 'bg-reef-500 text-white border-reef-500'
                                  : 'bg-white text-slate-400 border-slate-200',
                        ]"
                    >
                        <svg v-if="i < current" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span v-else>{{ i }}</span>
                    </div>
                    <span
                        v-if="labels.length === total"
                        class="absolute top-12 left-1/2 -translate-x-1/2 whitespace-nowrap text-[11px] uppercase font-bold tracking-wider"
                        :class="i === current ? 'text-ocean-600' : i < current ? 'text-slate-700' : 'text-slate-400'"
                    >
                        {{ labels[i - 1] }}
                    </span>
                </div>
                <div v-if="i < total" class="flex-1 h-1 mx-2" :class="i < current ? 'bg-reef-500' : 'bg-slate-200'"></div>
            </div>
        </div>
    </div>
</template>
