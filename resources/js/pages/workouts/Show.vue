<script setup lang="ts">
// Plano de treino: abas dos dias, músculos alvo (desenho), lista de exercícios e
// "Iniciar treino". A edição (séries, carga, ordem, nomes) fica no modo "Editar" e no menu ⋮.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Check,
    Clock,
    Dumbbell,
    Eye,
    History,
    MoreVertical,
    Pencil,
    Play,
    Plus,
    Share2,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ExercisePicker from '@/components/ExercisePicker.vue';
import SharePlanDialog from '@/components/SharePlanDialog.vue';
import InputError from '@/components/InputError.vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { show as exerciseShow } from '@/routes/exercises';
import { store as sessionStore } from '@/routes/sessions';
import {
    destroy as dayDestroy,
    store as dayStore,
    update as dayUpdate,
} from '@/routes/workout-days';
import {
    destroy as itemDestroy,
    move as itemMove,
    store as itemStore,
    update as itemUpdate,
} from '@/routes/workout-exercises';
import {
    destroy as planDestroy,
    index as workoutsIndex,
    update as planUpdate,
} from '@/routes/workouts';

type Item = {
    id: string;
    exercise_id: number;
    name: string;
    image: string | null;
    groups: string[];
    secondary_groups: string[];
    sets: number;
    reps_min: number | null;
    reps_max: number | null;
    target_load_kg: number | null;
    rest_seconds: number | null;
    notes: string | null;
    kind: 'strength' | 'cardio' | 'time';
    duration_sec: number | null;
    last: {
        date: string;
        load_kg: number | null;
        reps: number | null;
        duration_sec: number | null;
        distance_km: number | null;
    } | null;
};
type Day = {
    id: string;
    label: string;
    name: string | null;
    title: string;
    muscle_groups: string[];
    muscles: { code: string; name: string }[];
    exercises: Item[];
};
type Plan = {
    id: string;
    name: string;
    split_type: string;
    active: boolean;
    notes: string | null;
    share_url: string | null;
    days: Day[];
};

const props = defineProps<{ plan: Plan }>();
const shareOpen = ref(false);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Treinos', href: workoutsIndex() }] },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

// Dia selecionado (mantém a seleção ao recarregar os dados).
// Vindo da lista de treinos com ?day=<id>, abre direto nesse dia.
const askedDay =
    typeof window !== 'undefined'
        ? new URLSearchParams(window.location.search).get('day')
        : null;
const selectedId = ref(
    props.plan.days.find((d) => d.id === askedDay)?.id ??
        props.plan.days[0]?.id,
);
watch(
    () => props.plan.days,
    (days) => {
        if (!days.some((d) => d.id === selectedId.value))
            selectedId.value = days[0]?.id;
    },
);
const day = computed(
    () =>
        props.plan.days.find((d) => d.id === selectedId.value) ??
        props.plan.days[0],
);

const editing = ref(false);
const pickerOpen = ref(false);
const keep = { preserveScroll: true, preserveState: true };

const splitLabels: Record<string, string> = {
    a: 'Full body',
    ab: 'AB',
    abc: 'ABC',
    abcd: 'ABCD',
    abcde: 'ABCDE',
    custom: 'Personalizado',
};

/** Tempo estimado: ~40 s por série + descanso, ~1 min de troca entre exercícios. Arredonda de 5 em 5. */
const minutes = computed(() => {
    if (!day.value) return 0;
    const sec = day.value.exercises.reduce(
        (t, e) => t + e.sets * (40 + (e.rest_seconds ?? 60)) + 60,
        0,
    );
    return Math.max(5, Math.round(sec / 60 / 5) * 5);
});

