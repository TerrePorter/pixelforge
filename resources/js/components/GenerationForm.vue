<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatUsd, toNanos } from '@/lib/money';
import { characterCount, estimate } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import { credits } from '@/routes';
import { store } from '@/routes/generations';
import type { ImageQuality, ImageSize, PricingTable } from '@/types/generation';

const props = defineProps<{
    pricing: PricingTable;
}>();

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const sizes: { value: ImageSize; label: string; hint: string }[] = [
    { value: '1024x1024', label: 'Square', hint: '1024 × 1024' },
    { value: '1536x1024', label: 'Landscape', hint: '1536 × 1024' },
    { value: '1024x1536', label: 'Portrait', hint: '1024 × 1536' },
];

const qualities: { value: ImageQuality; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const form = useForm({
    prompt: '',
    size: '1024x1024' as ImageSize,
    quality: 'medium' as ImageQuality,
    reference_images: [] as string[],
});

const showBreakdown = ref(false);

const promptLength = computed(() => characterCount(form.prompt));
const filledReferences = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);

const cost = computed(() =>
    estimate(
        props.pricing,
        form.prompt,
        form.size,
        form.quality,
        filledReferences.value,
    ),
);

const breakdown = computed(() => {
    const { minUsage, maxUsage } = cost.value;
    const range = (min: number, max: number) =>
        min === max
            ? min.toLocaleString()
            : `${min.toLocaleString()}–${max.toLocaleString()}`;

    return [
        {
            label: 'Prompt text',
            tokens: range(
                minUsage.input_text_tokens,
                maxUsage.input_text_tokens,
            ),
            note: 'about 1 token per 4 characters, ±15%',
        },
        {
            label: 'Reference images',
            tokens: range(
                minUsage.input_image_tokens,
                maxUsage.input_image_tokens,
            ),
            note: '765 per image',
        },
        {
            label: 'Image output',
            tokens: range(minUsage.output_tokens, maxUsage.output_tokens),
            note: 'set by size and quality',
        },
        {
            label: 'Reasoning',
            tokens: range(minUsage.reasoning_tokens, maxUsage.reasoning_tokens),
            note: 'varies; known after it finishes',
        },
    ];
});

const page = usePage();

const balanceNanos = computed(() =>
    page.props.credits ? toNanos(page.props.credits.balance) : 0,
);

/** The worst case has to fit in the balance; the server checks this too. */
const canAfford = computed(
    () => cost.value.maxChargeNanos <= balanceNanos.value,
);

/** Set by the server when the balance check fails there (e.g. it changed meanwhile). */
const balanceError = computed(
    () => (form.errors as Record<string, string | undefined>).balance,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        canAfford.value &&
        form.prompt.trim() !== '' &&
        promptLength.value <= MAX_PROMPT,
);

function addReference() {
    if (form.reference_images.length < MAX_REFERENCES) {
        form.reference_images.push('');
    }
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

function referenceError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

function submit() {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => form.reset('reference_images'),
    });
}

function segment(active: boolean) {
    return cn(
        'flex-1 rounded-md px-3 py-2 text-sm transition',
        active
            ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-50'
            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
    );
}
</script>

