<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ArrowLeftIcon,
    CalendarDaysIcon,
    MapPinIcon,
    DocumentTextIcon,
    ShieldExclamationIcon,
    ExclamationTriangleIcon,
    ClockIcon,
    CurrencyDollarIcon,
    PhoneIcon,
    EnvelopeIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  instructor: { name: string }
  surf_spot: { name: string }
  waiver: {
      id: number
      pdf_path: string
      signed_at: string
      type: string
  } | null
}

interface Incident {
    id: number
    type: string
    severity: string
    description: string
    created_at: string
}

interface Student {
  id: number
  name: string
  email: string
  phone: string | null
  student_profile: {
      skill_level: string
      is_first_time: boolean
      status: string
  } | null
  bookings_as_student: Booking[]
  reported_incidents: Incident[]
}

interface Props {
  student: Student
}

const props = defineProps<Props>()

const getStatusVariant = (status?: string) => {
  if (!status) return 'ocean'
  switch (status.toLowerCase()) {
    case 'active': return 'ocean'
    case 'banned': return 'danger'
    default: return 'info'
  }
}

const getBookingStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'ocean'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}

const getSeverityVariant = (severity: string) => {
    switch (severity.toLowerCase()) {
        case 'minor': return 'info'
        case 'major': return 'warning'
        case 'critical': return 'danger'
        default: return 'default'
    }
}

