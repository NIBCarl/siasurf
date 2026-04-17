<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion: string;
    phpVersion: string;
}>();

const isScrolled = ref(false);

onMounted(() => {
    window.addEventListener('scroll', () => {
        isScrolled.value = window.scrollY > 50;
    });
});
</script>

<template>
    <Head title="SiaSurf | Smart Booking & Instructor Verification" />

    <div class="min-h-screen bg-slate-50 font-sans text-slate-900 selection:bg-teal-500 selection:text-white">
        <!-- Navigation -->
        <nav 
            :class="[
                'fixed top-0 z-50 w-full transition-all duration-300 px-6 py-4',
                isScrolled ? 'bg-white/80 backdrop-blur-md shadow-sm py-3' : 'bg-transparent'
            ]"
        >
            <div class="mx-auto flex max-w-7xl items-center justify-between">
                <div class="flex items-center gap-2">
                    <img src="/assets/images/siargao_cloud9_sunset.png" class="h-10 w-10 object-cover rounded-lg" alt="SiaSurf Logo" />
                    <span :class="['text-xl font-bold tracking-tight transition-colors duration-300', isScrolled ? 'text-slate-900' : 'text-white']">
                        Sia<span class="text-teal-500">Surf</span>
                    </span>
                </div>

                <div class="hidden items-center gap-8 md:flex">
                    <Link :href="route('instructors.search')" :class="['text-sm font-medium transition-colors duration-300 hover:text-teal-500', isScrolled ? 'text-slate-600' : 'text-white/90']">Find Instructors</Link>
                    <a href="#features" :class="['text-sm font-medium transition-colors duration-300 hover:text-teal-500', isScrolled ? 'text-slate-600' : 'text-white/90']">Safety Features</a>
                    <a href="#about" :class="['text-sm font-medium transition-colors duration-300 hover:text-teal-500', isScrolled ? 'text-slate-600' : 'text-white/90']">About Siargao</a>
                </div>

                <div v-if="canLogin" class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-full bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                        Dashboard
                    </Link>

                    <template v-else>
                        <Link
                            :href="route('login')"
                            :class="['px-4 py-2 text-sm font-semibold transition-colors duration-300 hover:text-teal-500', isScrolled ? 'text-slate-700' : 'text-white']"
                        >
                            Log in
                        </Link>

                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-full bg-teal-600 px-5 py-2 text-sm font-semibold text-white shadow-md shadow-teal-100 transition hover:bg-teal-700"
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
                    class="h-full w-full object-cover brightness-[0.85]" 
                    alt="Siargao Surf"
                />
                <div class="absolute inset-0 bg-gradient-to-b from-slate-900/40 via-transparent to-slate-50"></div>
            </div>

            <div class="container relative z-10 mx-auto px-6 text-center">
                <div class="animate-fade-in-up">
                    <span class="mb-4 inline-block rounded-full bg-white/20 px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-white backdrop-blur-md">
                        Siargao's Official Surf Booking
                    </span>
                    <h1 class="mx-auto mb-6 max-w-4xl text-5xl font-extrabold tracking-tight text-white md:text-7xl">
                        Surf with Confidence. <br/> 
                        <span class="text-teal-300 italic">Verified</span> by SiaSurf.
                    </h1>
                    <p class="mx-auto mb-10 max-w-2xl text-lg text-slate-100 md:text-xl">
                        The first centralized platform for Siargao's surfing community. 
                        Find certified instructors, monitor real-time safety, and enjoy professional surfing tourism.
                    </p>
                    
                    <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            :href="route('instructors.search')"
                            class="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-full bg-teal-600 px-8 py-4 text-lg font-bold text-white transition-all hover:bg-teal-700 sm:w-auto"
                        >
                            <span>Book a Lesson</span>
                            <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                            </svg>
                        </Link>
                        <Link
                            :href="route('register', { role: 'instructor' })"
                            class="flex w-full items-center justify-center rounded-full bg-white/10 px-8 py-4 text-lg font-bold text-white backdrop-blur-md transition hover:bg-white/20 sm:w-auto"
                        >
                            Instructor Portal
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Wave Decoration -->
            <div class="absolute bottom-0 left-0 w-full overflow-hidden leading-[0]">
                <svg class="relative block h-[100px] w-[calc(100%+1.3px)]" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
                    <path d="M321.39,56.44c12.2,2.37,24.3,4.42,36,6.36,12.72,2,25.43,3.92,38.15,5.77,13.62,2,27.24,3.87,40.85,5.64,15.11,2,30.22,3.8,45.33,5.43,18.45,2,36.8,3.74,55.15,5.1,23.59,1.75,47.11,3,70.52,3.64l.1,0h.1a1200,1200,0,0,1,56.55-1.93l.1,0h.1c23.09-.64,44.75-2.06,64.71-3.64,18.84-1.5,35.31-3.35,49.19-5.1a1200,1200,0,0,1,135-11.41l.1,0h.1a1200,1200,0,0,1,164.88,0c10.43.34,20.87.89,31.3,1.6a1200,1200,0,0,1,10.43,8.4c0,2.1.05,4.21.15,6.31V120H0V84.09l.15-6.31a1200,1200,0,0,1,10.43-8.4c10.43-.71,20.87-1.26,31.3-1.6a1200,1200,0,0,1,164.88,0l.1,0h.1a1200,1200,0,0,1,135,11.41c13.88,1.75,30.35,3.6,49.19,5.1,20,1.58,41.62,3,64.71,3.64h.1l.1,0L321.39,56.44Z" class="fill-slate-50"></path>
                </svg>
            </div>
        </section>

        <!-- Stats Section -->
        <section class="bg-slate-50 py-20">
            <div class="container mx-auto px-6">
                <div class="grid gap-12 text-center md:grid-cols-4">
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-2 text-4xl font-black text-teal-600">500+</div>
                        <div class="text-sm font-bold uppercase tracking-widest text-slate-500">Verified Instructors</div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-2 text-4xl font-black text-teal-600">12k+</div>
                        <div class="text-sm font-bold uppercase tracking-widest text-slate-500">Safe Sessions</div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-2 text-4xl font-black text-teal-600">100%</div>
                        <div class="text-sm font-bold uppercase tracking-widest text-slate-500">Safety Regulated</div>
                    </div>
                    <div class="group transform transition hover:-translate-y-2">
                        <div class="mb-2 text-4xl font-black text-teal-600">PayMongo</div>
                        <div class="text-sm font-bold uppercase tracking-widest text-slate-500">Secure GCash Payments</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section id="features" class="bg-white py-24">
            <div class="container mx-auto px-6">
                <div class="mb-20 text-center">
                    <h2 class="mb-4 text-3xl font-bold text-slate-900 md:text-5xl">Engineered for Safety. <br/> Designed for the Ocean.</h2>
                    <p class="mx-auto max-w-2xl text-slate-600">We bridge the gap between local surfing expertise and modern tourism standards.</p>
                </div>

                <div class="grid gap-8 lg:grid-cols-3">
                    <div class="rounded-3xl border border-slate-100 bg-slate-50 p-10 transition-all hover:bg-white hover:shadow-xl hover:shadow-slate-200/50">
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
        <section id="spots" class="bg-slate-50 py-24">
            <div class="container mx-auto px-6">
                <div class="mb-16 text-center">
                    <h2 class="mb-4 text-3xl font-bold text-slate-900 md:text-5xl">Explore the Island's Best</h2>
                    <p class="mx-auto max-w-2xl text-slate-600">Siargao is home to world-class breaks. Find the perfect spot for your skill level.</p>
                </div>

                <div class="grid gap-8 md:grid-cols-3">
                    <div class="group relative overflow-hidden rounded-[2rem] bg-white shadow-lg transition-all hover:-translate-y-2 hover:shadow-xl">
                        <img src="/images/spot_cloud9.png" class="h-64 w-full object-cover transition-transform duration-500 group-hover:scale-110" alt="Cloud 9" />
                        <div class="p-8">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900">Cloud 9</h3>
                                <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-600 uppercase tracking-wider">Advanced</span>
                            </div>
                            <p class="text-sm text-slate-600">The crown jewel of Siargao. Famous for its thick, hollow tubes and world-class barrels.</p>
                        </div>
                    </div>

                    <div class="group relative overflow-hidden rounded-[2rem] bg-white shadow-lg transition-all hover:-translate-y-2 hover:shadow-xl">
                        <img src="/images/spot_quicksilver.png" class="h-64 w-full object-cover transition-transform duration-500 group-hover:scale-110" alt="Quicksilver" />
                        <div class="p-8">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900">Quicksilver</h3>
                                <span class="rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-600 uppercase tracking-wider">Intermediate</span>
                            </div>
                            <p class="text-sm text-slate-600">Right next to Cloud 9. Offers faster, sharper waves perfect for refining your skills.</p>
                        </div>
                    </div>

                    <div class="group relative overflow-hidden rounded-[2rem] bg-white shadow-lg transition-all hover:-translate-y-2 hover:shadow-xl">
                        <img src="/images/spot_jacking_horse.png" class="h-64 w-full object-cover transition-transform duration-500 group-hover:scale-110" alt="Jacking Horse" />
                        <div class="p-8">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xl font-bold text-slate-900">Jacking Horse</h3>
                                <span class="rounded-full bg-teal-100 px-3 py-1 text-xs font-bold text-teal-600 uppercase tracking-wider">Beginner</span>
                            </div>
                            <p class="text-sm text-slate-600">A friendly, rolling break ideal for first-timers and those learning the basics.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Featured Instructors Section -->
        <section class="bg-white py-24">
            <div class="container mx-auto px-6">
                <div class="mb-16 flex flex-col items-center justify-between gap-6 md:flex-row">
                    <div>
                        <h2 class="mb-2 text-3xl font-bold text-slate-900 md:text-5xl">Meet Our Pros</h2>
                        <p class="text-slate-600">Every instructor on SiaSurf is SISA-certified and safety-verified.</p>
                    </div>
                    <Link :href="route('instructors.search')" class="rounded-full border-2 border-slate-900 px-8 py-3 font-bold text-slate-900 transition hover:bg-slate-900 hover:text-white">
                        View All 500+ Pros
                    </Link>
                </div>

                <div class="grid gap-12 lg:grid-cols-2">
                    <div class="flex flex-col items-center gap-8 rounded-[2rem] bg-slate-50 p-8 md:flex-row md:p-12">
                        <img src="/images/instructor_male.png" class="h-48 w-48 rounded-3xl object-cover shadow-xl" alt="Instructor" />
                        <div>
                            <div class="mb-4 flex items-center gap-3">
                                <span class="rounded-full bg-teal-600 px-3 py-1 text-xs font-bold text-white uppercase">Level 3 Expert</span>
                                <div class="flex text-amber-400">
                                    <svg v-for="i in 5" :key="i" class="h-4 w-4 fill-current" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                </div>
                            </div>
                            <h3 class="mb-2 text-2xl font-bold text-slate-900">Junrey 'Bogs' Capistrano</h3>
                            <p class="mb-6 text-slate-600">Local legend with 15 years at Cloud 9. Specializes in advanced tube riding and safety rescue.</p>
                            <button class="font-bold text-teal-600 hover:text-teal-700">View Fully Verified Profile →</button>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-8 rounded-[2rem] bg-slate-50 p-8 md:flex-row md:p-12">
                        <img src="/images/instructor_female.png" class="h-48 w-48 rounded-3xl object-cover shadow-xl" alt="Instructor" />
                        <div>
                            <div class="mb-4 flex items-center gap-3">
                                <span class="rounded-full bg-teal-600 px-3 py-1 text-xs font-bold text-white uppercase">Level 2 Pro</span>
                                <div class="flex text-amber-400">
                                    <svg v-for="i in 5" :key="i" class="h-4 w-4 fill-current" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                </div>
                            </div>
                            <h3 class="mb-2 text-2xl font-bold text-slate-900">Ana 'Maui' San Jose</h3>
                            <p class="mb-6 text-slate-600">ISA certified instructor focusing on intermediate women's surf camps and longboarding style.</p>
                            <button class="font-bold text-teal-600 hover:text-teal-700">View Fully Verified Profile →</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20">
            <div class="container mx-auto px-6">
                <div class="relative overflow-hidden rounded-[2rem] bg-teal-600 px-10 py-16 text-center text-white md:px-20 md:py-24">
                    <div class="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-teal-500 opacity-20"></div>
                    <div class="absolute -bottom-20 -left-20 h-64 w-64 rounded-full bg-teal-500 opacity-20"></div>
                    
                    <h2 class="relative z-10 mb-6 text-3xl font-bold md:text-5xl">Ready to catch your first wave?</h2>
                    <p class="relative z-10 mx-auto mb-10 max-w-xl text-teal-50 opacity-90">Join Siargao's most trusted surfing community today. Adventure is waiting, safety is guaranteed.</p>
                    <div class="relative z-10 flex flex-col justify-center gap-4 sm:flex-row">
                        <Link :href="route('instructors.search')" class="rounded-full bg-white px-10 py-4 font-bold text-teal-600 transition hover:bg-teal-50">View All Instructors</Link>
                        <Link :href="route('register')" class="rounded-full bg-teal-700 px-10 py-4 font-bold text-white transition hover:bg-teal-800">Create Private Account</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-slate-900 py-20 text-slate-400">
            <div class="container mx-auto px-6">
                <div class="grid gap-12 border-b border-slate-800 pb-20 lg:grid-cols-4">
                    <div class="col-span-2">
                        <div class="mb-6 flex items-center gap-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600 text-white">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <span class="text-2xl font-bold text-white">SiaSurf</span>
                        </div>
                        <p class="max-w-sm">
                            Smart Booking and Instructor Verification Platform with Real-time Safety Integration and Monitoring. Siargao Island, Philippines.
                        </p>
                    </div>
                    <div>
                        <h4 class="mb-6 font-bold text-white">Platform</h4>
                        <ul class="space-y-4">
                            <li><Link :href="route('instructors.search')" class="hover:text-white">Find Instructors</Link></li>
                            <li><a href="#" class="hover:text-white">Surf Spots</a></li>
                            <li><a href="#" class="hover:text-white">Safety Rules</a></li>
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
