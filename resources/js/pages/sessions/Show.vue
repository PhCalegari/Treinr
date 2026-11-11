<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Dumbbell,
    Play,
    Plus,
    Share2,
    Square,
    Timer,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ExerciseHowTo from '@/components/ExerciseHowTo.vue';
import ExercisePicker from '@/components/ExercisePicker.vue';
import ProgressionPanel from '@/components/ProgressionPanel.vue';
import ShareWorkoutDialog from '@/components/ShareWorkoutDialog.vue';
import type { ShareInfo } from '@/components/ShareWorkoutDialog.vue';
import type { Progression } from '@/components/ProgressionPanel.vue';
import { WORKOUT_FINISHED_EVENT } from '@/composables/useSupportPrompt';
import { fireRestAlert, prepareRestAlert } from '@/lib/restAlert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    destroy as seDestroy,
    update as seUpdate,
} from '@/routes/session-exercises';
import {
    destroy as setDestroy,
    store as setStore,
    update as setUpdate,
} from '@/routes/session-sets';
import {
    destroy as sessionDestroy,
    index as sessionsIndex,
    update as sessionUpdate,
} from '@/routes/sessions';
import { store as sessionExerciseStore } from '@/routes/sessions/exercises';

type SetRow = {
    id: string;
    set_number: number;
    reps: number | null;
    load_kg: number | null;
    duration_sec: number | null;
    distance_km: number | null;
    completed: boolean;
};
type Ex = {
    id: string;
    exercise_id: number;
    name: string;
    image: string | null;
    frames: string[];
    groups: string[];
    secondary_groups: string[];
    instructions: string[];
    muscle_groups: string[];
    completed: boolean;
    target: {
        reps_min: number | null;
        reps_max: number | null;
        rest_seconds: number | null;
        notes: string | null;
    } | null;
    previous: Record<string, string>;
    progression: Progression | null;
    /** strength = carga e reps; cardio = tempo (min) e distância; time = só tempo (s). */
    kind: 'strength' | 'cardio' | 'time';
    target_duration_sec: number | null;
    sets: SetRow[];
};
type Session = {
    id: string;
    title: string;
    started_at: string;
    finished_at: string | null;
    duration_min: number | null;
    completed_sets: number;
    volume_kg: number;
    notes: string | null;
    share: ShareInfo | null;
    exercises: Ex[];
};

const props = defineProps<{ session: Session }>();

// Compartilhar o treino feito. Abre sozinho logo depois de finalizar (?share=1).
const shareOpen = ref(false);
onMounted(() => {
    if (
        props.session.share &&
        new URLSearchParams(window.location.search).get('share') === '1'
    )
        shareOpen.value = true;
});

defineOptions({
    layout: { breadcrumbs: [{ title: 'Treino', href: sessionsIndex() }] },
});

const keep = { preserveScroll: true, preserveState: true };
const finished = computed(() => !!props.session.finished_at);
const totalSets = computed(() =>
    props.session.exercises.reduce((n, e) => n + e.sets.length, 0),
);

// ---------- Tempo de treino ----------
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(clock));
const elapsed = computed(() => {
    const s = Math.max(
        0,
        Math.floor(
            (now.value - new Date(props.session.started_at).getTime()) / 1000,
        ),
    );
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return (
        (h ? `${h}:` : '') +
        `${String(m).padStart(h ? 2 : 1, '0')}:${String(sec).padStart(2, '0')}`
    );
});

// ---------- Exercício aberto ----------
// Por padrão abre o primeiro ainda não concluído; tocar em outro troca.
const openId = ref<string | null>(null);
let advanceTimer: ReturnType<typeof setTimeout> | undefined;
const currentId = computed(
    () =>
        openId.value ??
        props.session.exercises.find((e) => !exDone(e))?.id ??
        null,
);
function toggleOpen(ex: Ex) {
    openId.value = currentId.value === ex.id ? '' : ex.id;
}
// Alterna os dois quadros do movimento a cada segundo.
// Janela "Como fazer" (animação + passo a passo), aberta ao tocar na imagem.
const howTo = ref<Ex | null>(null);
const howToOpen = ref(false);
function openHowTo(ex: Ex) {
    howTo.value = ex;
    howToOpen.value = true;
}
const doneSets = (ex: Ex) => ex.sets.filter((s) => isDone(s)).length;
const completedCount = computed(() =>
    props.session.exercises.reduce(
        (n, e) => n + e.sets.filter((s) => isDone(s)).length,
        0,
    ),
);
const progress = computed(() =>
    totalSets.value
        ? Math.round((completedCount.value / totalSets.value) * 100)
        : 0,
);

