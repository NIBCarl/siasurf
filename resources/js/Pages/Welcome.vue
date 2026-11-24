<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { getEcho } from '@/Composables/useEcho';

interface LandingStats {
    total_spots: number;
    beginner_safe_spots: number;
    verified_instructors: number;
    base_rate: number;
    completed_sessions: number;
}

interface LandingSpot {
    id: number | string;
    name: string;
    area: string | null;
    difficulty: string;
    difficulty_label: string;
    description: string;
    best_tide: string | null;
    image_url: string;
    url: string;
}

interface LandingInstructor {
    id: number | string;
    name: string;
    level: number;
    level_badge: string;
    bio: string;
    area: string | null;
    average_rating: number | null;
    total_reviews: number;
    show_stars: boolean;
    starting_rate: number | null;
    avatar_url: string;
    profile_url: string;
    cta_label: string;
}

interface LandingReview {
    id: number | string;
    rating: number;
    comment: string;
    student_name: string;
    student_avatar_url: string | null;
    instructor_name: string;
    surf_spot: string | null;
    created_at: string;
    photo_url: string | null;
}

interface LandingReviewStats {
    average_rating: number | null;
    total_reviews: number;
}

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion: string;
    phpVersion: string;
    stats: LandingStats;
    featuredSpots: LandingSpot[];
    featuredInstructors: LandingInstructor[];
    recentReviews: LandingReview[];
    reviewStats: LandingReviewStats;
}>();

const page = usePage();
const user = computed(() => (page.props.auth as any)?.user ?? null);
const userRole = computed(() => user.value?.role ?? null);

const isScrolled = ref(false);

const LANDING_PROPS = ['stats', 'featuredSpots', 'featuredInstructors', 'recentReviews', 'reviewStats'];
let reloadTimer: ReturnType<typeof setTimeout> | null = null;
let pollTimer: ReturnType<typeof setInterval> | null = null;
let isReloading = false;
let lastReloadAt = Date.now();

const reloadLandingData = () => {
    if (isReloading) return;
    isReloading = true;

    router.reload({
        only: LANDING_PROPS,
        onFinish: () => {
            isReloading = false;
            lastReloadAt = Date.now();
        },
    });
};

const scheduleLandingReload = () => {
    if (reloadTimer) {
        clearTimeout(reloadTimer);
    }

    reloadTimer = setTimeout(() => {
        reloadTimer = null;
        reloadLandingData();
    }, 500);
};

const onScroll = () => {
    isScrolled.value = window.scrollY > 50;
};

const onVisibilityChange = () => {
    if (!document.hidden && Date.now() - lastReloadAt > 15_000) {
        scheduleLandingReload();
    }
};

const spotDifficultyBadgeClass = (difficulty: string): string => {
    switch (difficulty) {
        case 'advanced':
            return 'bg-rose-100 text-rose-600';
        case 'intermediate':
            return 'bg-orange-100 text-orange-600';
        default:
            return 'bg-teal-100 text-teal-600';
    }
};

const instructorLevelBadgeClass = (level: number): string => {
    switch (level) {
        case 3:
            return 'bg-deep-900';
        case 2:
            return 'bg-lagoon-700';
        default:
            return 'bg-teal-600';
    }
};

const getInitials = (name: string): string => {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    return initials || 'S';
};

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('visibilitychange', onVisibilityChange);

    const echo = getEcho();
    if (echo) {
        echo.channel('landing.public').listen('.landing.updated', () => {
            scheduleLandingReload();
        });
    }

    pollTimer = setInterval(() => {
        if (!document.hidden) {
            scheduleLandingReload();
        }
    }, 60_000);
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('visibilitychange', onVisibilityChange);

    if (reloadTimer) {
        clearTimeout(reloadTimer);
        reloadTimer = null;
    }

    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    getEcho()?.leave('landing.public');
});
</script>

