<script setup lang="ts">
// Treino montado pela IA: a pessoa descreve o que quer (pode falar), a IA monta com
// exercícios da biblioteca e a pessoa revisa (troca, remove, ajusta) antes de salvar.
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Dumbbell,
    Mic,
    MicOff,
    Plus,
    RefreshCw,
    Replace,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ExercisePicker from '@/components/ExercisePicker.vue';
import type { PickedExercise } from '@/components/ExercisePicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDictation } from '@/composables/useDictation';
import { HttpError, postJson } from '@/lib/http';
import { goalLabels, levelLabels } from '@/lib/labels';
import { ai as workoutsAi, index as workoutsIndex } from '@/routes/workouts';
import { generate as aiGenerate, save as aiSave } from '@/routes/workouts/ai';

type Defaults = {
    goal: string;
    level: string;
    days: number;
    minutes: number;
    place: string;
};
type PlanExercise = {
    exercise_id: number;
    name: string | null;
    image: string | null;
    groups: string[];
    sets: number;
    reps_min: number | null;
    reps_max: number | null;
    rest_seconds: number | null;
    notes: string | null;
};
type PlanDay = { label: string; name: string; exercises: PlanExercise[] };
type Preview = {
    id: number;
    name: string;
    notes: string;
    days: PlanDay[];
    remaining: number;
};

const props = defineProps<{
    defaults: Defaults;
    places: { value: string; label: string }[];
    durations: number[];
    remaining: number;
    aiEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Treinos', href: workoutsIndex() },
            { title: 'Criar com IA', href: workoutsAi() },
        ],
    },
});

// ---------- Pedido ----------
const form = ref({ ...props.defaults, request: '' });
const error = ref('');
const generating = ref(false);
const remaining = ref(props.remaining);
const requestRef = ref('');
const dictation = useDictation(requestRef, (m) => (error.value = m));
// O ditado escreve em requestRef; espelha no formulário.
const syncRequest = () => (form.value.request = requestRef.value);

const examples = [
    'Quero focar em glúteo e posterior, tenho dor no joelho direito.',
    'Sou iniciante, quero perder barriga e não gosto de esteira.',
    'Quero braço e ombro maiores, treino no horário de almoço.',
];

async function generate() {
    dictation.stop();
    syncRequest();
    generating.value = true;
    error.value = '';
    try {
        const result = await postJson<Preview>(aiGenerate().url, form.value);
        preview.value = result;
        remaining.value = result.remaining;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        const body =
            e instanceof HttpError
                ? (e.body as {
                      errors?: Record<string, string[]>;
                      message?: string;
                  } | null)
                : null;
        error.value =
            e instanceof HttpError && e.status === 429
                ? 'Muitos pedidos seguidos. Aguarde um minuto.'
                : (body?.errors?.request?.[0] ??
                  Object.values(body?.errors ?? {})[0]?.[0] ??
                  'Não foi possível montar o treino agora. Tente de novo.');
    } finally {
        generating.value = false;
    }
}

// ---------- Prévia editável ----------
const preview = ref<Preview | null>(null);
const saving = ref(false);
const saveErrors = ref<Record<string, string>>({});

const pickerOpen = ref(false);
let pickTarget: { day: PlanDay; index: number | null } | null = null;
function openPicker(day: PlanDay, index: number | null) {
    pickTarget = { day, index };
    pickerOpen.value = true;
}
function onPick(id: number, ex?: PickedExercise) {
    if (!pickTarget) return;
    const base = {
        exercise_id: id,
        name: ex?.name ?? 'Exercício',
        image: ex?.image ?? null,
        groups: ex?.primary_groups ?? [],
    };
    const { day, index } = pickTarget;
    if (index === null) {
        day.exercises.push({
            ...base,
            sets: 3,
            reps_min: 8,
            reps_max: 12,
            rest_seconds: 60,
            notes: null,
        });
    } else {
        // Troca mantendo séries, repetições e descanso.
        day.exercises[index] = {
            ...day.exercises[index],
            ...base,
            notes: null,
        };
    }
    pickTarget = null;
}
function remove(day: PlanDay, index: number) {
    day.exercises.splice(index, 1);
}

const num = (v: unknown) =>
    v === '' || v === null || v === undefined ? null : Number(v);

function save() {
    if (!preview.value) return;
    saving.value = true;
    saveErrors.value = {};
    router.post(
        aiSave(preview.value.id).url,
        {
            name: preview.value.name,
            days: preview.value.days.map((d) => ({
                name: d.name,
                exercises: d.exercises.map((e) => ({
                    exercise_id: e.exercise_id,
                    sets: num(e.sets) ?? 3,
                    reps_min: num(e.reps_min),
                    reps_max: num(e.reps_max),
                    rest_seconds: num(e.rest_seconds),
                    notes: e.notes,
                })),
            })),
        },
        {
            onError: (e) => (saveErrors.value = e),
            onFinish: () => (saving.value = false),
        },
    );
}

