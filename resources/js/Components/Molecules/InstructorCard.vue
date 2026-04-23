<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { StarIcon, CheckBadgeIcon } from '@heroicons/vue/24/solid';

interface Instructor {
    id: number;
    name: string;
    instructor_profile: {
        bio: string;
        level: number;
        rate_per_hour: number;
        status: string;
        avatar?: string;
    };
    total_reviews?: number;
}

defineProps<{
    instructor: Instructor;
}>();
</script>

<template>
    <div class="group relative flex flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white transition-all hover:shadow-xl hover:shadow-slate-200/50">
        <!-- Image Area -->
        <div class="relative h-48 overflow-hidden bg-slate-100">
            <img 
                v-if="instructor.instructor_profile.avatar" 
                :src="instructor.instructor_profile.avatar" 
                class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
                alt="Instructor"
            />
            <div v-else class="flex h-full w-full items-center justify-center bg-teal-50 text-teal-600">
                <svg class="h-16 w-16 opacity-20" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                </svg>
            </div>
            
            <!-- Badge -->
            <div class="absolute right-4 top-4">
                <div class="flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-xs font-bold text-teal-600 shadow-sm backdrop-blur-md">
                    <CheckBadgeIcon class="h-4 w-4" />
                    Verified
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="flex flex-1 flex-col p-6">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <h3 class="text-xl font-bold text-slate-900 line-clamp-1 capitalize">{{ instructor.name }}</h3>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="inline-flex items-center rounded-md bg-teal-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700">
                            Level {{ instructor.instructor_profile.level }}
                        </span>
                        <span v-if="instructor.instructor_profile.level === 1" class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[9px] font-bold text-slate-600">
                            Beginners Only
                        </span>
                        <span v-else class="inline-flex items-center rounded-md bg-ocean-50 px-2 py-0.5 text-[9px] font-bold text-ocean-700">
                            All Levels
                        </span>
                    </div>
                    <div class="mt-2 flex items-center gap-1.5 text-[10px] text-slate-500">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span>Max {{ instructor.instructor_profile.level === 1 ? '2' : instructor.instructor_profile.level === 2 ? '1 (1-on-1)' : '5 (Group)' }} students</span>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-black text-slate-900">₱{{ instructor.instructor_profile.rate_per_hour }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400">per hour</div>
                </div>
            </div>

            <p class="mb-6 line-clamp-2 text-sm text-slate-500">
                {{ instructor.instructor_profile.bio || 'Professional surf instructor dedicated to providing safe and fun learning experiences in Siargao.' }}
            </p>

            <div class="mt-auto flex items-center justify-between border-t border-slate-50 pt-4">
                <div class="flex items-center gap-1">
                    <span class="text-xs font-bold text-slate-900">{{ instructor.total_reviews || 0 }}</span>
                    <span class="text-[10px] text-slate-400 uppercase tracking-widest font-bold">Reviews</span>
                </div>
                
                <Link 
                    :href="route('instructors.profile', instructor.id)" 
                    class="rounded-full bg-slate-900 px-4 py-2 text-xs font-bold text-white transition hover:bg-teal-600"
                >
                    View Profile
                </Link>
            </div>
        </div>
    </div>
</template>