<template>
    <Head title="SiaSurf | Smart Booking & Instructor Verification" />

    <div class="min-h-screen bg-sand-50 font-sans text-deep-950
        selection:bg-lagoon-600 selection:text-white">
        <!-- Navigation -->
        <nav 
            :class="[
                'fixed top-0 z-50 w-full transition-all duration-300 px-6 py-4',
                isScrolled ? 'bg-white/95 backdrop-blur-md shadow-sm py-3' : 'bg-transparent'
            ]"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between">
                <Link href="/" class="flex items-center">
                    <ApplicationLogo v-if="isScrolled" variant="horizontal" class="h-10 w-auto" />
                    <ApplicationLogo v-else variant="horizontal-dark" class="h-10 w-auto drop-shadow-sm" />
                </Link>

                <div class="hidden items-center gap-8 md:flex">
                    <Link v-if="userRole !== 'instructor'" :href="route('instructors.search')" :class="['text-sm font-medium transition-colors duration-300 hover:text-lagoon-400', isScrolled ? 'text-stone-700' : 'text-white/90']">Find Instructors</Link>
                    <a href="#features" :class="['text-sm font-medium transition-colors duration-300 hover:text-lagoon-400', isScrolled ? 'text-stone-700' : 'text-white/90']">Safety Features</a>
                    <a href="#about" :class="['text-sm font-medium transition-colors duration-300 hover:text-lagoon-400', isScrolled ? 'text-stone-700' : 'text-white/90']">About Siargao</a>
                </div>

                <div v-if="canLogin" class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-full bg-deep-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-deep-800"
                    >
                        Dashboard
                    </Link>

                    <template v-else>
                        <Link
                            :href="route('login')"
                            :class="['px-4 py-2 text-sm font-semibold transition-colors duration-300 hover:text-lagoon-400', isScrolled ? 'text-deep-900' : 'text-white']"
                        >
                            Log in
                        </Link>

                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-full bg-coral-700 px-5 py-2 text-sm font-semibold text-white shadow-md transition hover:bg-coral-800"
                        >
                            Join SiaSurf
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- Hero Section -->
        <section class="relative flex min-h-screen items-center justify-center overflow-hidden pt-20">
            <!-- Background Image -->
            <div class="absolute inset-0 z-0">
                <img 
                    src="/images/hero_siargao.png" 
                    class="h-full w-full object-cover brightness-[0.8]" 
                    alt="Siargao Surf"
                />
                <div class="absolute inset-0 bg-gradient-to-b from-deep-950/80 via-deep-900/60 to-deep-950/75"></div>
            </div>

            <div class="container relative z-10 mx-auto px-6 text-center">
                <div class="animate-fade-in-up">
                    <span class="mb-4 inline-block rounded-full border border-white/25 bg-deep-950/50 px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-lagoon-200 backdrop-blur-md">
                        Siargao's Official Surf Booking
                    </span>
                    <h1 class="mx-auto mb-6 max-w-4xl text-[clamp(2.25rem,6vw,4.5rem)] font-extrabold leading-[1.05] tracking-tight text-white drop-shadow-sm">
                        Surf with Confidence. <br/> 
                        <span class="text-lagoon-300 italic">Verified</span> by SiaSurf.
                    </h1>
                    <p class="mx-auto mb-10 max-w-2xl text-lg text-white/95 md:text-xl">
                        The first centralized platform for Siargao's surfing community. 
                        Find certified instructors, monitor real-time safety, and enjoy professional surfing tourism.
                    </p>
                    
                    <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            v-if="userRole === 'instructor'"
                            :href="route('dashboard')"
                            class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-full bg-coral-700 px-8 py-4 text-lg font-bold text-white shadow-lg transition-all hover:bg-coral-800 sm:w-auto"
                        >
                            <span>Go to Instructor Portal</span>
                            <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                            </svg>
                        </Link>
                        <Link
                            v-else-if="userRole === 'admin'"
                            :href="route('admin.dashboard')"
                            class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-full bg-coral-700 px-8 py-4 text-lg font-bold text-white shadow-lg transition-all hover:bg-coral-800 sm:w-auto"
                        >
                            <span>Admin Dashboard</span>
                            <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                            </svg>
                        </Link>
                        <template v-else>
                            <Link
                                :href="route('instructors.search')"
                                class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-full bg-coral-700 px-8 py-4 text-lg font-bold text-white shadow-lg transition-all hover:bg-coral-800 sm:w-auto"
                            >
                                <span>Book a Lesson</span>
                                <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                </svg>
                            </Link>
                            <Link
                                v-if="!user"
                                :href="route('register', { role: 'instructor' })"
                                class="flex w-full items-center justify-center rounded-full border border-white/30 bg-white/15 px-8 py-4 text-lg font-bold text-white backdrop-blur-md transition hover:bg-white/25 sm:w-auto"
                            >
                                Instructor Portal
                            </Link>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Wave Decoration -->
            <div class="absolute bottom-0 left-0 w-full overflow-hidden leading-[0]">
                <svg class="relative block h-[100px] w-[calc(100%+1.3px)]" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
                    <path d="M321.39,56.44c12.2,2.37,24.3,4.42,36,6.36,12.72,2,25.43,3.92,38.15,5.77,13.62,2,27.24,3.87,40.85,5.64,15.11,2,30.22,3.8,45.33,5.43,18.45,2,36.8,3.74,55.15,5.1,23.59,1.75,47.11,3,70.52,3.64l.1,0h.1a1200,1200,0,0,1,56.55-1.93l.1,0h.1c23.09-.64,44.75-2.06,64.71-3.64,18.84-1.5,35.31-3.35,49.19-5.1a1200,1200,0,0,1,135-11.41l.1,0h.1a1200,1200,0,0,1,164.88,0c10.43.34,20.87.89,31.3,1.6a1200,1200,0,0,1,10.43,8.4c0,2.1.05,4.21.15,6.31V120H0V84.09l.15-6.31a1200,1200,0,0,1,10.43-8.4c10.43-.71,20.87-1.26,31.3-1.6a1200,1200,0,0,1,164.88,0l.1,0h.1a1200,1200,0,0,1,135,11.41c13.88,1.75,30.35,3.6,49.19,5.1,20,1.58,41.62,3,64.71,3.64h.1l.1,0L321.39,56.44Z" class="fill-sand-50"></path>
                </svg>
            </div>
        </section>

        <!-- Stats Section -->
        <section class="bg-sand-50 py-12 md:py-20">
            <div class="container mx-auto px-6">
                <div class="grid grid-cols-2 gap-6 text-center md:grid-cols-4 md:gap-12">
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-1.5 text-2xl sm:text-4xl font-black text-deep-950">{{ stats.total_spots }} Breaks</div>
                        <div class="text-xs sm:text-sm font-bold uppercase tracking-widest text-stone-600">{{ stats.beginner_safe_spots }} Beginner-Safe Zones</div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-1.5 text-2xl sm:text-4xl font-black text-lagoon-700">
                            {{ stats.verified_instructors > 0 ? `${stats.verified_instructors} Verified` : '100% Live' }}
                        </div>
                        <div class="text-xs sm:text-sm font-bold uppercase tracking-widest text-stone-600">
                            {{ stats.verified_instructors > 0 ? 'SISA-Certified Instructors' : 'SISA QR Verification' }}
                        </div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-1.5 text-2xl sm:text-4xl font-black text-deep-950">₱{{ stats.base_rate.toLocaleString() }} / hr</div>
                        <div class="text-xs sm:text-sm font-bold uppercase tracking-widest text-stone-600">Standardized SISA Base Rate</div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-1.5 text-2xl sm:text-4xl font-black text-coral-700">GCash &amp; Cash</div>
                        <div class="text-xs sm:text-sm font-bold uppercase tracking-widest text-stone-600">Official Digital Receipts</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="bg-white py-14 md:py-24">
            <div class="container mx-auto px-6">
                <div class="mb-10 md:mb-20 text-center">
                    <h2 class="mb-4 text-3xl font-bold text-slate-900 md:text-5xl">Engineered for Safety. <br/> Designed for the Ocean.</h2>
                    <p class="mx-auto max-w-2xl text-slate-600">We bridge the gap between local surfing expertise and modern tourism standards.</p>
                </div>

                <div class="grid gap-6 md:gap-8 lg:grid-cols-3">
                    <div class="rounded-3xl border border-slate-100 bg-slate-50 p-7 sm:p-10 transition-all hover:bg-white hover:shadow-xl hover:shadow-slate-200/50">
                        <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-teal-600 shadow-sm">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <h3 class="mb-4 text-xl font-bold">Smart Verification</h3>
                        <p class="text-slate-600">Scan QR codes on instructor IDs to instantly verify certifications, level, and strike-record history. No more guessing.</p>
                    </div>

                    <div class="rounded-3xl border border-slate-100 bg-slate-50 p-10 transition-all hover:bg-white hover:shadow-xl hover:shadow-slate-200/50">
                        <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-teal-600 shadow-sm">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="mb-4 text-xl font-bold">Skill-Level Matching</h3>
                        <p class="text-slate-600">Our safety engine automatically matches you with instructors qualified for your level. Level 1 instructors for beginners only.</p>
                    </div>

                    <div class="rounded-3xl border border-slate-100 bg-slate-50 p-10 transition-all hover:bg-white hover:shadow-xl hover:shadow-slate-200/50">
                        <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-teal-600 shadow-sm">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3H3z" />
                            </svg>
                        </div>
                        <h3 class="mb-4 text-xl font-bold">Unified Payments</h3>
                        <p class="text-slate-600">Book and pay securely via GCash. Locked-in pricing ensures a fair experience for students and fair wages for instructors.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Surf Spots Gallery -->
        <section id="spots" class="bg-sand-50 py-14 md:py-24">
            <div class="container mx-auto px-6">
                <div class="mb-8 md:mb-16 flex flex-col items-center justify-between gap-6 md:flex-row">
                    <div class="text-center md:text-left">
                        <h2 class="mb-4 text-3xl font-bold text-slate-900 md:text-5xl">Explore the Island's Best</h2>
                        <p class="max-w-2xl text-slate-600">Every break on the island, mapped with its difficulty and local conditions. Find the spot that matches your skill level.</p>
                    </div>
                    <Link :href="route('surf-spots.index')" class="shrink-0 rounded-full border-2 border-deep-900 px-8 py-3 font-bold text-deep-900 transition hover:bg-deep-900 hover:text-white">
                        Explore All Breaks
                    </Link>
                </div>

                <div class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-4 -mx-6 px-6 md:mx-0 md:px-0 md:pb-0 md:grid md:grid-cols-3 md:gap-8 md:overflow-visible">
                    <Link
                        v-for="spot in featuredSpots"
                        :key="spot.id"
                        :href="spot.url"
                        class="w-[82vw] max-w-[320px] shrink-0 snap-center md:w-auto md:max-w-none group relative overflow-hidden rounded-[2rem] bg-white shadow-lg transition-all hover:-translate-y-2 hover:shadow-xl"
                    >
                        <img :src="spot.image_url" class="h-56 md:h-64 w-full object-cover transition-transform duration-500 group-hover:scale-110" :alt="spot.name" />
                        <div class="p-6 md:p-8">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <h3 class="text-xl font-bold text-slate-900">{{ spot.name }}</h3>
                                <span
                                    :class="[
                                        'shrink-0 rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider',
                                        spotDifficultyBadgeClass(spot.difficulty),
                                    ]"
                                >
                                    {{ spot.difficulty_label }}
                                </span>
                            </div>
                            <p v-if="spot.area" class="mb-2 text-xs font-semibold uppercase tracking-wider text-stone-500">
                                {{ spot.area }}<span v-if="spot.best_tide"> · {{ spot.best_tide }}</span>
                            </p>
                            <p class="line-clamp-3 text-sm text-slate-600">{{ spot.description }}</p>
                        </div>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Featured Instructors Section -->
        <section class="bg-white py-14 md:py-24">
            <div class="container mx-auto px-6">
                <div class="mb-8 md:mb-16 flex flex-col items-center justify-between gap-6 md:flex-row">
                    <div>
                        <h2 class="mb-2 text-3xl font-bold text-slate-900 md:text-5xl">Meet Our Pros</h2>
                        <p class="text-slate-600">Every instructor on SiaSurf is SISA-certified and safety-verified.</p>
                    </div>
                    <Link :href="route('instructors.search')" class="rounded-full border-2 border-deep-900 px-8 py-3 font-bold text-deep-900 transition hover:bg-deep-900 hover:text-white">
                        Browse Verified Instructors
                    </Link>
                </div>

                <div class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-4 -mx-6 px-6 lg:mx-0 lg:px-0 lg:pb-0 lg:grid lg:grid-cols-2 lg:gap-12 lg:overflow-visible">
                    <div
                        v-for="instructor in featuredInstructors"
                        :key="instructor.id"
                        class="w-[86vw] max-w-[340px] shrink-0 snap-center lg:w-auto lg:max-w-none flex flex-col items-center gap-6 md:gap-8 rounded-[2rem] bg-sand-50 p-6 md:flex-row md:p-12"
                    >
                        <img :src="instructor.avatar_url" class="h-44 w-44 md:h-48 md:w-48 shrink-0 rounded-3xl object-cover shadow-xl" :alt="instructor.name" />
                        <div class="min-w-0 flex-1">
                            <div class="mb-4 flex flex-wrap items-center gap-3">
                                <span
                                    :class="[
                                        'rounded-full px-3 py-1 text-xs font-bold text-white uppercase',
                                        instructorLevelBadgeClass(instructor.level),
                                    ]"
                                >
                                    {{ instructor.level_badge }}
                                </span>
                                <div v-if="instructor.show_stars" class="flex items-center gap-1.5">
                                    <div class="flex text-amber-400">
                                        <svg
                                            v-for="i in 5"
                                            :key="i"
                                            :class="['h-4 w-4 fill-current', i <= Math.round(instructor.average_rating ?? 5) ? 'text-amber-400' : 'text-stone-300']"
                                            viewBox="0 0 20 20"
                                        >
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    </div>
                                    <span v-if="instructor.total_reviews > 0" class="text-xs font-bold text-stone-600">
                                        {{ instructor.average_rating }} ({{ instructor.total_reviews }})
                                    </span>
                                </div>
                                <span v-else class="rounded-full bg-teal-100 px-3 py-1 text-xs font-bold text-teal-700">
                                    SISA Verified
                                </span>
                            </div>
                            <h3 class="mb-2 text-2xl font-bold text-deep-950">{{ instructor.name }}</h3>
                            <p v-if="instructor.area || instructor.starting_rate" class="mb-3 text-xs font-semibold uppercase tracking-wider text-stone-500">
                                <span v-if="instructor.area">{{ instructor.area }}</span>
                                <span v-if="instructor.area && instructor.starting_rate"> · </span>
                                <span v-if="instructor.starting_rate">From ₱{{ instructor.starting_rate.toLocaleString() }}/hr</span>
                            </p>
                            <p class="mb-6 line-clamp-3 text-stone-600">{{ instructor.bio }}</p>
                            <Link :href="instructor.profile_url" class="inline-flex items-center font-bold text-lagoon-700 hover:text-lagoon-800">
                                {{ instructor.cta_label }} →
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Verified Surfer Reviews Section -->
        <section id="reviews" class="bg-sand-50 py-14 md:py-24">
            <div class="container mx-auto px-6">
                <div class="mb-8 md:mb-16 flex flex-col items-center justify-between gap-6 md:flex-row">
                    <div class="text-center md:text-left">
                        <h2 class="mb-2 text-3xl font-bold text-slate-900 md:text-5xl">What Surfers Say</h2>
                        <p class="text-slate-600">Feedback from verified SiaSurf sessions across Siargao.</p>
                    </div>
                    <div
                        v-if="reviewStats.total_reviews > 0"
                        class="inline-flex items-center gap-2.5 rounded-full bg-white px-6 py-3 shadow-sm border border-stone-200"
                    >
                        <svg class="h-5 w-5 text-amber-400 fill-current" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                        <span class="text-sm font-bold text-deep-950">
                            {{ reviewStats.average_rating }} · {{ reviewStats.total_reviews }} Verified {{ reviewStats.total_reviews === 1 ? 'Review' : 'Reviews' }}
                        </span>
                    </div>
                </div>

                <div class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-4 -mx-6 px-6 md:mx-0 md:px-0 md:pb-0 md:grid md:grid-cols-3 md:gap-8 md:overflow-visible">
                    <div
                        v-for="review in recentReviews"
                        :key="review.id"
                        class="w-[84vw] max-w-[340px] shrink-0 snap-center md:w-auto md:max-w-none flex flex-col rounded-[2rem] bg-white p-7 shadow-lg"
                    >
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div class="flex text-amber-400">
                                <svg
                                    v-for="i in 5"
                                    :key="i"
                                    :class="['h-4 w-4 fill-current', i <= review.rating ? 'text-amber-400' : 'text-stone-300']"
                                    viewBox="0 0 20 20"
                                >
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            </div>
                            <span v-if="review.surf_spot" class="rounded-full bg-ocean-50 px-3 py-1 text-xs font-bold text-ocean-700">
                                {{ review.surf_spot }}
                            </span>
                        </div>

                        <p class="mb-6 line-clamp-4 flex-1 text-slate-600 leading-relaxed">
                            “{{ review.comment }}”
                        </p>

                        <div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <img
                                    v-if="review.student_avatar_url"
                                    :src="review.student_avatar_url"
                                    :alt="review.student_name"
                                    class="h-10 w-10 shrink-0 rounded-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-deep-900 text-xs font-bold text-white"
                                >
                                    {{ getInitials(review.student_name) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-bold text-deep-950">{{ review.student_name }}</div>
                                    <div class="truncate text-xs text-stone-500">with {{ review.instructor_name }}</div>
                                </div>
                            </div>
                            <span class="shrink-0 text-xs font-medium text-stone-400">{{ review.created_at }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-12 md:py-20 bg-white">
            <div class="container mx-auto px-6">
                <div class="relative overflow-hidden rounded-[2rem] bg-deep-900 px-10 py-16 text-center text-white md:px-20 md:py-24">
                    <div class="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-lagoon-500 opacity-20"></div>
                    <div class="absolute -bottom-20 -left-20 h-64 w-64 rounded-full bg-lagoon-500 opacity-20"></div>
                    
                    <h2 class="relative z-10 mb-6 text-3xl font-bold md:text-5xl">Ready to catch your first wave?</h2>
                    <p class="relative z-10 mx-auto mb-10 max-w-xl text-cyan-100/90">Join Siargao's most trusted surfing community today. Adventure is waiting, safety is guaranteed.</p>
                    <div class="relative z-10 flex flex-col justify-center gap-4 sm:flex-row">
                        <Link :href="route('instructors.search')" class="rounded-full bg-coral-700 px-10 py-4 font-bold text-white shadow-lg transition hover:bg-coral-800">View All Instructors</Link>
                        <Link :href="route('register')" class="rounded-full border border-white/25 bg-white/15 px-10 py-4 font-bold text-white transition hover:bg-white/25">Create Private Account</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-deep-950 py-20 text-slate-400">
            <div class="container mx-auto px-6">
                <div class="grid gap-12 border-b border-white/10 pb-20 lg:grid-cols-4">
                    <div class="col-span-2">
                        <div class="mb-6 flex items-center">
                            <ApplicationLogo variant="horizontal-dark" class="h-10 w-auto" />
                        </div>
                        <p class="max-w-sm">
                            Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring. Siargao Island, Philippines.
                        </p>
                    </div>
                    <div>
                        <h4 class="mb-6 font-bold text-white">Platform</h4>
                        <ul class="space-y-4">
                            <li><Link :href="route('instructors.search')" class="hover:text-white">Find Instructors</Link></li>
                            <li><Link :href="route('surf-spots.index')" class="hover:text-white">Surf Spots</Link></li>
                            <li><a href="#features" class="hover:text-white">Safety Rules</a></li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="mb-6 font-bold text-white">Legal</h4>
                        <ul class="space-y-4">
                            <li><a href="#" class="hover:text-white">Terms of Service</a></li>
                            <li><a href="#" class="hover:text-white">Privacy Policy</a></li>
                            <li><a href="#" class="hover:text-white">Digital Waivers</a></li>
                        </ul>
                    </div>
                </div>
                <div class="mt-10 flex flex-col items-center justify-between gap-6 md:flex-row">
                    <p class="text-sm">© 2026 SiaSurf Platform. All rights reserved.</p>
                    <div class="flex items-center gap-4 text-xs font-mono">
                        <span class="text-teal-600">Official Surf Integration</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@keyframes fade-in-up {
    0% {
        opacity: 0;
        transform: translateY(20px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in-up {
    animation: fade-in-up 1s ease-out forwards;
}
</style>
