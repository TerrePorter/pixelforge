<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatUsd, toNanos } from '@/lib/money';
import { cn } from '@/lib/utils';
import { credits, generate, logout } from '@/routes';

defineProps<{
    title: string;
}>();

const page = usePage();

const balanceNanos = computed(() =>
    page.props.credits ? toNanos(page.props.credits.balance) : null,
);

const reservedNanos = computed(() =>
    page.props.credits ? toNanos(page.props.credits.reserved) : 0,
);

const navLink = (active: boolean) =>
    cn(
        'text-sm hover:text-zinc-900 dark:hover:text-zinc-100',
        active
            ? 'font-medium text-zinc-900 dark:text-zinc-100'
            : 'text-zinc-600 dark:text-zinc-400',
    );
</script>

<template>
    <Head :title="title" />
    <div
        class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
    >
        <header
            class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div
                class="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 px-4"
            >
                <nav class="flex items-center gap-6">
                    <Link
                        :href="generate()"
                        class="font-semibold tracking-tight"
                    >
                        {{ $page.props.name }}
                    </Link>
                    <Link
                        :href="generate()"
                        :class="navLink($page.component === 'Generate')"
                    >
                        Generate
                    </Link>
                    <Link
                        :href="credits()"
                        :class="navLink($page.component === 'Credits')"
                    >
                        Credits
                    </Link>
                </nav>
                <div class="flex items-center gap-3 text-sm">
                    <Link
                        v-if="balanceNanos !== null"
                        :href="credits()"
                        class="rounded-md px-2 py-1 tabular-nums hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        :title="
                            reservedNanos > 0
                                ? `${formatUsd(reservedNanos)} is reserved for generations in progress`
                                : 'Your credit balance'
                        "
                    >
                        <span class="text-zinc-500">Balance</span>
                        <strong
                            :class="
                                cn('ml-1', balanceNanos < 0 && 'text-red-600')
                            "
                        >
                            {{ formatUsd(balanceNanos) }}
                        </strong>
                    </Link>
                    <Link
                        :href="credits()"
                        class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
                    >
                        Buy credits
                    </Link>
                    <Link
                        :href="logout()"
                        as="button"
                        class="rounded-md px-2 py-1 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    >
                        Log out
                    </Link>
                </div>
            </div>
        </header>
        <div
            v-if="$page.props.flash.success"
            class="border-b border-emerald-200 bg-emerald-50 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            role="status"
        >
            <p class="mx-auto max-w-5xl px-4 py-2">
                {{ $page.props.flash.success }}
            </p>
        </div>
        <main class="mx-auto max-w-5xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
