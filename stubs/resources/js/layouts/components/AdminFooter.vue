<!-- resources/js/components/Admin/AdminFooter.vue -->
<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';

    const appName = computed(() => usePage().props.appName as string);
    const year = new Date().getFullYear();
</script>

<template>
    <footer class="admin-footer">
        <span>&copy; {{ year }} {{ appName }}</span>
        <span class="admin-footer-credit">
            Crafted with
            <a
                href="https://lvntr.dev"
                target="_blank"
                rel="noopener"
                class="admin-footer-link"
            >
                Lvntr Starter Kit
                <i class="pi pi-arrow-up-right" style="font-size: 0.65rem" />
            </a>
        </span>
    </footer>
</template>

<style scoped>
    .admin-footer-credit {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .admin-footer-link {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-weight: 600;
        color: var(--p-primary-600);
        text-decoration: none;
        transition: color 150ms ease;
    }
    .admin-footer-link:hover {
        color: var(--p-primary-500);
    }
    :global(.dark) .admin-footer-link {
        color: var(--p-primary-300);
    }
</style>
