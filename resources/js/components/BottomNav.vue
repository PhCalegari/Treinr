<script setup lang="ts">
// Navegação inferior no celular (no computador fica a barra lateral).
// Escondida durante o treino, que tem a própria barra com o cronômetro.
import { Link, usePage } from '@inertiajs/vue3';
import { Apple, House, Menu, TrendingUp, Dumbbell } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as diaryIndex } from '@/routes/diary';
import { index as progressIndex } from '@/routes/progress';
import { index as workoutsIndex } from '@/routes/workouts';

const page = usePage();
const { setOpenMobile } = useSidebar();
const path = computed(() => new URL(page.url, 'http://x').pathname);
// Escondida no treino em andamento e no "criar treino com IA" (essas telas têm barra própria embaixo).
const hidden = computed(() => /^\/sessions\/[^/]+$/.test(path.value) || path.value === '/workouts/ai');

const items = [
    { label: 'Início', href: dashboard(), icon: House, match: ['/dashboard'] },
    { label: 'Treinos', href: workoutsIndex(), icon: Dumbbell, match: ['/workouts', '/sessions', '/exercises'] },
    { label: 'Alimentação', href: diaryIndex(), icon: Apple, match: ['/diary', '/goals'] },
    { label: 'Evolução', href: progressIndex(), icon: TrendingUp, match: ['/progress'] },
];
const isActive = (match: string[]) => match.some((m) => path.value === m || path.value.startsWith(m + '/'));
</script>

<template>
    <!-- Barra flutuante e arredondada; o item ativo fica numa cápsula. -->
    <nav
        v-if="!hidden"
        class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-40 rounded-[1.75rem] border border-sidebar-border bg-sidebar/90 p-1.5 shadow-[0_10px_30px_-10px_rgba(0,0,0,0.6)] backdrop-blur-md md:hidden"
        aria-label="Navegação principal"
    >
        <ul class="grid grid-cols-5 gap-1">
            <li v-for="item in items" :key="item.label">
                <Link
                    :href="item.href"
                    class="font-display flex flex-col items-center gap-1 rounded-[1.25rem] py-2 text-[10px] leading-tight font-semibold tracking-tight transition-colors"
                    :class="isActive(item.match) ? 'bg-sidebar-accent text-primary' : 'text-sidebar-foreground/75 hover:text-sidebar-foreground'"
                    :aria-current="isActive(item.match) ? 'page' : undefined"
                >
                    <component :is="item.icon" class="size-5" />
                    {{ item.label }}
                </Link>
            </li>
            <li>
                <button
                    type="button"
                    class="font-display flex w-full flex-col items-center gap-1 rounded-[1.25rem] py-2 text-[10px] leading-tight font-semibold tracking-tight text-sidebar-foreground/75 hover:text-sidebar-foreground"
                    @click="setOpenMobile(true)"
                >
                    <Menu class="size-5" />
                    Mais
                </button>
            </li>
        </ul>
    </nav>
</template>
