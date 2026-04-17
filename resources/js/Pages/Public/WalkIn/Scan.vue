<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Instructor {
  id: number
  name: string
  avatar_path: string
  instructor_profile: {
    bio: string
    level: number
    rate_per_hour: number
    status: string
  }
}

interface Props {
  instructor: Instructor
}

const props = defineProps<Props>()
</script>

<template>
  <Head :title="'Surf with ' + instructor.name" />

  <div class="min-h-screen bg-slate-900 text-white flex flex-col items-center justify-center p-6 relative overflow-hidden">
    
    <!-- Background Decoration -->
    <div class="absolute inset-0 z-0">
       <div class="absolute top-[-10%] right-[-10%] w-[50%] h-[50%] bg-blue-600/20 rounded-full blur-[120px]"></div>
       <div class="absolute bottom-[-10%] left-[-10%] w-[50%] h-[50%] bg-teal-600/20 rounded-full blur-[120px]"></div>
    </div>

    <div class="relative z-10 w-full max-w-sm text-center">
       
       <!-- QR Success Badge -->
       <div class="mb-8 animate-bounce">
          <div class="inline-flex items-center px-4 py-2 bg-green-500/20 border border-green-500/30 rounded-full text-green-400 text-xs font-black uppercase tracking-widest shadow-lg shadow-green-500/10">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
            </svg>
            Instructor Verified
          </div>
       </div>

       <!-- Instructor Card -->
       <div class="bg-white/10 backdrop-blur-xl border border-white/10 rounded-[2.5rem] p-8 mb-8 shadow-2xl">
          <div class="relative inline-block mb-6">
             <img 
               :src="instructor.avatar_path || 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=200&h=200'" 
               class="w-32 h-32 rounded-[2rem] object-cover border-4 border-white/10 shadow-xl"
             >
             <div class="absolute -bottom-2 -right-2 bg-blue-600 text-white p-2 rounded-xl shadow-lg ring-4 ring-slate-900">
               <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
               </svg>
             </div>
          </div>

          <h1 class="text-3xl font-black mb-1">{{ instructor.name }}</h1>
          <div class="flex justify-center items-center space-x-2 mb-6">
             <Badge variant="info">Level {{ instructor.instructor_profile.level }}</Badge>
             <span class="text-white/40 text-xs">•</span>
             <span class="text-blue-400 font-bold">₱{{ instructor.instructor_profile.rate_per_hour }}/hr</span>
          </div>

          <p class="text-white/60 text-sm leading-relaxed mb-8 italic">
            "{{ instructor.instructor_profile.bio.substring(0, 100) }}..."
          </p>

          <Link :href="route('bookings.create', instructor.id)" class="block">
             <BaseButton variant="primary" size="lg" class="w-full justify-center shadow-xl shadow-blue-600/20">
               Book This Session &rarr;
             </BaseButton>
          </Link>
       </div>

       <!-- Secondary Actions -->
       <div class="space-y-4">
          <Link :href="route('instructors.profile', instructor.id)" class="block text-white/40 text-xs font-bold uppercase tracking-widest hover:text-white transition-colors">
            View Full Profile & Reviews
          </Link>
          <div class="pt-8 flex items-center justify-center space-x-2">
             <span class="w-8 h-[1px] bg-white/10"></span>
             <span class="text-[10px] font-black text-white/20 uppercase tracking-widest">SiaSurf Secure Scan</span>
             <span class="w-8 h-[1px] bg-white/10"></span>
          </div>
       </div>

    </div>

  </div>
</template>
