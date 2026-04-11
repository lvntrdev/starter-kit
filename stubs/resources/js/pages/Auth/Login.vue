<script setup lang="ts">
    import { Link, useForm, usePage } from '@inertiajs/vue3';
    import AuthLayout from '@/layouts/AuthLayout.vue';

    interface Props {
        status?: string;
    }

    const props = defineProps<Props>();
    const page = usePage<{ features: { registration: boolean; password_reset: boolean } }>();

    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = () => {
        form.post('/login', {
            onFinish: () => form.reset('password'),
        });
    };
</script>

<template>
    <AuthLayout :title="$t('auth.login.title')">
        <template #header>
            <h2 class="auth-title">
                {{ $t('auth.login.heading') }}
            </h2>
            <p class="auth-subtitle">
                {{ $t('auth.login.subtitle') }}
            </p>
        </template>

        <!-- Status Message -->
        <div v-if="props.status" class="auth-status">
            {{ props.status }}
        </div>

        <form class="auth-form" @submit.prevent="submit">
            <!-- Email -->
            <div class="auth-form__field">
                <label for="email" class="auth-form__label">{{ $t('auth.login.email_label') }}</label>
                <IconField class="auth-input">
                    <InputIcon class="auth-input__icon pi pi-envelope" />
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        :placeholder="$t('auth.login.email_placeholder')"
                        :invalid="!!form.errors.email"
                        :aria-describedby="form.errors.email ? 'email-error' : undefined"
                        autocomplete="email"
                        autofocus
                        fluid
                        class="auth-input__control"
                    />
                </IconField>
                <small v-if="form.errors.email" id="email-error" class="auth-form__error">
                    {{ form.errors.email }}
                </small>
            </div>

            <!-- Password -->
            <div class="auth-form__field">
                <div class="auth-form__row">
                    <label for="password" class="auth-form__label">{{ $t('auth.login.password_label') }}</label>
                    <Link v-if="page.props.features.password_reset" href="/forgot-password" class="auth-link">
                        {{ $t('auth.login.forgot_password_link') }}
                    </Link>
                </div>
                <IconField class="auth-input auth-input--password">
                    <InputIcon class="auth-input__icon pi pi-lock" />
                    <Password
                        id="password"
                        v-model="form.password"
                        :invalid="!!form.errors.password"
                        :aria-describedby="form.errors.password ? 'password-error' : undefined"
                        :feedback="false"
                        autocomplete="current-password"
                        toggle-mask
                        fluid
                        input-class="auth-input__control auth-input__control--password"
                        class="auth-password"
                    />
                </IconField>
                <small v-if="form.errors.password" id="password-error" class="auth-form__error">
                    {{ form.errors.password }}
                </small>
            </div>

            <!-- Remember -->
            <div class="auth-form__options">
                <div class="auth-remember">
                    <Checkbox v-model="form.remember" input-id="remember" :binary="true" />
                    <label for="remember" class="auth-remember__label">{{ $t('auth.login.remember') }}</label>
                </div>
            </div>

            <!-- Submit -->
            <Button
                type="submit"
                :label="$t('auth.login.submit')"
                icon="pi pi-arrow-right"
                icon-pos="right"
                :loading="form.processing"
                class="auth-form__submit"
            />
        </form>

        <template v-if="page.props.features.registration" #footer>
            <span>{{ $t('auth.login.no_account') }}</span>
            {{ ' ' }}
            <Link href="/register" class="auth-link">
                {{ $t('auth.login.create_account') }}
            </Link>
        </template>
    </AuthLayout>
</template>
