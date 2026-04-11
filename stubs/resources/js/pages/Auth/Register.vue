<script setup lang="ts">
    import { useForm } from '@inertiajs/vue3';
    import AuthLayout from '@/layouts/AuthLayout.vue';

    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = () => {
        form.post('/register', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };
</script>

<template>
    <AuthLayout :title="$t('auth.register.title')">
        <template #header>
            <h2 class="auth-title">
                {{ $t('auth.register.heading') }}
            </h2>
            <p class="auth-subtitle">
                {{ $t('auth.register.subtitle') }}
            </p>
        </template>

        <form class="auth-form" @submit.prevent="submit">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="auth-form__field">
                    <label for="first_name" class="auth-form__label">{{ $t('auth.register.first_name_label') }}</label>
                    <IconField>
                        <InputIcon class="pi pi-user" />
                        <InputText
                            id="first_name"
                            v-model="form.first_name"
                            type="text"
                            :placeholder="$t('auth.register.first_name_placeholder')"
                            :invalid="!!form.errors.first_name"
                            :aria-describedby="form.errors.first_name ? 'first_name-error' : undefined"
                            autocomplete="first_name"
                            autofocus
                            fluid
                        />
                    </IconField>
                    <small v-if="form.errors.first_name" id="first_name-error" class="auth-form__error">
                        {{ form.errors.first_name }}
                    </small>
                </div>
                <div class="auth-form__field">
                    <label for="last_name" class="auth-form__label">{{ $t('auth.register.last_name_label') }}</label>
                    <IconField>
                        <InputIcon class="pi pi-user" />
                        <InputText
                            id="last_name"
                            v-model="form.last_name"
                            type="text"
                            :placeholder="$t('auth.register.last_name_placeholder')"
                            :invalid="!!form.errors.last_name"
                            :aria-describedby="form.errors.last_name ? 'last_name-error' : undefined"
                            autocomplete="last_name"
                            autofocus
                            fluid
                        />
                    </IconField>
                    <small v-if="form.errors.last_name" id="last_name-error" class="auth-form__error">
                        {{ form.errors.last_name }}
                    </small>
                </div>
            </div>

            <!-- Email -->
            <div class="auth-form__field">
                <label for="email" class="auth-form__label">{{ $t('auth.register.email_label') }}</label>
                <IconField>
                    <InputIcon class="pi pi-envelope" />
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        :placeholder="$t('auth.register.email_placeholder')"
                        :invalid="!!form.errors.email"
                        :aria-describedby="form.errors.email ? 'email-error' : undefined"
                        autocomplete="email"
                        fluid
                    />
                </IconField>
                <small v-if="form.errors.email" id="email-error" class="auth-form__error">
                    {{ form.errors.email }}
                </small>
            </div>

            <!-- Password -->
            <div class="auth-form__field">
                <label for="password" class="auth-form__label">{{ $t('auth.register.password_label') }}</label>
                <IconField>
                    <InputIcon class="pi pi-lock" />
                    <Password
                        id="password"
                        v-model="form.password"
                        :invalid="!!form.errors.password"
                        :aria-describedby="form.errors.password ? 'password-error' : undefined"
                        autocomplete="new-password"
                        toggle-mask
                        fluid
                    />
                </IconField>
                <small v-if="form.errors.password" id="password-error" class="auth-form__error">
                    {{ form.errors.password }}
                </small>
            </div>

            <!-- Password Confirmation -->
            <div class="auth-form__field">
                <label for="password_confirmation" class="auth-form__label">
                    {{ $t('auth.register.password_confirmation_label') }}
                </label>
                <IconField>
                    <InputIcon class="pi pi-lock" />
                    <Password
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :invalid="!!form.errors.password_confirmation"
                        :aria-describedby="
                            form.errors.password_confirmation ? 'password-confirmation-error' : undefined
                        "
                        :feedback="false"
                        autocomplete="new-password"
                        toggle-mask
                        fluid
                    />
                </IconField>
                <small
                    v-if="form.errors.password_confirmation"
                    id="password-confirmation-error"
                    class="auth-form__error"
                >
                    {{ form.errors.password_confirmation }}
                </small>
            </div>

            <!-- Submit -->
            <Button
                type="submit"
                :label="$t('auth.register.submit')"
                icon="pi pi-user-plus"
                :loading="form.processing"
                class="auth-form__submit"
            />
        </form>

        <template #footer>
            <span>{{ $t('auth.register.has_account') }}</span>
            {{ ' ' }}
            <a href="/login" class="auth-link" @click.prevent="$inertia.visit('/login')">
                {{ $t('auth.register.sign_in') }}
            </a>
        </template>
    </AuthLayout>
</template>
