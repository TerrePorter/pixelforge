<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import TextField from '@/components/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
</script>

<template>
    <AuthLayout
        title="Log in"
        description="Welcome back. Log in to generate images."
    >
        <Form
            v-bind="store.form()"
            :reset-on-error="['password']"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <TextField
                label="Email"
                name="email"
                type="email"
                autocomplete="email"
                autofocus
                :error="errors.email"
            />
            <TextField
                label="Password"
                name="password"
                type="password"
                autocomplete="current-password"
                :error="errors.password"
            />
            <label class="flex items-center gap-2 text-sm text-zinc-600">
                <input type="checkbox" name="remember" value="1" />
                Remember me
            </label>
            <button
                type="submit"
                :disabled="processing"
                class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
                {{ processing ? 'Logging in…' : 'Log in' }}
            </button>
        </Form>

        <template #footer>
            No account yet?
            <Link
                :href="register()"
                class="font-medium text-zinc-900 underline dark:text-zinc-100"
            >
                Create one
            </Link>
        </template>
    </AuthLayout>
</template>