// ---------- Descanso ----------
const restEndsAt = ref<number | null>(null);
const restLeft = computed(() =>
    restEndsAt.value
        ? Math.max(0, Math.ceil((restEndsAt.value - now.value) / 1000))
        : 0,
);
function startRest(seconds: number | null | undefined) {
    if (!seconds || seconds <= 0) return;
    prepareRestAlert();
    restEndsAt.value = Date.now() + seconds * 1000;
}
// Fim do descanso: bipe + vibração (e notificação se o app estiver em segundo plano).
watch(restLeft, (left, before) => {
    if (left === 0 && before > 0 && restEndsAt.value) {
        restEndsAt.value = null;
        const next = props.session.exercises.find((e) => !exDone(e));
        void fireRestAlert(next?.name ?? null);
    }
});

// ---------- Séries ----------
// Valores digitados ficam aqui até salvar; assim a tela não "pula" a cada gravação.
// "time" (cardio em minutos; alongamento em segundos) e "dist" (km) só valem fora da musculação.
const draft = ref<
    Record<string, { reps: string; load: string; time: string; dist: string }>
>({});
const kindOf = (set: SetRow) =>
    props.session.exercises.find((e) => e.sets.some((s) => s.id === set.id))
        ?.kind ?? 'strength';
const dec = (v: number) => String(Number(v.toFixed(2))).replace('.', ',');
function field(set: SetRow) {
    const kind = kindOf(set);
    return (draft.value[set.id] ??= {
        reps: set.reps?.toString() ?? '',
        load: set.load_kg?.toString() ?? '',
        time: set.duration_sec
            ? kind === 'cardio'
                ? dec(set.duration_sec / 60)
                : String(set.duration_sec)
            : '',
        dist: set.distance_km ? dec(set.distance_km) : '',
    });
}
/** O que é enviado ao salvar a série, conforme o tipo do exercício. */
function payload(ex: Ex, set: SetRow) {
    const f = field(set);
    if (ex.kind === 'strength')
        return { reps: int(f.reps), load_kg: num(f.load) };
    const t = num(f.time);
    const duration =
        t === null ? null : Math.round(ex.kind === 'cardio' ? t * 60 : t);
    return ex.kind === 'cardio'
        ? { duration_sec: duration, distance_km: num(f.dist) }
        : { duration_sec: duration };
}
const num = (v: string) => {
    const n = v.trim() === '' ? null : Number(v.replace(',', '.'));
    return n === null || Number.isFinite(n) ? n : null;
};
const int = (v: string) => {
    const n = num(v);
    return n === null ? null : Math.round(n);
};

// Marcação instantânea: a série fica verde no toque, sem esperar a internet da academia.
// O valor local vale até a resposta do servidor chegar; se ele recusar, volta e avisa.
const localDone = ref<Record<string, boolean>>({});
const localExDone = ref<Record<string, boolean>>({});
const isDone = (set: SetRow) => localDone.value[set.id] ?? set.completed;
const exDone = (ex: Ex) =>
    localExDone.value[ex.id] ??
    (ex.sets.length && ex.sets.some((s) => s.id in localDone.value)
        ? ex.sets.every(isDone)
        : ex.completed);
const saveError = ref('');
let saveErrorTimer: ReturnType<typeof setTimeout> | undefined;
function showSaveError(message: string) {
    saveError.value = message;
    clearTimeout(saveErrorTimer);
    saveErrorTimer = setTimeout(() => (saveError.value = ''), 6000);
}
// Quando o servidor responde, o valor dele passa a valer e a marcação local é descartada.
watch(
    () => props.session.exercises,
    (exercises) => {
        for (const ex of exercises) {
            if (localExDone.value[ex.id] === ex.completed)
                delete localExDone.value[ex.id];
            for (const s of ex.sets)
                if (localDone.value[s.id] === s.completed)
                    delete localDone.value[s.id];
        }
    },
    { deep: true },
);

