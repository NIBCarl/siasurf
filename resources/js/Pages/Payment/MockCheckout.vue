<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

interface Props {
  transactionId: string
  forceSuccess: boolean
}

const props = defineProps<Props>()

const processing = ref(false)

const processPayment = (success: boolean) => {
  processing.value = true
  router.post(route('payments.mock-process', { transactionId: props.transactionId }), {
    success,
  }, {
    onFinish: () => {
      processing.value = false
    },
  })
}
</script>

<template>
  <Head title="Mock GCash Checkout" />

  <div class="min-h-screen bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
      <!-- Header -->
      <div class="bg-blue-600 px-6 py-4 text-center">
        <p class="text-[10px] text-blue-200 font-black uppercase tracking-widest">Development Mode</p>
        <h1 class="text-white text-2xl font-bold mt-1">Mock GCash Checkout</h1>
      </div>

      <!-- Body -->
      <div class="p-6 space-y-6">
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
          <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p>This is a <strong>mock checkout page</strong> for local development. In production, students are redirected to the real PayMongo GCash checkout.</p>
          </div>
        </div>

        <div class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-500">Transaction ID</span>
            <span class="font-mono font-medium text-gray-900">{{ transactionId }}</span>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="space-y-3">
          <button
            @click="processPayment(true)"
            :disabled="processing"
            class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Simulate Successful Payment
          </button>

          <button
            @click="processPayment(false)"
            :disabled="processing"
            class="w-full py-3 px-4 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white font-bold rounded-xl transition-colors flex items-center justify-center gap-2"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Simulate Failed Payment
          </button>
        </div>

        <p v-if="processing" class="text-center text-sm text-gray-500 animate-pulse">
          Processing mock payment...
        </p>
      </div>
    </div>
  </div>
</template>