/** "40 kg × 10" (força) ou "25 min · 4,1 km" (cardio) da melhor série da última vez. */
function lastText(item: Item): string | null {
    const l = item.last;
    if (!l) return null;
    if (item.kind !== 'strength') {
        if (!l.duration_sec) return null;
        const t =
            item.kind === 'cardio'
                ? `${Math.round(l.duration_sec / 60)} min`
                : `${l.duration_sec}s`;
        return l.distance_km
            ? `${t} · ${String(l.distance_km).replace('.', ',')} km`
            : t;
    }
    if (!l.load_kg && !l.reps) return null;
    const kg = l.load_kg
        ? `${String(l.load_kg).replace('.', ',')} kg`
        : 'sem carga';
    return l.reps ? `${kg} × ${l.reps}` : kg;
}

function summary(item: Item): string {
    // Cardio/alongamento: tempo em vez de carga e repetições.
    if (item.kind !== 'strength') {
        const sec = item.duration_sec;
        const time = sec
            ? item.kind === 'cardio'
                ? `${Math.round(sec / 60)} min`
                : `${sec}s`
            : null;
        return [`${item.sets} ${item.sets === 1 ? 'série' : 'séries'}`, time]
            .filter(Boolean)
            .join(' • ');
    }
    const reps =
        item.reps_min && item.reps_max
            ? item.reps_min === item.reps_max
                ? `${item.reps_min}`
                : `${item.reps_min}–${item.reps_max}`
            : null;
    return [
        `${item.sets} ${item.sets === 1 ? 'série' : 'séries'}`,
        reps && `${reps} reps`,
        item.target_load_kg !== null &&
            `${String(item.target_load_kg).replace('.', ',')} kg`,
    ]
        .filter(Boolean)
        .join(' • ');
}

function renamePlan(e: Event) {
    const name = (e.target as HTMLInputElement).value.trim();
    if (name && name !== props.plan.name)
        router.patch(planUpdate(props.plan.id).url, { name }, keep);
}
function renameDay(e: Event) {
    const name = (e.target as HTMLInputElement).value.trim() || null;
    if (name !== day.value.name)
        router.patch(dayUpdate(day.value.id).url, { name }, keep);
}
function addDay() {
    router.post(
        dayStore(props.plan.id).url,
        {},
        {
            ...keep,
            onSuccess: () =>
                (selectedId.value =
                    props.plan.days[props.plan.days.length - 1]?.id),
        },
    );
}
function removeDay() {
    if (confirm(`Excluir ${day.value.title} e seus exercícios?`))
        router.delete(dayDestroy(day.value.id).url, keep);
}
function addExercise(exerciseId: number) {
    router.post(itemStore(day.value.id).url, { exercise_id: exerciseId }, keep);
}
/** Salva um campo numérico do exercício (vazio = sem valor). */
function saveField(item: Item, field: keyof Item, e: Event) {
    const raw = (e.target as HTMLInputElement).value;
    const value = raw === '' ? null : Number(raw);
    if (value === item[field]) return;
    router.patch(itemUpdate(item.id).url, { [field]: value }, keep);
}
function move(item: Item, direction: 'up' | 'down') {
    router.post(itemMove(item.id).url, { direction }, keep);
}
function removeItem(item: Item) {
    if (confirm(`Remover ${item.name} do treino?`))
        router.delete(itemDestroy(item.id).url, keep);
}
function toggleActive() {
    router.patch(
        planUpdate(props.plan.id).url,
        { active: !props.plan.active },
        keep,
    );
}
function deletePlan() {
    if (
        confirm(
            `Excluir o plano "${props.plan.name}"? O histórico de treinos já feitos é mantido.`,
        )
    )
        router.delete(planDestroy(props.plan.id).url);
}
function start() {
    router.post(sessionStore().url, { workout_day_id: day.value.id });
}

const numberClass = 'h-9 w-full px-2 text-center';
</script>