// Pedidos de série são independentes (async): um toque não cancela o anterior.
const setVisit = (onFail: () => void) => ({
    ...keep,
    async: true,
    onError: (errors: Record<string, string>) => {
        onFail();
        showSaveError(
            `Não foi possível salvar: ${Object.values(errors)[0] ?? 'tente de novo.'}`,
        );
    },
    onNetworkError: () => {
        onFail();
        showSaveError(
            'Sem conexão: a série não foi salva. Toque de novo quando a internet voltar.',
        );
    },
    onHttpException: () => {
        onFail();
        showSaveError('O servidor não respondeu. Toque de novo.');
    },
});

function saveSet(ex: Ex, set: SetRow) {
    router.patch(
        setUpdate(set.id).url,
        payload(ex, set),
        setVisit(() => {}),
    );
}

function toggleSet(ex: Ex, set: SetRow) {
    const f = field(set);
    const completing = !isDone(set);
    // Ao concluir sem repetições digitadas, usa o alvo máximo como ponto de partida.
    if (ex.kind === 'strength') {
        if (completing && f.reps.trim() === '' && ex.target?.reps_max)
            f.reps = String(ex.target.reps_max);
    } else if (completing && f.time.trim() === '') {
        // Cardio/alongamento sem tempo digitado: usa o da última vez ou o sugerido.
        const hint = lastTime(ex, set.set_number);
        if (hint) f.time = hint;
    }
    localDone.value[set.id] = completing;
    // Última série do exercício: mostra tudo verde por um instante antes de avançar para o próximo.
    if (completing && ex.sets.every(isDone)) {
        openId.value = ex.id;
        clearTimeout(advanceTimer);
        advanceTimer = setTimeout(() => {
            // Usa o exercício atual (os dados podem ter sido atualizados pelo servidor nesse meio tempo).
            const fresh = props.session.exercises.find((e) => e.id === ex.id);
            if (openId.value === ex.id && fresh && exDone(fresh))
                openId.value = null;
        }, 1500);
    }
    router.patch(
        setUpdate(set.id).url,
        { ...payload(ex, set), completed: completing },
        setVisit(() => delete localDone.value[set.id]),
    );
    if (completing) startRest(ex.target?.rest_seconds ?? 90);
}

/** Carga usada nessa série na última vez (dica no campo vazio). */
function lastLoad(ex: Ex, setNumber: number): string | null {
    const l =
        ex.progression?.last.sets[setNumber - 1]?.load_kg ??
        (ex.previous[setNumber] ? Number(ex.previous[setNumber]) : null);
    return l ? String(l).replace('.', ',') : null;
}

/** Repetições feitas nessa série na última vez (dica no campo vazio). */
function lastReps(ex: Ex, setNumber: number): string | null {
    const r = ex.progression?.last.sets[setNumber - 1]?.reps;
    return r ? String(r) : null;
}

/** Tempo da última vez nessa série (ou o sugerido pelo exercício), já na unidade do campo. */
function lastTime(ex: Ex, setNumber: number): string | null {
    const sec =
        ex.progression?.last.sets[setNumber - 1]?.duration_sec ??
        ex.target_duration_sec;
    if (!sec) return null;
    return ex.kind === 'cardio' ? dec(sec / 60) : String(sec);
}
function lastDist(ex: Ex, setNumber: number): string | null {
    const km = ex.progression?.last.sets[setNumber - 1]?.distance_km;
    return km ? dec(km) : null;
}

/** "Meta de hoje": preenche a carga (e as repetições, se houver) das séries ainda não feitas. */
function applySuggestion(ex: Ex) {
    const s = ex.progression?.suggestion;
    if (!s || ex.kind !== 'strength') return;
    for (const set of ex.sets) {
        if (isDone(set)) continue;
        const f = field(set);
        f.load = String(s.load_kg).replace('.', ',');
        if (s.reps) f.reps = String(s.reps);
        saveSet(ex, set);
    }
}

