<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Apple, Dumbbell, Play, Scale } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import Heading from '@/components/Heading.vue';
import InstallAppCard from '@/components/InstallAppCard.vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Button } from '@/components/ui/button';
import { fmtNumber, fmtShortDate } from '@/lib/chart';
import { dashboard } from '@/routes';
import { index as diaryIndex } from '@/routes/diary';
import { index as goalsIndex } from '@/routes/goals';
import { onboarding } from '@/routes';
import { index as progressIndex } from '@/routes/progress';
import { show as sessionShow, store as sessionStore } from '@/routes/sessions';
import { index as workoutsIndex } from '@/routes/workouts';

type Totals = {
    kcal: number;
    protein_g: number;
    carbs_g: number;
    fat_g: number;
};
type Goal = { kcal: number; protein_g: number; carbs_g: number; fat_g: number };

const props = defineProps<{
    nutrition: { totals: Totals; goal: Goal | null };
    training: {
        open: { id: string; title: string } | null;
        next: { id: string; title: string; plan: string } | null;
        last: { title: string; date: string } | null;
        week_count: number;
    };
    progress: {
        weight_kg: number;
        date: string;
        change_30d: number | null;
    } | null;
    weekMuscles: { code: string; name: string; sets: number }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Início', href: dashboard() }] },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const profileIncomplete = computed(
    () =>
        !user.value.birth_date ||
        !user.value.sex ||
        !user.value.height_cm ||
        !user.value.goal ||
        !user.value.experience_level,
);

// Conta nova sem perfil: abre os primeiros passos uma vez (dá para pular).
onMounted(() => {
    if (!profileIncomplete.value) return;
    let skipped = false;
    try {
        skipped = localStorage.getItem('treinr:onboarding-skipped') === '1';
        // Abre sozinho só na primeira vez; depois fica o aviso no início.
        localStorage.setItem('treinr:onboarding-skipped', '1');
    } catch {
        skipped = false;
    }
    if (!skipped) router.visit(onboarding().url);
});
// Grupos bem treinados (3+ séries) e pouco treinados, para o boneco da semana.
// Boneco da semana: todo grupo treinado acende; a força da cor segue as séries (10 por semana = cor cheia).
const weekGroups = computed(() => props.weekMuscles.map((m) => m.code));
const WEEK_TARGET = 10;
const weekIntensity = computed(() =>
    Object.fromEntries(
        props.weekMuscles.map((m) => [
            m.code,
            Math.min(1, m.sets / WEEK_TARGET),
        ]),
    ),
);
const chipColor = (code: string) =>
    `color-mix(in srgb, var(--muscle-active) ${Math.round(35 + (weekIntensity.value[code] ?? 0) * 65)}%, var(--muscle-idle))`;
const firstName = computed(() => user.value.name.split(' ')[0]);

const remaining = computed(() =>
    props.nutrition.goal
        ? Math.round(props.nutrition.goal.kcal - props.nutrition.totals.kcal)
        : null,
);
const kcalPct = computed(() =>
    props.nutrition.goal
        ? Math.min(
              100,
              (props.nutrition.totals.kcal / props.nutrition.goal.kcal) * 100,
          )
        : 0,
);
const signed = (v: number) =>
    `${v > 0 ? '+' : v < 0 ? '−' : ''}${fmtNumber(Math.abs(v))} kg`;

function startNext() {
    if (props.training.next)
        router.post(sessionStore().url, {
            workout_day_id: props.training.next.id,
        });
}
</script>

