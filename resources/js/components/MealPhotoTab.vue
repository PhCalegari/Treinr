<script setup lang="ts">
// Foto do prato: a IA sugere alimentos e porções; o usuário confere, corrige e só então salva.
import { router } from '@inertiajs/vue3';
import { Camera, ImageUp, RotateCcw, TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import type { FoodOption } from '@/components/AddFoodDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { HttpError, postForm } from '@/lib/http';
import { prepareImage } from '@/lib/image';
import { analyze as photoAnalyze, confirm as photoConfirm } from '@/routes/meal-photos';

type Macro = { kcal: number; protein_g: number; carbs_g: number; fat_g: number };
type Detected = { id: number; name: string; grams: number; confidence: number | null; estimate: Macro; food: FoodOption | null };
type Analysis = { id: number; is_food: boolean; notes: string | null; items: Detected[]; remaining_today: number };
type Row = Detected & { checked: boolean; gramsInput: string };

const props = defineProps<{ date: string; mealType: string; provider: string | null }>();
const emit = defineEmits<{ done: [] }>();

const previewUrl = ref<string | null>(null);
const analyzing = ref(false);
const error = ref('');
const analysis = ref<Analysis | null>(null);
const rows = ref<Row[]>([]);
const saving = ref(false);

onBeforeUnmount(() => previewUrl.value && URL.revokeObjectURL(previewUrl.value));

async function pick(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    reset();
    // 512 px bastam para reconhecer o prato e reduzem o custo (tokens) e os dados do celular.
    const prepared = await prepareImage(file, 512, 0.85);
    previewUrl.value = URL.createObjectURL(prepared);
    analyzing.value = true;
    try {
        const data = new FormData();
        data.append('photo', prepared);
        const result = await postForm<Analysis>(photoAnalyze().url, data);
        analysis.value = result;
        rows.value = result.items.map((i) => ({ ...i, checked: true, gramsInput: String(Math.round(i.grams)) }));
    } catch (err) {
        const body = err instanceof HttpError ? (err.body as { errors?: Record<string, string[]>; message?: string } | null) : null;
        error.value =
            err instanceof HttpError && err.status === 429
                ? 'Muitas fotos seguidas. Aguarde um minuto.'
                : (body?.errors?.photo?.[0] ?? 'Não foi possível analisar a foto agora. Tente de novo ou registre pela busca.');
    } finally {
        analyzing.value = false;
    }
}

function reset() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    analysis.value = null;
    rows.value = [];
    error.value = '';
}

const grams = (r: Row) => {
    const v = Number(r.gramsInput.replace(',', '.'));
    return Number.isFinite(v) && v > 0 ? v : 0;
};

/** Valores da porção: pela TACO quando houver correspondência; senão, a estimativa da IA reescalada. */
function values(r: Row): Macro {
    const g = grams(r);
    if (r.food) {
        const p = r.food.per_100;
        return { kcal: (p.kcal * g) / 100, protein_g: (p.protein * g) / 100, carbs_g: (p.carbs * g) / 100, fat_g: (p.fat * g) / 100 };
    }
    const s = r.grams > 0 ? g / r.grams : 1;
    return { kcal: r.estimate.kcal * s, protein_g: r.estimate.protein_g * s, carbs_g: r.estimate.carbs_g * s, fat_g: r.estimate.fat_g * s };
}

const chosen = computed(() => rows.value.filter((r) => r.checked && grams(r) > 0));
const total = computed(() =>
    chosen.value.reduce(
        (t, r) => {
            const v = values(r);
            return { kcal: t.kcal + v.kcal, protein_g: t.protein_g + v.protein_g, carbs_g: t.carbs_g + v.carbs_g, fat_g: t.fat_g + v.fat_g };
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
            items: chosen.value.map((r) => ({ id: r.id, grams: grams(r), name: r.name })),
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('done'),
            onError: (e) => (error.value = Object.values(e)[0] ?? 'Não foi possível salvar.'),
            onFinish: () => (saving.value = false),
        },
    );
}

