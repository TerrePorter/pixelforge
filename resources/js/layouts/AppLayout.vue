<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { generate, logout } from '@/routes';

defineProps<{
    title: string;
}>();
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
                        class="text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                    >
                        Generate
                    </Link>
                </nav>
                <div class="flex items-center gap-4 text-sm">
                    <span class="hidden text-zinc-500 sm:inline">
                        {{ $page.props.auth.user?.name }}
                    </span>
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
        <main class="mx-auto max-w-5xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
