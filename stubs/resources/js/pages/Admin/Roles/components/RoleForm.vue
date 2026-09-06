<!-- resources/js/components/Admin/RoleForm.vue -->
<script setup lang="ts">
    import adminRoles from '@/routes/roles';
    import { useForm } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { Button, Checkbox, Message } from 'primevue';
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import { FB } from '@lvntr/components/FormBuilder/core';
    import { TB } from '@lvntr/components/TabBuilder/core';
    import { usePageHeader } from '@/composables/usePageHeader';

    // Aura + geri-butonlu sayfa: başlık topbar'da; geri butonu sekmelerin
    // üstünde kendi başlıksız kartında gösterilir (bkz. template).
    const pageHeader = usePageHeader();

    interface PermissionGroup {
        label: string;
        resources: Record<string, string[]>;
    }

    interface Role {
        id?: number;
        name: string;
        display_name?: Record<string, string>;
        color?: string | null;
        permissions: string[];
    }

    interface Props {
        role?: Role | null;
        permissionsByGroup?: Record<string, PermissionGroup>;
        availableLocales?: Record<string, string>;
        userPermissions?: string[] | null;
        inDialog?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        role: null,
        permissionsByGroup: () => ({}),
        availableLocales: () => ({}),
        userPermissions: null,
        inDialog: false,
    });

    /** null = system_admin, can grant everything */
    const canGrantAll = computed(() => props.userPermissions === null);

    function canGrant(permission: string): boolean {
        if (canGrantAll.value) return true;
        return props.userPermissions!.includes(permission);
    }

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
        color: props.role?.color ?? '',
        permissions: props.role?.permissions ?? ([] as string[]),
    });

    // ── Basic info via FormBuilder (external v-model mode — submit stays here) ──

    const basics = ref<Record<string, unknown>>({
        name: form.name,
        display_name: { ...form.display_name },
        color: form.color,
    });

    const basicsConfig = FB.form()
        .layout('vertical')
        .cols(3)
        .isCard(false)
        .addFields(
            FB.inputText()
                .key('name')
                .label('validation.attributes.role_name')
                .placeholder('sk-role.role_name_placeholder')
                .required(),
            FB.translatableText().key('display_name').label('validation.attributes.display_name').required(),
            FB.colorSelector()
                .key('color')
                .label('sk-role.color')
                .format('name')
                .hint('sk-role.color_hint')
                .required(),
        )
        .build();

    // İzinler ilk, temel bilgiler ikinci sekme.
    const activeTab = ref('permissions');

    const tabConfig = TB.tabs()
        .vertical()
        .addTabs(
            TB.item()
                .key('permissions')
                .label('sk-role.permissions')
                .description('sk-role.permissions_description')
                .icon('pi pi-shield')
                .iconColor('indigo'),
            TB.item()
                .key('basic_info')
                .label('sk-role.basic_info')
                .description('sk-role.basic_info_description')
                .icon('pi pi-id-card')
                .iconColor('blue'),
        )
        .build();

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

    // Vertical SkTabs mounts only the active panel — a validation error on the
    // inactive tab's fields would otherwise render nowhere and fail silently.
    function focusErroredTab(errors: Record<string, string>) {
        const hasBasicInfoError = Object.keys(errors).some(
            (key) => key === 'name' || key === 'color' || key.startsWith('display_name'),
        );
        if (hasBasicInfoError) {
            activeTab.value = 'basic_info';
        } else if (errors.permissions) {
            activeTab.value = 'permissions';
        }
    }

    function submit() {
        form.name = basics.value.name as string;
        form.display_name = basics.value.display_name as Record<string, string>;
        form.color = (basics.value.color as string) ?? '';

        if (isEdit.value) {
            form.put(adminRoles.update.url({ id: props.role!.id! }), {
                onSuccess: () => emit('success'),
                onError: focusErroredTab,
            });
        } else {
            form.post(adminRoles.store.url(), {
                onSuccess: () => emit('success'),
                onError: focusErroredTab,
            });
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
        // Split on the LAST dot: most permissions are "resource.ability" (one
        // dot), but "system.health.view" has two — the ability is only the
        // final segment.
        const parts = permission.split('.');
        return parts[parts.length - 1];
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
        const key = `sk-role.resources.${resource}`;
        const translated = trans(key);
        return translated !== key ? translated : resource.charAt(0).toUpperCase() + resource.slice(1);
    }

    function translateAbility(ability: string): string {
        const key = `sk-role.abilities.${ability}`;
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
        reset: () => {
            form.reset();
            basics.value = { name: form.name, display_name: { ...form.display_name }, color: form.color };
        },
    });
