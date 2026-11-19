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
        case 'beginner': return 'bg-ocean-50 text-ocean-700 ring-ocean-600/20';
        case 'intermediate': return 'bg-wave-50 text-wave-700 ring-wave-600/20';
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
                    <div class="flex flex-wrap items-center gap-2">
                        <span :class="['px-3 py-1.5 rounded-xl text-sm font-bold ring-1 ring-inset capitalize shadow-sm', getLevelBadgeClass(studentProfile.skill_level)]">
                            {{ studentProfile.skill_level }}
                        </span>
                        <span
                            v-if="studentProfile.skill_assessed_at"
                            class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20"
                        >
                            Pre-assessed
                        </span>
                        <span
                            v-else
                            class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20"
                        >
                            Not yet assessed
                        </span>
                    </div>
                </div>
            </div>

            <div
                v-if="!studentProfile.skill_assessed_at"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
            >
                <p class="font-semibold">Complete your skill pre-assessment</p>
                <p class="mt-1 text-amber-800">
                    Your account currently defaults to Beginner. Answer the short pre-assessment so SiaSurf can match you with the right instructors and surf zones.
                </p>
                <a
                    :href="route('student.onboarding')"
                    class="mt-2.5 inline-flex items-center gap-1.5 font-semibold text-lagoon-700 hover:text-lagoon-800"
                >
                    Take skill pre-assessment
                    <span aria-hidden="true">→</span>
                </a>
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
