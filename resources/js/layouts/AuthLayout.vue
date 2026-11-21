<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <Head :title="title" />
    <div
        class="flex min-h-screen items-center justify-center bg-zinc-50 px-4 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
    >
        <main class="w-full max-w-sm">
            <p class="mb-8 text-center text-lg font-semibold tracking-tight">
                {{ $page.props.name }}
            </p>
            <div
                class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h1 class="text-xl font-semibold">{{ title }}</h1>
                <p class="mt-1 mb-6 text-sm text-zinc-500">{{ description }}</p>
                <slot />
            </div>
            <div class="mt-4 text-center text-sm text-zinc-500">
                <slot name="footer" />
            </div>
        </main>
    </div>
</template>
