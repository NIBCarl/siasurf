<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue';
import { 
    AcademicCapIcon, 
    CheckCircleIcon, 
    XCircleIcon,
    ArrowPathIcon,
    UserIcon
} from '@heroicons/vue/24/outline';
import badgeStyles from '@/Components/Atoms/Badge.vue'; // Or wherever a badge component might be.
import { formatPhilippineDate } from '@/utils/philippineTime';
// Since we don't know the exact badge atom location, we'll use inline tailwind classes for badges.

interface Student {
    id: number;
    name: string;
    email: string;
}

interface Instructor {
    id: number;
    name: string;
}

interface UpgradeRequest {
    id: number;
    student_id: number;
    instructor_id: number;
    booking_id: number;
    current_level: string;
    requested_level: string;
    status: 'pending' | 'approved' | 'rejected';
    created_at: string;
    student: Student;
    instructor: Instructor;
}

interface PaginationData {
    data: UpgradeRequest[];
    links: any[];
    current_page: number;
    last_page: number;
    total: number;
}

const props = defineProps<{
    upgradeRequests: PaginationData;
}>();

const form = useForm({});

const approveRequest = (id: number) => {
    form.post(route('admin.skill-upgrades.approve', id), {
        preserveScroll: true,
    });
};

const rejectRequest = (id: number) => {
    form.post(route('admin.skill-upgrades.reject', id), {
        preserveScroll: true,
    });
};

const getStatusBadgeClass = (status: string) => {
    switch(status) {
        case 'pending': return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'approved': return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'rejected': return 'bg-rose-100 text-rose-800 border-rose-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

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
    <Head title="Skill Upgrades | SiaSurf Admin" />

    <AppSidebarLayout>
        <div class="py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Page Header -->
                <div class="mb-8">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight flex items-center">
                        <AcademicCapIcon class="w-7 h-7 sm:w-8 sm:h-8 mr-3 shrink-0 text-lagoon-700" />
                        Skill Upgrade Requests
                    </h1>
                    <p class="mt-2 text-sm text-slate-500">
                        Review and approve instructor recommendations for student skill level promotions.
                    </p>
                </div>

                <!-- Requests Table & Mobile Cards -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div v-if="upgradeRequests.data.length === 0" class="px-6 py-12 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                            <AcademicCapIcon class="w-12 h-12 text-slate-300 mb-3" />
                            <p class="text-sm">No skill upgrade requests found.</p>
                        </div>
                    </div>

                    <template v-else>
                        <!-- Mobile cards -->
                        <div class="divide-y divide-slate-200 md:hidden">
                            <div v-for="req in upgradeRequests.data" :key="`mob-${req.id}`" class="p-4 space-y-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="h-9 w-9 rounded-full bg-lagoon-100 flex items-center justify-center text-lagoon-700 font-bold shrink-0">
                                            {{ req.student.name.charAt(0) }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-slate-900 truncate">{{ req.student.name }}</div>
                                            <div class="text-xs text-slate-500 truncate">{{ req.student.email }}</div>
                                        </div>
                                    </div>
                                    <span :class="['shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize', getStatusBadgeClass(req.status)]">
                                        {{ req.status }}
                                    </span>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span :class="['inline-flex items-center px-2 py-1 rounded-md font-medium ring-1 ring-inset capitalize', getLevelBadgeClass(req.current_level)]">
                                        {{ req.current_level }}
                                    </span>
                                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                    <span :class="['inline-flex items-center px-2 py-1 rounded-md font-medium ring-1 ring-inset capitalize', getLevelBadgeClass(req.requested_level)]">
                                        {{ req.requested_level }}
                                    </span>
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                    <span class="flex items-center text-slate-700">
                                        <UserIcon class="w-3.5 h-3.5 mr-1 text-slate-400 shrink-0" />
                                        By {{ req.instructor.name }}
                                    </span>
                                    <span>{{ formatPhilippineDate(req.created_at) }}</span>
                                </div>

                                <div v-if="req.status === 'pending'" class="flex gap-2 pt-1">
                                    <button 
                                        @click="rejectRequest(req.id)"
                                        :disabled="form.processing"
                                        class="flex-1 min-h-[44px] inline-flex items-center justify-center px-3 py-2 border border-slate-300 text-xs font-semibold rounded-lg text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-50"
                                    >
                                        Reject
                                    </button>
                                    <button 
                                        @click="approveRequest(req.id)"
                                        :disabled="form.processing"
                                        class="flex-1 min-h-[44px] inline-flex items-center justify-center px-3 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-sm text-white bg-coral-700 hover:bg-coral-800 disabled:opacity-50"
                                    >
                                        Approve
                                    </button>
                                </div>
                                <p v-else class="text-xs text-slate-400">Processed</p>
                            </div>
                        </div>

                        <!-- Desktop table -->
                        <div class="hidden overflow-x-auto md:block">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Student
                                        </th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Progression
                                        </th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Requested By
                                        </th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" class="px-6 py-4 text-right text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-slate-200">
                                    <tr v-for="req in upgradeRequests.data" :key="req.id" class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                            {{ formatPhilippineDate(req.created_at) }}
                                        </td>
                                        
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="h-8 w-8 rounded-full bg-lagoon-100 flex items-center justify-center text-lagoon-700 font-bold shrink-0">
                                                    {{ req.student.name.charAt(0) }}
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-slate-900">{{ req.student.name }}</div>
                                                    <div class="text-xs text-slate-500">{{ req.student.email }}</div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center space-x-2">
                                                <span :class="['inline-flex items-center px-2 py-1 rounded-md text-xs font-medium ring-1 ring-inset capitalize', getLevelBadgeClass(req.current_level)]">
                                                    {{ req.current_level }}
                                                </span>
                                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                                </svg>
                                                <span :class="['inline-flex items-center px-2 py-1 rounded-md text-xs font-medium ring-1 ring-inset capitalize', getLevelBadgeClass(req.requested_level)]">
                                                    {{ req.requested_level }}
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center text-sm text-slate-900">
                                                <UserIcon class="w-4 h-4 mr-1 text-slate-400" />
                                                {{ req.instructor.name }}
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border capitalize', getStatusBadgeClass(req.status)]">
                                                {{ req.status }}
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium border-t-0 space-x-2">
                                            <template v-if="req.status === 'pending'">
                                                <button 
                                                    @click="rejectRequest(req.id)"
                                                    :disabled="form.processing"
                                                    class="inline-flex items-center px-3 py-1.5 border border-slate-300 text-xs font-medium rounded text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-lagoon-600 disabled:opacity-50"
                                                >
                                                    Reject
                                                </button>
                                                <button 
                                                    @click="approveRequest(req.id)"
                                                    :disabled="form.processing"
                                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-coral-700 hover:bg-coral-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-coral-600 disabled:opacity-50"
                                                >
                                                    Approve
                                                </button>
                                            </template>
                                            <template v-else>
                                                <span class="text-slate-400 text-sm">Processed</span>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <!-- Pagination -->
                    <!-- Usually handled by a component, fallback to simplistic render or standard Inertia Links if available -->
                </div>
            </div>
        </div>
    </AppSidebarLayout>
</template>
