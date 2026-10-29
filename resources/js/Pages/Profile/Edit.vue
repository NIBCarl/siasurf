<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import StudentSkillProfile from './Partials/StudentSkillProfile.vue';
import ProfilePhotoCard from '@/Components/Molecules/ProfilePhotoCard.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CheckCircleIcon } from '@heroicons/vue/24/outline';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const flashSuccess = computed(() => (page.props.flash as any)?.success ?? null);

// The dedicated photo uploader is part of the student and instructor settings.
const showsPhotoUploader = computed(() => user.value?.role === 'student' || user.value?.role === 'instructor');
</script>

<template>
    <Head title="Profile Settings" />

    <AppLayout>
        <template #header>
            Profile Settings
        </template>

        <div class="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <div>
                <p class="eyebrow">Account</p>
                <h2 class="mt-1 text-headline font-semibold tracking-tight text-deep-900">Your settings</h2>
                <p class="mt-1 max-w-2xl text-body-sm text-stone-600">
                    Manage your profile photo, contact details, password, and account.
                </p>
            </div>

            <div
                v-if="flashSuccess"
                role="status"
                class="flex items-start gap-3 rounded-card border border-lagoon-200 bg-lagoon-50 p-4 text-body-sm font-medium text-lagoon-800"
            >
                <CheckCircleIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span>{{ flashSuccess }}</span>
            </div>

            <!-- Dedicated profile photo upload (students + instructors) -->
            <ProfilePhotoCard v-if="showsPhotoUploader" id="profile-photo" />

            <!-- Surfing Skill Profile (Students Only) -->
            <section v-if="user?.role === 'student'" class="surface p-6 sm:p-8">
                <div class="max-w-xl">
                    <StudentSkillProfile />
                </div>
            </section>

            <!-- Profile Info Section -->
            <section class="surface p-6 sm:p-8">
                <div class="max-w-xl">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                    />
                </div>
            </section>

            <!-- Password Section -->
            <section class="surface p-6 sm:p-8">
                <div class="max-w-xl">
                    <UpdatePasswordForm />
                </div>
            </section>

            <!-- Delete Account Section -->
            <section class="rounded-card border border-rose-200 bg-rose-50/40 p-6 sm:p-8">
                <div class="max-w-xl">
                    <DeleteUserForm />
                </div>
            </section>
        </div>
    </AppLayout>
</template>

