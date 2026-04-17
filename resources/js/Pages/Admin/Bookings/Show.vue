<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { 
    ChevronLeftIcon,
    ArrowDownTrayIcon,
    PencilSquareIcon,
    MapPinIcon,
    UserIcon,
    IdentificationIcon,
    CreditCardIcon,
    ShieldCheckIcon,
    ClockIcon,
    InformationCircleIcon,
    CheckBadgeIcon,
    DocumentTextIcon
} from '@heroicons/vue/24/outline'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'

interface Booking {
  id: number
  date: string
  time_period: string
  status: string
  total_amount: number
  skill_level: string
  student_age: number
  student_count: number
  notes: string
  student: { name: string; email: string; phone: string }
  instructor: { name: string; email: string }
  surf_spot: { name: string }
  payment?: { amount: number; payment_method: string; status: string; paid_at: string }
  waiver?: { signed_at: string; pdf_path: string }
}

interface Props {
  booking: Booking
}

defineProps<Props>()

const getStatusVariant = (status: string) => {
  switch (status.toLowerCase()) {
    case 'confirmed': return 'ocean'
    case 'pending': return 'warning'
    case 'completed': return 'success'
    case 'cancelled': return 'danger'
    default: return 'info'
  }
}
</script>

<template>
    <AppLayout>
    <Head :title="'Booking #BK-' + booking.id" />

    <div class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 pb-12">
        <!-- Dashboard Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
                <Link 
                    :href="route('admin.bookings.index')" 
                    class="group inline-flex items-center text-sm font-bold text-slate-400 hover:text-ocean-600 transition-colors mb-2"
                >
                    <ChevronLeftIcon class="w-4 h-4 mr-1 group-hover:-translate-x-1 transition-transform" />
                    Back to Bookings
                </Link>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Session Dossier</h1>
                    <Badge :variant="getStatusVariant(booking.status)" size="sm">
                        {{ booking.status.toUpperCase() }}
                    </Badge>
                </div>
                <p class="text-slate-500 mt-1">Ref: #BK-{{ booking.id }} • Recorded on {{ booking.date }}</p>
            </div>
            
            <div class="flex gap-2">
                <button class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all shadow-sm">
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Receipt
                </button>
                <button class="flex items-center gap-2 px-4 py-2 bg-ocean-600 text-white rounded-xl text-sm font-bold hover:bg-ocean-700 transition-all shadow-lg shadow-ocean-600/20">
                    <PencilSquareIcon class="w-4 h-4" />
                    Edit Details
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-6 gap-8">
            
            <!-- Main Content Area -->
            <div class="lg:col-span-4 space-y-8">
                
                <!-- Core Session Section -->
                <div class="bg-white rounded-[2.5rem] p-10 border border-slate-100 shadow-sm relative overflow-hidden">
                    <div class="absolute top-0 right-0 p-10 opacity-5">
                        <MapPinIcon class="w-32 h-32 text-ocean-600" />
                    </div>
                    
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-8">
                            <ClockIcon class="w-5 h-5 text-ocean-600" />
                            <h3 class="text-xs font-black uppercase text-slate-400 tracking-widest">Session Logic & Allocation</h3>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Assigned Surf Spot</p>
                                <p class="text-xl font-black text-slate-900">{{ booking.surf_spot.name }}</p>
                                <p class="text-xs text-ocean-600 font-bold">Standard Difficulty</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Skill Classification</p>
                                <p class="text-xl font-black text-slate-900 capitalize">{{ booking.skill_level }}</p>
                                <p class="text-xs text-wave-600 font-bold">1:{{ booking.student_count }} Instructor Ratio</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-tighter">Schedule Window</p>
                                <p class="text-xl font-black text-slate-900 capitalize">{{ booking.time_period }}</p>
                                <p class="text-xs text-slate-400 font-bold">{{ booking.date }}</p>
                            </div>
                        </div>

                        <div v-if="booking.notes" class="mt-10 p-6 bg-slate-50 rounded-2xl border-l-4 border-ocean-500">
                            <div class="flex items-start gap-3">
                                <InformationCircleIcon class="w-5 h-5 text-ocean-600 shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1">Student Notes</p>
                                    <p class="text-sm text-slate-600 italic">"{{ booking.notes }}"</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Participants Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Student Info -->
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm group">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-ocean-50 rounded-xl flex items-center justify-center">
                                    <UserIcon class="w-5 h-5 text-ocean-600" />
                                </div>
                                <h3 class="text-xs font-black uppercase text-slate-400 tracking-widest">Student Profile</h3>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <p class="text-lg font-black text-slate-900">{{ booking.student.name }}</p>
                                <p class="text-xs text-slate-400">Primary Reservation Contact</p>
                            </div>
                            <div class="pt-4 border-t border-slate-50 space-y-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">Email</span>
                                    <span class="font-bold text-slate-700">{{ booking.student.email }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">WhatsApp/Phone</span>
                                    <span class="font-bold text-slate-700">{{ booking.student.phone }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-slate-400">Age Bracket</span>
                                    <span class="font-bold text-slate-700">{{ booking.student_age }} Years Old</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Instructor Info -->
                    <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-reef-50 rounded-xl flex items-center justify-center">
                                    <IdentificationIcon class="w-5 h-5 text-reef-600" />
                                </div>
                                <h3 class="text-xs font-black uppercase text-slate-400 tracking-widest">Instructor Lead</h3>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-14 h-14 bg-reef-600 rounded-2xl flex items-center justify-center text-white font-black text-xl shadow-lg shadow-reef-600/20">
                                {{ booking.instructor.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="font-black text-slate-900">{{ booking.instructor.name }}</p>
                                <p class="text-[10px] font-black text-reef-600 uppercase tracking-tighter flex items-center gap-1">
                                    <CheckBadgeIcon class="w-3 h-3" />
                                    LGU Verified Professional
                                </p>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-50 space-y-4 text-center">
                            <button class="w-full py-2.5 bg-slate-50 rounded-xl text-xs font-black uppercase tracking-widest text-slate-600 hover:bg-slate-100 transition-colors">
                                View Full Profile
                            </button>
                            <p class="text-[10px] text-slate-400 font-bold italic uppercase tracking-tighter">Earnings Share: 70% (₱{{ (booking.total_amount * 0.7).toFixed(0) }})</p>
                        </div>
                    </div>
                </div>

                <!-- Financials & Compliance Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Payment Status -->
                    <div class="bg-ocean-600 rounded-[2.5rem] p-10 text-white relative overflow-hidden group shadow-xl shadow-ocean-600/20">
                        <div class="absolute -right-4 -bottom-4 opacity-10 group-hover:rotate-12 transition-transform duration-700">
                            <CreditCardIcon class="w-40 h-40" />
                        </div>
                        
                        <div class="relative z-10 h-full flex flex-col">
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-[10px] font-black uppercase opacity-60 tracking-widest">Payment Settlement</h3>
                                <div class="w-8 h-8 bg-white/10 rounded-lg flex items-center justify-center">
                                    <CreditCardIcon class="w-4 h-4" />
                                </div>
                            </div>

                            <div v-if="booking.payment" class="space-y-6">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-[10px] font-black uppercase opacity-40 mb-1">Method</p>
                                        <p class="text-xl font-black tracking-tight uppercase">{{ booking.payment.payment_method }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-black uppercase opacity-40 mb-1">Status</p>
                                        <p class="text-xl font-black tracking-tight uppercase">{{ booking.payment.status }}</p>
                                    </div>
                                </div>
                                <div class="pt-6 border-t border-white/10">
                                    <p class="text-4xl font-black tracking-tighter">₱{{ booking.total_amount }}</p>
                                    <p v-if="booking.payment.paid_at" class="text-[10px] font-bold opacity-40 mt-1">Processed on {{ new Date(booking.payment.paid_at).toLocaleDateString() }}</p>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-8 opacity-40 italic">
                                <p class="text-sm font-bold">Awaiting Transaction</p>
                            </div>
                        </div>
                    </div>

                    <!-- Legal Compliance -->
                    <div class="bg-slate-900 rounded-[2.5rem] p-10 text-white relative overflow-hidden group border border-slate-800 shadow-xl">
                        <div class="absolute -right-4 -bottom-4 opacity-10 group-hover:-rotate-12 transition-transform duration-700">
                            <ShieldCheckIcon class="w-40 h-40" />
                        </div>

                        <div class="relative z-10 h-full flex flex-col">
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-[10px] font-black uppercase opacity-60 tracking-widest">Legal Compliance</h3>
                                <div class="w-8 h-8 bg-white/5 rounded-lg flex items-center justify-center">
                                    <ShieldCheckIcon class="w-4 h-4" />
                                </div>
                            </div>

                            <div v-if="booking.waiver" class="space-y-6 text-center py-4">
                                <div class="w-16 h-16 bg-ocean-500/20 rounded-full flex items-center justify-center mx-auto ring-4 ring-ocean-500/10">
                                    <DocumentTextIcon class="w-8 h-8 text-ocean-400" />
                                </div>
                                <div class="space-y-1">
                                    <p class="text-sm font-black text-ocean-400">Electronic Waiver Active</p>
                                    <p class="text-[10px] font-bold opacity-30 tracking-tight">Signed: {{ new Date(booking.waiver.signed_at).toLocaleString() }}</p>
                                </div>
                                <div class="pt-4">
                                    <Link :href="route('waivers.view', booking.id)" class="px-8 py-2.5 bg-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-white/20 transition-all border border-white/5">
                                        Open Document
                                    </Link>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-8 text-rose-400 italic">
                                <InformationCircleIcon class="w-10 h-10 mb-2 opacity-50" />
                                <p class="text-sm font-bold uppercase tracking-widest">Waiver Missing</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar Activity Log -->
            <div class="lg:col-span-2 space-y-8">
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between mb-8 pb-4 border-b border-slate-50">
                        <h3 class="text-xs font-black uppercase text-slate-400 tracking-widest">Activity Timeline</h3>
                        <span class="px-2 py-0.5 bg-slate-100 rounded text-[9px] font-black text-slate-500 uppercase tracking-widest">Vault Protected</span>
                    </div>

                    <div class="relative pl-8 space-y-8 before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-100">
                        <!-- Step 1 -->
                        <div class="relative group/step">
                            <div class="absolute -left-10 top-0 w-6 h-6 rounded-full bg-ocean-600 border-4 border-white shadow-sm ring-4 ring-ocean-50 z-10 transition-transform group-hover/step:scale-125"></div>
                            <div class="flex flex-col">
                                <span class="text-[9px] font-black text-ocean-600 uppercase tracking-tighter">14:22 • TODAY</span>
                                <span class="text-sm font-bold text-slate-900">Booking Confirmed</span>
                                <span class="text-[11px] text-slate-400 mt-0.5">Automated system release after verification.</span>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="relative group/step">
                            <div class="absolute -left-10 top-0 w-6 h-6 rounded-full bg-reef-500 border-4 border-white shadow-sm ring-4 ring-reef-50 z-10 transition-transform group-hover/step:scale-125"></div>
                            <div class="flex flex-col">
                                <span class="text-[9px] font-black text-reef-600 uppercase tracking-tighter">14:15 • TODAY</span>
                                <span class="text-sm font-bold text-slate-900">Payment Secured</span>
                                <span class="text-[11px] text-slate-400 mt-0.5">GCash transaction verified by PayMongo.</span>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="relative group/step">
                            <div class="absolute -left-10 top-0 w-6 h-6 rounded-full bg-wave-500 border-4 border-white shadow-sm ring-4 ring-wave-50 z-10 transition-transform group-hover/step:scale-125"></div>
                            <div class="flex flex-col">
                                <span class="text-[9px] font-black text-wave-600 uppercase tracking-tighter">14:02 • TODAY</span>
                                <span class="text-sm font-bold text-slate-900">Signature Affixed</span>
                                <span class="text-[11px] text-slate-400 mt-0.5">Electronic waveform capture successful.</span>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div class="relative group/step">
                            <div class="absolute -left-10 top-0 w-6 h-6 rounded-full bg-slate-300 border-4 border-white shadow-sm ring-4 ring-slate-50 z-10 transition-transform group-hover/step:scale-125"></div>
                            <div class="flex flex-col opacity-60">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-tighter">14:00 • TODAY</span>
                                <span class="text-sm font-bold text-slate-900">Reservation Inflow</span>
                                <span class="text-[11px] text-slate-400 mt-0.5">Inquiry registered via Cloud9 entry.</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 p-4 bg-slate-900 rounded-2xl text-[10px] text-slate-400 text-center uppercase font-black tracking-widest leading-relaxed">
                        Data Cryptographically Signed <br/> 
                        <span class="text-ocean-400">SIASURF-SECURE-ENCLAVE</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </AppLayout>
</template>