function addSet(ex: Ex) {
    router.post(setStore(ex.id).url, {}, keep);
}

function removeSet(set: SetRow) {
    router.delete(setDestroy(set.id).url, keep);
}

function toggleExercise(ex: Ex) {
    const completing = !exDone(ex);
    localExDone.value[ex.id] = completing;
    router.patch(
        seUpdate(ex.id).url,
        { completed: completing },
        setVisit(() => delete localExDone.value[ex.id]),
    );
}

function removeExercise(ex: Ex) {
    if (confirm(`Remover ${ex.name} deste treino?`))
        router.delete(seDestroy(ex.id).url, keep);
}

const pickerOpen = ref(false);
function addExercise(exerciseId: number) {
    router.post(
        sessionExerciseStore(props.session.id).url,
        { exercise_id: exerciseId },
        keep,
    );
}

function saveNotes(e: Event) {
    router.patch(
        sessionUpdate(props.session.id).url,
        { notes: (e.target as HTMLTextAreaElement).value || null },
        keep,
    );
}

function finish() {
    const pending = totalSets.value - completedCount.value;
    const msg =
        pending > 0
            ? `Ainda há ${pending} série(s) não marcadas. Finalizar mesmo assim?`
            : 'Finalizar o treino?';
    if (confirm(msg)) {
        router.patch(
            sessionUpdate(props.session.id).url,
            { finished: true },
            {
                // Fim do treino: bom momento para o pedido de apoio (se estiver na vez).
                onSuccess: () =>
                    window.dispatchEvent(new Event(WORKOUT_FINISHED_EVENT)),
            },
        );
    }
}

function reopen() {
    router.patch(
        sessionUpdate(props.session.id).url,
        { finished: false },
        keep,
    );
}

function discard() {
    if (confirm('Excluir este treino? As séries registradas serão apagadas.'))
        router.delete(sessionDestroy(props.session.id).url);
}

function previousText(ex: Ex) {
    const loads = Object.values(ex.previous);
    return loads.length
        ? `Última vez: ${loads.map((l) => Number(l)).join(' / ')} kg`
        : null;
}

