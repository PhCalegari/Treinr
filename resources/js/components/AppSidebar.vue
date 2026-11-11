<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Apple,
    Bug,
    ClipboardList,
    Dumbbell,
    Heart,
    LayoutGrid,
    Target,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { errors as adminErrors } from '@/routes/admin';
import { index as adminUsers } from '@/routes/admin/users';
import { index as diaryIndex } from '@/routes/diary';
import { index as exercisesIndex } from '@/routes/exercises';
import { index as goalsIndex } from '@/routes/goals';
import { index as progressIndex } from '@/routes/progress';
import { index as workoutsIndex } from '@/routes/workouts';
import { useSupportPrompt } from '@/composables/useSupportPrompt';
import type { NavItem } from '@/types';

const page = usePage();
const support = useSupportPrompt();

// O item de admin só aparece para administradores; a permissão real é checada no servidor.
const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Início', href: dashboard(), icon: LayoutGrid },
    { title: 'Treinos', href: workoutsIndex(), icon: ClipboardList },
    { title: 'Exercícios', href: exercisesIndex(), icon: Dumbbell },
    { title: 'Alimentação', href: diaryIndex(), icon: Apple },
    { title: 'Metas', href: goalsIndex(), icon: Target },
    { title: 'Evolução', href: progressIndex(), icon: TrendingUp },
    ...(page.props.auth.user?.role === 'admin'
        ? [
              {
                  title: 'Usuários',
                  href: adminUsers(),
                  icon: Users,
                  badge: page.props.pendingUsers || undefined,
              },
              { title: 'Erros do servidor', href: adminErrors(), icon: Bug },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu v-if="page.props.supportEnabled">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        tooltip="Apoiar o Treinr"
                        @click="support.show()"
                    >
                        <Heart class="text-primary" />
                        <span>Apoiar o Treinr</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
