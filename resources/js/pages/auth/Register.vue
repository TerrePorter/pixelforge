<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import TextField from '@/components/TextField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
</script>

<template>
    <AuthLayout
        title="Create an account"
        description="Sign up to start generating images."
    >
        <Form
            v-bind="store.form()"
            :reset-on-error="['password', 'password_confirmation']"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <TextField
                label="Name"
                name="name"
                autocomplete="name"
                autofocus
                :error="errors.name"
            />
            <TextField
                label="Email"
                name="email"
                type="email"
                autocomplete="email"
                :error="errors.email"
            />
            <TextField
                label="Password"
                name="password"
                type="password"
                autocomplete="new-password"
                :error="errors.password"
            />
            <TextField
                label="Confirm password"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                :error="errors.password_confirmation"
            />
            <button
                type="submit"
                :disabled="processing"
                class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300"
            >
                {{ processing ? 'Creating account…' : 'Create account' }}
            </button>
        </Form>

        <template #footer>
            Already have an account?
            <Link
                :href="login()"
                class="font-medium text-zinc-900 underline dark:text-zinc-100"
            >
                Log in
            </Link>
        </template>
    </AuthLayout>
</template>
