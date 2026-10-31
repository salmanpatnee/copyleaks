<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center gap-6 bg-background p-6 md:p-10"
    >
        <Link :href="home()" class="flex items-center gap-2 font-medium">
            <AppLogoIcon class="size-8 fill-current text-foreground" />
            <span>{{ appName }}</span>
        </Link>
        <main class="w-full max-w-xl">
            <slot />
        </main>
    </div>
</template>
