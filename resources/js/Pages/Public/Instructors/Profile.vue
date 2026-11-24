<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InstructorDirectoryMap from '@/Components/Organisms/InstructorDirectoryMap.vue';
import SurfZoneMap, { type SurfZoneSpot } from '@/Components/Organisms/SurfZoneMap.vue';
import SurfZoneTabs from '@/Components/Molecules/SurfZoneTabs.vue';
import { 
    StarIcon, 
    CheckBadgeIcon, 
    CalendarIcon, 
    MapPinIcon, 
    ShieldCheckIcon,
    AcademicCapIcon,
    BanknotesIcon,
    ArrowLeftIcon
} from '@heroicons/vue/24/solid';
import { 
    ShieldCheckIcon as ShieldCheckOutline,
    ChatBubbleLeftRightIcon
} from '@heroicons/vue/24/outline';
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { resolveAvatarUrl } from '@/utils/avatar';

const props = defineProps<{
    instructor: any;
    totalReviews: number;
    serviceRates?: Array<{
        type: string;
        label: string;
        description: string;
        rate: number;
        min: number;
        max: number;
    }>;
    startingRate?: number;
    surfSpots?: SurfZoneSpot[];
    studentBookingBlockReason?: string | null;
    weeklyAvailability?: Array<{
        value: number;
        label: string;
        short_label: string;
        periods: string[];
        ranges?: Array<{
            period: string;
            period_label: string;
            start: string;
            end: string;
            label: string;
        }>;
    }>;
    averageRating?: number;
    instructorSessionWarning?: {
        in_session: boolean;
        ends_at_label?: string | null;
        message: string;
    } | null;
}>();

