<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd, formatUsdExact, toNanos } from '@/lib/money';
import { cn } from '@/lib/utils';
import { report as reportRoute } from '@/routes/admin';

type ReportUser = { id: number; name: string; email: string } | null;

type Figures = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    tokens: Record<string, number>;
};

type Day = Figures & {
    date: string;
    pixelforge: {
        generations: number;
        cost: string;
        difference: string;
        matches: boolean;
    } | null;
    users: (Figures & { user: ReportUser })[];
};

const props = defineProps<{
    report: {
        start_date: string;
        end_date: string;
        totals: Figures;
        days: Day[];
        users: (Figures & { user: ReportUser })[];
        pixelforge:
            | { available: false }
            | {
                  available: true;
                  total_cost: string;
                  total_generations: number;
                  matches: boolean;
              };
    };
}>();

const range = reactive({
    start_date: props.report.start_date,
    end_date: props.report.end_date,
});

const expanded = ref<Set<string>>(new Set());

function toggle(date: string) {
    const next = new Set(expanded.value);

    if (next.has(date)) {
        next.delete(date);
    } else {
        next.add(date);
    }

    expanded.value = next;
}

function apply() {
    router.get(
        reportRoute.url({ query: { ...range } }),
        {},
        {
            preserveScroll: true,
        },
    );
}

function lastDays(days: number) {
    const end = new Date();
    const start = new Date(end.getTime() - (days - 1) * 86_400_000);

    range.start_date = start.toISOString().slice(0, 10);
    range.end_date = end.toISOString().slice(0, 10);
    apply();
}

const margin = computed(() => {
    const charged = toNanos(props.report.totals.charged);

    return charged === 0
        ? null
        : Math.round((toNanos(props.report.totals.profit) / charged) * 1000) /
              10;
});

const mismatchedDays = computed(
    () =>
        props.report.days.filter((day) => day.pixelforge?.matches === false)
            .length,
);

const userLabel = (user: ReportUser) =>
    user ? `${user.name} (${user.email})` : 'Deleted user';

const money = (amount: string) => formatUsdExact(amount);
</script>

