<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatUsd, formatUsdExact, toNanos } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store } from '@/routes/generations';
import type { Generation } from '@/types/generation';

const props = defineProps<{
    generation: Generation;
    /** Current time in ms, ticking once a second, for elapsed times. */
    now: number;
}>();

const imageBroken = ref(false);

const sizeLabels: Record<Generation['size'], string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const waitingLabels: Record<string, string> = {
    concurrency_limit_exceeded: 'Waiting for a free slot',
    rate_limit_exceeded: 'Pixelforge is busy, retrying',
    overloaded: 'Pixelforge is busy, retrying',
    connection_error: 'Connection problem, retrying',
    server_error: 'Pixelforge error, retrying',
};

/** Sending normally takes a second or two; after this we tell the user it's slow. */
const SLOW_SUBMIT_SECONDS = 60;

/**
 * Still not sent to Pixelforge after a minute, with no known reason (e.g. the queue
 * is backed up). It will still go out automatically, and nothing is charged until then.
 */
const isSlowToSubmit = computed(() => {
    const g = props.generation;

    return (
        g.status === 'pending' &&
        g.waiting_reason === null &&
        g.created_at !== null &&
        props.now - Date.parse(g.created_at) > SLOW_SUBMIT_SECONDS * 1000
    );
});

const status = computed(() => {
    const g = props.generation;

    switch (g.status) {
        case 'pending':
            return {
                label: isSlowToSubmit.value
                    ? 'Delayed'
                    : (waitingLabels[g.waiting_reason ?? ''] ?? 'Submitting'),
                tone: 'waiting',
            };
        case 'queued':
            return { label: 'Queued', tone: 'waiting' };
        case 'processing':
            return { label: 'Generating', tone: 'active' };
        case 'succeeded':
            return { label: 'Done', tone: 'success' };
        case 'failed':
            return { label: 'Failed', tone: 'error' };
    }

    return { label: g.status, tone: 'waiting' };
});

const elapsed = computed(() => {
    const g = props.generation;
    const from = g.started_at ?? g.created_at;

    if (!from) {
        return null;
    }

    const end = g.completed_at ? Date.parse(g.completed_at) : props.now;
    const seconds = Math.max(0, Math.round((end - Date.parse(from)) / 1000));

    return seconds < 60
        ? `${seconds}s`
        : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
});

const isRunning = computed(
    () => !['succeeded', 'failed'].includes(props.generation.status),
);

const tokenTotal = computed(() => {
    const usage = props.generation.usage;

    return usage
        ? usage.input_text_tokens +
              usage.input_image_tokens +
              usage.output_tokens +
              usage.reasoning_tokens
        : null;
});

function retry() {
    const g = props.generation;

    router.visit(store(), {
        data: {
            prompt: g.prompt,
            size: g.size,
            quality: g.quality,
            reference_images: g.reference_images,
        },
        preserveScroll: true,
    });
}

const pill = computed(() =>
    cn(
        'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
        {
            waiting:
                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
            active: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
            success:
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
            error: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
        }[status.value.tone],
    ),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
    >
        <div
            :class="
                cn(
                    'relative flex items-center justify-center bg-zinc-100 dark:bg-zinc-800',
                    {
                        '1024x1024': 'aspect-square',
                        '1536x1024': 'aspect-[3/2]',
                        '1024x1536': 'aspect-[2/3]',
                    }[generation.size],
                )
            "
        >
            <a
                v-if="
                    generation.image_url &&
                    !generation.image_expired &&
                    !imageBroken
                "
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
                class="block h-full w-full"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="h-full w-full object-cover"
                    @error="imageBroken = true"
                />
            </a>
            <p
                v-else-if="generation.status === 'succeeded'"
                class="px-4 text-center text-sm text-zinc-500"
            >
                Image link expired.<br />
                <span class="text-xs"
                    >Pixelforge keeps images for 24 hours.</span
                >
            </p>
            <p
                v-else-if="generation.status === 'failed'"
                class="px-6 text-center text-sm text-red-700 dark:text-red-300"
            >
                {{ generation.error_message ?? 'The generation failed.' }}
            </p>
            <div v-else class="flex flex-col items-center gap-2 text-zinc-500">
                <span
                    class="h-6 w-6 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-700 dark:border-zinc-600 dark:border-t-zinc-200"
                    aria-hidden="true"
                />
                <span class="text-sm">{{ status.label }}…</span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <span :class="pill">
                    <span
                        v-if="isRunning"
                        class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"
                    />
                    {{ status.label }}
                </span>
                <span class="text-xs text-zinc-500 tabular-nums">
                    {{ sizeLabels[generation.size] }} ·
                    {{ generation.quality }}
                    <template v-if="elapsed"> · {{ elapsed }}</template>
                </span>
            </div>

            <p
                v-if="isSlowToSubmit"
                class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                role="status"
            >
                This hasn't been sent to Pixelforge yet. It will go
                automatically, and you won't be charged until it runs.
            </p>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <dl
                class="mt-auto grid grid-cols-2 gap-2 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800"
            >
                <div>
                    <dt class="text-zinc-500">Estimated</dt>
                    <dd
                        class="tabular-nums"
                        :title="`${formatUsdExact(generation.estimated_min_charge)} – ${formatUsdExact(generation.estimated_max_charge)}`"
                    >
                        {{
                            formatUsd(toNanos(generation.estimated_min_charge))
                        }}
                        –
                        {{
                            formatUsd(toNanos(generation.estimated_max_charge))
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Charged</dt>
                    <dd
                        v-if="
                            generation.status === 'succeeded' &&
                            generation.charge
                        "
                        class="font-medium tabular-nums"
                        :title="`${formatUsdExact(generation.charge)} · ${tokenTotal?.toLocaleString()} tokens`"
                    >
                        {{ formatUsd(toNanos(generation.charge)) }}
                    </dd>
                    <dd
                        v-else-if="generation.status === 'failed'"
                        class="font-medium"
                    >
                        $0.00 — not charged
                    </dd>
                    <dd v-else class="text-zinc-500">When it finishes</dd>
                </div>
            </dl>

            <button
                v-if="generation.status === 'failed'"
                type="button"
                class="self-start text-sm font-medium text-zinc-700 underline-offset-2 hover:underline dark:text-zinc-300"
                @click="retry"
            >
                Try again
            </button>
        </div>
    </article>
</template>
