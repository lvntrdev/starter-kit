<script setup lang="ts">
    import { ref } from 'vue';
    import { useForm } from '@inertiajs/vue3';
    import AuthLayout from '@/layouts/AuthLayout.vue';
    import TurnstileWidget from '@/components/Auth/TurnstileWidget.vue';

    interface Props {
        status?: string;
    }

    const props = defineProps<Props>();
    const turnstileRef = ref<InstanceType<typeof TurnstileWidget>>();

    const form = useForm({
        email: '',
        cf_turnstile_response: '',
    });

    const submit = () => {
        form.post('/forgot-password', {
            onFinish: () => turnstileRef.value?.reset(),
        });
    };
</script>

<template>
    <AuthLayout title="Forgot Password">
        <template #header>
            <h2 class="auth-title">
                Forgot Password
            </h2>
            <p class="auth-subtitle">
                Enter your email address and we will send you a password reset link.
            </p>
        </template>

        <!-- Status Message -->
        <div v-if="props.status" class="auth-status">
            {{ props.status }}
        </div>

        <form class="auth-form" @submit.prevent="submit">
            <!-- Email -->
            <div class="auth-form__field">
                <label for="email" class="auth-form__label"> Email </label>
                <IconField>
                    <InputIcon class="pi pi-envelope" />
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        placeholder="example@email.com"
                        :invalid="!!form.errors.email"
                        autocomplete="email"
                        autofocus
                        fluid
                    />
                </IconField>
                <small v-if="form.errors.email" class="auth-form__error">
                    {{ form.errors.email }}
                </small>
            </div>

            <!-- Turnstile -->
            <TurnstileWidget ref="turnstileRef" v-model="form.cf_turnstile_response" />
            <small v-if="form.errors.cf_turnstile_response" class="auth-form__error">
                {{ form.errors.cf_turnstile_response }}
            </small>

            <!-- Submit -->
            <Button
                type="submit"
                label="Send Reset Link"
                icon="pi pi-envelope"
                :loading="form.processing"
                class="auth-form__submit"
            />
        </form>

        <template #footer>
            <a href="/login" class="auth-link" @click.prevent="$inertia.visit('/login')"> Back to sign in </a>
        </template>
    </AuthLayout>
</template>