<template>
    <Head :title="plan.name" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 pb-36">
        <div class="flex items-center gap-2">
            <Link
                :href="workoutsIndex()"
                class="rounded-full p-1 text-muted-foreground hover:text-foreground"
                aria-label="Voltar aos treinos"
                ><ArrowLeft class="size-5"
            /></Link>
            <Input
                v-if="editing"
                :default-value="plan.name"
                class="h-10 text-base font-semibold"
                aria-label="Nome do plano"
                @change="renamePlan"
            />
            <h1 v-else class="min-w-0 flex-1 truncate text-lg font-semibold">
                {{ plan.name }}
            </h1>
            <button
                v-if="!editing"
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-full bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                data-test="share-plan"
                @click="shareOpen = true"
            >
                <Share2 class="size-4" /> Compartilhar
            </button>
        </div>
        <SharePlanDialog v-model:open="shareOpen" :plan="plan" />

        <!-- Abas dos dias -->
        <div
            class="grid grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))] gap-2"
            role="tablist"
            aria-label="Dias do plano"
        >
            <button
                v-for="d in plan.days"
                :key="d.id"
                type="button"
                role="tab"
                :aria-selected="d.id === day?.id"
                class="flex min-w-0 flex-col items-center justify-center gap-1 rounded-2xl border-2 bg-card px-2 py-2.5 transition-colors"
                :class="
                    d.id === day?.id
                        ? 'border-foreground'
                        : 'border-transparent hover:border-border'
                "
                :data-test="`day-tab-${d.label}`"
                @click="selectedId = d.id"
            >
                <span
                    class="text-[10px] leading-none font-semibold tracking-wide text-muted-foreground uppercase"
                    >Treino</span
                >
                <span
                    class="font-display text-2xl leading-none font-extrabold"
                    >{{ d.label }}</span
                >
            </button>
            <button
                v-if="plan.days.length < 7"
                type="button"
                class="flex min-w-0 flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed px-2 py-2.5 text-muted-foreground hover:text-foreground"
                data-test="add-day"
                @click="addDay"
            >
                <Plus class="size-5" />
                <span class="text-xs">Dia</span>
            </button>
        </div>
        <InputError :message="errors.day" />

        <template v-if="day">
            <!-- Nome do dia + configurações -->
            <section class="flex flex-col gap-3">
                <Input
                    v-if="editing"
                    :key="day.id"
                    :default-value="day.name ?? ''"
                    placeholder="Nome do treino (ex.: Peito e tríceps)"
                    aria-label="Nome do treino"
                    @change="renameDay"
                />
                <h2
                    v-else
                    class="font-display text-xl font-bold tracking-tight uppercase"
                >
                    {{ day.name || `Treino ${day.label}` }}
                </h2>
                <div class="flex flex-wrap gap-2">
                    <span
                        class="flex shrink-0 items-center gap-1.5 rounded-full bg-card px-4 py-2 text-sm"
                        ><Clock class="size-4 text-muted-foreground" /> ~{{
                            minutes
                        }}
                        min</span
                    >
                    <span
                        class="shrink-0 rounded-full bg-card px-4 py-2 text-sm"
                        >Músculos ({{ day.muscles.length }})</span
                    >
                    <span
                        class="shrink-0 rounded-full bg-card px-4 py-2 text-sm"
                        >{{
                            splitLabels[plan.split_type] ?? plan.split_type
                        }}</span
                    >
                    <span
                        v-if="!plan.active"
                        class="shrink-0 rounded-full bg-card px-4 py-2 text-sm text-muted-foreground"
                        >Inativo</span
                    >
                </div>
            </section>

            <!-- Músculos alvo -->
            <section v-if="day.muscles.length" class="flex flex-col gap-3">
                <h3
                    class="font-display text-lg font-bold tracking-tight uppercase"
                >
                    Músculos alvo
                </h3>
                <div
                    class="grid grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-3"
                >
                    <div
                        v-for="m in day.muscles"
                        :key="m.code"
                        class="flex min-w-0 flex-col items-center gap-2 rounded-2xl bg-card p-3"
                    >
                        <MuscleMap
                            :groups="[m.code]"
                            crop
                            class="size-20"
                            :label="m.name"
                        />
                        <span
                            class="text-center text-sm leading-tight text-muted-foreground"
                            >{{ m.name }}</span
                        >
                    </div>
                </div>
            </section>

            <!-- Exercícios -->
            <section class="flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <h3
                        class="font-display text-lg font-bold tracking-tight uppercase"
                    >
                        {{ day.exercises.length }}
                        {{
                            day.exercises.length === 1
                                ? 'exercício'
                                : 'exercícios'
                        }}
                    </h3>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 text-sm hover:text-primary"
                        :aria-pressed="editing"
                        data-test="toggle-edit"
                        @click="editing = !editing"
                    >
                        <template v-if="editing"
                            ><Check class="size-4" /> Pronto</template
                        >
                        <template v-else
                            >Editar <Pencil class="size-4"
                        /></template>
                    </button>
                </div>

                <ul class="divide-y divide-border/60">
                    <li
                        v-for="(item, i) in day.exercises"
                        :key="item.id"
                        class="py-4"
                        :data-test="`plan-item-${item.exercise_id}`"
                    >
                        <div class="flex items-center gap-4">
                            <!-- Imagem em cartão branco + mini desenho do músculo no canto -->
                            <Link
                                :href="exerciseShow(item.exercise_id)"
                                class="relative shrink-0"
                                :aria-label="`Ver ${item.name}`"
                            >
                                <div
                                    class="flex size-24 items-center justify-center overflow-hidden rounded-2xl bg-white"
                                >
                                    <img
                                        v-if="item.image"
                                        :src="item.image"
                                        alt=""
                                        class="size-full object-cover"
                                        loading="lazy"
                                    />
                                    <Dumbbell
                                        v-else
                                        class="size-6 text-slate-400"
                                    />
                                </div>
                                <div
                                    class="absolute -right-2 -bottom-2 size-11 overflow-hidden rounded-xl border-2 border-background bg-card p-0.5"
                                >
                                    <MuscleMap
                                        :groups="item.groups"
                                        :secondary="item.secondary_groups"
                                        crop
                                        class="size-full"
                                    />
                                </div>
                            </Link>
                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="exerciseShow(item.exercise_id)"
                                    class="block leading-tight font-medium break-words hover:underline"
                                    >{{ item.name }}</Link
                                >
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ summary(item) }}
                                </p>
                                <p
                                    v-if="item.last && lastText(item)"
                                    class="mt-1 inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs"
                                    :data-test="`last-${item.id}`"
                                >
                                    <History class="size-3" /> Última:
                                    <span class="font-semibold">{{
                                        lastText(item)
                                    }}</span>
                                </p>
                                <p
                                    v-if="item.notes && !editing"
                                    class="mt-1 line-clamp-2 text-xs text-primary/90"
                                >
                                    {{ item.notes }}
                                </p>
                            </div>
                            <DropdownMenu v-if="!editing">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-full p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                                        :aria-label="`Opções de ${item.name}`"
                                    >
                                        <MoreVertical class="size-5" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="
                                                exerciseShow(item.exercise_id)
                                            "
                                            ><Eye class="size-4" /> Ver
                                            exercício</Link
                                        >
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @select="editing = true"
                                        ><Pencil class="size-4" /> Editar séries
                                        e carga</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        :disabled="i === 0"
                                        @select="move(item, 'up')"
                                        ><ArrowUp class="size-4" />
                                        Subir</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        :disabled="
                                            i === day.exercises.length - 1
                                        "
                                        @select="move(item, 'down')"
                                        ><ArrowDown class="size-4" />
                                        Descer</DropdownMenuItem
                                    >
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="text-destructive"
                                        @select="removeItem(item)"
                                        ><Trash2 class="size-4" />
                                        Remover</DropdownMenuItem
                                    >
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div v-else class="flex shrink-0 flex-col">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="i === 0"
                                    aria-label="Subir"
                                    @click="move(item, 'up')"
                                    ><ArrowUp class="size-4"
                                /></Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="i === day.exercises.length - 1"
                                    aria-label="Descer"
                                    @click="move(item, 'down')"
                                    ><ArrowDown class="size-4"
                                /></Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 text-destructive"
                                    aria-label="Remover"
                                    @click="removeItem(item)"
                                    ><Trash2 class="size-4"
                                /></Button>
                            </div>
                        </div>

                        <!-- Modo edição: séries, repetições, carga e descanso -->
                        <div
                            v-if="editing"
                            class="mt-3 grid grid-cols-5 gap-2 text-xs text-muted-foreground"
                        >
                            <label class="grid gap-1"
                                >Séries
                                <Input
                                    type="number"
                                    min="1"
                                    max="20"
                                    :default-value="item.sets"
                                    :class="numberClass"
                                    @change="saveField(item, 'sets', $event)"
                                />
                            </label>
                            <label class="grid gap-1"
                                >Reps mín.
                                <Input
                                    type="number"
                                    min="1"
                                    max="100"
                                    :default-value="item.reps_min ?? ''"
                                    :class="numberClass"
                                    @change="
                                        saveField(item, 'reps_min', $event)
                                    "
                                />
                            </label>
                            <label class="grid gap-1"
                                >Reps máx.
                                <Input
                                    type="number"
                                    min="1"
                                    max="100"
                                    :default-value="item.reps_max ?? ''"
                                    :class="numberClass"
                                    @change="
                                        saveField(item, 'reps_max', $event)
                                    "
                                />
                            </label>
                            <label class="grid gap-1"
                                >Carga (kg)
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.5"
                                    inputmode="decimal"
                                    :default-value="item.target_load_kg ?? ''"
                                    :class="numberClass"
                                    @change="
                                        saveField(
                                            item,
                                            'target_load_kg',
                                            $event,
                                        )
                                    "
                                />
                            </label>
                            <label class="grid gap-1"
                                >Descanso (s)
                                <Input
                                    type="number"
                                    min="0"
                                    max="900"
                                    step="15"
                                    :default-value="item.rest_seconds ?? ''"
                                    :class="numberClass"
                                    @change="
                                        saveField(item, 'rest_seconds', $event)
                                    "
                                />
                            </label>
                        </div>
                    </li>
                </ul>

                <InputError
                    :message="
                        errors.reps_max ||
                        errors.sets ||
                        errors.target_load_kg ||
                        errors.rest_seconds
                    "
                />

                <p
                    v-if="day.exercises.length === 0"
                    class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Nenhum exercício neste treino ainda.
                </p>

                <Button
                    variant="outline"
                    class="mt-2 self-start rounded-full"
                    data-test="add-exercise"
                    @click="pickerOpen = true"
                    ><Plus class="size-4" /> Adicionar exercício</Button
                >
            </section>

            <!-- Ações do plano (no modo edição) -->
            <section v-if="editing" class="flex flex-wrap gap-2 border-t pt-4">
                <Button
                    v-if="plan.days.length > 1"
                    variant="ghost"
                    size="sm"
                    @click="removeDay"
                    >Excluir treino {{ day.label }}</Button
                >
                <Button variant="ghost" size="sm" @click="toggleActive">{{
                    plan.active ? 'Marcar plano como inativo' : 'Reativar plano'
                }}</Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="deletePlan"
                    >Excluir plano</Button
                >
            </section>
        </template>
    </div>

    <!-- Iniciar treino (flutuante, acima da barra de navegação) -->
    <div
        v-if="day && !editing"
        class="pointer-events-none fixed inset-x-0 bottom-24 z-30 flex justify-center px-6 md:bottom-6 md:left-[var(--sidebar-width)]"
    >
        <button
            type="button"
            class="tracking-tightr pointer-events-auto flex w-full max-w-md items-center justify-center gap-2 rounded-full bg-primary px-6 py-4 font-display text-xl font-bold text-primary-foreground uppercase shadow-[0_8px_30px_-6px_var(--primary)] transition hover:brightness-105 disabled:opacity-50"
            :disabled="day.exercises.length === 0"
            data-test="start-session"
            @click="start"
        >
            <Play class="size-5 fill-current" /> Iniciar treino {{ day.label }}
        </button>
    </div>

    <ExercisePicker v-model:open="pickerOpen" @select="addExercise" />
</template>
