<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import InputError from '@/Components/InputError.vue';
import { CameraIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { initialsOf, resolveAvatarUrl } from '@/utils/avatar';

const page = usePage();
const user = computed(() => (page.props.auth as any)?.user ?? null);

const form = useForm<{ avatar: File | null }>({
    avatar: null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);

const currentAvatar = computed<string | null>(() => resolveAvatarUrl(user.value));
const shownAvatar = computed(() => preview.value ?? currentAvatar.value);
const initials = computed(() => initialsOf(user.value?.name) || '?');
const displayName = computed(() => user.value?.name ?? 'your account');

const clearPreview = () => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = null;
};

// A successful upload replaces the page props; drop the local object URL then.
watch(currentAvatar, clearPreview);

const chooseFile = () => {
    fileInput.value?.click();
};

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (!file) {
        return;
    }

    clearPreview();
    preview.value = URL.createObjectURL(file);
    form.avatar = file;
    form.clearErrors();
};

const resetInput = () => {
    form.reset('avatar');
    clearPreview();

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const submit = () => {
    if (!form.avatar) {
        return;
    }

    form.post(route('profile.avatar.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: resetInput,
    });
};

const remove = () => {
    if (!window.confirm(`Remove the profile photo for ${displayName.value}?`)) {
        return;
    }

    // Never attach a not-yet-saved file to the delete request.
    form.avatar = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }

    form.delete(route('profile.avatar.destroy'), {
        preserveScroll: true,
        onSuccess: resetInput,
    });
};

onBeforeUnmount(clearPreview);
</script>

<template>
    <AppCard padding="lg" class="overflow-hidden">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
            <div class="relative shrink-0 self-center sm:self-auto">
                <div class="h-24 w-24 overflow-hidden rounded-full ring-4 ring-lagoon-100">
                    <img
                        v-if="shownAvatar"
                        :src="shownAvatar"
                        :alt="`Profile photo for ${displayName}`"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full w-full items-center justify-center bg-lagoon-50 text-headline font-bold text-lagoon-700"
                        aria-hidden="true"
                    >
                        {{ initials }}
                    </div>
                </div>

                <button
                    type="button"
                    class="absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-coral-700 text-white shadow-card transition-colors hover:bg-coral-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-lagoon-600 focus-visible:ring-offset-2"
                    aria-label="Choose a profile photo"
                    @click="chooseFile"
                >
                    <CameraIcon class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            <div class="min-w-0 flex-1">
                <p class="eyebrow">Your photo</p>
                <h2 class="mt-1 text-title font-semibold tracking-tight text-deep-900">Profile photo</h2>
                <p class="mt-1 max-w-xl text-body-sm text-stone-600">
                    Add a clear photo of yourself. It appears on your bookings, reviews, messages, and public instructor
                    profile. JPG or PNG, up to 2&nbsp;MB.
                </p>

                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png"
                    class="sr-only"
                    @change="onFileChange"
                />

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <AppButton type="button" size="sm" @click="chooseFile">
                        <CameraIcon class="h-4 w-4" aria-hidden="true" />
                        {{ currentAvatar ? 'Change photo' : 'Upload photo' }}
                    </AppButton>

                    <AppButton
                        v-if="form.avatar"
                        type="button"
                        size="sm"
                        variant="primary"
                        :loading="form.processing"
                        @click="submit"
                    >
                        Save photo
                    </AppButton>

                    <AppButton
                        v-if="currentAvatar && !form.processing"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="text-rose-700 hover:bg-rose-50"
                        @click="remove"
                    >
                        <TrashIcon class="h-4 w-4" aria-hidden="true" />
                        Remove
                    </AppButton>
                </div>

                <p v-if="form.progress" class="mt-3 text-label font-semibold text-lagoon-700">
                    Uploading… {{ form.progress.percentage }}%
                </p>

                <InputError class="mt-2" :message="form.errors.avatar" />
            </div>
        </div>
    </AppCard>
</template>
