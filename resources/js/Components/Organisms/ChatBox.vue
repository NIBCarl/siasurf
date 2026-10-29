<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import {
  ChatBubbleLeftRightIcon,
  PaperAirplaneIcon,
  ShieldCheckIcon,
} from '@heroicons/vue/24/outline'
import { useChat } from '@/Composables/useChat'
import { resolveAvatarUrl } from '@/utils/avatar'

interface Props {
  bookingId: number
  otherPartyName: string
  otherPartyAvatar?: string | null
}

const props = defineProps<Props>()

const page = usePage()
const currentUserId = computed(() => Number((page.props.auth as any)?.user?.id ?? 0))

const chat = useChat(props.bookingId, currentUserId.value)

const body = ref('')
const scrollContainer = ref<HTMLElement | null>(null)

const displayName = computed(() => chat.otherParty.value?.name ?? props.otherPartyName)
const displayAvatar = computed(() => chat.otherParty.value?.avatar_path ?? props.otherPartyAvatar ?? null)

const avatarUrl = computed(() => resolveAvatarUrl(displayAvatar.value))

const initials = computed(() =>
  displayName.value
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join(''),
)

const scrollToBottom = async () => {
  await nextTick()
  if (!scrollContainer.value) return
  scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight
}

const submit = async () => {
  const message = body.value
  const sent = await chat.send(message)
  if (sent) {
    body.value = ''
    await scrollToBottom()
  }
}

watch(chat.messages, scrollToBottom, { deep: true })

onMounted(async () => {
  await chat.load()
  // Opening the panel marks the thread read on the server; refresh the shared
  // nav badge so the sidebar/dashboard count drops immediately.
  router.reload({ only: ['nav'] })
  chat.subscribe()
  await scrollToBottom()
})

onBeforeUnmount(() => {
  chat.unsubscribe()
})
</script>

<template>
  <section
    class="flex h-[min(70vh,560px)] min-h-[420px] flex-col overflow-hidden rounded-card border border-sand-200 bg-white"
    aria-label="Booking messages"
  >
    <header class="flex items-center gap-3 border-b border-deep-800 bg-deep-950 px-4 py-3 text-white">
      <span
        v-if="avatarUrl"
        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white/10"
      >
        <img :src="avatarUrl" :alt="displayName" class="h-full w-full object-cover" />
      </span>
      <span
        v-else
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-label font-semibold text-lagoon-200"
        aria-hidden="true"
      >
        {{ initials }}
      </span>

      <div class="min-w-0 flex-1">
        <p class="truncate text-body-sm font-semibold">{{ displayName }}</p>
        <p class="flex items-center gap-1 text-label text-lagoon-200">
          <ShieldCheckIcon class="h-3.5 w-3.5" aria-hidden="true" />
          Booking #SURF-{{ bookingId.toString().padStart(5, '0') }}
        </p>
      </div>

      <span
        v-if="chat.unreadCount.value > 0"
        class="rounded-full bg-coral-500 px-2 py-0.5 text-label font-bold text-white"
      >
        {{ chat.unreadCount.value }} new
      </span>
    </header>

    <div
      ref="scrollContainer"
      class="flex-1 space-y-3 overflow-y-auto bg-sand-50 px-4 py-4"
      aria-live="polite"
    >
      <p v-if="chat.loading.value" class="py-10 text-center text-body-sm text-stone-500">
        Loading conversation...
      </p>

      <div
        v-else-if="chat.error.value"
        class="rounded-control border border-rose-200 bg-rose-50 px-3 py-2 text-body-sm font-medium text-rose-800"
        role="alert"
      >
        {{ chat.error.value }}
      </div>

      <div
        v-else-if="!chat.messages.value.length"
        class="flex h-full flex-col items-center justify-center px-6 text-center"
      >
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-lagoon-50 text-lagoon-700">
          <ChatBubbleLeftRightIcon class="h-6 w-6" aria-hidden="true" />
        </span>
        <p class="mt-3 text-body font-semibold text-deep-900">No messages yet</p>
        <p class="mt-1 max-w-xs text-body-sm text-stone-500">
          Use this thread for meeting point, time changes, and equipment questions.
        </p>
      </div>

      <template v-else>
        <div
          v-for="message in chat.messages.value"
          :key="message.id"
          class="flex flex-col"
          :class="message.mine ? 'items-end' : 'items-start'"
        >
          <p v-if="!message.mine" class="mb-1 px-1 text-label font-semibold text-stone-500">
            {{ message.sender_name }}
          </p>

          <div
            class="max-w-[85%] rounded-2xl px-3.5 py-2.5 text-body-sm shadow-sm"
            :class="
              message.mine
                ? 'rounded-br-md bg-lagoon-700 text-white'
                : 'rounded-bl-md border border-sand-200 bg-white text-deep-900'
            "
          >
            <p class="whitespace-pre-line break-words">{{ message.body }}</p>
          </div>

          <p class="mt-1 px-1 text-[11px] font-medium text-stone-400">
            {{ message.created_at_label }}
            <span v-if="message.mine && message.read_at"> · Read</span>
          </p>
        </div>
      </template>
    </div>

    <footer class="border-t border-sand-200 bg-white p-3">
      <form v-if="chat.canSend.value" class="flex items-end gap-2" @submit.prevent="submit">
        <label for="chat-message" class="sr-only">Message</label>
        <textarea
          id="chat-message"
          v-model="body"
          rows="2"
          maxlength="2000"
          placeholder="Write a message..."
          class="min-h-[52px] flex-1 resize-none rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-body-sm text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
          :disabled="chat.sending.value"
          @keydown.enter.exact.prevent="submit"
        ></textarea>
        <button
          type="submit"
          class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-coral-700 text-white shadow-card transition-colors hover:bg-coral-800 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="!body.trim() || chat.sending.value"
          aria-label="Send message"
        >
          <PaperAirplaneIcon class="h-5 w-5 -rotate-45" aria-hidden="true" />
        </button>
      </form>

      <p
        v-else
        class="rounded-control border border-sand-200 bg-sand-50 px-3 py-2.5 text-center text-body-sm font-medium text-stone-500"
      >
        {{ chat.readOnlyReason.value ?? 'Chat is not available for this booking.' }}
      </p>

      <p v-if="chat.validationError.value" class="mt-2 text-body-sm font-semibold text-rose-700" role="alert">
        {{ chat.validationError.value }}
      </p>
      <p class="mt-2 text-[11px] text-stone-400">
        Messages are visible only to you and {{ displayName }}.
      </p>
    </footer>
  </section>
</template>

