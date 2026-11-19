<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppSwitch from '@/Components/UI/AppSwitch.vue';
import InputError from '@/Components/InputError.vue';

interface Profile {
    skill_level: string;
    date_of_birth: string | null;
    age: number | null;
    height: number | null;
    weight: number | null;
    has_board: boolean;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    medical_info: string | null;
}

const props = defineProps<{
    profile: Profile;
    intended: string | null;
}>();

const form = useForm({
    date_of_birth: props.profile.date_of_birth ?? '',
    height: props.profile.height ?? ('' as number | string),
    weight: props.profile.weight ?? ('' as number | string),
    has_board: props.profile.has_board,
    emergency_contact_name: props.profile.emergency_contact_name ?? '',
    emergency_contact_phone: props.profile.emergency_contact_phone ?? '',
    medical_info: props.profile.medical_info ?? '',
    intended: props.intended ?? '',
});

// Age is derived from the date of birth so the student sees what the safety
// rules will actually use. Kept in sync as they type.
const age = ref<number | null>(props.profile.age);

watch(
    () => form.date_of_birth,
    (value) => {
        if (!value) {
            age.value = null;
            return;
        }
        const dob = new Date(value);
        if (Number.isNaN(dob.getTime())) {
            age.value = null;
            return;
        }
        const today = new Date();
        let years = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            years -= 1;
        }
        age.value = years >= 0 ? years : null;
    },
    { immediate: true },
);

const isMinor = computed(() => age.value !== null && age.value < 18);
const boardNeedsMeasurements = computed(() => !form.has_board);

// Height and weight only matter for board sizing, so they are not demanded from
// a student who brings their own board.
const measurementRequired = computed(
    () => boardNeedsMeasurements.value && age.value !== null && age.value >= 5,
);

function submit() {
    form.post(route('student.onboarding.store'), {
        preserveScroll: true,
    });
}

function skip() {
    form.post(route('student.onboarding.skip'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Your surf profile" />

    <AppLayout>
        <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-8">
                <p class="text-eyebrow uppercase tracking-widest text-lagoon-600">Welcome to SiaSurf</p>
                <h1 class="mt-2 font-display text-display text-deep-900">Tell us about your surfing</h1>
                <p class="mt-3 max-w-2xl text-body text-stone-600">
                    Fill this in once and every booking will come prefilled — you will not be asked for
                    your age, height, or board on each lesson. Your instructor sees this before you meet.
                </p>
            </header>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Skill level is read-only here: it is set at signup and only a
                     verified instructor can recommend an upgrade. -->
                <section class="rounded-panel border border-sand-200 bg-white p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-h3 text-deep-900">Your level</h2>
                            <p class="mt-1 text-body-sm text-stone-600">
                                An instructor can recommend an upgrade after a lesson.
                            </p>
                        </div>
                        <span class="rounded-full bg-lagoon-50 px-3 py-1 text-body-sm font-semibold capitalize text-lagoon-700 ring-1 ring-inset ring-lagoon-600/20">
                            {{ profile.skill_level }}
                        </span>
                    </div>
                </section>

                <section class="rounded-panel border border-sand-200 bg-white p-6">
                    <h2 class="text-h3 text-deep-900">About you</h2>
                    <p class="mt-1 text-body-sm text-stone-600">
                        Age decides which breaks and instructors are safe for you.
                    </p>

                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="date_of_birth" class="block text-body-sm font-semibold text-deep-900">
                                Date of birth
                            </label>
                            <input
                                id="date_of_birth"
                                v-model="form.date_of_birth"
                                type="date"
                                :max="new Date().toISOString().split('T')[0]"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.date_of_birth" class="mt-1.5" />
                            <p v-if="age !== null" class="mt-1.5 text-body-sm text-stone-600">
                                You are <span class="font-semibold text-deep-900">{{ age }}</span> years old.
                            </p>
                        </div>

                        <div class="flex items-end">
                            <p
                                v-if="isMinor"
                                class="w-full rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-800"
                            >
                                Under 18 — a parent or guardian must sign your waiver before a lesson
                                is confirmed.
                            </p>
                        </div>
                    </div>
                </section>

                <section class="rounded-panel border border-sand-200 bg-white p-6">
                    <h2 class="text-h3 text-deep-900">Board and fit</h2>
                    <p class="mt-1 text-body-sm text-stone-600">
                        Your instructor uses this to bring the right board.
                    </p>

                    <div class="mt-5">
                        <AppSwitch
                            v-model="form.has_board"
                            label="I have my own surfboard"
                            description="Turn this on and we will not ask for your height or weight."
                        />
                        <InputError :message="form.errors.has_board" class="mt-1.5" />
                    </div>

                    <div v-if="boardNeedsMeasurements" class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="height" class="block text-body-sm font-semibold text-deep-900">
                                Height (cm)
                                <span v-if="measurementRequired" class="text-rose-600">*</span>
                            </label>
                            <input
                                id="height"
                                v-model="form.height"
                                type="number"
                                inputmode="decimal"
                                min="50"
                                max="250"
                                step="0.01"
                                placeholder="e.g. 175"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.height" class="mt-1.5" />
                        </div>

                        <div>
                            <label for="weight" class="block text-body-sm font-semibold text-deep-900">
                                Weight (kg)
                                <span v-if="measurementRequired" class="text-rose-600">*</span>
                            </label>
                            <input
                                id="weight"
                                v-model="form.weight"
                                type="number"
                                inputmode="decimal"
                                min="10"
                                max="200"
                                step="0.01"
                                placeholder="e.g. 70"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.weight" class="mt-1.5" />
                        </div>
                    </div>
                </section>

                <section class="rounded-panel border border-sand-200 bg-white p-6">
                    <h2 class="text-h3 text-deep-900">Safety</h2>
                    <p class="mt-1 text-body-sm text-stone-600">
                        Optional, but it is the first thing an instructor wants in an emergency.
                    </p>

                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="emergency_contact_name" class="block text-body-sm font-semibold text-deep-900">
                                Emergency contact name
                            </label>
                            <input
                                id="emergency_contact_name"
                                v-model="form.emergency_contact_name"
                                type="text"
                                maxlength="255"
                                placeholder="Who should we call?"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.emergency_contact_name" class="mt-1.5" />
                        </div>

                        <div>
                            <label for="emergency_contact_phone" class="block text-body-sm font-semibold text-deep-900">
                                Emergency contact phone
                            </label>
                            <input
                                id="emergency_contact_phone"
                                v-model="form.emergency_contact_phone"
                                type="tel"
                                maxlength="50"
                                placeholder="+63 9XX XXX XXXX"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.emergency_contact_phone" class="mt-1.5" />
                        </div>
                    </div>

                    <div class="mt-5">
                        <label for="medical_info" class="block text-body-sm font-semibold text-deep-900">
                            Medical conditions or allergies
                        </label>
                        <textarea
                            id="medical_info"
                            v-model="form.medical_info"
                            rows="3"
                            maxlength="1000"
                            placeholder="Asthma, heart conditions, allergies, medications — anything your instructor should know."
                            class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                        />
                        <InputError :message="form.errors.medical_info" class="mt-1.5" />
                        <p class="mt-1.5 text-body-sm text-stone-500">
                            Only your instructor and SISA see this.
                        </p>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <AppButton variant="ghost" :disabled="form.processing" @click="skip">
                        Skip for now
                    </AppButton>

                    <AppButton type="submit" size="lg" :loading="form.processing">
                        Save and continue
                    </AppButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
