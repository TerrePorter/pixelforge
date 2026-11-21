<script setup lang="ts">
defineProps<{
    label: string;
    name: string;
    type?: string;
    autocomplete?: string;
    error?: string;
    autofocus?: boolean;
}>();
</script>

<template>
    <label class="block">
        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {{ label }}
        </span>
        <input
            v-focus="autofocus ?? false"
            :name="name"
            :type="type ?? 'text'"
            :autocomplete="autocomplete"
            :aria-invalid="error ? true : undefined"
            required
            class="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm shadow-xs outline-none focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 aria-invalid:border-red-500 dark:border-zinc-700 dark:bg-zinc-900 dark:focus:ring-zinc-800"
        />
        <span v-if="error" class="mt-1 block text-sm text-red-600">
            {{ error }}
        </span>
    </label>
</template>