<template>
    <Head title="Início" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            :title="`Olá, ${firstName}!`"
            description="Seu dia de treino e alimentação"
        />

        <InstallAppCard />

        <div
            v-if="profileIncomplete"
            class="rounded-2xl border border-primary/40 bg-primary/10 p-4 text-sm"
        >
            <p class="font-medium">Complete seu perfil</p>
            <p class="mt-1 text-muted-foreground">
                Data de nascimento, sexo, altura, objetivo e nível são usados
                para sugerir treinos e estimar seu gasto calórico.
            </p>
            <Link
                :href="onboarding()"
                class="mt-2 inline-block font-medium underline underline-offset-4"
                >Completar perfil</Link
            >
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <!-- Alimentação de hoje -->
            <section
                class="flex flex-col rounded-2xl bg-card p-4"
                data-test="dash-nutrition"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Apple class="size-4" /> Alimentação hoje
                </h2>
                <p class="mt-2 text-3xl font-semibold">
                    {{ fmtNumber(nutrition.totals.kcal, 0) }}
                    <span class="text-base font-normal text-muted-foreground"
                        >kcal</span
                    >
                </p>
                <template v-if="nutrition.goal">
                    <div
                        class="mt-2 h-2 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--viz-series)_18%,transparent)]"
                        aria-hidden="true"
                    >
                        <div
                            class="h-full rounded-full bg-[var(--viz-series)]"
                            :style="{ width: kcalPct + '%' }"
                        ></div>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            remaining! >= 0
                                ? `Faltam ${fmtNumber(remaining!, 0)} kcal`
                                : `${fmtNumber(-remaining!, 0)} kcal acima da meta`
                        }}
                        · meta {{ fmtNumber(nutrition.goal.kcal, 0) }}
                    </p>
                </template>
                <p class="mt-1 text-sm text-muted-foreground">
                    P {{ fmtNumber(nutrition.totals.protein_g) }} g · C
                    {{ fmtNumber(nutrition.totals.carbs_g) }} g · G
                    {{ fmtNumber(nutrition.totals.fat_g) }} g
                </p>
                <div class="mt-auto flex flex-wrap gap-3 pt-3 text-sm">
                    <Link
                        :href="diaryIndex()"
                        class="font-medium underline underline-offset-4"
                        >Abrir diário</Link
                    >
                    <Link
                        v-if="!nutrition.goal"
                        :href="goalsIndex()"
                        class="font-medium underline underline-offset-4"
                        >Definir meta</Link
                    >
                </div>
            </section>

            <!-- Treino -->
            <section
                class="flex flex-col rounded-2xl bg-card p-4"
                data-test="dash-training"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Dumbbell class="size-4" /> Treino
                </h2>
                <template v-if="training.open">
                    <p class="mt-2 font-medium">
                        Em andamento: {{ training.open.title }}
                    </p>
                    <Button as-child size="sm" class="mt-3 self-start"
                        ><Link :href="sessionShow(training.open.id)"
                            >Continuar treino</Link
                        ></Button
                    >
                </template>
                <template v-else-if="training.next">
                    <p class="mt-2 text-xl font-semibold">
                        {{ training.next.title }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Próximo do plano "{{ training.next.plan }}"
                    </p>
                    <Button size="sm" class="mt-3 self-start" @click="startNext"
                        ><Play class="size-4" /> Começar</Button
                    >
                </template>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    Nenhum plano ativo.
                </p>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{ training.week_count }} treino(s) nesta semana<template
                        v-if="training.last"
                    >
                        · último: {{ training.last.title }},
                        {{ fmtShortDate(training.last.date) }}</template
                    >
                </p>
                <Link
                    :href="workoutsIndex()"
                    class="mt-auto pt-3 text-sm font-medium underline underline-offset-4"
                    >Ver treinos</Link
                >
            </section>

            <!-- Peso -->
            <section
                class="flex flex-col rounded-2xl bg-card p-4"
                data-test="dash-progress"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Scale class="size-4" /> Peso
                </h2>
                <template v-if="progress">
                    <p class="mt-2 text-3xl font-semibold">
                        {{ fmtNumber(progress.weight_kg) }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                            >kg</span
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        em {{ fmtShortDate(progress.date)
                        }}<template v-if="progress.change_30d !== null">
                            · {{ signed(progress.change_30d) }} em 30
                            dias</template
                        >
                    </p>
                </template>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    Nenhum peso registrado.
                </p>
                <Link
                    :href="progressIndex()"
                    class="mt-auto pt-3 text-sm font-medium underline underline-offset-4"
                >
                    {{ progress ? 'Ver evolução' : 'Registrar peso' }}
                </Link>
            </section>
        </div>

        <!-- Músculos treinados na semana -->
        <section class="rounded-2xl bg-card p-4" data-test="dash-week-muscles">
            <div class="flex items-baseline justify-between gap-2">
                <h2
                    class="font-display text-lg font-bold tracking-tight uppercase"
                >
                    Músculos da semana
                </h2>
                <span class="text-xs text-muted-foreground">desde segunda</span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <figure class="flex flex-col items-center gap-1">
                    <MuscleMap
                        view="front"
                        :groups="weekGroups"
                        :intensity="weekIntensity"
                        class="h-56 w-full"
                        label="Frente: músculos treinados na semana"
                    />
                    <figcaption class="text-xs text-muted-foreground">
                        Frente
                    </figcaption>
                </figure>
                <figure class="flex flex-col items-center gap-1">
                    <MuscleMap
                        view="back"
                        :groups="weekGroups"
                        :intensity="weekIntensity"
                        class="h-56 w-full"
                        label="Costas: músculos treinados na semana"
                    />
                    <figcaption class="text-xs text-muted-foreground">
                        Costas
                    </figcaption>
                </figure>
            </div>
            <ul v-if="weekMuscles.length" class="mt-3 flex flex-wrap gap-2">
                <li
                    v-for="m in weekMuscles"
                    :key="m.code"
                    class="flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-sm"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ background: chipColor(m.code) }"
                        aria-hidden="true"
                    ></span>
                    {{ m.name }}
                    <span class="text-muted-foreground"
                        >· {{ m.sets }}
                        {{ m.sets === 1 ? 'série' : 'séries' }}</span
                    >
                </li>
            </ul>
            <p v-else class="mt-3 text-sm text-muted-foreground">
                Nenhuma série concluída nesta semana ainda. Os músculos
                treinados vão acendendo aqui.
            </p>
            <p
                v-if="weekMuscles.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                Quanto mais forte o amarelo, mais séries na semana (cor cheia a
                partir de 10).
            </p>
        </section>
    </div>
</template>
