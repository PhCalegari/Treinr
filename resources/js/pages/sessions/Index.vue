<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { index as sessionsIndex, show as sessionShow } from '@/routes/sessions';
import { index as workoutsIndex } from '@/routes/workouts';

type SessionSummary = {
    id: string;
    title: string;
    started_at: string;
    finished_at: string | null;
    duration_min: number | null;
    exercises_count: number;
    completed_sets: number;
    volume_kg: number;
};
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };

defineProps<{ sessions: Paginated<SessionSummary> }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Treinos', href: workoutsIndex() }, { title: 'Histórico', href: sessionsIndex() }] },
});

const fmtDate = (iso: string) =>
    new Date(iso).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Histórico de treinos" />

    <div class="flex flex-col gap-4 p-4">
        <Heading title="Histórico de treinos" :description="`${sessions.total} treino(s) registrados`" />

        <Link
            v-for="s in sessions.data"
            :key="s.id"
            :href="sessionShow(s.id)"
            class="flex items-center justify-between gap-3 rounded-2xl bg-card p-4 hover:bg-muted/60"
        >
            <div class="min-w-0">
                <p class="truncate font-medium">{{ s.title }}</p>
                <p class="text-sm text-muted-foreground">{{ fmtDate(s.started_at) }}</p>
            </div>
            <div class="shrink-0 text-right text-sm">
                <Badge v-if="!s.finished_at" class="mb-1">Em andamento</Badge>
                <p v-else class="text-muted-foreground">{{ s.duration_min }} min</p>
                <p class="text-muted-foreground">{{ s.completed_sets }} séries · {{ s.volume_kg }} kg</p>
            </div>
        </Link>

        <p v-if="sessions.data.length === 0" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            Nenhum treino registrado ainda. <Link :href="workoutsIndex()" class="underline">Comece um treino</Link>.
        </p>

        <div v-if="sessions.last_page > 1" class="flex items-center justify-between text-sm">
            <Link v-if="sessions.prev_page_url" :href="sessions.prev_page_url" class="underline">Anterior</Link>
            <span v-else></span>
            <span class="text-muted-foreground">Página {{ sessions.current_page }} de {{ sessions.last_page }}</span>
            <Link v-if="sessions.next_page_url" :href="sessions.next_page_url" class="underline">Próxima</Link>
            <span v-else></span>
        </div>
    </div>
</template>