const fmt = (v: number) => (Math.round(v * 10) / 10).toLocaleString('pt-BR');
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Escolher foto -->
        <p v-if="!provider" class="text-sm text-muted-foreground">A análise por foto não está configurada neste servidor. Use a busca ou o cadastro manual.</p>
        <template v-else-if="!previewUrl">
            <p class="text-sm text-muted-foreground">
                Fotografe o prato de cima, inteiro e com boa luz. A IA sugere os alimentos e as porções; você confere antes de salvar.
            </p>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-4 text-sm font-medium hover:bg-muted">
                    <Camera class="size-6" /> Tirar foto
                    <input type="file" accept="image/*" capture="environment" class="sr-only" data-test="photo-camera" @change="pick" />
                </label>
                <label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-4 text-sm font-medium hover:bg-muted">
                    <ImageUp class="size-6" /> Da galeria
                    <input type="file" accept="image/*" class="sr-only" data-test="photo-gallery" @change="pick" />
                </label>
            </div>
            <p class="text-[11px] text-muted-foreground">
                A foto é enviada {{ provider === 'openai' ? 'à OpenAI' : 'ao Google Gemini' }} só para a análise e não fica guardada no Treinr; a
                localização gravada pelo celular é removida antes.
                <template v-if="provider === 'gemini'">No plano gratuito, o Google pode usar as imagens para melhorar os serviços dele.</template>
            </p>
        </template>

        <!-- Analisando / resultado -->
        <template v-else>
            <div class="flex items-start gap-3">
                <img :src="previewUrl" alt="Foto do prato" class="size-20 shrink-0 rounded-lg object-cover" />
                <div class="min-w-0 flex-1 text-sm">
                    <p v-if="analyzing" class="font-medium">Analisando a foto…</p>
                    <p v-if="analyzing" class="text-muted-foreground">Costuma levar de 5 a 30 segundos.</p>
                    <template v-else-if="analysis">
                        <p class="font-medium">{{ rows.length ? `${rows.length} alimento(s) encontrados` : 'Nenhum alimento reconhecido' }}</p>
                        <p v-if="analysis.notes" class="text-muted-foreground">{{ analysis.notes }}</p>
                        <p class="text-xs text-muted-foreground">Restam {{ analysis.remaining_today }} análises hoje.</p>
                    </template>
                    <button type="button" class="mt-1 flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-4" :disabled="analyzing" @click="reset">
                        <RotateCcw class="size-3" /> Outra foto
                    </button>
                </div>
            </div>
            <div v-if="analyzing" class="h-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                <div class="h-full w-1/3 animate-pulse rounded-full bg-primary"></div>
            </div>

            <p v-if="analysis && !analysis.is_food" class="text-sm text-muted-foreground">A foto não parece ter comida. Tente outra foto ou use a busca.</p>

            <ul v-if="rows.length" class="divide-y rounded-lg border" data-test="photo-items">
                <li v-for="r in rows" :key="r.id" class="flex items-start gap-2 p-2" :class="!r.checked && 'opacity-50'">
                    <input v-model="r.checked" type="checkbox" class="mt-1 size-4" :aria-label="`Incluir ${r.name}`" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ r.name }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ r.food ? `TACO: ${r.food.name}` : 'Valores estimados pela IA' }}
                        </p>
                        <p v-if="r.confidence !== null && r.confidence < 0.6" class="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                            <TriangleAlert class="size-3" /> pouca certeza: confira
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ Math.round(values(r).kcal) }} kcal · P {{ fmt(values(r).protein_g) }} · C {{ fmt(values(r).carbs_g) }} · G {{ fmt(values(r).fat_g) }}
                        </p>
                    </div>
                    <label class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                        <input
                            v-model="r.gramsInput"
                            inputmode="decimal"
                            class="h-8 w-16 rounded-md border border-input bg-transparent px-2 text-right text-sm text-foreground"
                            :aria-label="`Gramas de ${r.name}`"
                            :disabled="!r.checked"
                        />
                        g
                    </label>
                </li>
            </ul>

            <div v-if="chosen.length" class="grid grid-cols-4 gap-2 rounded-lg bg-muted p-3 text-center text-sm">
                <div><p class="text-lg font-semibold">{{ Math.round(total.kcal) }}</p><p class="text-xs text-muted-foreground">kcal</p></div>
                <div><p class="text-lg font-semibold">{{ fmt(total.protein_g) }}</p><p class="text-xs text-muted-foreground">prot. (g)</p></div>
                <div><p class="text-lg font-semibold">{{ fmt(total.carbs_g) }}</p><p class="text-xs text-muted-foreground">carb. (g)</p></div>
                <div><p class="text-lg font-semibold">{{ fmt(total.fat_g) }}</p><p class="text-xs text-muted-foreground">gord. (g)</p></div>
            </div>
            <p v-if="rows.length" class="text-[11px] text-muted-foreground">
                Porções e alimentos são estimativas a partir da foto. Ajuste as gramas e desmarque o que não comeu.
            </p>

            <Button v-if="rows.length" :disabled="saving || !chosen.length" data-test="photo-confirm" @click="save">
                Adicionar {{ chosen.length }} {{ chosen.length === 1 ? 'alimento' : 'alimentos' }}
            </Button>
        </template>

        <InputError :message="error" />
    </div>
</template>
