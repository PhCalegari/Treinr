<script setup lang="ts">
// Tela "Treinos": seu plano com os últimos 7 dias (corpo de cada dia), começar um treino,
// atividade dos últimos meses, treinos recentes, planos e criação de plano.
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    Dumbbell,
    Flame,
    History,
    Play,
    Plus,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import LineChart from '@/components/charts/LineChart.vue';
import InputError from '@/components/InputError.vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index as sessionsIndex,
    show as sessionShow,
    store as sessionStore,
} from '@/routes/sessions';
import {
    ai as workoutsAi,
    index as workoutsIndex,
    recovery as workoutsRecovery,
    show as workoutShow,
    store as workoutStore,
} from '@/routes/workouts';

type PlanDay = {
    id: string;
    label: string;
    name: string | null;
    title: string;
};
type Plan = {
    id: string;
    name: string;
    split_type: string;
    active: boolean;
    days: PlanDay[];
};
type WeekDay = {
    date: string;
    groups: string[];
    secondary: string[];
    sets: number;
    sessions: { id: string; title: string; label: string; finished: boolean }[];
};
type Recent = {
    id: string;
    title: string;
    date: string;
    exercises: number;
    duration_min: number;
};

const props = defineProps<{
    plans: Plan[];
    openSession: { id: string; title: string; started_at: string } | null;
    splits: string[];
    week: WeekDay[];
    activity: {
        points: { date: string; value: number }[];
        total_min: number;
        count: number;
    };
    recent: Recent[];
    nextDayId: string | null;
    recovery: {
        code: string;
        name: string;
        status: 'fatigued' | 'recovering' | 'fresh';
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Treinos', href: workoutsIndex() }] },
});

const splitLabels: Record<string, string> = {
    a: 'A (full body)',
    ab: 'AB',
    abc: 'ABC',
    abcd: 'ABCD',
    abcde: 'ABCDE',
    custom: 'Personalizada',
};

const activePlan = computed(
    () => props.plans.find((p) => p.active) ?? props.plans[0] ?? null,
);

// ---------- Últimos 7 dias ----------
const WEEKDAYS = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
const asDate = (iso: string) => new Date(iso + 'T12:00:00');
/** null = os 7 dias juntos; senão, o índice do dia escolhido (padrão: hoje). */
const selected = ref<number | null>(props.week.length - 1);
const trainedDays = computed(() => props.week.filter((d) => d.sets > 0).length);
const view = computed(() => {
    if (selected.value !== null) return props.week[selected.value];
    const groups = [...new Set(props.week.flatMap((d) => d.groups))];
    return {
        groups,
        secondary: [...new Set(props.week.flatMap((d) => d.secondary))].filter(
            (g) => !groups.includes(g),
        ),
        sets: props.week.reduce((n, d) => n + d.sets, 0),
        sessions: props.week.flatMap((d) => d.sessions),
    };
});
const viewTitle = computed(() => {
    if (selected.value === null) return 'Últimos 7 dias';
    const d = props.week[selected.value];
    if (selected.value === props.week.length - 1) return 'Hoje';
    if (selected.value === props.week.length - 2) return 'Ontem';
    return asDate(d.date).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: 'short',
    });
});
const dayLabels = (d: WeekDay) =>
    [...new Set(d.sessions.map((s) => s.label))].join('·');

// ---------- Recuperação ----------
const RECOVERY_COLOR = {
    fatigued: 'var(--destructive)',
    recovering: 'var(--primary)',
    fresh: 'var(--success)',
} as const;
const recoveryColors = computed(() =>
    Object.fromEntries(
        props.recovery.map((m) => [m.code, RECOVERY_COLOR[m.status]]),
    ),
);
const recoveryText = computed(() => {
    const tired = props.recovery
        .filter((m) => m.status === 'fatigued')
        .map((m) => m.name);
    const ready = props.recovery.filter((m) => m.status === 'fresh').length;
    if (tired.length)
        return `Descanse: ${tired.slice(0, 3).join(', ')}${tired.length > 3 ? '…' : ''}`;
    return ready === props.recovery.length
        ? 'Todos os músculos prontos para treinar'
        : `${ready} de ${props.recovery.length} grupos prontos`;
});

// ---------- Começar ----------
function start(dayId: string | null) {
    router.post(sessionStore().url, dayId ? { workout_day_id: dayId } : {});
}

