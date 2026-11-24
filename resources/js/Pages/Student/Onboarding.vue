<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppSwitch from '@/Components/UI/AppSwitch.vue';
import InputError from '@/Components/InputError.vue';
import { calculatePhilippineAge, getPhilippineMaxBirthday, getPhilippineTodayKey } from '@/utils/philippineTime';

interface Profile {
    skill_level: string;
    skill_assessed_at: string | null;
    is_first_time: boolean;
    phone: string | null;
    date_of_birth: string | null;
    country: string | null;
    age: number | null;
    height: number | null;
    weight: number | null;
    has_board: boolean;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
}

interface AssessmentQuestion {
    index: number;
    key: string;
    topic: string;
    question: string;
    options: string[];
    required: boolean;
}

interface AssessmentDefinition {
    skills: {
        basic: string[];
        intermediate: string[];
        advanced: string[];
    };
    questions: AssessmentQuestion[];
    thresholds: {
        intermediate_skills_required: number;
        advanced_skills_required: number;
        intermediate_knowledge_required: number;
        advanced_knowledge_required: number;
    };
}

interface AssessmentFlag {
    code: string;
    severity: 'warning' | 'info' | string;
    message: string;
}

interface AssessmentSummary {
    id: number;
    assessed_level: string;
    assessed_level_label: string;
    checklist_level: string;
    knowledge_level: string;
    knowledge_score: number;
    knowledge_total: number;
    can_swim_deep_water: boolean;
    is_first_time: boolean;
    water_safety_passed: boolean;
    safety_flags: AssessmentFlag[];
    has_safety_warnings: boolean;
    ticked_skills_count: number;
    ticked_skills: Array<{ tier: string; label: string }>;
    bookable_instructors: string;
    allowed_zones: string;
    assessed_at: string | null;
    assessed_at_label: string | null;
}

const props = defineProps<{
    profile: Profile;
    assessmentDefinition: AssessmentDefinition;
    latestAssessment: AssessmentSummary | null;
    intended: string | null;
}>();

const page = usePage();
const flashSuccess = computed(() => (page.props.flash as any)?.success ?? null);

const assessmentLevels = ['beginner', 'intermediate', 'advanced'] as const;
const todayKey = getPhilippineTodayKey();

const assessmentForm = useForm({
    date_of_birth: props.profile.date_of_birth ?? '',
    can_swim_deep_water: false,
    is_first_time: false,
    skills: {
        basic: [] as number[],
        intermediate: [] as number[],
        advanced: [] as number[],
    },
    answers: [null, null, null, null] as Array<number | null>,
    confirmed: false,
    intended: props.intended ?? '',
});

const assessmentAge = computed(() => calculatePhilippineAge(assessmentForm.date_of_birth));
const assessmentTooYoung = computed(() => assessmentAge.value !== null && assessmentAge.value < 5);
const assessmentIsMinor = computed(() => assessmentAge.value !== null && assessmentAge.value >= 5 && assessmentAge.value < 18);
const assessmentNeedsLevel3 = computed(() => assessmentAge.value !== null && assessmentAge.value >= 5 && assessmentAge.value <= 12);

const canSubmitAssessment = computed(
    () =>
        Boolean(assessmentForm.date_of_birth) &&
        !assessmentTooYoung.value &&
        assessmentForm.answers[0] !== null &&
        assessmentForm.confirmed &&
        !assessmentForm.processing,
);

function submitAssessment() {
    if (!canSubmitAssessment.value) {
        return;
    }

    assessmentForm.post(route('student.onboarding.assessment'), {
        preserveScroll: true,
    });
}

const maxBirthday = getPhilippineMaxBirthday(5);

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
    phone: props.profile.phone ?? '',
    date_of_birth: props.profile.date_of_birth ?? '',
    country: props.profile.country ?? '',
    height: props.profile.height ?? ('' as number | string),
    weight: props.profile.weight ?? ('' as number | string),
    has_board: props.profile.has_board,
    emergency_contact_name: props.profile.emergency_contact_name ?? '',
    emergency_contact_phone: props.profile.emergency_contact_phone ?? '',
    intended: props.intended ?? '',
});

// Age is derived from the date of birth so the student sees what the safety
// rules will actually use. Kept in sync as they type.
const age = ref<number | null>(props.profile.age);

