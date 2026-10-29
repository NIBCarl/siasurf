<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const page = usePage();

const email = computed(
    () => (page.props.auth as any)?.user?.email ?? null,
);

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);

const verificationMailFailed = computed(
    () => props.status === 'verification-mail-failed',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <div class="mb-4 text-sm text-slate-600">
            Thanks for signing up! Before getting started, verify your email
            address by clicking the link we sent
            <template v-if="email">
                to <strong class="text-slate-900">{{ email }}</strong>
            </template>
            <template v-else>to the address you registered with</template>.
            If you didn't receive it, we will gladly send you another.
        </div>

        <div
            v-if="verificationLinkSent"
            class="mb-4 text-sm font-medium text-emerald-600"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <div
            v-if="verificationMailFailed"
            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
        >
            <p class="font-semibold">We could not send the verification email.</p>
            <p class="mt-1 text-xs leading-relaxed">
                Your account was created. Check the <code>MAIL_*</code> settings
                (Gmail address + App Password), then press
                <strong>Resend Verification Email</strong>. If it keeps failing,
                ask SISA to verify the account manually.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Resend Verification Email
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-slate-600 underline hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-wave-500 focus:ring-offset-2"
                    >Log Out</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