<template>
    <form
        class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        @submit.prevent="submit"
    >
        <label class="block">
            <span
                class="flex items-baseline justify-between text-sm font-medium"
            >
                Prompt
                <span
                    :class="
                        cn(
                            'text-xs font-normal text-zinc-500',
                            promptLength > MAX_PROMPT && 'text-red-600',
                        )
                    "
                >
                    {{ promptLength.toLocaleString() }} /
                    {{ MAX_PROMPT.toLocaleString() }}
                </span>
            </span>
            <textarea
                v-model="form.prompt"
                rows="4"
                placeholder="A lighthouse on a cliff at dusk, painted in watercolour"
                class="mt-1 block w-full resize-y rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:ring-zinc-800"
                @keydown.meta.enter="submit"
                @keydown.ctrl.enter="submit"
            />
            <span
                v-if="form.errors.prompt"
                class="mt-1 block text-sm text-red-600"
            >
                {{ form.errors.prompt }}
            </span>
        </label>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <fieldset>
                <legend class="text-sm font-medium">Size</legend>
                <div
                    class="mt-1 flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800"
                >
                    <button
                        v-for="option in sizes"
                        :key="option.value"
                        type="button"
                        :class="segment(form.size === option.value)"
                        :aria-pressed="form.size === option.value"
                        :title="option.hint"
                        @click="form.size = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </fieldset>
            <fieldset>
                <legend class="text-sm font-medium">Quality</legend>
                <div
                    class="mt-1 flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800"
                >
                    <button
                        v-for="option in qualities"
                        :key="option.value"
                        type="button"
                        :class="segment(form.quality === option.value)"
                        :aria-pressed="form.quality === option.value"
                        @click="form.quality = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </fieldset>
        </div>

        <div class="mt-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium">
                    Reference images
                    <span class="font-normal text-zinc-500">(optional)</span>
                </span>
                <button
                    v-if="form.reference_images.length < MAX_REFERENCES"
                    type="button"
                    class="text-sm text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-400"
                    @click="addReference"
                >
                    + Add URL
                </button>
            </div>
            <div
                v-for="(_, index) in form.reference_images"
                :key="index"
                class="mt-2"
            >
                <div class="flex gap-2">
                    <input
                        v-model="form.reference_images[index]"
                        type="url"
                        placeholder="https://example.com/reference.png"
                        class="block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-950 dark:focus:ring-zinc-800"
                    />
                    <button
                        type="button"
                        class="rounded-md px-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="removeReference(index)"
                    >
                        ✕
                    </button>
                </div>
                <span
                    v-if="referenceError(index)"
                    class="mt-1 block text-sm text-red-600"
                >
                    {{ referenceError(index) }}
                </span>
            </div>
            <span
                v-if="form.errors.reference_images"
                class="mt-1 block text-sm text-red-600"
            >
                {{ form.errors.reference_images }}
            </span>
        </div>

        <div
            class="mt-5 flex flex-col gap-4 border-t border-zinc-200 pt-4 sm:flex-row sm:items-end sm:justify-between dark:border-zinc-800"
        >
            <div class="text-sm">
                <p>
                    Estimated cost
                    <strong class="text-base tabular-nums">
                        {{ formatUsd(cost.minChargeNanos) }} –
                        {{ formatUsd(cost.maxChargeNanos) }}
                    </strong>
                </p>
                <p class="mt-0.5 text-zinc-500">
                    You're charged the actual cost once it finishes, never more
                    than {{ formatUsd(cost.maxChargeNanos) }}. Failed
                    generations are free.
                </p>
                <p
                    v-if="!canAfford || balanceError"
                    class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    role="alert"
                >
                    <template v-if="!canAfford">
                        Your balance is {{ formatUsd(balanceNanos) }}, so you
                        can't cover the {{ formatUsd(cost.maxChargeNanos) }}
                        this could cost.
                    </template>
                    <template v-else>{{ balanceError }}</template>
                    <Link
                        :href="credits()"
                        class="ml-1 font-medium underline underline-offset-2"
                    >
                        Buy credits
                    </Link>
                </p>
                <button
                    type="button"
                    class="mt-1 text-xs text-zinc-500 underline-offset-2 hover:underline"
                    :aria-expanded="showBreakdown"
                    @click="showBreakdown = !showBreakdown"
                >
                    {{ showBreakdown ? 'Hide' : 'Show' }} token breakdown
                </button>
                <table
                    v-if="showBreakdown"
                    class="mt-2 text-xs text-zinc-600 dark:text-zinc-400"
                >
                    <tbody>
                        <tr v-for="row in breakdown" :key="row.label">
                            <td class="pr-4">{{ row.label }}</td>
                            <td class="pr-4 text-right tabular-nums">
                                {{ row.tokens }}
                            </td>
                            <td class="text-zinc-400">{{ row.note }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button
                type="submit"
                :disabled="!canSubmit"
                class="shrink-0 rounded-md bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
                {{ form.processing ? 'Submitting…' : 'Generate' }}
            </button>
        </div>
    </form>
</template>
