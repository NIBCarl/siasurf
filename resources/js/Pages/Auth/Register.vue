<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    defaultRole?: 'student' | 'instructor';
}>();

const maxBirthday = (() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 5);
    return d.toISOString().split('T')[0];
})();

const commonCountries = [
    'Philippines',
    'Australia',
    'United States',
    'United Kingdom',
    'Canada',
    'France',
    'Germany',
    'Spain',
    'Netherlands',
    'Italy',
    'Switzerland',
    'Sweden',
    'Japan',
    'South Korea',
    'Singapore',
    'New Zealand',
    'Israel',
    'Brazil',
];

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.defaultRole === 'instructor' ? 'instructor' : 'student',
    skill_level: 'beginner',
    is_first_time: true,
    birthday: '',
    country: '',
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

            <!-- Student Profile Fields -->
            <div v-if="form.role === 'student'" class="mt-6 border-t border-slate-200 pt-4">
                <h3 class="text-sm font-medium text-slate-900">Student Profile &amp; Surf Experience</h3>
                <p class="text-xs text-slate-500 mb-4">Used to match you with the right instructor and prefill your surf lesson waiver.</p>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="birthday" value="Birthday" />
                        <TextInput
                            id="birthday"
                            type="date"
                            class="mt-1 block w-full"
                            v-model="form.birthday"
                            :max="maxBirthday"
                            min="1920-01-01"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.birthday" />
                    </div>

                    <div>
                        <InputLabel for="country" value="Country" />
                        <TextInput
                            id="country"
                            type="text"
                            list="register-country-options"
                            class="mt-1 block w-full"
                            v-model="form.country"
                            placeholder="e.g. Philippines"
                            autocomplete="country-name"
                            required
                        />
                        <datalist id="register-country-options">
                            <option v-for="country in commonCountries" :key="country" :value="country" />
                        </datalist>
                        <InputError class="mt-2" :message="form.errors.country" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel for="skill_level" value="Skill Level" />
                    <select
                        id="skill_level"
                        v-model="form.skill_level"
                        class="mt-1 block w-full border-slate-300 focus:border-wave-500 focus:ring-wave-500 rounded-md shadow-sm"
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
                            class="h-4 w-4 rounded border-slate-300 text-wave-600 focus:ring-wave-500"
                        />
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="is_first_time" class="font-medium text-slate-700">This is my first time surfing in Siargao</label>
                        <p class="text-slate-500">We'll give you extra care and tips for beginners.</p>
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
                    class="rounded-md text-sm text-slate-600 underline hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-wave-500 focus:ring-offset-2"
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