function targetText(ex: Ex) {
    if (ex.kind !== 'strength') {
        const sec = ex.target_duration_sec;
        if (!sec) return null;
        return ex.kind === 'cardio' ? `${dec(sec / 60)} min` : `${sec}s`;
    }
    const t = ex.target;
    if (!t) return null;
    const reps =
        t.reps_min && t.reps_max
            ? t.reps_min === t.reps_max
                ? `${t.reps_min}`
                : `${t.reps_min}–${t.reps_max}`
            : null;
    return [
        reps && `${reps} reps`,
        t.rest_seconds && `descanso ${t.rest_seconds}s`,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <Head :title="session.title" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4 pb-32">
        <!-- Cabeçalho: voltar, título e adicionar exercício -->
        <div class="flex items-center gap-3">
            <Link
                :href="sessionsIndex()"
                class="rounded-full p-1 text-muted-foreground hover:text-foreground"
                aria-label="Voltar ao histórico"
            >
                <ArrowLeft class="size-5" />
            </Link>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-lg font-semibold">
                    {{ session.title }}
                </h1>
                <p class="text-xs text-muted-foreground">
                    {{ completedCount }}/{{ totalSets }} séries ·
                    {{ session.volume_kg }} kg de volume
                </p>
            </div>
            <button
                type="button"
                class="flex size-10 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md hover:brightness-95"
                aria-label="Adicionar exercício"
                @click="pickerOpen = true"
            >
                <Plus class="size-5" />
            </button>
        </div>
        <div
            class="h-1 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Séries concluídas"
        >
            <div
                class="h-full rounded-full bg-primary transition-all"
                :style="{ width: progress + '%' }"
            ></div>
        </div>

        <div
            v-if="finished"
            class="flex items-center justify-between gap-2 rounded-xl bg-card p-3 text-sm"
        >
            <span
                >Concluído em
                {{ new Date(session.finished_at!).toLocaleString('pt-BR') }} ·
                {{ session.duration_min }} min</span
            >
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    v-if="session.share"
                    size="sm"
                    data-test="open-share"
                    @click="shareOpen = true"
                    ><Share2 class="size-4" /> Compartilhar</Button
                >
                <Button variant="ghost" size="sm" @click="reopen"
                    >Reabrir</Button
                >
            </div>
        </div>
        <ShareWorkoutDialog
            v-if="session.share"
            v-model:open="shareOpen"
            :title="session.title"
            :started-at="session.started_at"
            :duration-min="session.duration_min"
            :volume-kg="session.volume_kg"
            :sets="session.completed_sets"
            :share="session.share"
        />

        <!-- Lista de exercícios -->
        <template v-for="ex in session.exercises" :key="ex.id">
            <section
                class="overflow-hidden rounded-xl border-l-4 bg-card"
                :class="
                    currentId === ex.id
                        ? 'border-l-primary'
                        : exDone(ex)
                          ? 'border-l-success/60'
                          : 'border-l-transparent'
                "
                :data-test="`session-exercise-${ex.exercise_id}`"
            >
                <!-- Linha resumida (toque para abrir) -->
                <div class="flex items-center gap-3 p-3">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 text-left"
                        :aria-expanded="currentId === ex.id"
                        @click="toggleOpen(ex)"
                    >
                        <div
                            v-if="currentId !== ex.id"
                            class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                        >
                            <img
                                v-if="ex.image"
                                :src="ex.image"
                                alt=""
                                class="size-full object-cover"
                                loading="lazy"
                            />
                            <Dumbbell
                                v-else
                                class="size-5 text-muted-foreground"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ ex.name }}</p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ ex.sets.length }} séries<template
                                    v-if="targetText(ex)"
                                >
                                    · {{ targetText(ex) }}</template
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{
                                    previousText(ex) ??
                                    ex.muscle_groups.join(', ')
                                }}
                            </p>
                        </div>
                        <ChevronDown
                            class="size-4 shrink-0 text-muted-foreground transition-transform"
                            :class="currentId === ex.id && 'rotate-180'"
                        />
                    </button>
                    <button
                        type="button"
                        class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                        :class="
                            exDone(ex)
                                ? 'border-success bg-success text-white'
                                : 'border-muted-foreground/40 text-transparent hover:border-success'
                        "
                        :aria-label="
                            exDone(ex)
                                ? 'Desmarcar exercício'
                                : 'Marcar exercício como feito'
                        "
                        @click="toggleExercise(ex)"
                    >
                        <Check class="size-4" stroke-width="3" />
                    </button>
                </div>

                <!-- Exercício aberto: imagem grande, séries e ações -->
                <div v-if="currentId === ex.id" class="px-3 pb-3">
                    <!-- Toque na imagem: abre o "Como fazer" com a animação, sem sair do treino -->
                    <button
                        v-if="ex.frames.length"
                        type="button"
                        class="group relative block w-full overflow-hidden rounded-2xl bg-white"
                        :aria-label="`Ver como fazer ${ex.name}`"
                        :data-test="`howto-${ex.exercise_id}`"
                        @click="openHowTo(ex)"
                    >
                        <img
                            :src="ex.frames[0]"
                            :alt="`Execução de ${ex.name}`"
                            class="aspect-[4/3] max-h-72 w-full object-contain"
                        />
                        <span
                            class="absolute inset-0 flex items-center justify-center bg-black/10 transition group-hover:bg-black/20"
                        >
                            <span
                                class="flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg"
                                ><Play class="size-6 fill-current"
                            /></span>
                        </span>
                        <span
                            class="absolute bottom-2 left-2 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white"
                            >Ver como fazer</span
                        >
                    </button>
                    <p
                        v-if="ex.target?.notes"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        {{ ex.target.notes }}
                    </p>
                    <!-- Última vez, evolução e meta de hoje -->
                    <ProgressionPanel
                        v-if="ex.progression"
                        :progression="ex.progression"
                        :kind="ex.kind"
                        class="mt-3"
                        @apply="applySuggestion(ex)"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ doneSets(ex) }}/{{ ex.sets.length }} séries feitas
                    </p>

                    <div
                        class="mt-2 grid items-center gap-2 text-xs text-muted-foreground"
                        :class="
                            ex.kind === 'time'
                                ? 'grid-cols-[2rem_1fr_2.75rem_2rem]'
                                : 'grid-cols-[2rem_1fr_1fr_2.75rem_2rem]'
                        "
                    >
                        <span>#</span>
                        <template v-if="ex.kind === 'strength'"
                            ><span>Carga (kg)</span><span>Reps</span></template
                        >
                        <template v-else-if="ex.kind === 'cardio'"
                            ><span>Tempo (min)</span
                            ><span>Distância (km)</span></template
                        >
                        <span v-else>Tempo (s)</span>
                        <span></span><span></span>
                    </div>
                    <div
                        v-for="set in ex.sets"
                        :key="set.id"
                        class="mt-1 grid items-center gap-2"
                        :class="
                            ex.kind === 'time'
                                ? 'grid-cols-[2rem_1fr_2.75rem_2rem]'
                                : 'grid-cols-[2rem_1fr_1fr_2.75rem_2rem]'
                        "
                        :data-test="`set-${set.id}`"
                    >
                        <span
                            class="text-sm font-semibold"
                            :class="
                                isDone(set)
                                    ? 'text-success'
                                    : 'text-muted-foreground'
                            "
                            >{{ set.set_number }}</span
                        >
                        <template v-if="ex.kind !== 'strength'">
                            <Input
                                v-model="field(set).time"
                                type="text"
                                inputmode="decimal"
                                :placeholder="
                                    lastTime(ex, set.set_number) ?? '–'
                                "
                                class="h-10 bg-muted/40 text-center"
                                :aria-label="
                                    ex.kind === 'cardio'
                                        ? 'Tempo em minutos'
                                        : 'Tempo em segundos'
                                "
                                :data-test="`time-${set.id}`"
                                @change="saveSet(ex, set)"
                            />
                            <Input
                                v-if="ex.kind === 'cardio'"
                                v-model="field(set).dist"
                                type="text"
                                inputmode="decimal"
                                :placeholder="
                                    lastDist(ex, set.set_number) ?? '–'
                                "
                                class="h-10 bg-muted/40 text-center"
                                aria-label="Distância em km"
                                @change="saveSet(ex, set)"
                            />
                        </template>
                        <template v-else>
                            <Input
                                v-model="field(set).load"
                                type="text"
                                inputmode="decimal"
                                :placeholder="
                                    lastLoad(ex, set.set_number) ?? '–'
                                "
                                class="h-10 bg-muted/40 text-center"
                                aria-label="Carga em kg"
                                @change="saveSet(ex, set)"
                            />
                            <Input
                                v-model="field(set).reps"
                                type="text"
                                inputmode="numeric"
                                :placeholder="
                                    lastReps(ex, set.set_number) ??
                                    (ex.target?.reps_max
                                        ? String(ex.target.reps_max)
                                        : '–')
                                "
                                class="h-10 bg-muted/40 text-center"
                                aria-label="Repetições"
                                @change="saveSet(ex, set)"
                            />
                        </template>
                        <button
                            type="button"
                            class="flex size-10 items-center justify-center rounded-full border-2 transition-colors"
                            :class="
                                isDone(set)
                                    ? 'border-success bg-success text-white'
                                    : 'border-muted-foreground/40 text-muted-foreground hover:border-success'
                            "
                            :aria-label="
                                isDone(set)
                                    ? 'Desmarcar série'
                                    : 'Concluir série'
                            "
                            :data-test="`complete-${set.id}`"
                            @click="toggleSet(ex, set)"
                        >
                            <Check class="size-4" stroke-width="3" />
                        </button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            aria-label="Remover série"
                            @click="removeSet(set)"
                        >
                            <X class="size-3" />
                        </Button>
                    </div>
                    <div
                        class="mt-3 flex items-center justify-between border-t pt-2"
                    >
                        <Button variant="ghost" size="sm" @click="addSet(ex)"
                            ><Plus class="size-4" /> Série</Button
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            @click="removeExercise(ex)"
                            ><Trash2 class="size-4" /> Remover</Button
                        >
                    </div>
                </div>
            </section>

            <!-- Descanso, logo abaixo do exercício em andamento -->
            <section
                v-if="restLeft > 0 && currentId === ex.id"
                class="flex items-center gap-3 rounded-xl border-l-4 border-l-transparent bg-card p-3"
                role="timer"
                aria-live="polite"
            >
                <div
                    class="relative flex size-16 shrink-0 items-center justify-center rounded-full bg-primary text-xl font-bold text-primary-foreground tabular-nums"
                >
                    {{ restLeft }}
                    <Timer
                        class="absolute -top-1 -right-1 size-5 rounded-full bg-card p-0.5 text-primary"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">Descanso</p>
                    <p class="text-sm text-muted-foreground">
                        {{ Math.floor(restLeft / 60) }}:{{
                            String(restLeft % 60).padStart(2, '0')
                        }}
                        restantes
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="restEndsAt = (restEndsAt ?? Date.now()) + 30000"
                    >+30s</Button
                >
                <Button variant="ghost" size="sm" @click="restEndsAt = null"
                    >Pular</Button
                >
            </section>
        </template>

        <p
            v-if="session.exercises.length === 0"
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            Treino livre: toque no
            <span class="font-semibold text-primary">+</span> para adicionar os
            exercícios.
        </p>

        <label class="mt-2 grid gap-1 text-sm">
            Observações
            <textarea
                :value="session.notes ?? ''"
                rows="2"
                maxlength="1000"
                class="rounded-lg border border-input bg-card p-2 text-sm"
                placeholder="Como foi o treino?"
                @change="saveNotes"
            ></textarea>
        </label>
        <Button
            variant="ghost"
            class="self-start text-destructive"
            @click="discard"
            >Excluir treino</Button
        >
    </div>

    <!-- Aviso quando uma série não foi salva (ex.: sem internet) -->
    <div
        v-if="saveError"
        class="fixed inset-x-4 bottom-24 z-50 mx-auto max-w-md rounded-lg bg-destructive px-4 py-3 text-sm font-medium text-white shadow-lg"
        role="alert"
        data-test="save-error"
    >
        {{ saveError }}
    </div>

    <!-- Barra inferior: descanso, tempo de treino e finalizar -->
    <div
        class="fixed inset-x-0 bottom-0 z-40 border-t bg-sidebar/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:left-[var(--sidebar-width)]"
    >
        <div
            class="mx-auto flex max-w-2xl items-center justify-between gap-4 px-6 py-3"
        >
            <button
                type="button"
                class="flex size-12 items-center justify-center rounded-full border-2 border-foreground/70 disabled:opacity-40"
                :aria-label="
                    restLeft > 0 ? 'Pular descanso' : 'Iniciar descanso'
                "
                :disabled="finished"
                @click="
                    restLeft > 0
                        ? (restEndsAt = null)
                        : startRest(
                              session.exercises.find((e) => e.id === currentId)
                                  ?.target?.rest_seconds ?? 90,
                          )
                "
            >
                <Timer class="size-5" />
            </button>
            <div class="text-center">
                <p class="text-xl font-bold tabular-nums">
                    {{ finished ? `${session.duration_min} min` : elapsed }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        finished
                            ? 'concluído'
                            : restLeft > 0
                              ? `descanso ${restLeft}s`
                              : 'tempo de treino'
                    }}
                </p>
            </div>
            <button
                v-if="!finished"
                type="button"
                class="flex size-12 items-center justify-center rounded-full border-2 border-foreground/70 hover:border-primary hover:text-primary"
                aria-label="Finalizar treino"
                data-test="finish-session"
                @click="finish"
            >
                <Square class="size-4 fill-current" />
            </button>
            <button
                v-else
                type="button"
                class="flex size-12 items-center justify-center rounded-full border-2 border-foreground/70 text-xs"
                @click="reopen"
            >
                Abrir
            </button>
        </div>
    </div>

    <ExercisePicker v-model:open="pickerOpen" @select="addExercise" />
    <ExerciseHowTo v-model:open="howToOpen" :exercise="howTo" />
</template>
