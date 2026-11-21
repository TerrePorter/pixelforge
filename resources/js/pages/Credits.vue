<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd, formatUsdExact, toNanos } from '@/lib/money';
import { cn } from '@/lib/utils';
import { generate } from '@/routes';
import { store } from '@/routes/credits';

type Transaction = {
    id: number;
    type: 'purchase' | 'hold' | 'hold_release' | 'charge';
    amount: string;
    description: string | null;
    prompt: string | null;
    created_at: string | null;
};

defineProps<{
    packs: number[];
    transactions: Transaction[];
}>();

const page = usePage();

const balance = computed(() =>
    page.props.credits ? toNanos(page.props.credits.balance) : 0,
);
const reserved = computed(() =>
    page.props.credits ? toNanos(page.props.credits.reserved) : 0,
);

const form = useForm({ amount: 0 });

function buy(amount: number) {
    form.amount = amount;
    form.submit(store(), { preserveScroll: true });
}

const typeLabels: Record<Transaction['type'], string> = {
    purchase: 'Bought credits',
    hold: 'Reserved',
    hold_release: 'Reservation returned',
    charge: 'Charged',
};

function when(iso: string | null) {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
}
</script>

<template>
    <AppLayout title="Credits">
        <h1 class="text-2xl font-semibold tracking-tight">Credits</h1>
        <p class="mt-1 mb-6 text-sm text-zinc-500">
            Credits are US dollars. Each image is charged its actual cost once
            it finishes; failed generations are free.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <section
                class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm text-zinc-500">Balance</h2>
                <p
                    :class="
                        cn(
                            'mt-1 text-3xl font-semibold tabular-nums',
                            balance < 0 && 'text-red-600',
                        )
                    "
                >
                    {{ formatUsd(balance) }}
                </p>
                <p v-if="reserved > 0" class="mt-2 text-sm text-zinc-500">
                    {{ formatUsd(reserved) }} is reserved for generations in
                    progress. Whatever they don't use comes back when they
                    finish.
                </p>
            </section>

            <section
                class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
            >
                <h2 class="text-sm font-medium">Buy credits</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    This is a demo: no payment is taken.
                </p>
                <div class="mt-4 flex gap-2">
                    <button
                        v-for="pack in packs"
                        :key="pack"
                        type="button"
                        :disabled="form.processing"
                        class="flex-1 rounded-md border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="buy(pack)"
                    >
                        + ${{ pack }}
                    </button>
                </div>
                <p v-if="form.errors.amount" class="mt-2 text-sm text-red-600">
                    {{ form.errors.amount }}
                </p>
                <Link
                    :href="generate()"
                    class="mt-4 inline-block text-sm text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-400"
                >
                    Back to generating →
                </Link>
            </section>
        </div>

        <section class="mt-10">
            <h2 class="text-lg font-semibold">History</h2>
            <p
                v-if="transactions.length === 0"
                class="mt-4 rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700"
            >
                No credit activity yet.
            </p>
            <div
                v-else
                class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
            >
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-zinc-500">
                        <tr
                            class="border-b border-zinc-200 dark:border-zinc-800"
                        >
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">What</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transaction in transactions"
                            :key="transaction.id"
                            class="border-b border-zinc-100 last:border-0 dark:border-zinc-800"
                        >
                            <td
                                class="px-4 py-2 whitespace-nowrap text-zinc-500"
                            >
                                {{ when(transaction.created_at) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ typeLabels[transaction.type] }}
                                <span
                                    v-if="transaction.prompt"
                                    class="text-zinc-500"
                                >
                                    · {{ transaction.prompt }}
                                </span>
                            </td>
                            <td
                                :class="
                                    cn(
                                        'px-4 py-2 text-right whitespace-nowrap tabular-nums',
                                        transaction.amount.startsWith('-')
                                            ? 'text-zinc-900 dark:text-zinc-100'
                                            : 'text-emerald-700 dark:text-emerald-400',
                                    )
                                "
                                :title="formatUsdExact(transaction.amount)"
                            >
                                {{
                                    transaction.amount.startsWith('-')
                                        ? ''
                                        : '+'
                                }}{{ formatUsd(toNanos(transaction.amount)) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