// ---------- Atividade ----------
const fmtHours = (min: number) => {
    const h = Math.floor(min / 60);
    return h ? `${h}h ${min % 60}min` : `${min}min`;
};
const fmtDay = (iso: string) =>
    new Date(iso).toLocaleDateString('pt-BR', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="Treinos" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 pb-28">
        <!-- Cabeçalho -->
        <div class="flex items-center justify-between gap-2">
            <h1
                class="font-display text-3xl font-bold tracking-tight uppercase"
            >
                Treinos
            </h1>
            <div class="flex items-center gap-2">
                <Link
                    :href="sessionsIndex()"
                    class="flex size-10 items-center justify-center rounded-full bg-card hover:bg-muted"
                    aria-label="Histórico de treinos"
                >
                    <History class="size-5" />
                </Link>
                <a
                    href="#novo-plano"
                    class="flex size-10 items-center justify-center rounded-full bg-primary text-primary-foreground hover:brightness-95"
                    aria-label="Novo plano"
                >
                    <Plus class="size-5" />
                </a>
            </div>
        </div>

        <Link
            v-if="openSession"
            :href="sessionShow(openSession.id)"
            class="flex items-center justify-between gap-3 rounded-2xl border border-primary bg-primary/10 p-4"
        >
            <span class="min-w-0">
                <span class="block font-semibold">Treino em andamento</span>
                <span class="block truncate text-sm text-muted-foreground">{{
                    openSession.title
                }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-1 text-sm font-medium"
                >Continuar <ChevronRight class="size-4"
            /></span>
        </Link>

        <!-- Seu plano: últimos 7 dias -->
        <section class="rounded-2xl bg-card p-4" data-test="week-card">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 font-semibold">
                        Seu plano
                        <span
                            v-if="trainedDays"
                            class="flex items-center gap-0.5 text-sm text-primary"
                            :title="`${trainedDays} dia(s) com treino nos últimos 7 dias`"
                        >
                            <Flame class="size-4 fill-current" />{{
                                trainedDays
                            }}
                        </span>
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ activePlan?.name ?? 'Nenhum plano ainda' }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium"
                        :class="
                            selected === null
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted hover:bg-muted/70'
                        "
                        :aria-pressed="selected === null"
                        @click="
                            selected =
                                selected === null ? week.length - 1 : null
                        "
                    >
                        7 dias
                    </button>
                    <Link
                        v-if="activePlan"
                        :href="workoutShow(activePlan.id)"
                        class="rounded-full bg-muted px-3 py-1.5 text-xs font-medium hover:bg-muted/70"
                        >Ajustar plano</Link
                    >
                </div>
            </div>

            <div
                class="mt-3 grid grid-cols-7 gap-1.5"
                role="tablist"
                aria-label="Últimos 7 dias"
            >
                <button
                    v-for="(d, i) in week"
                    :key="d.date"
                    type="button"
                    role="tab"
                    :aria-selected="selected === i"
                    class="relative flex flex-col items-center rounded-xl py-2 transition-colors"
                    :class="
                        selected === i
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted/60 hover:bg-muted'
                    "
                    :data-test="`week-day-${i}`"
                    @click="selected = i"
                >
                    <span
                        v-if="d.sets > 0"
                        class="absolute -top-1.5 -right-1 flex size-4 items-center justify-center rounded-full bg-success text-white ring-2 ring-card"
                        aria-hidden="true"
                    >
                        <Check class="size-2.5" stroke-width="4" />
                    </span>
                    <span
                        class="text-[0.6rem] font-semibold tracking-wide opacity-80"
                        >{{ WEEKDAYS[asDate(d.date).getDay()] }}</span
                    >
                    <span
                        class="font-display text-xl leading-tight font-bold"
                        >{{ asDate(d.date).getDate() }}</span
                    >
                    <span
                        class="h-3 max-w-full truncate px-0.5 text-[0.6rem] leading-3 opacity-80"
                        >{{
                            d.sessions.length
                                ? dayLabels(d)
                                : i === week.length - 1
                                  ? 'hoje'
                                  : ''
                        }}</span
                    >
                </button>
            </div>

            <!-- Corpo do dia (ou da semana) -->
            <div class="mt-4 flex items-center justify-between gap-2">
                <p
                    class="font-display text-lg font-bold tracking-tight uppercase first-letter:uppercase"
                >
                    {{ viewTitle }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ view.sets }} {{ view.sets === 1 ? 'série' : 'séries' }}
                </p>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2" data-test="day-body">
                <MuscleMap
                    view="front"
                    :groups="view.groups"
                    :secondary="view.secondary"
                    class="h-52 w-full"
                    :label="`Frente: músculos de ${viewTitle}`"
                />
                <MuscleMap
                    view="back"
                    :groups="view.groups"
                    :secondary="view.secondary"
                    class="h-52 w-full"
                    :label="`Costas: músculos de ${viewTitle}`"
                />
            </div>
            <div
                class="mt-2 flex items-center justify-center gap-4 text-xs text-muted-foreground"
            >
                <span class="flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-full bg-[var(--muscle-active)]"
                    ></span
                    >Principal</span
                >
                <span class="flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-full bg-[var(--muscle-secondary)]"
                    ></span
                    >Auxiliar</span
                >
            </div>
            <ul v-if="view.sessions.length" class="mt-3 flex flex-col gap-1.5">
                <li v-for="s in view.sessions" :key="s.id">
                    <Link
                        :href="sessionShow(s.id)"
                        class="flex items-center justify-between gap-2 rounded-xl bg-muted/50 px-3 py-2 text-sm hover:bg-muted"
                    >
                        <span class="truncate">{{ s.title }}</span>
                        <span
                            class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                            >{{ s.finished ? 'Feito' : 'Em andamento' }}
                            <ChevronRight class="size-4"
                        /></span>
                    </Link>
                </li>
            </ul>
            <p v-else class="mt-3 text-center text-sm text-muted-foreground">
                {{
                    selected === null
                        ? 'Nenhum treino nos últimos 7 dias.'
                        : 'Sem treino neste dia.'
                }}
            </p>
        </section>

        <!-- Recuperação muscular (atalho) -->
        <Link
            :href="workoutsRecovery()"
            class="flex items-center gap-3 rounded-2xl bg-card p-3 pr-4 hover:bg-muted/60"
            data-test="open-recovery"
        >
            <span class="flex h-20 w-24 shrink-0 gap-0.5">
                <MuscleMap
                    view="front"
                    :groups="[]"
                    :colors="recoveryColors"
                    class="h-full w-1/2"
                    label="Recuperação (frente)"
                />
                <MuscleMap
                    view="back"
                    :groups="[]"
                    :colors="recoveryColors"
                    class="h-full w-1/2"
                    label="Recuperação (costas)"
                />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-semibold">Recuperação muscular</span>
                <span class="block text-sm text-muted-foreground">{{
                    recoveryText
                }}</span>
            </span>
            <ChevronRight class="size-5 shrink-0 text-muted-foreground" />
        </Link>

        <!-- Começar um treino -->
        <section>
            <div class="flex items-center justify-between">
                <h2
                    class="font-display text-lg font-bold tracking-tight uppercase"
                >
                    Começar um treino
                </h2>
                <Link
                    v-if="activePlan"
                    :href="workoutShow(activePlan.id)"
                    class="text-xs text-muted-foreground underline underline-offset-4"
                    >Editar</Link
                >
            </div>
            <div
                class="mt-2 grid grid-cols-4 gap-x-2 gap-y-4 pt-1 sm:grid-cols-6"
            >
                <button
                    v-for="d in activePlan?.days ?? []"
                    :key="d.id"
                    type="button"
                    class="flex min-w-0 flex-col items-center gap-1.5 disabled:opacity-50"
                    :disabled="!!openSession"
                    :data-test="`start-${d.id}`"
                    @click="start(d.id)"
                >
                    <span
                        class="flex size-16 items-center justify-center rounded-full bg-card font-display text-2xl font-bold"
                        :class="
                            d.id === nextDayId
                                ? 'bg-primary text-primary-foreground'
                                : ''
                        "
                        >{{ d.label }}</span
                    >
                    <span class="w-full text-center text-xs leading-tight">
                        <span class="block font-medium"
                            >Treino {{ d.label }}</span
                        >
                        <span class="line-clamp-2 text-muted-foreground">{{
                            d.id === nextDayId ? 'Próximo' : (d.name ?? '')
                        }}</span>
                    </span>
                </button>
                <button
                    type="button"
                    class="flex min-w-0 flex-col items-center gap-1.5 disabled:opacity-50"
                    :disabled="!!openSession"
                    @click="start(null)"
                >
                    <span
                        class="flex size-16 items-center justify-center rounded-full bg-card"
                        ><Dumbbell class="size-6"
                    /></span>
                    <span class="text-center text-xs leading-tight font-medium"
                        >Treino livre</span
                    >
                </button>
                <Link
                    :href="workoutsAi()"
                    class="flex min-w-0 flex-col items-center gap-1.5"
                    data-test="open-ai-workout"
                >
                    <span
                        class="flex size-16 items-center justify-center rounded-full bg-card text-primary"
                        ><Sparkles class="size-6"
                    /></span>
                    <span class="text-center text-xs leading-tight font-medium"
                        >Criar com IA</span
                    >
                </Link>
            </div>
        </section>

        <!-- Atividade -->
        <section class="rounded-2xl bg-card p-4">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Atividade</h2>
                <span
                    class="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground"
                    >3 meses</span
                >
            </div>
            <div
                v-if="activity.points.length > 1 && activity.total_min > 0"
                class="mt-2"
            >
                <LineChart
                    :points="activity.points"
                    unit="h"
                    label="Horas de treino acumuladas"
                    :height="160"
                />
            </div>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                Finalize alguns treinos para ver a evolução aqui.
            </p>
            <div class="mt-2 flex items-end justify-between gap-2">
                <p>
                    <span
                        class="block font-display text-2xl leading-none font-bold"
                        >{{ fmtHours(activity.total_min) }}</span
                    >
                    <span class="text-xs text-muted-foreground"
                        >{{ activity.count }}
                        {{ activity.count === 1 ? 'treino' : 'treinos' }} no
                        total</span
                    >
                </p>
                <Link
                    :href="sessionsIndex()"
                    class="flex items-center text-sm text-muted-foreground hover:text-foreground"
                    >Ver mais <ChevronRight class="size-4"
                /></Link>
            </div>
        </section>

        <!-- Treinos recentes -->
        <section v-if="recent.length" class="rounded-2xl bg-card p-4">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 font-semibold">
                    <Dumbbell class="size-4" /> Treinos recentes
                </h2>
                <Link
                    :href="sessionsIndex()"
                    class="text-muted-foreground hover:text-foreground"
                    aria-label="Ver histórico"
                    ><ChevronRight class="size-5"
                /></Link>
            </div>
            <ul class="mt-2 divide-y divide-border">
                <li v-for="r in recent" :key="r.id">
                    <Link
                        :href="sessionShow(r.id)"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{
                                r.title
                            }}</span>
                            <span
                                class="text-xs text-muted-foreground first-letter:uppercase"
                                >{{ fmtDay(r.date) }} ·
                                {{ r.duration_min }} min</span
                            >
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground"
                            >{{ r.exercises }}
                            {{
                                r.exercises === 1 ? 'exercício' : 'exercícios'
                            }}</span
                        >
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Meus planos -->
        <section class="flex flex-col gap-3">
            <h2 class="font-display text-lg font-bold tracking-tight uppercase">
                Meus planos
            </h2>
            <article
                v-for="plan in plans"
                :key="plan.id"
                class="rounded-2xl bg-card p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <Link
                        :href="workoutShow(plan.id)"
                        class="min-w-0 font-semibold break-words hover:underline"
                        >{{ plan.name }}</Link
                    >
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge variant="outline">{{
                            splitLabels[plan.split_type] ?? plan.split_type
                        }}</Badge>
                        <Badge v-if="!plan.active" variant="secondary"
                            >Inativo</Badge
                        >
                    </div>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="day in plan.days"
                        :key="day.id"
                        class="flex items-center justify-between gap-2 rounded-xl bg-muted/50 p-2 pl-3"
                    >
                        <!-- Tocar no nome abre o treino desse dia (exercícios, músculos etc.). -->
                        <Link
                            :href="
                                workoutShow(plan.id, { query: { day: day.id } })
                            "
                            class="min-w-0 flex-1 text-sm leading-tight break-words hover:underline"
                            :data-test="`open-day-${day.id}`"
                            >{{ day.title }}</Link
                        >
                        <Button
                            size="sm"
                            class="shrink-0"
                            :disabled="!!openSession"
                            @click="start(day.id)"
                            ><Play class="size-3" /> Iniciar</Button
                        >
                    </div>
                </div>
            </article>
            <p
                v-if="plans.length === 0"
                class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Você ainda não tem planos. Crie o primeiro abaixo ou peça para a
                IA montar.
            </p>
        </section>

        <!-- Novo plano -->
        <section id="novo-plano" class="scroll-mt-4 rounded-2xl bg-card p-4">
            <h2 class="font-semibold">Novo plano</h2>
            <Form
                v-bind="workoutStore.form()"
                class="mt-3 grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-end"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1">
                    <Label for="plan-name">Nome</Label>
                    <Input
                        id="plan-name"
                        name="name"
                        required
                        placeholder="Ex.: Hipertrofia ABC"
                        data-test="plan-name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="split">Divisão</Label>
                    <select id="split" name="split_type" :class="selectClass">
                        <option
                            v-for="s in splits"
                            :key="s"
                            :value="s"
                            :selected="s === 'abc'"
                        >
                            {{ splitLabels[s] ?? s }}
                        </option>
                    </select>
                </div>
                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="create-plan"
                    ><Plus class="size-4" /> Criar</Button
                >
            </Form>
        </section>
    </div>
</template>
