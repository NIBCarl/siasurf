<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen bg-sand-50">
        <!-- Left Side: Cinematic Background (Desktop only) -->
        <div class="relative hidden w-0 flex-1 lg:block">
            <img
                class="absolute inset-0 h-full w-full object-cover"
                src="/assets/images/siargao_cloud9_sunset.png"
                alt="Siargao Cloud 9 Sunset"
            />
            <div class="absolute inset-0 bg-gradient-to-r from-ocean-900/40 to-transparent"></div>
            
            <!-- Floating Brand Card -->
            <div class="absolute bottom-12 left-12 max-w-md animate-fade-in-up">
                <h2 class="text-4xl font-bold text-white drop-shadow-lg">
                    Discover Siargao's Waves
                </h2>
                <p class="mt-4 text-lg text-white/90 drop-shadow-md">
                    Seamless bookings, certified instructors, and real-time safety for the ultimate surfing experience.
                </p>
            </div>
        </div>

        <!-- Right Side: Content Area -->
        <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <!-- Logo -->
                <div class="flex justify-center lg:justify-start">
                    <Link href="/">
                        <ApplicationLogo class="h-16 w-auto fill-current text-ocean-600 transition-transform duration-300 hover:scale-110" />
                    </Link>
                </div>

                <div class="mt-8">
                    <div class="mt-6">
                        <slot />
                    </div>
                </div>
                
                <!-- Footer Info -->
                <div class="mt-12 text-center text-sm text-sand-500 lg:text-left">
                    <p>&copy; 2026 SiaSurf. Professional Surfing Integration.</p>
                </div>
            </div>
        </div>
    </div>
</template>
