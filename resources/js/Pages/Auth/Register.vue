<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    defaultRole?: 'student' | 'instructor';
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.defaultRole === 'instructor' ? 'instructor' : 'student',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Name" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <!-- Role Selection -->
            <div class="mt-4">
                <InputLabel for="role" value="I am a" />
                
                <div class="mt-2 flex gap-4">
                    <label class="flex items-center cursor-pointer">
                        <input
                            type="radio"
                            name="role"
                            value="student"
                            v-model="form.role"
                            class="text-wave-600 focus:ring-wave-500"
                        />
                        <span class="ml-2 text-sm text-slate-700">Student/Tourist</span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input
                            type="radio"
                            name="role"
                            value="instructor"
                            v-model="form.role"
                            class="text-wave-600 focus:ring-wave-500"
                        />
                        <span class="ml-2 text-sm text-slate-700">Surf Instructor</span>
                    </label>
                </div>

                <InputError class="mt-2" :message="form.errors.role" />
            </div>

            <!-- Student Onboarding Notice -->
            <div v-if="form.role === 'student'" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3.5">
                <h3 class="text-sm font-medium text-slate-900">Skill Pre-Assessment</h3>
                <p class="mt-1 text-xs text-slate-600">
                    Right after signing up, you'll complete a quick skill &amp; water-safety pre-assessment and your surf profile (birthday, country, and board details).
                </p>
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <PasswordInput
                    id="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="Confirm Password"
                />

                <PasswordInput
                    id="password_confirmation"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="mt-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                <Link
                    :href="route('login')"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-md text-sm text-slate-600 underline hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-wave-500 focus:ring-offset-2"
                >
                    Already registered?
                </Link>

                <PrimaryButton
                    class="w-full sm:w-auto sm:ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Register
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