const page = usePage();
const isAuthenticated = computed(() => Boolean((page.props.auth as any)?.user));
const isStudent = computed(() => (page.props.auth as any)?.user?.role === 'student');
const canBook = computed(() => !isAuthenticated.value || isStudent.value);
const instructorAvatar = computed(() => resolveAvatarUrl(props.instructor));
const instructorInitials = computed(() =>
    String(props.instructor.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join(''),
);

const mapInstructors = computed(() => {
    const profile = props.instructor.instructor_profile;
    if (!profile?.latitude || !profile?.longitude) return [];

    return [{
        id: props.instructor.id,
        name: props.instructor.name,
        area: profile.area ?? null,
        meeting_point: profile.meeting_point ?? null,
        latitude: Number(profile.latitude),
        longitude: Number(profile.longitude),
        level: profile.level,
        level_label: instructorLevelLabel[profile.level] ?? `Level ${profile.level}`,
        rate_per_hour: Number(profile.rate_per_hour),
        average_rating: props.averageRating ?? 0,
        total_reviews: props.totalReviews,
        profile_url: route('instructors.profile', props.instructor.id),
        avatar_url: resolveAvatarUrl(props.instructor),
    }];
});

const instructorLevelLabel: Record<number, string> = {
    1: 'Level 1 (SISA Certified)',
    2: 'Level 2 (SISA/BLS Certified)',
    3: 'Level 3 (ISA International Certified)'
};

const instructorLevelDescription: Record<number, string> = {
    1: 'Certified for Beginner Teaching only. Maximum 1 student per session (1-on-1).',
    2: 'Certified for Beginner Teaching (1 student) and Intermediate Guiding (up to 2 students).',
    3: 'Certified for Teaching, Guiding, and Coaching across all skill levels (up to 3 same-level students).'
};
</script>

<template>
    <Head :title="`${instructor.name} | Surf Instructor`" />

    <AppSidebarLayout>
        <template #header>
            Instructor Profile
        </template>

        <Head :title="`${instructor.name} | Surf Instructor`" />

        <div class="mb-6 sm:mb-10 flex flex-wrap items-center justify-between gap-3 px-0 sm:px-4">
            <Link :href="route('instructors.search')" class="flex items-center gap-3 text-sm font-black text-slate-400 transition-all hover:text-ocean-600 group">
                <div class="p-2 rounded-xl bg-white border border-slate-100 group-hover:border-ocean-100 group-hover:bg-ocean-50 transition-all">
                    <ArrowLeftIcon class="h-4 w-4" />
                </div>
                Back to Discovery
            </Link>
            
            <div v-if="canBook" class="flex items-center gap-4">
                <Link
                    v-if="!studentBookingBlockReason"
                    :href="route('bookings.create', instructor.id)" 
                    class="rounded-2xl bg-coral-700 px-5 sm:px-8 py-3 sm:py-3.5 text-sm font-black text-white shadow-card transition-all hover:bg-coral-800 active:scale-95"
                >
                    Book This Expert
                </Link>
                <span
                    v-else
                    class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-2.5 text-xs font-bold text-amber-800"
                >
                    Unavailable for your skill level
                </span>
            </div>
        </div>

        <div class="grid gap-6 sm:gap-8 lg:grid-cols-3 lg:gap-12" :class="canBook ? 'pb-24 lg:pb-0' : ''">
            <!-- Left Column: Primary Info -->
            <div class="min-w-0 lg:col-span-2 space-y-6 sm:space-y-8 lg:space-y-12">
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] p-5 sm:p-8 lg:p-10 shadow-sm border border-slate-100">
                    <div class="flex flex-col items-center md:items-start gap-6 sm:gap-10 md:flex-row">
                        <div class="relative group shrink-0">
                            <div class="h-36 w-36 sm:h-48 sm:w-48 overflow-hidden rounded-[2rem] sm:rounded-[2.5rem] border-8 border-slate-50 bg-sand-50 shadow-2xl transition-transform duration-500 group-hover:scale-105">
                                <img 
                                    v-if="instructorAvatar" 
                                    :src="instructorAvatar" 
                                    class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-110" 
                                    alt=""
                                />
                                <div v-else class="flex h-full w-full items-center justify-center bg-lagoon-50 text-display font-bold text-lagoon-700">
                                    {{ instructorInitials }}
                                </div>
                            </div>
                            <div class="absolute -bottom-2 -right-2 flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-2xl sm:rounded-3xl bg-ocean-600 text-white shadow-2xl shadow-ocean-200 border-4 border-white rotate-12 group-hover:rotate-0 transition-transform">
                                <CheckBadgeIcon class="h-7 w-7 sm:h-8 sm:w-8" />
                            </div>
                        </div>

                        <div class="min-w-0 flex-1 text-center md:text-left">
                            <div class="mb-4">
                                <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mb-3 sm:mb-4">
                                    <span class="inline-block rounded-xl bg-sand-100 px-4 py-1.5 text-[10px] font-black uppercase tracking-[0.2em] text-sand-700">
                                        Verified Surf Professional
                                    </span>
                                    <span
                                        v-if="instructorSessionWarning?.in_session"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-amber-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-amber-900"
                                        :title="instructorSessionWarning.message"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                        Currently in session<template v-if="instructorSessionWarning.ends_at_label"> · until ~{{ instructorSessionWarning.ends_at_label }}</template>
                                    </span>
                                </div>
                                <h1 class="text-3xl sm:text-5xl font-extrabold text-slate-900 capitalize tracking-tight leading-tight break-words">
                                    {{ instructor.name }}
                                </h1>
                            </div>
                            
                            <div class="flex flex-wrap items-center justify-center md:justify-start gap-4 sm:gap-6 text-slate-500">
                                <div v-if="totalReviews > 0" class="flex items-center gap-2 font-black text-xs uppercase tracking-widest text-lagoon-700">
                                    <ChatBubbleLeftRightIcon class="h-5 w-5" />
                                    <span>{{ totalReviews }} reviews</span>
                                </div>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"
                                >
                                    <StarIcon class="h-3.5 w-3.5 text-emerald-700" />
                                    New SISA-Verified Coach
                                </span>
                                <div class="flex items-center gap-2 font-bold text-xs uppercase tracking-widest">
                                    <MapPinIcon class="h-5 w-5 text-slate-400" />
                                    <span>Siargao, PH</span>
                                </div>
                                <div class="flex items-center gap-2 font-black text-xs uppercase tracking-widest text-emerald-600">
                                    <ShieldCheckIcon class="h-5 w-5" />
                                    <span>Safety First</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 pt-6 sm:mt-12 sm:pt-10 border-t border-slate-50">
                        <h2 class="mb-4 sm:mb-6 text-lg sm:text-xl font-extrabold text-slate-900 uppercase tracking-widest">Professional Bio</h2>
                        <p class="text-base sm:text-lg leading-relaxed text-slate-600 font-medium italic break-words">
                            "{{ instructor.instructor_profile.bio || 'This instructor is a dedicated member of the Siargao surfing community, certified to provide professional and safe surfing lessons for various skill levels.' }}"
                        </p>
                    </div>
                </div>

                <!-- Weekly Availability (FR 1.8.2) -->
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] p-5 sm:p-8 lg:p-10 shadow-sm border border-slate-100">
                    <div class="mb-8 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-xl font-extrabold text-slate-900 uppercase tracking-widest">Weekly Schedule</h2>
                        <div class="flex items-center gap-4 text-[10px] font-black uppercase tracking-widest">
                            <span class="flex items-center gap-2 text-emerald-600">
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Available
                            </span>
                            <span class="flex items-center gap-2 text-slate-400">
                                <span class="h-2.5 w-2.5 rounded-full bg-slate-200"></span> Not available
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                        <div
                            v-for="day in weeklyAvailability"
                            :key="day.value"
                            :class="[
                                'rounded-3xl border p-4 text-center transition-all',
                                day.periods.length > 0
                                    ? 'border-emerald-100 bg-emerald-50/60'
                                    : 'border-slate-100 bg-slate-50/60',
                            ]"
                        >
                            <p
                                :class="[
                                    'text-[10px] font-black uppercase tracking-widest',
                                    day.periods.length > 0 ? 'text-slate-900' : 'text-slate-400',
                                ]"
                            >
                                {{ day.label }}
                            </p>

                            <p v-if="day.ranges?.length" class="mt-1 text-[9px] font-bold leading-tight text-slate-500">
                                {{ day.ranges.map((range) => range.label).join(' · ') }}
                            </p>

                            <div class="mt-3 space-y-1.5">
                                <span
                                    v-for="period in ['morning', 'afternoon']"
                                    :key="period"
                                    :class="[
                                        'block rounded-xl px-2 py-1 text-[10px] font-black uppercase tracking-wider',
                                        day.periods.includes(period)
                                            ? 'bg-emerald-500 text-white'
                                            : 'bg-white text-slate-300',
                                    ]"
                                >
                                    {{ period === 'morning' ? 'AM' : 'PM' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <p v-if="!weeklyAvailability || weeklyAvailability.every(d => d.periods.length === 0)" class="mt-6 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-xs font-bold text-amber-700">
                        This instructor has not published a weekly schedule yet. Pick a date on the booking form to check specific times.
                    </p>
                    <p v-else class="mt-6 text-xs font-medium text-slate-500">
                        Exact times are confirmed on the booking form, where you can also see any dates already booked.
                    </p>
                </div>

                <!-- Surf zones & teaching location -->
                <div v-if="(props.surfSpots ?? []).length > 0" class="rounded-3xl sm:rounded-[2.5rem] border border-slate-100 bg-white p-5 sm:p-8 lg:p-10 shadow-sm">
                    <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-ocean-500">Where you can surf</p>
                            <h2 class="mt-1 text-xl font-extrabold uppercase tracking-widest text-slate-900">Surf zones</h2>
                            <p class="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-600">
                                <MapPinIcon class="h-4 w-4 text-ocean-500" />
                                {{ instructor.instructor_profile.area || 'Siargao Island' }}
                                <span v-if="instructor.instructor_profile.meeting_point">· {{ instructor.instructor_profile.meeting_point }}</span>
                            </p>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-emerald-700 w-fit">
                            {{ props.surfSpots!.length }} selected surf zone{{ props.surfSpots!.length === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <SurfZoneTabs :spots="props.surfSpots!" :initial-limit="4" class="mb-5" />

                    <SurfZoneMap
                        mode="view"
                        :spots="props.surfSpots!"
                        height="320px"
                    />
                </div>

                <!-- Teaching location fallback when no surf zones configured -->
                <div v-else-if="mapInstructors.length" class="rounded-3xl sm:rounded-[2.5rem] border border-slate-100 bg-white p-5 sm:p-8 lg:p-10 shadow-sm">
                    <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-ocean-500">Find your coach</p>
                            <h2 class="mt-1 text-xl font-extrabold uppercase tracking-widest text-slate-900">Teaching location</h2>
                            <p class="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-600">
                                <MapPinIcon class="h-4 w-4 text-ocean-500" />
                                {{ instructor.instructor_profile.area || 'Siargao Island' }}
                                <span v-if="instructor.instructor_profile.meeting_point">· {{ instructor.instructor_profile.meeting_point }}</span>
                            </p>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-emerald-700 w-fit">
                            Live directory pin
                        </span>
                    </div>

                    <InstructorDirectoryMap
                        :instructors="mapInstructors"
                        :selected-id="instructor.id"
                        :center="{ latitude: Number(instructor.instructor_profile.latitude), longitude: Number(instructor.instructor_profile.longitude), zoom: 13 }"
                        height="300px"
                    />
                </div>

                <!-- Certificates -->
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] p-5 sm:p-8 lg:p-10 shadow-sm border border-slate-100">
                    <h2 class="mb-6 sm:mb-8 text-lg sm:text-xl font-extrabold text-slate-900 uppercase tracking-widest">Verified Credentials</h2>
                    <div class="grid gap-4 sm:gap-6 sm:grid-cols-2">
                        <div v-for="cert in instructor.certificates" :key="cert.id" class="flex items-center gap-5 rounded-3xl border border-slate-50 bg-slate-50/50 p-5 sm:p-6 transition-all hover:bg-white hover:shadow-xl hover:shadow-slate-100 group">
                            <div class="flex h-14 w-14 sm:h-16 sm:w-16 shrink-0 items-center justify-center rounded-2xl bg-white text-ocean-600 shadow-sm group-hover:scale-110 transition-transform">
                                <AcademicCapIcon class="h-7 w-7 sm:h-8 sm:w-8" />
                            </div>
                            <div>
                                <div class="font-black text-slate-900 uppercase text-xs tracking-widest mb-1">{{ cert.type }}</div>
                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none">Admin Verified</div>
                            </div>
                        </div>
                        <div v-if="instructor.certificates.length === 0" class="col-span-full py-12 text-center text-slate-400 border-4 border-dashed border-slate-50 rounded-[2.5rem]">
                            <p class="font-bold uppercase tracking-widest text-xs">Credential profile in review</p>
                        </div>
                    </div>
                </div>

                <!-- Reviews -->
                <div class="bg-white rounded-3xl sm:rounded-[2.5rem] p-5 sm:p-8 lg:p-10 shadow-sm border border-slate-100">
                    <div class="mb-8 sm:mb-10 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 uppercase tracking-widest">Student Feedback</h2>
                        <div class="flex items-center gap-3 rounded-2xl bg-slate-900 px-5 sm:px-6 py-2.5 text-xs font-black text-white uppercase tracking-widest">
                            <ChatBubbleLeftRightIcon class="h-4 w-4" />
                            {{ totalReviews }} Feedback
                        </div>
                    </div>

                    <div class="space-y-6 sm:space-y-8">
                        <div v-for="review in instructor.reviews_as_instructor" :key="review.id" class="rounded-[2rem] bg-slate-50 p-6 sm:p-8 transition-all hover:bg-white border border-transparent hover:border-slate-100 hover:shadow-xl hover:shadow-slate-100">
                            <div class="mb-6 flex items-center justify-between font-black uppercase tracking-widest text-[10px]">
                                <div class="flex items-center gap-4">
                                    <div class="h-12 w-12 rounded-2xl bg-sand-200 border-4 border-white shadow-sm flex items-center justify-center text-sand-700 text-sm">
                                        {{ review.student.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <div class="text-slate-900">{{ review.student.name }}</div>
                                        <div class="text-emerald-600 mt-1">Verified Experience</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-ocean-600">
                                    <StarIcon class="w-4 h-4" />
                                    <span>Recommended</span>
                                </div>
                            </div>
                            <p class="text-slate-600 font-medium leading-relaxed italic text-lg">"{{ review.comment }}"</p>
                        </div>
                        <div v-if="instructor.reviews_as_instructor.length === 0" class="py-20 text-center bg-slate-50/50 rounded-[2rem] border border-dashed border-slate-100">
                            <p class="text-slate-400 font-black uppercase tracking-widest text-xs">Waves are waiting for reviews...</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Booking Widget -->
            <div class="min-w-0 lg:col-span-1">
                <div class="sticky top-28 space-y-6 sm:space-y-8">
                    <div class="overflow-hidden rounded-3xl sm:rounded-[3rem] bg-deep-900 p-6 sm:p-8 lg:p-10 text-white shadow-lift relative group">
                        <!-- Decorative element -->
                        <div class="absolute -top-12 -right-12 h-40 w-40 bg-lagoon-500/20 rounded-full blur-3xl transition-all group-hover:scale-150 duration-700" />
                        
                        <div class="relative">
                            <div class="mb-8 flex items-center justify-between">
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.3em] text-lagoon-200/80 mb-2">
                                        {{ serviceRates && serviceRates.length > 1 ? 'Starting Hourly Rate' : 'Hourly Investment' }}
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-[10px] font-black text-lagoon-200/80">PHP</span>
                                        <span class="text-5xl font-black tracking-tight italic">₱{{ Number(startingRate ?? instructor.instructor_profile.rate_per_hour ?? 600).toLocaleString() }}</span>
                                    </div>
                                </div>
                                <div class="h-16 w-16 rounded-3xl bg-white/10 flex items-center justify-center backdrop-blur-xl border border-white/10 group-hover:rotate-12 transition-transform duration-500">
                                    <BanknotesIcon class="h-8 w-8 text-lagoon-300" />
                                </div>
                            </div>

                            <div v-if="serviceRates && serviceRates.length > 0" class="mb-8 space-y-2.5">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-lagoon-200/80">Service Rates (per hour)</p>
                                <div
                                    v-for="service in serviceRates"
                                    :key="service.type"
                                    class="flex items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
                                >
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-wider text-white">{{ service.label }}</p>
                                        <p class="text-[10px] text-cyan-100/75">{{ service.description }}</p>
                                    </div>
                                    <span class="ml-3 shrink-0 text-sm font-black text-lagoon-300">
                                        ₱{{ Number(service.rate).toLocaleString() }}/hr
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-6 mb-10">
                                <div class="flex items-start gap-4 p-4 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                                    <ShieldCheckOutline class="h-6 w-6 shrink-0 text-emerald-400" />
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-widest mb-1 text-emerald-300">Safety Guard</p>
                                        <p class="text-[10px] font-bold text-cyan-100/75 leading-tight">Board-certified safety and CPR standards.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-4 p-4 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                                    <CalendarIcon class="h-6 w-6 shrink-0 text-lagoon-300" />
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-widest mb-1 text-lagoon-300">Smart Booking</p>
                                        <p class="text-[10px] font-bold text-cyan-100/75 leading-tight">Real-time availability and instant confirmation.</p>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isStudent && studentBookingBlockReason"
                                class="rounded-2xl border border-amber-400/30 bg-amber-500/15 px-4 py-4 text-center text-xs font-semibold text-amber-200"
                            >
                                {{ studentBookingBlockReason }}
                            </div>

                            <Link
                                v-else-if="isStudent"
                                :href="route('bookings.create', instructor.id)"
                                class="flex h-16 w-full items-center justify-center rounded-2xl bg-coral-700 text-sm font-black text-white shadow-lg transition-all hover:bg-coral-800 active:scale-95 group/btn"
                            >
                                START BOOKING SESSION
                                <ArrowLeftIcon class="w-4 h-4 ml-3 rotate-180 group-hover/btn:translate-x-1 transition-transform" />
                            </Link>

                            <div v-else-if="!isAuthenticated" class="space-y-3">
                                <Link
                                    :href="route('bookings.create', instructor.id)"
                                    class="flex h-16 w-full items-center justify-center rounded-2xl bg-coral-700 text-sm font-black text-white shadow-lg transition-all hover:bg-coral-800 active:scale-95"
                                >
                                    SIGN IN TO BOOK
                                    <ArrowLeftIcon class="w-4 h-4 ml-3 rotate-180" />
                                </Link>
                                <Link
                                    :href="route('register')"
                                    class="flex h-12 w-full items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-xs font-black uppercase tracking-widest text-white transition hover:bg-white/15"
                                >
                                    Create a free account
                                </Link>
                                <p class="text-center text-[10px] font-bold uppercase tracking-widest text-cyan-100/70">
                                    Browsing is free · sign in only when you book
                                </p>
                            </div>

                            <p v-else class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-center text-xs font-semibold text-cyan-100">
                                Booking is available to student accounts.
                            </p>

                            <div class="mt-8 flex items-center justify-center gap-3">
                                <div class="h-1 w-1 rounded-full bg-lagoon-400/50" />
                                <p class="text-[10px] font-black uppercase tracking-widest text-cyan-100/70">
                                    SISA-Verified Safety Standards
                                </p>
                                <div class="h-1 w-1 rounded-full bg-lagoon-400/50" />
                            </div>
                        </div>
                    </div>

                    <!-- Pro Insights -->
                    <div class="rounded-3xl sm:rounded-[2.5rem] border border-slate-100 bg-white p-6 sm:p-8 shadow-sm">
                        <div class="flex items-center gap-3 mb-4">
                            <AcademicCapIcon class="w-5 h-5 text-sand-500" />
                            <h4 class="text-xs font-bold text-slate-900 uppercase tracking-widest">Instructor Insights</h4>
                        </div>
                        <p class="text-xs font-bold leading-relaxed text-slate-400 italic">
                            "This instructor is best known for their patience with beginners and deep knowledge of the Cloud 9 break pattern. Highly recommended for early morning sessions."
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sticky Mobile Booking CTA -->
        <div
            v-if="canBook"
            :class="[
                'lg:hidden fixed inset-x-0 z-20 border-t border-sand-200 bg-white/95 px-4 py-3 shadow-lift backdrop-blur-md',
                isAuthenticated ? 'bottom-[57px]' : 'bottom-0 pb-[calc(0.75rem+env(safe-area-inset-bottom))]',
            ]"
        >
            <div class="mx-auto flex max-w-xl items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-base font-extrabold leading-tight text-deep-950">
                        ₱{{ Number(startingRate ?? instructor.instructor_profile.rate_per_hour ?? 600).toLocaleString() }}
                        <span class="text-xs font-medium text-stone-500">/ hr</span>
                    </p>
                    <p class="truncate text-[11px] font-semibold text-lagoon-700">
                        SISA-verified · Level {{ instructor.instructor_profile.level }}
                    </p>
                </div>

                <span
                    v-if="isStudent && studentBookingBlockReason"
                    class="shrink-0 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800"
                >
                    Unavailable for your level
                </span>
                <Link
                    v-else
                    :href="route('bookings.create', instructor.id)"
                    class="inline-flex min-h-[48px] flex-1 max-w-[220px] items-center justify-center rounded-xl bg-coral-700 px-4 text-xs font-black uppercase tracking-wider text-white shadow-card transition hover:bg-coral-800 active:scale-[0.98]"
                >
                    {{ isStudent ? 'Book Lesson →' : 'Sign in to Book →' }}
                </Link>
            </div>
        </div>
    </AppSidebarLayout>
</template>