<template>
    <AppLayout title="Admin report">
        <h1 class="text-2xl font-semibold tracking-tight">Admin report</h1>
        <p class="mt-1 mb-6 text-sm text-zinc-500">
            Succeeded generations, grouped by the UTC day they completed (the
            day Pixelforge bills them). Amounts are exact, in USD.
        </p>

        <form
            class="flex flex-wrap items-end gap-3 text-sm"
            @submit.prevent="apply"
        >
            <label class="block">
                <span class="text-zinc-500">From (UTC)</span>
                <input
                    v-model="range.start_date"
                    type="date"
                    class="mt-1 block rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900"
                />
            </label>
            <label class="block">
                <span class="text-zinc-500">To (UTC)</span>
                <input
                    v-model="range.end_date"
                    type="date"
                    class="mt-1 block rounded-md border border-zinc-300 bg-white px-2 py-1.5 dark:border-zinc-700 dark:bg-zinc-900"
                />
            </label>
            <button
                type="submit"
                class="rounded-md bg-zinc-900 px-4 py-2 font-medium text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900"
            >
                Apply
            </button>
            <button
                type="button"
                class="rounded-md px-3 py-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                @click="lastDays(7)"
            >
                Last 7 days
            </button>
            <button
                type="button"
                class="rounded-md px-3 py-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                @click="lastDays(30)"
            >
                Last 30 days
            </button>
            <span
                v-if="$page.props.errors.start_date"
                class="w-full text-red-600"
            >
                {{ $page.props.errors.start_date }}
            </span>
        </form>

        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <div
                v-for="card in [
                    {
                        label: 'Charged to users',
                        value: report.totals.charged,
                    },
                    {
                        label: 'Pixelforge cost',
                        value: report.totals.provider_cost,
                    },
                    { label: 'Profit', value: report.totals.profit },
                ]"
                :key="card.label"
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <p class="text-xs text-zinc-500">{{ card.label }}</p>
                <p
                    class="mt-1 text-xl font-semibold tabular-nums"
                    :title="money(card.value)"
                >
                    {{ formatUsd(toNanos(card.value)) }}
                </p>
                <p class="font-mono text-xs text-zinc-400">
                    {{ money(card.value) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
            >
                <p class="text-xs text-zinc-500">Generations</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">
                    {{ report.totals.generations.toLocaleString() }}
                </p>
                <p v-if="margin !== null" class="text-xs text-zinc-400">
                    {{ margin }}% margin
                </p>
            </div>
        </div>

        <div
            :class="
                cn(
                    'mt-4 rounded-lg px-4 py-3 text-sm',
                    !report.pixelforge.available
                        ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                        : report.pixelforge.matches
                          ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                          : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
                )
            "
            role="status"
        >
            <template v-if="!report.pixelforge.available">
                Couldn't reach Pixelforge's usage API, so this shows our own
                records only.
            </template>
            <template v-else-if="report.pixelforge.matches">
                ✓ Matches Pixelforge. Our recorded cost of
                <strong class="font-mono">{{
                    money(report.totals.provider_cost)
                }}</strong>
                for {{ report.totals.generations }} generations equals what
                <code>GET /v1/usage</code> reports for this range.
            </template>
            <template v-else>
                ⚠ Doesn't match Pixelforge. It reports
                <strong class="font-mono">{{
                    money(report.pixelforge.total_cost)
                }}</strong>
                for {{ report.pixelforge.total_generations }} generations; we
                recorded
                <strong class="font-mono">{{
                    money(report.totals.provider_cost)
                }}</strong>
                for {{ report.totals.generations }}. {{ mismatchedDays }} day(s)
                differ, marked below.
            </template>
        </div>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">By day</h2>
            <p
                v-if="report.days.length === 0"
                class="mt-3 rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700"
            >
                No completed generations in this range.
            </p>
            <div
                v-else
                class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500">
                        <tr
                            class="border-b border-zinc-200 dark:border-zinc-800"
                        >
                            <th class="px-4 py-2 font-medium">Day (UTC)</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Pixelforge reports
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs tabular-nums">
                        <template v-for="day in report.days" :key="day.date">
                            <tr
                                class="cursor-pointer border-b border-zinc-100 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/50"
                                :aria-expanded="expanded.has(day.date)"
                                @click="toggle(day.date)"
                            >
                                <td class="px-4 py-2 font-sans text-sm">
                                    <span class="mr-1 text-zinc-400">{{
                                        expanded.has(day.date) ? '▾' : '▸'
                                    }}</span>
                                    {{ day.date }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ day.generations }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ money(day.charged) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ money(day.provider_cost) }}
                                </td>
                                <td
                                    :class="
                                        cn(
                                            'px-4 py-2 text-right',
                                            day.pixelforge?.matches === false &&
                                                'text-amber-700 dark:text-amber-300',
                                        )
                                    "
                                    :title="
                                        day.pixelforge &&
                                        !day.pixelforge.matches
                                            ? `Pixelforge: ${day.pixelforge.generations} generations. We recorded ${money(day.pixelforge.difference)} more.`
                                            : undefined
                                    "
                                >
                                    <template v-if="day.pixelforge">
                                        {{ money(day.pixelforge.cost) }}
                                        {{ day.pixelforge.matches ? '✓' : '⚠' }}
                                    </template>
                                    <span v-else class="text-zinc-400">—</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ money(day.profit) }}
                                </td>
                            </tr>
                            <tr
                                v-for="row in expanded.has(day.date)
                                    ? day.users
                                    : []"
                                :key="`${day.date}-${row.user?.id}`"
                                class="border-b border-zinc-100 bg-zinc-50/60 text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-400"
                            >
                                <td class="py-1.5 pr-4 pl-10 font-sans text-xs">
                                    {{ userLabel(row.user) }}
                                </td>
                                <td class="px-4 py-1.5 text-right">
                                    {{ row.generations }}
                                </td>
                                <td class="px-4 py-1.5 text-right">
                                    {{ money(row.charged) }}
                                </td>
                                <td class="px-4 py-1.5 text-right">
                                    {{ money(row.provider_cost) }}
                                </td>
                                <td class="px-4 py-1.5" />
                                <td class="px-4 py-1.5 text-right">
                                    {{ money(row.profit) }}
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">By user</h2>
            <p
                v-if="report.users.length === 0"
                class="mt-3 text-sm text-zinc-500"
            >
                No users had completed generations in this range.
            </p>
            <div
                v-else
                class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
            >
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500">
                        <tr
                            class="border-b border-zinc-200 dark:border-zinc-800"
                        >
                            <th class="px-4 py-2 font-medium">User</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Generations
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Charged
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs tabular-nums">
                        <tr
                            v-for="row in report.users"
                            :key="row.user?.id"
                            class="border-b border-zinc-100 last:border-0 dark:border-zinc-800"
                        >
                            <td class="px-4 py-2 font-sans text-sm">
                                {{ userLabel(row.user) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ row.generations }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ money(row.charged) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ money(row.provider_cost) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ money(row.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
