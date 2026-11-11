<script setup lang="ts">
// Refeição falada: o próprio celular transcreve (Web Speech API, grátis, sem tokens);
// só o texto vai para a IA, que devolve os alimentos com a medida caseira para revisar.
import { router } from '@inertiajs/vue3';
import { Mic, MicOff, RotateCcw, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { FoodOption } from '@/components/AddFoodDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useDictation } from '@/composables/useDictation';
import { HttpError, postJson } from '@/lib/http';
import { confirm as photoConfirm } from '@/routes/meal-photos';
import { analyze as textAnalyze } from '@/routes/meal-texts';

type Macro = {
    kcal: number;
    protein_g: number;
    carbs_g: number;
    fat_g: number;
};
type Detected = {
    id: number;
    name: string;
    grams: number;
    portion: { label: string; count: number; grams_each: number } | null;
    estimate: Macro;
    food: FoodOption | null;
};
type Analysis = { id: number; items: Detected[]; remaining_today: number };
type Row = Detected & {
    checked: boolean;
    count: number | null;
    gramsInput: string;
};

const props = defineProps<{
    date: string;
    mealType: string;
    provider: string | null;
}>();
const emit = defineEmits<{ done: [] }>();

// ---------- Texto -> itens (IA) ----------
const analyzing = ref(false);
const error = ref('');

// ---------- Fala -> texto (no aparelho, grátis) ----------
const text = ref('');
const {
    canListen,
    listening,
    interim,
    seconds,
    toggle: toggleListening,
    stop: stopListening,
} = useDictation(text, (message) => (error.value = message));
const analysis = ref<Analysis | null>(null);
const rows = ref<Row[]>([]);
const saving = ref(false);

async function analyze() {
    stopListening();
    if (text.value.trim().length < 3) return;
    analyzing.value = true;
    error.value = '';
    try {
        const result = await postJson<Analysis>(textAnalyze().url, {
            text: text.value.trim(),
        });
        analysis.value = result;
        rows.value = result.items.map((i) => ({
            ...i,
            checked: true,
            count: i.portion?.count ?? null,
            gramsInput: String(Math.round(i.grams)),
        }));
        if (!rows.value.length)
            error.value =
                'Não encontrei alimentos no texto. Tente descrever de outro jeito.';
    } catch (err) {
        const body =
            err instanceof HttpError
                ? (err.body as { errors?: Record<string, string[]> } | null)
                : null;
        error.value =
            err instanceof HttpError && err.status === 429
                ? 'Muitas tentativas seguidas. Aguarde um minuto.'
                : (body?.errors?.text?.[0] ??
                  'Não foi possível entender agora. Tente de novo ou use a busca.');
    } finally {
        analyzing.value = false;
    }
}

function reset() {
    analysis.value = null;
    rows.value = [];
    error.value = '';
}

const grams = (r: Row) => {
    const v = Number(r.gramsInput.replace(',', '.'));
    return Number.isFinite(v) && v > 0 ? v : 0;
};
const fmtCount = (c: number) =>
    c === 0.5 ? '½' : Number.isInteger(c) ? String(c) : `${Math.floor(c)}½`;

/** Mais/menos meia medida; os gramas acompanham. */
function step(r: Row, delta: number) {
    if (!r.portion) return;
    const next = Math.max(0.5, (r.count ?? r.portion.count) + delta);
    r.count = next;
    r.gramsInput = String(Math.round(next * r.portion.grams_each));
}
/** Gramas digitados à mão: a medida caseira deixa de valer. */
function onGrams(r: Row) {
    r.count = null;
}

function values(r: Row): Macro {
    const g = grams(r);
    if (r.food) {
        const p = r.food.per_100;
        return {
            kcal: (p.kcal * g) / 100,
            protein_g: (p.protein * g) / 100,
            carbs_g: (p.carbs * g) / 100,
            fat_g: (p.fat * g) / 100,
        };
    }
    const s = r.grams > 0 ? g / r.grams : 1;
    return {
        kcal: r.estimate.kcal * s,
        protein_g: r.estimate.protein_g * s,
        carbs_g: r.estimate.carbs_g * s,
        fat_g: r.estimate.fat_g * s,
    };
}

const chosen = computed(() =>
    rows.value.filter((r) => r.checked && grams(r) > 0),
);
const total = computed(() =>
    chosen.value.reduce(
        (t, r) => {
            const v = values(r);
            return {
                kcal: t.kcal + v.kcal,
                protein_g: t.protein_g + v.protein_g,
                carbs_g: t.carbs_g + v.carbs_g,
                fat_g: t.fat_g + v.fat_g,
            };
        },
        { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 },
    ),
);

function save() {
    if (!analysis.value || !chosen.value.length) return;
    saving.value = true;
    router.post(
        photoConfirm(analysis.value.id).url,
        {
            meal_date: props.date,
            meal_type: props.mealType,
            items: chosen.value.map((r) => ({
                id: r.id,
                grams: grams(r),
                name: r.name,
                portion_count: r.count,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('done'),
            onError: (e) =>
                (error.value =
                    Object.values(e)[0] ?? 'Não foi possível salvar.'),
            onFinish: () => (saving.value = false),
        },
    );
}

const fmt = (v: number) => (Math.round(v * 10) / 10).toLocaleString('pt-BR');
</script>

<template>
    <div class="flex flex-col gap-3">
        <p v-if="!provider" class="text-sm text-muted-foreground">
            A análise com IA não está configurada neste servidor. Use a busca ou
            o cadastro manual.
        </p>

        <!-- 1) Falar ou digitar -->
        <template v-else-if="!analysis">
            <div v-if="canListen" class="flex flex-col items-center gap-2 py-2">
                <button
                    type="button"
                    class="relative flex size-20 items-center justify-center rounded-full shadow-md transition-colors"
                    :class="
                        listening
                            ? 'bg-destructive text-white'
                            : 'bg-primary text-primary-foreground'
                    "
                    :aria-label="
                        listening ? 'Parar de ouvir' : 'Falar o que comeu'
                    "
                    :aria-pressed="listening"
                    data-test="voice-mic"
                    @click="toggleListening"
                >
                    <span
                        v-if="listening"
                        class="absolute inset-0 animate-ping rounded-full bg-destructive/40"
                        aria-hidden="true"
                    ></span>
                    <component
                        :is="listening ? MicOff : Mic"
                        class="relative size-8"
                    />
                </button>
                <p
                    class="text-sm"
                    :class="listening ? 'font-medium' : 'text-muted-foreground'"
                >
                    {{
                        listening
                            ? `Ouvindo… ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} · toque para parar`
                            : text.trim()
                              ? 'Toque de novo para falar mais'
                              : 'Toque e fale o que comeu'
                    }}
                </p>
            </div>

            <label class="grid gap-1 text-sm">
                <span class="text-muted-foreground">{{
                    canListen
                        ? 'Ou digite / corrija o texto:'
                        : 'Descreva o que comeu:'
                }}</span>
                <textarea
                    v-model="text"
                    rows="3"
                    maxlength="500"
                    class="rounded-lg border border-input bg-transparent p-2 text-sm"
                    placeholder="Ex.: arroz, feijão e um bife, uma colher de cada, e um copo de suco de laranja"
                    data-test="voice-text"
                ></textarea>
            </label>
            <p
                v-if="interim"
                class="-mt-2 text-xs text-muted-foreground italic"
            >
                {{ interim }}…
            </p>

            <Button
                :disabled="analyzing || text.trim().length < 3"
                data-test="voice-analyze"
                @click="analyze"
            >
                <Sparkles class="size-4" :class="analyzing && 'animate-spin'" />
                {{
                    analyzing ? 'Separando os alimentos…' : 'Entender refeição'
                }}
            </Button>
            <!-- Prévia enquanto a IA trabalha (costuma levar 2 a 5 s) -->
            <ul
                v-if="analyzing"
                class="divide-y rounded-lg border"
                aria-hidden="true"
            >
                <li v-for="n in 3" :key="n" class="flex items-center gap-3 p-3">
                    <span
                        class="size-5 shrink-0 animate-pulse rounded bg-muted"
                    ></span>
                    <span class="flex-1 space-y-2">
                        <span
                            class="block h-3.5 w-2/5 animate-pulse rounded bg-muted"
                        ></span>
                        <span
                            class="block h-3 w-3/5 animate-pulse rounded bg-muted/70"
                        ></span>
                    </span>
                    <span
                        class="h-8 w-16 animate-pulse rounded bg-muted"
                    ></span>
                </li>
            </ul>
            <p class="text-[11px] text-muted-foreground">
                A voz é transcrita pelo próprio celular; só o texto vai para a
                IA ({{ provider === 'openai' ? 'OpenAI' : 'Google Gemini' }}),
                que separa os alimentos. Nada é salvo antes de você conferir.
            </p>
        </template>

        <!-- 2) Conferir -->
        <template v-else>
            <div
                class="flex items-start justify-between gap-2 rounded-lg bg-muted/40 p-2 text-sm"
            >
                <p class="italic">“{{ text }}”</p>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground underline underline-offset-4"
                    @click="reset"
                >
                    <RotateCcw class="size-3" /> Refazer
                </button>
            </div>
            <p class="text-xs text-muted-foreground">
                Restam {{ analysis.remaining_today }} análises hoje.
            </p>

            <ul
                v-if="rows.length"
                class="divide-y rounded-lg border"
                data-test="voice-items"
            >
                <li
                    v-for="r in rows"
                    :key="r.id"
                    class="p-3 transition-opacity"
                    :class="!r.checked && 'opacity-50'"
                >
                    <div class="flex items-start gap-3">
                        <input
                            v-model="r.checked"
                            type="checkbox"
                            class="mt-0.5 size-5 shrink-0 accent-[var(--primary)]"
                            :aria-label="`Incluir ${r.name}`"
                        />
                        <div class="min-w-0 flex-1">
                            <!-- Nome e calorias na mesma linha -->
                            <div
                                class="flex items-baseline justify-between gap-2"
                            >
                                <p
                                    class="min-w-0 leading-tight font-medium break-words"
                                >
                                    {{ r.name }}
                                </p>
                                <p class="shrink-0 font-semibold tabular-nums">
                                    {{ Math.round(values(r).kcal) }}
                                    <span
                                        class="text-xs font-normal text-muted-foreground"
                                        >kcal</span
                                    >
                                </p>
                            </div>
                            <p
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{
                                    r.food
                                        ? `TACO: ${r.food.name}`
                                        : 'Valores estimados pela IA'
                                }}
                            </p>
                            <p
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                P {{ fmt(values(r).protein_g) }} g · C
                                {{ fmt(values(r).carbs_g) }} g · G
                                {{ fmt(values(r).fat_g) }} g
                            </p>
                        </div>
                    </div>

                    <!-- Quantidade (largura toda do item): medida caseira (− / +) e gramas -->
                    <div class="mt-2 flex items-center gap-2">
                        <div
                            v-if="r.portion && r.count !== null"
                            class="flex min-w-0 flex-1 items-center gap-2"
                        >
                            <div
                                class="flex shrink-0 overflow-hidden rounded-lg border border-input"
                            >
                                <button
                                    type="button"
                                    class="flex size-9 items-center justify-center text-lg hover:bg-muted disabled:opacity-40"
                                    aria-label="Menos meia medida"
                                    :disabled="!r.checked || r.count <= 0.5"
                                    @click="step(r, -0.5)"
                                >
                                    −
                                </button>
                                <button
                                    type="button"
                                    class="flex size-9 items-center justify-center border-l border-input text-lg hover:bg-muted disabled:opacity-40"
                                    aria-label="Mais meia medida"
                                    :disabled="!r.checked"
                                    @click="step(r, 0.5)"
                                >
                                    +
                                </button>
                            </div>
                            <!-- Pode quebrar linha ("colher de servir") em vez de cortar o texto. -->
                            <span
                                class="min-w-0 text-sm leading-tight tabular-nums"
                            >
                                <strong>{{ fmtCount(r.count) }}×</strong>
                                {{ r.portion.label }}
                            </span>
                        </div>
                        <label
                            class="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                        >
                            <input
                                v-model="r.gramsInput"
                                inputmode="decimal"
                                class="h-9 w-16 rounded-lg border border-input bg-transparent px-2 text-right text-sm text-foreground"
                                :aria-label="`Gramas de ${r.name}`"
                                :disabled="!r.checked"
                                @input="onGrams(r)"
                            />
                            g
                        </label>
                    </div>
                </li>
            </ul>

            <div
                v-if="chosen.length"
                class="grid grid-cols-4 gap-2 rounded-lg bg-muted p-3 text-center text-sm"
            >
                <div>
                    <p class="text-lg font-semibold">
                        {{ Math.round(total.kcal) }}
                    </p>
                    <p class="text-xs text-muted-foreground">kcal</p>
                </div>
                <div>
                    <p class="text-lg font-semibold">
                        {{ fmt(total.protein_g) }}
                    </p>
                    <p class="text-xs text-muted-foreground">prot. (g)</p>
                </div>
                <div>
                    <p class="text-lg font-semibold">
                        {{ fmt(total.carbs_g) }}
                    </p>
                    <p class="text-xs text-muted-foreground">carb. (g)</p>
                </div>
                <div>
                    <p class="text-lg font-semibold">{{ fmt(total.fat_g) }}</p>
                    <p class="text-xs text-muted-foreground">gord. (g)</p>
                </div>
            </div>
            <p v-if="rows.length" class="text-[11px] text-muted-foreground">
                Medidas caseiras pelas médias do IBGE. Ajuste o que for preciso
                e desmarque o que não comeu.
            </p>

            <Button
                v-if="rows.length"
                :disabled="saving || !chosen.length"
                data-test="voice-confirm"
                @click="save"
            >
                Adicionar {{ chosen.length }}
                {{ chosen.length === 1 ? 'alimento' : 'alimentos' }}
            </Button>
        </template>

        <InputError :message="error" />
    </div>
</template>