watch(
    () => props.profile.date_of_birth,
    (value) => {
        if (value && !form.date_of_birth) {
            form.date_of_birth = value;
        }
        if (value && !assessmentForm.date_of_birth) {
            assessmentForm.date_of_birth = value;
        }
    },
);

watch(
    () => form.date_of_birth,
    (value) => {
        age.value = calculatePhilippineAge(value);
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
                    Complete your quick skill pre-assessment and surf profile once. Your level sets which
                    instructors and breaks you can book safely, and your details will prefill every booking.
                </p>
            </header>

            <div
                v-if="flashSuccess"
                role="status"
                class="mb-6 rounded-panel border border-lagoon-200 bg-lagoon-50 px-5 py-4 text-body-sm font-medium text-lagoon-900"
            >
                {{ flashSuccess }}
            </div>

            <div class="space-y-8">
                <!-- STEP 1: SKILL PRE-ASSESSMENT (OR ASSESSED RESULT) -->
                <section v-if="latestAssessment" class="rounded-panel border border-sand-200 bg-white p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-eyebrow uppercase tracking-widest text-lagoon-600">Step 1 · Assessed level</p>
                            <h2 class="mt-1 text-h2 font-semibold capitalize text-deep-900">
                                {{ profile.skill_level }}
                            </h2>
                            <p v-if="latestAssessment.assessed_at_label" class="mt-1 text-body-sm text-stone-500">
                                Completed {{ latestAssessment.assessed_at_label }} · Verified instructors can recommend an upgrade after a lesson.
                            </p>
                        </div>
                        <span class="rounded-full bg-lagoon-50 px-3 py-1 text-body-sm font-semibold capitalize text-lagoon-700 ring-1 ring-inset ring-lagoon-600/20">
                            {{ profile.skill_level }}
                        </span>
                    </div>

                    <!-- Three-tier level bar -->
                    <div class="mt-5 grid grid-cols-3 gap-2">
                        <div
                            v-for="lvl in assessmentLevels"
                            :key="lvl"
                            :class="[
                                'rounded-control border px-3 py-2.5 text-center text-body-sm capitalize transition-colors',
                                latestAssessment.assessed_level === lvl
                                    ? 'border-lagoon-600 bg-lagoon-600 font-semibold text-white'
                                    : 'border-sand-200 bg-sand-50/50 text-stone-500',
                            ]"
                        >
                            {{ lvl }}
                        </div>
                    </div>

                    <!-- Safety flags -->
                    <div v-if="latestAssessment.safety_flags.length > 0" class="mt-4 space-y-2">
                        <div
                            v-for="flag in latestAssessment.safety_flags"
                            :key="flag.code"
                            :class="[
                                'rounded-control border-l-4 px-4 py-3 text-body-sm',
                                flag.severity === 'warning'
                                    ? 'border-amber-500 bg-amber-50 text-amber-900'
                                    : 'border-lagoon-500 bg-lagoon-50/70 text-deep-900',
                            ]"
                        >
                            {{ flag.message }}
                        </div>
                    </div>

                    <div
                        v-if="profile.age !== null && profile.age < 18"
                        class="mt-4 rounded-control border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-body-sm text-amber-900"
                    >
                        <strong>Parent or Guardian Consent Required (Age {{ profile.age }}):</strong>
                        You can continue using SiaSurf, but a parent or legal guardian must sign consent in the digital waiver before any booking is submitted.
                        <template v-if="profile.age <= 12">
                            For safety, surfers aged 5–12 must book with a Level 3 (Expert) instructor.
                        </template>
                    </div>

                    <!-- Breakdown rows -->
                    <dl class="mt-5 divide-y divide-sand-200 border-t border-sand-200 text-body-sm">
                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-stone-600">Skills checklist</dt>
                            <dd class="font-semibold capitalize text-deep-900">{{ latestAssessment.checklist_level }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-stone-600">
                                Knowledge questions ({{ latestAssessment.knowledge_score }} of {{ latestAssessment.knowledge_total }} correct)
                            </dt>
                            <dd class="font-semibold capitalize text-deep-900">{{ latestAssessment.knowledge_level }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-stone-600">Final assessed level (the lower of the two, after safety rules)</dt>
                            <dd class="font-semibold capitalize text-deep-900">{{ latestAssessment.assessed_level }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 grid gap-4 border-t border-sand-200 pt-5 sm:grid-cols-2">
                        <div>
                            <h3 class="text-body-sm font-semibold text-deep-900">What you can book</h3>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-body-sm text-stone-600">
                                <li>{{ latestAssessment.bookable_instructors }}</li>
                                <li>{{ latestAssessment.allowed_zones }}</li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="text-body-sm font-semibold text-deep-900">What your instructor sees</h3>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-body-sm text-stone-600">
                                <li>Your level and the skills you ticked ({{ latestAssessment.ticked_skills_count }} items)</li>
                                <li>Your {{ latestAssessment.knowledge_total }} answers ({{ latestAssessment.knowledge_score }} correct)</li>
                                <li>Any safety warning above</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <form v-else class="space-y-6" @submit.prevent="submitAssessment">
                    <section class="rounded-panel border border-sand-200 bg-white p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-eyebrow uppercase tracking-widest text-lagoon-600">Step 1 · Skill pre-assessment</p>
                                <h2 class="mt-1 text-h2 font-semibold text-deep-900">Verify your surf level</h2>
                                <p class="mt-1.5 text-body-sm text-stone-600">
                                    Answer honestly. Your level decides which instructors and surf zones you can book,
                                    and it keeps you safe in the water.
                                </p>
                            </div>
                            <span class="rounded-full bg-amber-50 px-3 py-1 text-body-sm font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20">
                                Not yet assessed
                            </span>
                        </div>

                        <p class="mt-4 border-l-2 border-lagoon-600 pl-3 text-body-sm text-stone-600">
                            Intermediate needs all Basic items and {{ assessmentDefinition.thresholds.intermediate_skills_required }} of
                            {{ assessmentDefinition.skills.intermediate.length }} Intermediate items, plus
                            {{ assessmentDefinition.thresholds.intermediate_knowledge_required }} of {{ assessmentDefinition.questions.length }}
                            correct answers including the water safety question. Advanced needs
                            {{ assessmentDefinition.thresholds.advanced_skills_required }} of
                            {{ assessmentDefinition.skills.advanced.length }} Advanced items and all
                            {{ assessmentDefinition.thresholds.advanced_knowledge_required }} answers correct.
                        </p>

                        <InputError :message="(assessmentForm.errors as Record<string, string>).assessment" class="mt-3" />

                        <!-- Part 0: Date of Birth & Age Eligibility -->
                        <fieldset class="mt-6 rounded-control border border-sand-200 bg-sand-50/40 p-4">
                            <legend class="px-1.5 text-body-sm font-semibold text-deep-900">Date of Birth &amp; Age Eligibility</legend>
                            <div class="mt-2 grid gap-4 sm:grid-cols-2 sm:items-end">
                                <div>
                                    <label for="assessment_date_of_birth" class="block text-body-sm font-semibold text-deep-900">
                                        Your Date of Birth <span class="text-rose-600">*</span>
                                    </label>
                                    <input
                                        id="assessment_date_of_birth"
                                        v-model="assessmentForm.date_of_birth"
                                        type="date"
                                        required
                                        min="1920-01-01"
                                        :max="todayKey"
                                        class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                                    />
                                    <InputError :message="assessmentForm.errors.date_of_birth" class="mt-1.5" />
                                </div>
                                <div v-if="assessmentAge !== null" class="text-body-sm">
                                    <span
                                        v-if="assessmentTooYoung"
                                        class="inline-flex items-center rounded-full bg-rose-50 px-3 py-1 font-semibold text-rose-800 ring-1 ring-inset ring-rose-600/20"
                                    >
                                        Age {{ assessmentAge }} — Minimum age is 5
                                    </span>
                                    <span
                                        v-else-if="assessmentIsMinor"
                                        class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1 font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20"
                                    >
                                        Age {{ assessmentAge }} — Parental consent required
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-600/20"
                                    >
                                        Age {{ assessmentAge }} — Eligible
                                    </span>
                                </div>
                            </div>
                            <p
                                v-if="assessmentTooYoung"
                                class="mt-3 rounded-control border border-rose-200 bg-rose-50 px-3 py-2 text-body-sm font-medium text-rose-800"
                            >
                                Students must be at least 5 years old to book surf lessons on SiaSurf.
                            </p>
                            <p
                                v-else-if="assessmentIsMinor"
                                class="mt-3 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-900"
                            >
                                <strong>Under 18 — Parent or Guardian Consent Required:</strong> You may continue with this pre-assessment and book a lesson, but your parent or legal guardian must provide their consent and signature in the digital waiver before submitting any booking.
                                <template v-if="assessmentNeedsLevel3">
                                    <strong>Safety rule:</strong> Surfers aged 5–12 must book with a Level 3 (Expert) instructor.
                                </template>
                            </p>
                        </fieldset>

                        <!-- Part A: Basics -->
                        <fieldset class="mt-6 rounded-control border border-sand-200 bg-sand-50/40 p-4">
                            <legend class="px-1.5 text-body-sm font-semibold text-deep-900">Part A. Basics</legend>
                            <div class="mt-2 space-y-2">
                                <label class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60">
                                    <input
                                        v-model="assessmentForm.can_swim_deep_water"
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                    />
                                    <span class="text-body-sm text-deep-900">
                                        I can swim comfortably in deep water without a board.
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60">
                                    <input
                                        v-model="assessmentForm.is_first_time"
                                        type="checkbox"
                                        class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                    />
                                    <span class="text-body-sm text-deep-900">
                                        This is my first time surfing, or I’m not sure of my level.
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <!-- Part B: Skills Checklist -->
                        <fieldset class="mt-5 rounded-control border border-sand-200 bg-sand-50/40 p-4">
                            <legend class="px-1.5 text-body-sm font-semibold text-deep-900">Part B. Tick the skills you can do</legend>

                            <div class="mt-3 space-y-4">
                                <div>
                                    <p class="text-label font-semibold uppercase tracking-wider text-stone-500">Basic</p>
                                    <div class="mt-1.5 space-y-1">
                                        <label
                                            v-for="(item, idx) in assessmentDefinition.skills.basic"
                                            :key="`basic-${idx}`"
                                            class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60"
                                        >
                                            <input
                                                v-model="assessmentForm.skills.basic"
                                                type="checkbox"
                                                :value="idx"
                                                class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                            />
                                            <span class="text-body-sm text-deep-900">{{ item }}</span>
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <p class="text-label font-semibold uppercase tracking-wider text-stone-500">Intermediate</p>
                                    <div class="mt-1.5 space-y-1">
                                        <label
                                            v-for="(item, idx) in assessmentDefinition.skills.intermediate"
                                            :key="`inter-${idx}`"
                                            class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60"
                                        >
                                            <input
                                                v-model="assessmentForm.skills.intermediate"
                                                type="checkbox"
                                                :value="idx"
                                                class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                            />
                                            <span class="text-body-sm text-deep-900">{{ item }}</span>
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <p class="text-label font-semibold uppercase tracking-wider text-stone-500">Advanced</p>
                                    <div class="mt-1.5 space-y-1">
                                        <label
                                            v-for="(item, idx) in assessmentDefinition.skills.advanced"
                                            :key="`adv-${idx}`"
                                            class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60"
                                        >
                                            <input
                                                v-model="assessmentForm.skills.advanced"
                                                type="checkbox"
                                                :value="idx"
                                                class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                            />
                                            <span class="text-body-sm text-deep-900">{{ item }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </fieldset>

                        <!-- Part C: Knowledge Questions -->
                        <fieldset class="mt-5 rounded-control border border-sand-200 bg-sand-50/40 p-4">
                            <legend class="px-1.5 text-body-sm font-semibold text-deep-900">Part C. Four short questions</legend>

                            <div class="mt-3 space-y-5">
                                <div v-for="q in assessmentDefinition.questions" :key="q.key">
                                    <p class="text-body-sm font-semibold text-deep-900">
                                        {{ q.index + 1 }}. {{ q.topic }}
                                        <span v-if="q.required" class="font-semibold text-rose-600">(required)</span>
                                    </p>
                                    <p class="mt-0.5 text-body-sm text-stone-700">{{ q.question }}</p>
                                    <div class="mt-2 space-y-1">
                                        <label
                                            v-for="(opt, optIdx) in q.options"
                                            :key="`${q.key}-${optIdx}`"
                                            class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60"
                                        >
                                            <input
                                                v-model="assessmentForm.answers[q.index]"
                                                type="radio"
                                                :name="`assessment_q_${q.index}`"
                                                :value="optIdx"
                                                class="mt-0.5 h-4 w-4 border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                            />
                                            <span class="text-body-sm text-deep-900">{{ opt }}</span>
                                        </label>
                                    </div>
                                    <InputError
                                        :message="(assessmentForm.errors as Record<string, string>)[`answers.${q.index}`]"
                                        class="mt-1"
                                    />
                                </div>
                            </div>
                        </fieldset>

                        <!-- Confirmation -->
                        <div class="mt-5">
                            <label class="flex cursor-pointer items-start gap-3 rounded-control p-2 hover:bg-lagoon-50/60">
                                <input
                                    v-model="assessmentForm.confirmed"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-sand-300 text-lagoon-600 focus:ring-lagoon-600"
                                />
                                <span class="text-body-sm font-medium text-deep-900">
                                    I confirm that my answers are accurate. (This initial assessment is submitted once; instructors can recommend level upgrades after lessons.)
                                </span>
                            </label>
                            <InputError :message="assessmentForm.errors.confirmed" class="mt-1" />
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-4">
                            <AppButton
                                type="submit"
                                size="lg"
                                :loading="assessmentForm.processing"
                                :disabled="!canSubmitAssessment"
                            >
                                See my level
                            </AppButton>
                            <p v-if="!canSubmitAssessment" class="text-body-sm text-stone-500">
                                <template v-if="!assessmentForm.date_of_birth">
                                    Enter your date of birth, answer the water safety question, and confirm your answers to continue.
                                </template>
                                <template v-else-if="assessmentTooYoung">
                                    Students must be at least 5 years old to take surf lessons.
                                </template>
                                <template v-else>
                                    Answer the water safety question and confirm your answers to continue.
                                </template>
                            </p>
                        </div>
                    </section>
                </form>

                <!-- STEP 2: SURF PROFILE & BOOKING PREFILL DETAILS -->
                <form class="space-y-6" @submit.prevent="submit">
                    <section class="rounded-panel border border-sand-200 bg-white p-6">
                        <p class="text-eyebrow uppercase tracking-widest text-lagoon-600">Step 2 · Booking details</p>
                        <h2 class="mt-1 text-h3 text-deep-900">About you</h2>
                        <p class="mt-1 text-body-sm text-stone-600">
                            Used to prefill your surf lesson waiver and check age-based safety rules, and your phone lets your instructor reach you if a lesson needs to move.
                        </p>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="date_of_birth" class="block text-body-sm font-semibold text-deep-900">
                                    Birthday
                                </label>
                                <input
                                    id="date_of_birth"
                                    v-model="form.date_of_birth"
                                    type="date"
                                    :max="maxBirthday"
                                    min="1920-01-01"
                                    class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                                />
                                <InputError :message="form.errors.date_of_birth" class="mt-1.5" />
                                <p v-if="age !== null" class="mt-1.5 text-body-sm text-stone-600">
                                    You are <span class="font-semibold text-deep-900">{{ age }}</span> years old.
                                </p>
                            </div>

                            <div>
                                <label for="country" class="block text-body-sm font-semibold text-deep-900">
                                    Country
                                </label>
                                <input
                                    id="country"
                                    v-model="form.country"
                                    type="text"
                                    list="onboarding-country-options"
                                    maxlength="100"
                                    autocomplete="country-name"
                                    placeholder="e.g. Philippines"
                                    class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                                />
                                <datalist id="onboarding-country-options">
                                    <option v-for="country in commonCountries" :key="country" :value="country" />
                                </datalist>
                                <InputError :message="form.errors.country" class="mt-1.5" />
                                <p class="mt-1.5 text-body-sm text-stone-500">
                                    Prefilled on your surf lesson waiver.
                                </p>
                            </div>
                        </div>

                        <div class="mt-5">
                            <label for="phone" class="block text-body-sm font-semibold text-deep-900">
                                Your phone number
                            </label>
                            <input
                                id="phone"
                                v-model="form.phone"
                                type="tel"
                                maxlength="30"
                                autocomplete="tel"
                                placeholder="+63 917 000 0000"
                                class="mt-1.5 block w-full rounded-control border border-sand-300 bg-white px-3 py-2 text-body text-deep-900 shadow-sm focus:border-lagoon-600 focus:outline-none focus:ring-2 focus:ring-lagoon-600/20"
                            />
                            <InputError :message="form.errors.phone" class="mt-1.5" />
                            <p class="mt-1.5 text-body-sm text-stone-500">
                                Used by your instructor or SISA if a lesson needs to be moved.
                            </p>
                        </div>

                        <p
                            v-if="isMinor"
                            class="mt-4 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 text-body-sm text-amber-800"
                        >
                            Under 18 — a parent or guardian must sign your waiver before a lesson
                            is confirmed.
                        </p>
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
        </div>
    </AppLayout>
</template>
