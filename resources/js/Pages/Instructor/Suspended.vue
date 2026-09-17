<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import { ShieldExclamationIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
</script>

<template>
  <Head title="Account Suspended" />

  <AppLayout :hide-sidebar="true">
    <div class="py-12 bg-white min-h-screen">
      <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 text-center pt-12 animate-in fade-in slide-in-from-bottom-4 duration-700">
        
        <!-- Illustration Placeholder -->
        <div class="mb-12 flex justify-center">
            <div class="relative w-48 h-48 bg-rose-50 rounded-full flex items-center justify-center animate-pulse border-8 border-rose-100">
                <ShieldExclamationIcon class="w-24 h-24 text-rose-500" />
            </div>
        </div>

        <h1 class="text-4xl font-extrabold text-slate-900 mb-6 tracking-tight uppercase">Account Suspended</h1>
        <p class="text-xl text-slate-600 mb-12 font-medium">
          Your instructor privileges have been temporarily suspended due to safety protocol violations or excessive strikes. You cannot accept new bookings or manage your availability at this time.
        </p>

        <div class="p-8 bg-slate-50 rounded-[2rem] text-left border border-slate-100 mb-12 shadow-inner">
             <h4 class="font-bold text-slate-900 mb-4 uppercase tracking-widest text-sm flex items-center gap-2">
                 <ChatBubbleLeftRightIcon class="w-5 h-5 text-slate-400" />
                 What to do next
             </h4>
             <ul class="space-y-4 text-sm text-slate-600 font-medium">
               <li class="flex items-start">
                 <span class="w-6 h-6 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center text-[10px] font-black mr-3 mt-0.5 shrink-0">1</span>
                 Review any recent email communications from the SISA admin regarding your suspension.
               </li>
               <li class="flex items-start">
                 <span class="w-6 h-6 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center text-[10px] font-black mr-3 mt-0.5 shrink-0">2</span>
                 If you believe this was an error, you may contact the admin office to appeal your case.
               </li>
               <li class="flex items-start">
                 <span class="w-6 h-6 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center text-[10px] font-black mr-3 mt-0.5 shrink-0">3</span>
                 Any upcoming bookings you had may have been automatically cancelled or reassigned.
               </li>
             </ul>
        </div>

        <div class="space-x-4">
           <Link :href="route('logout')" method="post" as="button">
              <BaseButton variant="secondary" class="rounded-xl font-bold px-8 shadow-sm">Sign Out</BaseButton>
           </Link>
        </div>

      </div>
    </div>
  </AppLayout>
</template>
