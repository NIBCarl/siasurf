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
    { total: 4, labels: () => ['Lesson & Spot', 'Waiver & Consent', 'Payment', 'Confirmed'], wide: false },
);
</script>

<template>
    <div :class="labels.length === total ? 'mb-14 pb-5' : 'mb-12'">
        <div class="flex items-center justify-between mx-auto" :class="wide ? 'max-w-3xl' : 'max-w-2xl'">
            <div v-for="i in total" :key="i" class="flex items-center flex-1 last:flex-none">
                <div
                    :class="[
                        'w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all duration-300 shadow-sm border-2',
                        i === current
                            ? 'bg-coral-700 text-white border-coral-700 ring-4 ring-coral-100'
                            : i < current
                              ? 'bg-lagoon-600 text-white border-lagoon-600'
                              : 'bg-white text-stone-500 border-sand-300',
                    ]"
                >
                    <svg v-if="i < current" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span v-else>{{ i }}</span>
                </div>
                <div v-if="i < total" class="flex-1 h-1 mx-2" :class="i < current ? 'bg-lagoon-600' : 'bg-sand-200'"></div>
            </div>
        </div>
        <div
            v-if="labels.length === total"
            class="flex justify-between mx-auto mt-3 text-[11px] sm:text-xs uppercase font-bold tracking-wider text-stone-500 px-1 gap-2"
            :class="wide ? 'max-w-3xl' : 'max-w-2xl'"
        >
            <span
                v-for="(label, idx) in labels"
                :key="label"
                :class="{
                    'text-deep-950 font-extrabold': idx + 1 === current,
                    'text-lagoon-700': idx + 1 < current,
                }"
            >{{ label }}</span>
        </div>
    </div>
</template>
