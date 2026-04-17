<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import StudentSkillProfile from './Partials/StudentSkillProfile.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile" />

    <AppLayout>
        <template #header>
            Profile Settings
        </template>

        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <!-- Surfing Skill Profile (Students Only) -->
            <div v-if="user?.role === 'student'" class="bg-white/80 backdrop-blur-xl border border-slate-100 p-8 rounded-[2.5rem] shadow-sm shadow-slate-200/50">
                <div class="max-w-xl">
                    <StudentSkillProfile />
                </div>
            </div>

            <!-- Profile Info Section -->
            <div class="bg-white/80 backdrop-blur-xl border border-slate-100 p-8 rounded-[2.5rem] shadow-sm shadow-slate-200/50">
                <div class="max-w-xl">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                    />
                </div>
            </div>

            <!-- Password Section -->
            <div class="bg-white/80 backdrop-blur-xl border border-slate-100 p-8 rounded-[2.5rem] shadow-sm shadow-slate-200/50">
                <div class="max-w-xl">
                    <UpdatePasswordForm />
                </div>
            </div>

            <!-- Delete Account Section -->
            <div class="bg-rose-50/30 backdrop-blur-xl border border-rose-100 p-8 rounded-[2.5rem] shadow-sm shadow-rose-200/20">
                <div class="max-w-xl">
                    <DeleteUserForm />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

