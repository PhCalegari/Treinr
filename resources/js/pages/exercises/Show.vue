<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Dumbbell } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { forceLabels, formatSuggestion, levelLabels, mechanicLabels } from '@/lib/labels';
import { index as exercisesIndex } from '@/routes/exercises';

type MuscleRef = { code: string; name: string; group: string | null };
type ExerciseDetail = {
    id: number;
    name: string;
    original_name: string | null;
    category: string | null;
    level: string | null;
    mechanic: string | null;
    force_type: string | null;
    instructions: string[];
    instructions_language: 'pt' | 'en';
    suggested: { sets: number | null; reps_min: number | null; reps_max: number | null; duration_sec: number | null };
    primary_groups: string[];
    secondary_groups: string[];
    primary_muscles: MuscleRef[];
    secondary_muscles: MuscleRef[];
    equipment: string[];
    images: string[];
    videos: string[];
    attribution: string | null;
};

type HistoryRow = {
    session_id: string;
    date: string;
    sets: { reps: number | null; load_kg: number | null }[];
    best_load_kg: number;
    volume_kg: number;
};

const props = defineProps<{ exercise: ExerciseDetail; history: HistoryRow[] }>();

const fmtDate = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });

// Variação da melhor carga entre o primeiro e o último registro do histórico.
const progress = computed(() => {
    if (props.history.length < 2) return null;
    const latest = props.history[0].best_load_kg;
    const first = props.history[props.history.length - 1].best_load_kg;
    return { latest, first, diff: Math.round((latest - first) * 10) / 10 };
});

defineOptions({
    layout: { breadcrumbs: [{ title: 'Exercícios', href: exercisesIndex() }] },
});

const suggestion = computed(() => formatSuggestion(props.exercise.suggested));

// As imagens da base são duas fotos (início e fim do movimento): alterna entre elas.
const frame = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.exercise.images.length > 1) {
        timer = setInterval(() => (frame.value = (frame.value + 1) % props.exercise.images.length), 1200);
    }
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="exercise.name" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <Link :href="exercisesIndex()" class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowLeft class="size-4" /> Voltar para exercícios
        </Link>

        <div>
            <h1 class="text-2xl font-semibold">{{ exercise.name }}</h1>
            <p v-if="exercise.original_name" class="text-sm text-muted-foreground">{{ exercise.original_name }}</p>
            <div class="mt-2 flex flex-wrap gap-1">
                <Badge v-if="exercise.category" variant="outline">{{ exercise.category }}</Badge>
                <Badge v-if="exercise.level" variant="outline">{{ levelLabels[exercise.level] }}</Badge>
                <Badge v-if="exercise.mechanic" variant="outline">{{ mechanicLabels[exercise.mechanic] }}</Badge>
                <Badge v-if="exercise.force_type" variant="outline">{{ forceLabels[exercise.force_type] }}</Badge>
            </div>
        </div>

        <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl border bg-muted sm:aspect-video">
            <img
                v-if="exercise.images.length"
                :src="exercise.images[frame]"
                :alt="`Demonstração: ${exercise.name}`"
                class="size-full object-contain"
            />
            <Dumbbell v-else class="size-10 text-muted-foreground" />
        </div>
        <p v-if="exercise.images.length > 1" class="-mt-4 text-center text-xs text-muted-foreground">
            As imagens alternam entre o início e o fim do movimento.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <section class="rounded-2xl bg-card p-4">
                <h2 class="font-medium">Músculos trabalhados</h2>
                <p class="mt-3 text-xs tracking-wide text-muted-foreground uppercase">Principais</p>
                <div class="mt-1 flex flex-wrap gap-1">
                    <Badge v-for="g in exercise.primary_groups" :key="g">{{ g }}</Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">{{ exercise.primary_muscles.map((m) => m.name).join(', ') }}</p>

                <template v-if="exercise.secondary_muscles.length">
                    <p class="mt-3 text-xs tracking-wide text-muted-foreground uppercase">Secundários</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <Badge v-for="g in exercise.secondary_groups" :key="g" variant="secondary">{{ g }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ exercise.secondary_muscles.map((m) => m.name).join(', ') }}</p>
                </template>
            </section>

            <section class="rounded-2xl bg-card p-4">
                <h2 class="font-medium">Como prescrever</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div v-if="suggestion">
                        <dt class="text-muted-foreground">Sugestão inicial</dt>
                        <dd class="font-medium">{{ suggestion }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Equipamento</dt>
                        <dd class="font-medium">{{ exercise.equipment.length ? exercise.equipment.join(', ') : 'Nenhum' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-muted-foreground">
                    Sugestão genérica pela categoria do exercício. Ajuste séries, repetições e carga ao montar seu treino.
                </p>
            </section>
        </div>

        <section v-if="exercise.instructions.length" class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Execução</h2>
            <p v-if="exercise.instructions_language === 'en'" class="mt-1 text-xs text-muted-foreground">
                Instruções no idioma original (inglês). A tradução está em andamento.
            </p>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm" :lang="exercise.instructions_language">
                <li v-for="(step, i) in exercise.instructions" :key="i">{{ step }}</li>
            </ol>
        </section>

        <section class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Seu histórico</h2>
            <p v-if="history.length === 0" class="mt-2 text-sm text-muted-foreground">
                Você ainda não registrou este exercício. As cargas aparecem aqui depois do primeiro treino.
            </p>
            <template v-else>
                <p v-if="progress" class="mt-1 text-sm text-muted-foreground">
                    Melhor carga: {{ progress.first }} kg → {{ progress.latest }} kg
                    <span :class="progress.diff > 0 ? 'text-green-600' : progress.diff < 0 ? 'text-destructive' : ''">
                        ({{ progress.diff > 0 ? '+' : '' }}{{ progress.diff }} kg)
                    </span>
                </p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1 pr-3 font-normal">Data</th>
                                <th class="py-1 pr-3 font-normal">Séries (kg × reps)</th>
                                <th class="py-1 pr-3 text-right font-normal">Melhor</th>
                                <th class="py-1 text-right font-normal">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in history" :key="h.session_id" class="border-t">
                                <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDate(h.date) }}</td>
                                <td class="py-2 pr-3">{{ h.sets.map((s) => `${s.load_kg ?? '–'}×${s.reps ?? '–'}`).join('  ') }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ h.best_load_kg }} kg</td>
                                <td class="py-2 text-right tabular-nums">{{ h.volume_kg }} kg</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <p v-if="exercise.attribution" class="text-xs text-muted-foreground">Fonte: {{ exercise.attribution }}</p>
    </div>
</template>
