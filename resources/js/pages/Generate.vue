<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import GenerationForm from '@/components/GenerationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Generation, PricingTable } from '@/types/generation';

const props = defineProps<{
    pricing: PricingTable;
    generations: Generation[];
}>();

// Poll our own server (never Pixelforge) while anything is still running.
// "rest" waits for each response before scheduling the next, so a slow server
// never gets a pile-up of reloads (which also delays form submissions).
const { start, stop } = usePoll(
    2500,
    { only: ['generations'] },
    { autoStart: false, mode: 'rest' },
);

const hasRunning = computed(() =>
    props.generations.some((g) => !['succeeded', 'failed'].includes(g.status)),
);

watch(hasRunning, (running) => (running ? start() : stop()), {
    immediate: true,
});

// A clock for elapsed times on running cards.
const now = ref(Date.now());
let timer: number | undefined;

onMounted(() => {
    timer = window.setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <AppLayout title="Generate">
        <h1 class="text-2xl font-semibold tracking-tight">Generate an image</h1>
        <p class="mt-1 mb-6 text-sm text-zinc-500">
            Describe what you want, pick a size and quality, and see the cost
            before you submit.
        </p>

        <GenerationForm :pricing="pricing" />

        <section class="mt-10">
            <h2 class="text-lg font-semibold">Your generations</h2>
            <p
                v-if="generations.length === 0"
                class="mt-4 rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700"
            >
                Nothing yet. Your images will appear here as they're generated.
            </p>
            <div v-else class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations"
                    :key="generation.id"
                    :generation="generation"
                    :now="now"
                />
            </div>
        </section>
    </AppLayout>
</template>