function again() {
    preview.value = null;
    requestRef.value = form.value.request;
}

const firstSaveError = computed(() => Object.values(saveErrors.value)[0]);
const chip = (active: boolean) =>
    `rounded-full border px-3 py-1.5 text-sm transition-colors ${active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'}`;
</script>

<template>
    <Head title="Criar treino com IA" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 pb-24">
        <Link
            :href="workoutsIndex()"
            class="flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" /> Treinos
        </Link>

        <!-- 1) O que você quer -->
        <template v-if="!preview">
            <Heading
                title="Criar treino com IA"
                description="Conte o que você quer e a IA monta o plano com exercícios da biblioteca"
            />

            <p
                v-if="!aiEnabled"
                class="rounded-xl bg-card p-4 text-sm text-muted-foreground"
            >
                A IA não está configurada neste servidor.
            </p>

            <form
                v-else
                class="flex flex-col gap-5 rounded-xl bg-card p-4"
                @submit.prevent="generate"
            >
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">Objetivo</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(label, value) in goalLabels"
                            :key="value"
                            type="button"
                            :class="chip(form.goal === value)"
                            :aria-pressed="form.goal === value"
                            @click="form.goal = value"
                        >
                            {{ label }}
                        </button>
                    </div>
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">Nível</legend>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(label, value) in levelLabels"
                            :key="value"
                            type="button"
                            :class="chip(form.level === value)"
                            :aria-pressed="form.level === value"
                            @click="form.level = value"
                        >
                            {{ label }}
                        </button>
                    </div>
                </fieldset>

                <div class="grid gap-5 sm:grid-cols-2">
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            Dias por semana
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="d in 6"
                                :key="d"
                                type="button"
                                :class="chip(form.days === d)"
                                class="min-w-10"
                                :aria-pressed="form.days === d"
                                @click="form.days = d"
                            >
                                {{ d }}
                            </button>
                        </div>
                    </fieldset>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            Tempo por treino
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="m in durations"
                                :key="m"
                                type="button"
                                :class="chip(form.minutes === m)"
                                :aria-pressed="form.minutes === m"
                                @click="form.minutes = m"
                            >
                                {{ m }} min
                            </button>
                        </div>
                    </fieldset>
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Onde você treina
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="p in places"
                            :key="p.value"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                        >
                            <input
                                v-model="form.place"
                                type="radio"
                                name="place"
                                :value="p.value"
                            />
                            {{ p.label }}
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-2">
                        <label for="ai-request" class="text-sm font-medium"
                            >O que você quer?
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></label
                        >
                        <button
                            v-if="dictation.canListen"
                            type="button"
                            class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium"
                            :class="
                                dictation.listening.value
                                    ? 'bg-destructive text-white'
                                    : 'bg-primary text-primary-foreground'
                            "
                            :aria-pressed="dictation.listening.value"
                            data-test="ai-mic"
                            @click="dictation.toggle()"
                        >
                            <component
                                :is="dictation.listening.value ? MicOff : Mic"
                                class="size-4"
                            />
                            {{
                                dictation.listening.value
                                    ? `Parar · ${dictation.elapsed()}`
                                    : 'Falar'
                            }}
                        </button>
                    </div>
                    <textarea
                        id="ai-request"
                        v-model="requestRef"
                        rows="4"
                        maxlength="800"
                        class="rounded-lg border border-input bg-transparent p-2 text-sm"
                        placeholder="Ex.: quero focar em glúteo e posterior, tenho dor no joelho direito e não gosto de agachamento livre"
                        data-test="ai-request"
                        @input="syncRequest"
                    ></textarea>
                    <p
                        v-if="dictation.interim.value"
                        class="-mt-1 text-xs text-muted-foreground italic"
                    >
                        {{ dictation.interim.value }}…
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="ex in examples"
                            :key="ex"
                            type="button"
                            class="rounded-full bg-muted px-2.5 py-1 text-left text-xs text-muted-foreground hover:text-foreground"
                            @click="
                                requestRef = ex;
                                syncRequest();
                            "
                        >
                            {{ ex }}
                        </button>
                    </div>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    :disabled="generating || remaining <= 0"
                    data-test="ai-generate"
                >
                    <Sparkles
                        class="size-4"
                        :class="generating && 'animate-spin'"
                    />
                    {{
                        generating
                            ? 'Montando seu treino…'
                            : 'Montar meu treino'
                    }}
                </Button>
                <p class="-mt-2 text-center text-xs text-muted-foreground">
                    <template v-if="generating"
                        >Costuma levar de 5 a 20 segundos.</template
                    >
                    <template v-else
                        >Restam {{ remaining }} treinos com IA hoje. Nada é
                        salvo antes de você revisar.</template
                    >
                </p>
                <InputError :message="error" />
            </form>

            <!-- Prévia animada enquanto a IA monta -->
            <div v-if="generating" class="grid gap-3" aria-hidden="true">
                <div v-for="n in 3" :key="n" class="rounded-xl bg-card p-4">
                    <div class="h-4 w-1/3 animate-pulse rounded bg-muted"></div>
                    <div
                        v-for="k in 3"
                        :key="k"
                        class="mt-3 flex items-center gap-3"
                    >
                        <div
                            class="size-12 animate-pulse rounded-lg bg-muted"
                        ></div>
                        <div class="flex-1 space-y-2">
                            <div
                                class="h-3 w-1/2 animate-pulse rounded bg-muted"
                            ></div>
                            <div
                                class="h-3 w-1/3 animate-pulse rounded bg-muted/70"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- 2) Revisar e salvar -->
        <template v-else>
            <div class="flex flex-col gap-2 rounded-xl bg-card p-4">
                <label class="text-xs text-muted-foreground" for="plan-name"
                    >Nome do plano</label
                >
                <Input
                    id="plan-name"
                    v-model="preview.name"
                    class="text-base font-semibold"
                    maxlength="120"
                />
                <p
                    v-if="preview.notes"
                    class="flex gap-2 text-sm text-muted-foreground"
                >
                    <Sparkles class="mt-0.5 size-4 shrink-0 text-primary" />
                    {{ preview.notes }}
                </p>
            </div>

            <section
                v-for="day in preview.days"
                :key="day.label"
                class="rounded-xl bg-card p-4"
                :data-test="`ai-day-${day.label}`"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary font-bold text-primary-foreground"
                        >{{ day.label }}</span
                    >
                    <Input
                        v-model="day.name"
                        class="h-9 font-medium"
                        maxlength="120"
                        :aria-label="`Nome do treino ${day.label}`"
                    />
                </div>

                <ul class="mt-3 divide-y">
                    <li
                        v-for="(ex, i) in day.exercises"
                        :key="`${ex.exercise_id}-${i}`"
                        class="py-3"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
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
                                <p
                                    class="leading-tight font-medium break-words"
                                >
                                    {{ ex.name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ ex.groups.join(', ') }}
                                </p>
                                <p
                                    v-if="ex.notes"
                                    class="mt-0.5 text-xs text-muted-foreground italic"
                                >
                                    {{ ex.notes }}
                                </p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                    :aria-label="`Trocar ${ex.name}`"
                                    @click="openPicker(day, i)"
                                >
                                    <Replace class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    :aria-label="`Remover ${ex.name}`"
                                    @click="remove(day, i)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </div>
                        <!-- Séries × repetições e descanso -->
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm"
                        >
                            <label class="flex items-center gap-1">
                                <input
                                    v-model.number="ex.sets"
                                    type="number"
                                    min="1"
                                    max="10"
                                    class="h-8 w-12 rounded-md border border-input bg-transparent text-center"
                                    aria-label="Séries"
                                />
                                <span class="text-muted-foreground">×</span>
                            </label>
                            <span class="flex items-center gap-1">
                                <input
                                    v-model.number="ex.reps_min"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="h-8 w-12 rounded-md border border-input bg-transparent text-center"
                                    aria-label="Repetições mínimas"
                                />
                                <span class="text-muted-foreground">a</span>
                                <input
                                    v-model.number="ex.reps_max"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="h-8 w-12 rounded-md border border-input bg-transparent text-center"
                                    aria-label="Repetições máximas"
                                />
                                <span class="text-muted-foreground">reps</span>
                            </span>
                            <label class="flex items-center gap-1">
                                <input
                                    v-model.number="ex.rest_seconds"
                                    type="number"
                                    min="0"
                                    max="600"
                                    step="15"
                                    class="h-8 w-14 rounded-md border border-input bg-transparent text-center"
                                    aria-label="Descanso em segundos"
                                />
                                <span class="text-muted-foreground"
                                    >s descanso</span
                                >
                            </label>
                        </div>
                    </li>
                </ul>
                <p
                    v-if="!day.exercises.length"
                    class="py-3 text-sm text-muted-foreground"
                >
                    Sem exercícios. Adicione ao menos um.
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="mt-1"
                    @click="openPicker(day, null)"
                    ><Plus class="size-4" /> Adicionar exercício</Button
                >
            </section>

            <InputError :message="firstSaveError" />

            <!-- Ações fixas no rodapé -->
            <div
                class="fixed inset-x-0 bottom-0 z-40 border-t bg-sidebar/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:left-[var(--sidebar-width)]"
            >
                <div class="mx-auto flex max-w-2xl gap-2 px-4 py-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="flex-1"
                        :disabled="saving"
                        @click="again"
                        ><RefreshCw class="size-4" /> Gerar de novo</Button
                    >
                    <Button
                        type="button"
                        class="flex-1"
                        :disabled="saving"
                        data-test="ai-save"
                        @click="save"
                        >{{ saving ? 'Salvando…' : 'Salvar treino' }}</Button
                    >
                </div>
            </div>
        </template>
    </div>

    <ExercisePicker v-model:open="pickerOpen" @select="onPick" />
</template>