const toggleBanStatus = () => {
    const isBanned = props.student.student_profile?.status === 'banned'
    const action = isBanned ? 'unban' : 'ban'
    
    if (confirm(`Are you sure you want to ${action} this student? ${!isBanned ? 'They will not be able to book any more lessons.' : ''}`)) {
        router.post(route('admin.students.toggle-status', props.student.id))
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="`Admin: ${student.name} Profile`" />

        <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-start justify-between gap-6">
                <div>
                    <nav class="flex mb-4" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3 text-sm font-medium">
                            <li>
                                <Link :href="route('admin.students.index')" class="text-slate-400 hover:text-ocean-600 transition-colors flex items-center gap-1">
                                    <ArrowLeftIcon class="w-3 h-3" />
                                    Directory
                                </Link>
                            </li>
                            <div class="flex items-center">
                                <svg class="w-3 h-3 text-slate-300 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/>
                                </svg>
                                <span class="ml-1 font-bold text-ocean-600 md:ml-2">Profile</span>
                            </div>
                        </ol>
                    </nav>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">{{ student.name }}</h1>
                    <div class="flex flex-wrap items-center gap-4 mt-3">
                        <Badge :variant="getStatusVariant(student.student_profile?.status)" class="text-xs">
                            {{ student.student_profile?.status.toUpperCase() ?? 'ACTIVE' }}
                        </Badge>
                        <Badge v-if="student.student_profile" variant="info" class="text-xs">
                            {{ student.student_profile.skill_level.toUpperCase() }}
                        </Badge>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button 
                        @click="toggleBanStatus"
                        :class="[
                            'flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-black transition-all shadow-lg',
                            student.student_profile?.status === 'banned' 
                                ? 'bg-slate-800 hover:bg-slate-900 text-white shadow-slate-800/20' 
                                : 'bg-rose-500 hover:bg-rose-600 text-white shadow-rose-500/20'
                        ]"
                    >
                        <ShieldExclamationIcon class="w-5 h-5" />
                        {{ student.student_profile?.status === 'banned' ? 'Lift Ban' : 'Ban Student' }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column: Personal Info & Stats -->
                <div class="space-y-8">
                    <!-- Contact Card -->
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden group">
                        <div class="absolute top-0 right-0 p-8 opacity-5 group-hover:opacity-10 transition-opacity duration-500 pointer-events-none">
                            <DocumentTextIcon class="w-32 h-32" />
                        </div>
                        
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-6">Contact Information</h3>
                        
                        <div class="space-y-6">
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1 flex items-center gap-1.5">
                                    <EnvelopeIcon class="w-3 h-3" /> Email Address
                                </p>
                                <p class="font-bold text-slate-900">{{ student.email }}</p>
                            </div>
                            
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1 flex items-center gap-1.5">
                                    <PhoneIcon class="w-3 h-3" /> Phone Number
                                </p>
                                <p class="font-bold text-slate-900">{{ student.phone || 'Not provided' }}</p>
                            </div>
                            
                            <div v-if="student.student_profile">
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">First Time Surfer?</p>
                                <p class="font-bold text-slate-900">{{ student.student_profile.is_first_time ? 'Yes' : 'No' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Booking History & Waivers -->
                <div class="lg:col-span-2 space-y-8">
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden">
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-6">Session & Waiver History</h3>
                        
                        <div class="space-y-4">
                            <div v-if="student.bookings_as_student.length === 0" class="p-12 text-center border-2 border-dashed border-slate-100 rounded-3xl">
                                <CalendarDaysIcon class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                                <p class="font-bold text-slate-400">No bookings found for this student.</p>
                            </div>

                            <div v-for="booking in student.bookings_as_student" :key="booking.id" 
                                class="flex flex-col md:flex-row items-center justify-between p-5 bg-slate-50/50 rounded-2xl border border-slate-100 hover:border-ocean-200 hover:bg-ocean-50/30 transition-all gap-4">
                                
                                <div class="flex flex-col w-full md:w-auto">
                                    <div class="flex items-center gap-3 mb-1">
                                        <Badge :variant="getBookingStatusVariant(booking.status)" size="sm">
                                            {{ booking.status.toUpperCase() }}
                                        </Badge>
                                        <span class="text-sm font-black text-slate-900">{{ booking.date }}</span>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500 mt-1 flex items-center gap-1.5">
                                        <MapPinIcon class="w-3.5 h-3.5" /> {{ booking.surf_spot.name }}
                                        <span class="mx-1">&bull;</span>
                                        Instructor: {{ booking.instructor.name }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-3 w-full md:w-auto justify-end">
                                    <!-- View Booking Link -->
                                    <Link :href="route('admin.bookings.show', booking.id)"
                                        class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-all">
                                        View details
                                    </Link>

                                    <!-- Waiver PDF Link -->
                                    <a v-if="booking.waiver" :href="route('waivers.download', booking.waiver.id)"
                                        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-ocean-50 border border-ocean-100 text-ocean-700 hover:bg-ocean-600 hover:text-white transition-all shadow-sm">
                                        <DocumentTextIcon class="w-3.5 h-3.5" />
                                        Waiver PDF
                                    </a>
                                    <span v-else class="px-4 py-2 text-[10px] font-black uppercase tracking-widest text-slate-400">
                                        No Waiver
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Incidents Log -->
                    <div v-if="student.reported_incidents.length > 0" class="bg-rose-50/30 rounded-[2.5rem] p-8 border border-rose-100 shadow-sm">
                        <h3 class="text-[10px] font-bold text-rose-500 uppercase tracking-[0.2em] mb-6 flex items-center gap-2">
                            <ExclamationTriangleIcon class="w-4 h-4" />
                            Reported Incidents
                        </h3>
                        
                        <div class="space-y-4">
                            <div v-for="incident in student.reported_incidents" :key="incident.id" class="p-5 bg-white rounded-2xl border border-rose-100 shadow-sm">
                                <div class="flex items-center justify-between mb-2">
                                    <Badge :variant="getSeverityVariant(incident.severity)" size="sm">
                                        {{ incident.severity.toUpperCase() }}
                                    </Badge>
                                    <span class="text-[10px] font-black text-slate-400 tracking-widest uppercase">{{ new Date(incident.created_at).toLocaleDateString() }}</span>
                                </div>
                                <h4 class="font-bold text-slate-900">{{ incident.type.replace('_', ' ').toUpperCase() }}</h4>
                                <p class="text-sm text-slate-600 mt-2">{{ incident.description }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
