<script setup lang="ts">
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import Modal from '@/Components/Modal.vue';
import BaseButton from '@/Components/Atoms/BaseButton.vue';

defineProps<{
    show: boolean;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'stay'): void;
    (e: 'confirm'): void;
}>();
</script>

<template>
    <Modal
        :show="show"
        max-width="md"
        :closeable="!loading"
        @close="if (!loading) emit('stay');"
    >
        <div class="p-6">
            <div class="flex items-start gap-4">
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600"
                >
                    <ExclamationTriangleIcon class="h-6 w-6" aria-hidden="true" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-slate-900">
                        Do you want to leave this page?
                    </h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        Your reserved time slot will be released immediately so other
                        students can book it. Any details you typed on this waiver
                        will not be saved.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <BaseButton
                    type="button"
                    variant="secondary"
                    :disabled="loading"
                    @click="emit('stay')"
                >
                    Stay &amp; Keep Slot
                </BaseButton>
                <BaseButton
                    type="button"
                    variant="danger"
                    :loading="loading"
                    @click="emit('confirm')"
                >
                    Leave &amp; Release Slot
                </BaseButton>
            </div>
        </div>
    </Modal>
</template>
