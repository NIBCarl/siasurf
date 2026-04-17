<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'student',
    skill_level: 'beginner',
    is_first_time: true,
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
                            class="text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="ml-2 text-sm text-gray-700">Student/Tourist</span>
                    </label>
                    <label class="flex items-center cursor-pointer">
                        <input
                            type="radio"
                            name="role"
                            value="instructor"
                            v-model="form.role"
                            class="text-indigo-600 focus:ring-indigo-500"
                        />
                        <span class="ml-2 text-sm text-gray-700">Surf Instructor</span>
                    </label>
                </div>

                <InputError class="mt-2" :message="form.errors.role" />
            </div>

            <!-- Student Profile Fields -->
            <div v-if="form.role === 'student'" class="mt-6 border-t border-gray-200 pt-4">
                <h3 class="text-sm font-medium text-gray-900">Surf Experience</h3>
                <p class="text-xs text-gray-500 mb-4">This helps us match you with the right instructor.</p>
                
                <div class="mt-4">
                    <InputLabel for="skill_level" value="Skill Level" />
                    <select
                        id="skill_level"
                        v-model="form.skill_level"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                        required
                    >
                        <option value="beginner">Beginner (Never surfed or still learning basics)</option>
                        <option value="intermediate">Intermediate (Can catch green waves and turn)</option>
                        <option value="advanced">Advanced (Proficient in maneuvers)</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.skill_level" />
                </div>

                <div class="mt-4 flex items-start">
                    <div class="flex h-5 items-center">
                        <input
                            id="is_first_time"
                            type="checkbox"
                            v-model="form.is_first_time"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="is_first_time" class="font-medium text-gray-700">This is my first time surfing in Siargao</label>
                        <p class="text-gray-500">We'll give you extra care and tips for beginners.</p>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
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

                <TextInput
                    id="password_confirmation"
                    type="password"
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

            <div class="mt-4 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Already registered?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Register
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