</script>

<template>
  <form
    class="space-y-6"
    @submit.prevent="submit"
  >
    <!-- Aura + header-in-card: sayfanın geri butonu, hangi sekme aktif olursa
         olsun sabit kalsın diye sekmelerin ÜSTÜNDE, tek başına duruyor. -->
    <SkCard
      v-if="pageHeader.active"
      flush
    >
      <template #actions>
        <Button
          icon="pi pi-arrow-left"
          :label="trans('sk-button.back')"
          severity="secondary"
          variant="outlined"
          @click="pageHeader.goBack"
        />
      </template>
    </SkCard>

    <SkTabs
      v-model="activeTab"
      :config="tabConfig"
    >
      <template #permissions>
        <SkCard
          :title="trans('sk-role.permissions')"
          :subtitle="trans('sk-role.permissions_description')"
          flush
        >
          <template #title-end>
            <span class="text-xs font-medium text-surface-400 dark:text-surface-500 whitespace-nowrap">
              {{
                trans('sk-role.permission_count', {
                  selected: String(form.permissions.length),
                  total: String(allPermissionsFlat.length),
                })
              }}
            </span>
          </template>
          <template #content>
            <!-- Permissions Table -->
            <div v-if="hasPermissions">
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="bg-surface-50 dark:bg-surface-800/60">
                      <th
                        class="px-4 py-3 text-left align-bottom text-xs font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500 min-w-40"
                      >
                        {{ trans('sk-role.resource') }}
                      </th>
                      <th class="px-2 py-3 text-center min-w-15">
                        <div class="flex flex-col items-center gap-1.5">
                          <span
                            class="text-xs font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500"
                          >
                            {{ trans('sk-common.all') }}
                          </span>
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
                        </div>
                      </th>
                      <th
                        v-for="ability in allAbilities"
                        :key="ability"
                        class="px-2 py-3 text-center min-w-20"
                      >
                        <div class="flex flex-col items-center gap-1.5">
                          <span
                            class="text-xs font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500"
                          >
                            {{ translateAbility(ability) }}
                          </span>
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
                    <template
                      v-for="(group, groupKey) in permissionsByGroup"
                      :key="groupKey"
                    >
                      <!-- Group Header Row -->
                      <tr class="border-t border-surface-200 dark:border-surface-700 bg-surface-50/60 dark:bg-surface-800/40">
                        <td
                          class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400"
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
                        <td
                          v-for="ability in allAbilities"
                          :key="ability"
                        />
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
                          <span
                            v-else
                            class="text-surface-300 dark:text-surface-700"
                          >—</span>
                        </td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>

              <small
                v-if="form.errors.permissions"
                class="text-red-500 mt-1 block px-6 pb-2"
              >
                {{ form.errors.permissions }}
              </small>
            </div>

            <Message
              v-else
              severity="info"
              :closable="false"
              class="m-6"
            >
              {{ trans('sk-role.no_permissions_available') }}
            </Message>
          </template>
        </SkCard>
      </template>

      <template #basic_info>
        <SkCard
          :title="trans('sk-role.basic_info')"
          :subtitle="trans('sk-role.basic_info_description')"
        >
          <template #content>
            <SkForm
              v-model="basics"
              :config="basicsConfig"
              :errors="form.errors as Record<string, string>"
            />
          </template>
        </SkCard>
      </template>
    </SkTabs>

    <!-- İki sekmenin de tek ortak kaydet aksiyonu — sekme değişse de sabit kalır. -->
    <div class="flex justify-end">
      <Button
        type="submit"
        :label="isEdit ? trans('sk-button.update') : trans('sk-button.save')"
        icon="pi pi-save"
        :loading="form.processing"
      />
    </div>
  </form>
</template>
