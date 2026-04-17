<!-- resources/js/components/Admin/RoleForm.vue -->
<script setup lang="ts">
    import adminRoles from '@/routes/roles';
    import { router, useForm } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { Button, Card, Checkbox, InputText, Message } from 'primevue';

    interface PermissionGroup {
        label: string;
        resources: Record<string, string[]>;
    }

    interface Role {
        id?: number;
        name: string;
        display_name?: Record<string, string>;
        permissions: string[];
    }

    interface Props {
        role?: Role | null;
        permissionsByGroup?: Record<string, PermissionGroup>;
        availableLocales?: Record<string, string>;
        userPermissions?: string[] | null;
        inDialog?: boolean;
        showBack?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        role: null,
        permissionsByGroup: () => ({}),
        availableLocales: () => ({}),
        userPermissions: null,
        inDialog: false,
        showBack: false,
    });

    /** null = system_admin, can grant everything */
    const canGrantAll = computed(() => props.userPermissions === null);

    function canGrant(permission: string): boolean {
        if (canGrantAll.value) return true;
        return props.userPermissions!.includes(permission);
    }

    const showCancelButton = computed(() => props.inDialog || props.showBack);

    const emit = defineEmits<{
        success: [];
        cancel: [];
    }>();

    const isEdit = computed(() => !!props.role);

    const form = useForm({
        name: props.role?.name ?? '',
        display_name: Object.fromEntries(
            Object.keys(props.availableLocales).map((locale) => [locale, props.role?.display_name?.[locale] ?? '']),
        ),
        permissions: props.role?.permissions ?? ([] as string[]),
    });

    // Flatten all permissions across all groups for global operations
    const allPermissionsFlat = computed(() => {
        const all: string[] = [];
        for (const group of Object.values(props.permissionsByGroup)) {
            for (const permissions of Object.values(group.resources)) {
                all.push(...permissions);
            }
        }
        return all;
    });

    // Flatten all resources across all groups into a single lookup
    const allResourcePermissions = computed(() => {
        const map: Record<string, string[]> = {};
        for (const group of Object.values(props.permissionsByGroup)) {
            for (const [resource, permissions] of Object.entries(group.resources)) {
                map[resource] = permissions;
            }
        }
        return map;
    });

    function submit() {
        if (isEdit.value) {
            form.put(adminRoles.update.url({ id: props.role!.id! }), {
                onSuccess: () => emit('success'),
            });
        } else {
            form.post(adminRoles.store.url(), {
                onSuccess: () => emit('success'),
            });
        }
    }

    function cancel() {
        if (props.inDialog) {
            emit('cancel');
        } else {
            router.visit('/admin/roles');
        }
    }

    function togglePermission(permission: string) {
        if (!canGrant(permission)) return;
        const index = form.permissions.indexOf(permission);
        if (index === -1) {
            form.permissions.push(permission);
        } else {
            form.permissions.splice(index, 1);
        }
    }

    function isChecked(permission: string): boolean {
        return form.permissions.includes(permission);
    }

    function toggleResource(resource: string) {
        const permissions = (allResourcePermissions.value[resource] ?? []).filter(canGrant);
        if (permissions.length === 0) return;
        const allChecked = permissions.every((p) => form.permissions.includes(p));

        if (allChecked) {
            form.permissions = form.permissions.filter((p) => !permissions.includes(p));
        } else {
            const toAdd = permissions.filter((p) => !form.permissions.includes(p));
            form.permissions.push(...toAdd);
        }
    }

    function isResourceAllChecked(resource: string): boolean {
        const permissions = allResourcePermissions.value[resource] ?? [];
        return permissions.length > 0 && permissions.every((p) => form.permissions.includes(p));
    }

    function isResourcePartiallyChecked(resource: string): boolean {
        const permissions = allResourcePermissions.value[resource] ?? [];
        const checkedCount = permissions.filter((p) => form.permissions.includes(p)).length;
        return checkedCount > 0 && checkedCount < permissions.length;
    }

    function abilityFromPermission(permission: string): string {
        const parts = permission.split('.');
        return parts.length > 1 ? parts[1] : parts[0];
    }

    // Collect all unique abilities across all resources (for table columns)
    const allAbilities = computed(() => {
        const abilities = new Set<string>();
        for (const p of allPermissionsFlat.value) {
            abilities.add(abilityFromPermission(p));
        }
        return Array.from(abilities);
    });

    // Check if a specific resource has a specific ability permission
    function hasPermission(resource: string, ability: string): boolean {
        const permissions = allResourcePermissions.value[resource] ?? [];
        return permissions.includes(`${resource}.${ability}`);
    }

    function translateResource(resource: string): string {
        const key = `admin.roles.resources.${resource}`;
        const translated = trans(key);
        return translated !== key ? translated : resource.charAt(0).toUpperCase() + resource.slice(1);
    }

    function translateAbility(ability: string): string {
        const key = `admin.roles.abilities.${ability}`;
        const translated = trans(key);
        return translated !== key ? translated : ability.charAt(0).toUpperCase() + ability.slice(1);
    }

    // Toggle entire column (ability across all resources)
    function toggleAbilityColumn(ability: string) {
        const resources = Object.keys(allResourcePermissions.value);
        const relevantPermissions = resources
            .filter((r) => hasPermission(r, ability))
            .map((r) => `${r}.${ability}`)
            .filter(canGrant);

        if (relevantPermissions.length === 0) return;
        const allChecked = relevantPermissions.every((p) => form.permissions.includes(p));

        if (allChecked) {
            form.permissions = form.permissions.filter((p) => !relevantPermissions.includes(p));
        } else {
            const toAdd = relevantPermissions.filter((p) => !form.permissions.includes(p));
            form.permissions.push(...toAdd);
        }
    }

    function isAbilityColumnAllChecked(ability: string): boolean {
        const resources = Object.keys(allResourcePermissions.value);
        const relevantPermissions = resources.filter((r) => hasPermission(r, ability)).map((r) => `${r}.${ability}`);
        return relevantPermissions.length > 0 && relevantPermissions.every((p) => form.permissions.includes(p));
    }

    function isAbilityColumnPartiallyChecked(ability: string): boolean {
        const resources = Object.keys(allResourcePermissions.value);
        const relevantPermissions = resources.filter((r) => hasPermission(r, ability)).map((r) => `${r}.${ability}`);
        const checkedCount = relevantPermissions.filter((p) => form.permissions.includes(p)).length;
        return checkedCount > 0 && checkedCount < relevantPermissions.length;
    }

    // Toggle all permissions in a group
    function toggleGroup(groupKey: string) {
        const group = props.permissionsByGroup[groupKey];
        if (!group) return;

        const groupPermissions = Object.values(group.resources).flat().filter(canGrant);
        if (groupPermissions.length === 0) return;
        const allChecked = groupPermissions.every((p) => form.permissions.includes(p));

        if (allChecked) {
            form.permissions = form.permissions.filter((p) => !groupPermissions.includes(p));
        } else {
            const toAdd = groupPermissions.filter((p) => !form.permissions.includes(p));
            form.permissions.push(...toAdd);
        }
    }

    function isGroupAllChecked(groupKey: string): boolean {
        const group = props.permissionsByGroup[groupKey];
        if (!group) return false;
        const groupPermissions = Object.values(group.resources).flat();
        return groupPermissions.length > 0 && groupPermissions.every((p) => form.permissions.includes(p));
    }

    function isGroupPartiallyChecked(groupKey: string): boolean {
        const group = props.permissionsByGroup[groupKey];
        if (!group) return false;
        const groupPermissions = Object.values(group.resources).flat();
        const checkedCount = groupPermissions.filter((p) => form.permissions.includes(p)).length;
        return checkedCount > 0 && checkedCount < groupPermissions.length;
    }

    const hasPermissions = computed(() => Object.keys(props.permissionsByGroup).length > 0);

    defineExpose({
        reset: () => form.reset(),
    });
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <Card>
            <template #content>
                <!-- Role Name -->
                <div>
                    <label for="role-name" class="block font-bold text-surface-700 dark:text-surface-300 mb-2">
                        {{ trans('sk-attribute.attributes.role_name') }}
                    </label>
                    <InputText
                        id="role-name"
                        v-model="form.name"
                        :placeholder="trans('sk-role.role_name_placeholder')"
                        class="w-full"
                        :invalid="!!form.errors.name"
                    />
                    <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                </div>

                <!-- Display Names per Locale -->
                <div v-if="Object.keys(availableLocales).length > 0" class="mt-6">
                    <label class="block font-bold text-surface-700 dark:text-surface-300 mb-2">
                        {{ trans('sk-attribute.attributes.display_name') }}
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div v-for="(label, locale) in availableLocales" :key="locale">
                            <label
                                :for="`display-name-${locale}`"
                                class="block font-medium text-surface-500 dark:text-surface-400 mb-1 uppercase"
                            >
                                {{ label }}
                            </label>
                            <InputText
                                :id="`display-name-${locale}`"
                                v-model="form.display_name[locale]"
                                :placeholder="`${trans('sk-role.display_name')} (${label})`"
                                class="w-full"
                                :invalid="!!form.errors[`display_name.${locale}`]"
                            />
                            <small v-if="form.errors[`display_name.${locale}`]" class="text-red-500">
                                {{ form.errors[`display_name.${locale}`] }}
                            </small>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <Card>
            <template #title>
                {{ trans('sk-role.permissions') }}
            </template>
            <template #content>
                <!-- Permissions Table -->
                <div v-if="hasPermissions">
                    <div class="overflow-x-auto rounded-lg border border-surface-200 dark:border-surface-700">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-surface-50 dark:bg-surface-800">
                                    <th class="px-4 py-3 text-left font-semibold uppercase text-surface-500 min-w-40">
                                        {{ trans('sk-role.resource') }}
                                    </th>
                                    <th class="px-2 py-3 text-center min-w-15">
                                        <Checkbox
                                            :model-value="
                                                form.permissions.length === allPermissionsFlat.length &&
                                                    allPermissionsFlat.length > 0
                                            "
                                            :binary="true"
                                            :indeterminate="
                                                form.permissions.length > 0 &&
                                                    form.permissions.length < allPermissionsFlat.length
                                            "
                                            :disabled="!canGrantAll && allPermissionsFlat.every((p) => !canGrant(p))"
                                            @update:model-value="
                                                () => {
                                                    const grantable = allPermissionsFlat.filter(canGrant);
                                                    const allChecked = grantable.every((p) =>
                                                        form.permissions.includes(p),
                                                    );
                                                    if (allChecked) {
                                                        form.permissions = form.permissions.filter(
                                                            (p) => !grantable.includes(p),
                                                        );
                                                    } else {
                                                        const toAdd = grantable.filter(
                                                            (p) => !form.permissions.includes(p),
                                                        );
                                                        form.permissions.push(...toAdd);
                                                    }
                                                }
                                            "
                                        />
                                    </th>
                                    <th
                                        v-for="ability in allAbilities"
                                        :key="ability"
                                        class="px-2 py-3 text-center font-semibold uppercase text-surface-500 min-w-20"
                                    >
                                        <div class="flex flex-col items-center gap-1">
                                            <span>{{ translateAbility(ability) }}</span>
                                            <Checkbox
                                                :model-value="isAbilityColumnAllChecked(ability)"
                                                :binary="true"
                                                :indeterminate="isAbilityColumnPartiallyChecked(ability)"
                                                :disabled="
                                                    !canGrantAll &&
                                                        Object.keys(allResourcePermissions)
                                                            .filter((r) => hasPermission(r, ability))
                                                            .every((r) => !canGrant(`${r}.${ability}`))
                                                "
                                                @update:model-value="toggleAbilityColumn(ability)"
                                            />
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(group, groupKey) in permissionsByGroup" :key="groupKey">
                                    <!-- Group Header Row -->
                                    <tr class="bg-surface-100 dark:bg-surface-700/50">
                                        <td
                                            class="px-4 py-2 font-semibold text-surface-900 dark:text-surface-100 uppercase tracking-wide"
                                        >
                                            {{ group.label }}
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <Checkbox
                                                :model-value="isGroupAllChecked(groupKey)"
                                                :binary="true"
                                                :indeterminate="isGroupPartiallyChecked(groupKey)"
                                                :disabled="
                                                    !canGrantAll &&
                                                        Object.values(group.resources)
                                                            .flat()
                                                            .every((p) => !canGrant(p))
                                                "
                                                @update:model-value="toggleGroup(groupKey)"
                                            />
                                        </td>
                                        <td v-for="ability in allAbilities" :key="ability" />
                                    </tr>
                                    <!-- Resource Rows -->
                                    <tr
                                        v-for="(permissions, resource) in group.resources"
                                        :key="resource"
                                        class="border-t border-surface-200 dark:border-surface-700 hover:bg-surface-50/50 dark:hover:bg-surface-800/30"
                                    >
                                        <td class="px-4 py-2.5 pl-8 font-medium text-surface-800 dark:text-surface-200">
                                            {{ translateResource(resource) }}
                                        </td>
                                        <td class="px-2 py-2.5 text-center">
                                            <Checkbox
                                                :model-value="isResourceAllChecked(resource)"
                                                :binary="true"
                                                :indeterminate="isResourcePartiallyChecked(resource)"
                                                :disabled="
                                                    !canGrantAll && permissions.every((p: string) => !canGrant(p))
                                                "
                                                @update:model-value="toggleResource(resource)"
                                            />
                                        </td>
                                        <td
                                            v-for="ability in allAbilities"
                                            :key="ability"
                                            class="px-2 py-2.5 text-center"
                                        >
                                            <Checkbox
                                                v-if="hasPermission(resource, ability)"
                                                :model-value="isChecked(`${resource}.${ability}`)"
                                                :binary="true"
                                                :disabled="!canGrant(`${resource}.${ability}`)"
                                                @update:model-value="togglePermission(`${resource}.${ability}`)"
                                            />
                                            <span v-else class="text-surface-300 dark:text-surface-700">—</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <small v-if="form.errors.permissions" class="text-red-500 mt-1 block">
                        {{ form.errors.permissions }}
                    </small>
                </div>

                <Message v-else severity="info" :closable="false">
                    {{ trans('sk-role.no_permissions_available') }}
                </Message>

                <!-- Actions -->
                <div class="flex justify-end gap-2 pt-4">
                    <Button
                        v-if="showCancelButton"
                        type="button"
                        :label="inDialog ? trans('sk-button.cancel') : trans('sk-button.back')"
                        :icon="inDialog ? undefined : 'pi pi-arrow-left'"
                        severity="secondary"
                        outlined
                        @click="cancel"
                    />
                    <Button
                        type="submit"
                        :label="isEdit ? trans('sk-button.update') : trans('sk-button.save')"
                        :icon="isEdit ? 'pi pi-save' : 'pi pi-save'"
                        :loading="form.processing"
                    />
                </div>
            </template>
        </Card>
    </form>
</template>
