<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { TrophyIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);
const studentProfile = computed(() => user.value?.student_profile);
const pendingRequests = computed(() => user.value?.skill_upgrade_requests || []);

const hasPendingRequest = computed(() => pendingRequests.value.length > 0);
const pendingRequestLevel = computed(() => {
    return hasPendingRequest.value ? pendingRequests.value[0].requested_level : null;
});

const getLevelBadgeClass = (level: string) => {
    switch(level) {
        case 'beginner': return 'bg-blue-50 text-blue-700 ring-blue-600/20';
        case 'intermediate': return 'bg-indigo-50 text-indigo-700 ring-indigo-600/20';
        case 'advanced': return 'bg-purple-50 text-purple-700 ring-purple-600/20';
        default: return 'bg-slate-50 text-slate-700 ring-slate-600/20';
    }
};
</script>

<template>
    <section v-if="user?.role === 'student' && studentProfile">
        <header>
            <h2 class="text-lg font-medium text-slate-900 border-b border-slate-100 pb-3 mb-6 flex items-center">
                <TrophyIcon class="w-5 h-5 mr-2 text-ocean-600" />
                Surfing Skill Profile
            </h2>
            <p class="mt-1 text-sm text-slate-600">
                Your current surfing proficiency. Only verified instructors can request to upgrade your skill level.
            </p>
        </header>

        <div class="mt-6 flex flex-col space-y-6">
            <div class="flex items-center space-x-4">
                <div class="flex-1">
                    <p class="text-sm font-medium text-slate-500 uppercase tracking-wider mb-1">Current Level</p>
                    <div class="flex items-center">
                        <span :class="['px-3 py-1.5 rounded-xl text-sm font-bold ring-1 ring-inset capitalize shadow-sm', getLevelBadgeClass(studentProfile.skill_level)]">
                            {{ studentProfile.skill_level }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Pending Request Alert -->
            <div v-if="hasPendingRequest" class="bg-amber-50 rounded-2xl p-4 border border-amber-100 flex items-start space-x-3">
                <ArrowPathIcon class="w-5 h-5 text-amber-600 shrink-0 mt-0.5 animate-spin-slow" />
                <div>
                    <h3 class="text-sm font-semibold text-amber-800">Level Upgrade Pending</h3>
                    <p class="text-sm text-amber-700 mt-1">
                        An instructor has recommended you for graduation to 
                        <span class="font-bold capitalize">{{ pendingRequestLevel }}</span>. 
                        This request is currently under review by the administrators.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.animate-spin-slow {
    animation: spin 3s linear infinite;
}
</style>
