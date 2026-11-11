<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowLeft, Barcode, Camera, Mic, PencilLine, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BarcodeScanner from '@/components/BarcodeScanner.vue';
import InputError from '@/components/InputError.vue';
import MealPhotoTab from '@/components/MealPhotoTab.vue';
import MealVoiceTab from '@/components/MealVoiceTab.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getJson, HttpError, postJson } from '@/lib/http';
import { store as diaryItemStore } from '@/routes/diary-items';
import { barcode as foodBarcode, search as foodSearch, store as foodStore } from '@/routes/foods';

export type FoodOption = {
    food_id: number | null;
    source: string;
    code: string | null;
    name: string;
    brand: string | null;
    category?: string | null;
    unit: 'g' | 'ml';
    last_quantity?: number;
    last_portion?: { label: string; count: number } | null;
    per_100: { kcal: number; protein: number; carbs: number; fat: number };
    portions?: Portion[];
};
export type Portion = { label: string; grams: number; source: string };

const props = defineProps<{ date: string; mealType: string; mealLabel: string; photoAi?: string | null }>();
const open = defineModel<boolean>('open', { default: false });

type Tab = 'search' | 'barcode' | 'photo' | 'voice';
const tab = ref<Tab>('search');
const selected = ref<FoodOption | null>(null);
const selectedOrigin = ref<'search' | 'barcode' | 'manual'>('search');
const quantity = ref('100');
const errors = ref<Record<string, string>>({});
const saving = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        tab.value = 'search';
        reset();
    }
});

function reset() {
    selected.value = null;
    quantity.value = '100';
    errors.value = {};
    q.value = '';
    results.value = [];
    searchError.value = '';
    barcodeMsg.value = '';
    manualCode.value = '';
    manual.value = { name: '', quantity: '100', unit: 'g', kcal: '', protein: '', carbs: '', fat: '', save: false };
    showManual.value = false;
}

// ---------- Busca ----------
const q = ref('');
const results = ref<FoodOption[]>([]);
const searching = ref(false);
const searchError = ref('');
const offline = ref(false);

async function runSearch() {
    if (q.value.trim().length < 2) return;
    searching.value = true;
    searchError.value = '';
    try {
        const data = await getJson<{ items: FoodOption[]; online: boolean }>(foodSearch({ query: { q: q.value.trim() } }).url);
        results.value = data.items;
        offline.value = !data.online;
        if (!data.items.length) {
            searchError.value = 'Nada encontrado. Tente outro nome ou cadastre abaixo.';
            openManual();
        }
    } catch (e) {
        searchError.value =
            e instanceof HttpError && e.status === 429
                ? 'Muitas buscas seguidas. Aguarde um minuto.'
                : 'Busca indisponível no momento. Use o cadastro manual abaixo.';
    } finally {
        searching.value = false;
    }
}

// ---------- Código de barras ----------
const manualCode = ref('');
const barcodeMsg = ref('');
const lookingUp = ref(false);

async function lookup(code: string) {
    const clean = code.replace(/\D/g, '');
    if (clean.length < 8) {
        barcodeMsg.value = 'Código inválido (precisa ter de 8 a 14 números).';
        return;
    }
    lookingUp.value = true;
    barcodeMsg.value = '';
    try {
        const data = await getJson<{ food: FoodOption }>(foodBarcode(clean).url);
        choose(data.food, 'barcode');
    } catch (e) {
        const status = e instanceof HttpError ? e.status : 0;
        barcodeMsg.value =
            status === 404
                ? `Produto ${clean} não encontrado na Open Food Facts. Cadastre manualmente.`
                : status === 503
                  ? 'Open Food Facts indisponível agora. Tente de novo ou cadastre manualmente.'
                  : 'Não foi possível consultar o código.';
    } finally {
        lookingUp.value = false;
    }
}

// ---------- Seleção e quantidade ----------
// Medida caseira escolhida (null = gramas/ml digitados) e quantas dela.
const portion = ref<Portion | null>(null);
const portionCount = ref(1);

function choose(food: FoodOption, origin: 'search' | 'barcode' | 'manual') {
    selected.value = food;
    selectedOrigin.value = origin;
    errors.value = {};
    // Repete a última forma usada; senão, sugere a primeira medida "de servir" ou 100 g.
    const last = food.last_portion ? food.portions?.find((p) => p.label === food.last_portion!.label) : undefined;
    if (last) {
        selectPortion(last, food.last_portion!.count);
    } else {
        portion.value = null;
        portionCount.value = 1;
        quantity.value = String(food.last_quantity ?? 100);
    }
}

function selectPortion(p: Portion | null, count = 1) {
    portion.value = p;
    portionCount.value = count;
    if (p) quantity.value = String(Math.round(p.grams * count * 10) / 10);
}

function stepPortion(delta: number) {
    const next = Math.max(0.5, Math.round((portionCount.value + delta) * 2) / 2);
    selectPortion(portion.value, next);
}

/** Digitar gramas à mão desfaz a medida caseira. */
function onGramsInput() {
    portion.value = null;
}

const fmtCount = (c: number) => (c === 0.5 ? '½' : Number.isInteger(c) ? String(c) : `${Math.floor(c)}½`);

const qty = computed(() => Number(quantity.value.replace(',', '.')) || 0);
const preview = computed(() => {
    const f = selected.value;
    if (!f) return null;
    const k = qty.value / 100;
    return {
        kcal: Math.round(f.per_100.kcal * k),
        protein: Math.round(f.per_100.protein * k * 10) / 10,
        carbs: Math.round(f.per_100.carbs * k * 10) / 10,
        fat: Math.round(f.per_100.fat * k * 10) / 10,
    };
});

function submitSelected() {
    const f = selected.value!;
    const base = {
        meal_date: props.date,
        meal_type: props.mealType,
        quantity: qty.value,
        origin: selectedOrigin.value,
        portion_label: portion.value?.label ?? null,
        portion_count: portion.value ? portionCount.value : null,
    };
    // Do catálogo: o servidor calcula. Recente sem catálogo: envia os totais calculados aqui.
    const payload = f.food_id
        ? { ...base, food_id: f.food_id }
        : { ...base, food_name: f.name, unit: f.unit, kcal: preview.value!.kcal, protein_g: preview.value!.protein, carbs_g: preview.value!.carbs, fat_g: preview.value!.fat };
    send(payload);
}

// ---------- Manual (fica dentro de Buscar) ----------
const showManual = ref(false);
/** Abre o cadastro manual já com o nome que foi buscado. */
function openManual() {
    showManual.value = true;
    if (!manual.value.name.trim() && q.value.trim()) manual.value.name = q.value.trim();
}
const manual = ref({ name: '', quantity: '100', unit: 'g' as 'g' | 'ml', kcal: '', protein: '', carbs: '', fat: '', save: false });
const n = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')));

async function submitManual() {
    const m = manual.value;
    const quantityValue = n(m.quantity) ?? 0;
    send({
        meal_date: props.date,
        meal_type: props.mealType,
        origin: 'manual',
        food_name: m.name.trim(),
        quantity: quantityValue,
        unit: m.unit,
        kcal: n(m.kcal),
        protein_g: n(m.protein),
        carbs_g: n(m.carbs),
        fat_g: n(m.fat),
    }, async () => {
        // Opcional: guarda como "meu alimento" (valores convertidos para 100 g/ml).
        if (m.save && quantityValue > 0) {
            const per = (v: string) => Math.round(((n(v) ?? 0) * 100) / quantityValue * 100) / 100;
            await postJson(foodStore().url, { name: m.name.trim(), unit: m.unit, kcal: per(m.kcal), protein: per(m.protein), carbs: per(m.carbs), fat: per(m.fat) }).catch(() => {});
        }
    });
}

function send(payload: Record<string, string | number | null>, after?: () => Promise<void>) {
    saving.value = true;
    router.post(diaryItemStore().url, payload, {
        preserveScroll: true,
        onSuccess: async () => {
            await after?.();
            open.value = false;
        },
        onError: (e) => (errors.value = e),
        onFinish: () => (saving.value = false),
    });
}

const tabs: { id: Tab; label: string; icon: unknown }[] = [
    { id: 'search', label: 'Buscar', icon: Search },
    { id: 'barcode', label: 'Código', icon: Barcode },
    { id: 'photo', label: 'Foto', icon: Camera },
    { id: 'voice', label: 'Falar', icon: Mic },
];
function sourceLabel(r: FoodOption) {
    if (r.source === 'custom') return 'Meu alimento';
    if (r.source === 'taco') return `Caseiro/genérico (TACO)` + (r.category ? ` · ${r.category}` : '');
    return r.brand ?? 'Produto';
}
const fmt = (v: number) => (Math.round(v * 10) / 10).toLocaleString('pt-BR');
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[92vh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Adicionar em {{ mealLabel }}</DialogTitle>
                <DialogDescription>Busque, leia o código de barras, fotografe o prato, fale o que comeu ou informe os valores.</DialogDescription>
            </DialogHeader>

            <!-- Etapa 2: quantidade do alimento escolhido -->
            <div v-if="selected" class="flex flex-col gap-4">
                <button type="button" class="flex items-center gap-1 self-start text-sm text-muted-foreground" @click="selected = null">
                    <ArrowLeft class="size-4" /> Voltar
                </button>
                <div>
                    <p class="font-medium">{{ selected.name }}</p>
                    <p v-if="selected.brand || selected.category" class="text-sm text-muted-foreground">{{ selected.brand ?? selected.category }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Por 100 {{ selected.unit }}: {{ fmt(selected.per_100.kcal) }} kcal · P {{ fmt(selected.per_100.protein) }} g ·
                        C {{ fmt(selected.per_100.carbs) }} g · G {{ fmt(selected.per_100.fat) }} g
                    </p>
                </div>
                <!-- Medidas caseiras (IBGE) ou porção do rótulo -->
                <div v-if="selected.portions?.length" class="grid gap-2">
                    <Label>Medida</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            class="rounded-full border px-3 py-1 text-sm"
                            :class="!portion ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            @click="selectPortion(null)"
                        >
                            {{ selected.unit === 'ml' ? 'ml' : 'gramas' }}
                        </button>
                        <button
                            v-for="p in selected.portions"
                            :key="p.label"
                            type="button"
                            class="rounded-full border px-3 py-1 text-sm"
                            :class="portion?.label === p.label ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            :data-test="`portion-${p.label}`"
                            @click="selectPortion(p, portion?.label === p.label ? portionCount : 1)"
                        >
                            {{ p.label }} <span class="opacity-70">· {{ Math.round(p.grams) }} {{ selected.unit }}</span>
                        </button>
                    </div>
                    <div v-if="portion" class="flex items-center gap-3">
                        <Button type="button" variant="outline" size="icon" aria-label="Menos" :disabled="portionCount <= 0.5" @click="stepPortion(-0.5)">−</Button>
                        <span class="min-w-24 text-center font-medium tabular-nums" data-test="portion-count">{{ fmtCount(portionCount) }} × {{ portion.label }}</span>
                        <Button type="button" variant="outline" size="icon" aria-label="Mais" @click="stepPortion(0.5)">+</Button>
                    </div>
                </div>

                <div class="grid gap-1">
                    <Label for="qty">Quantidade ({{ selected.unit }})</Label>
                    <Input id="qty" v-model="quantity" inputmode="decimal" :autofocus="!selected.portions?.length" data-test="food-quantity" @input="onGramsInput" />
                    <p v-if="portion" class="text-xs text-muted-foreground">
                        {{ fmtCount(portionCount) }} × {{ portion.label }} ≈ {{ qty }} {{ selected.unit }}
                        <template v-if="portion.source === 'ibge'"> (medida média do IBGE)</template>
                        <template v-else-if="portion.source === 'label'"> (porção do rótulo)</template>
                    </p>
                    <InputError :message="errors.quantity" />
                </div>
                <div v-if="preview" class="grid grid-cols-4 gap-2 rounded-lg bg-muted p-3 text-center text-sm">
                    <div><p class="text-lg font-semibold">{{ preview.kcal }}</p><p class="text-xs text-muted-foreground">kcal</p></div>
                    <div><p class="text-lg font-semibold">{{ fmt(preview.protein) }}</p><p class="text-xs text-muted-foreground">prot. (g)</p></div>
                    <div><p class="text-lg font-semibold">{{ fmt(preview.carbs) }}</p><p class="text-xs text-muted-foreground">carb. (g)</p></div>
                    <div><p class="text-lg font-semibold">{{ fmt(preview.fat) }}</p><p class="text-xs text-muted-foreground">gord. (g)</p></div>
                </div>
                <p v-if="selected.source === 'open_food_facts'" class="text-xs text-muted-foreground">
                    Dados da comunidade Open Food Facts. Confira com o rótulo se algo parecer errado.
                </p>
                <p v-else-if="selected.source === 'taco'" class="text-xs text-muted-foreground">
                    Valores da TACO (NEPA-UNICAMP), por 100 g de parte comestível.
                </p>
                <Button :disabled="saving || qty <= 0" data-test="confirm-food" @click="submitSelected">Adicionar</Button>
            </div>

            <!-- Etapa 1: escolher o alimento -->
            <template v-else>
                <div class="grid grid-cols-4 gap-1 rounded-lg bg-muted p-1">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        type="button"
                        class="flex flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1.5 text-[11px] font-medium sm:flex-row sm:gap-1 sm:text-xs"
                        :class="tab === t.id ? 'bg-background shadow-sm' : 'text-muted-foreground'"
                        @click="tab = t.id"
                    >
                        <component :is="t.icon" class="size-3.5" /> {{ t.label }}
                    </button>
                </div>

                <div class="-mx-2 min-h-48 flex-1 overflow-y-auto px-2">
                    <!-- Buscar (com o cadastro manual logo abaixo) -->
                    <template v-if="tab === 'search'">
                        <form class="flex gap-2" @submit.prevent="runSearch">
                            <Input v-model="q" placeholder="Ex.: arroz cozido, feijão, frango grelhado" autofocus data-test="food-search" />
                            <Button type="submit" :disabled="searching">{{ searching ? '…' : 'Buscar' }}</Button>
                        </form>
                        <p v-if="offline && results.length" class="mt-2 text-xs text-muted-foreground">Open Food Facts indisponível: mostrando só alimentos já salvos.</p>
                        <p v-if="searchError" class="py-4 text-center text-sm text-muted-foreground">{{ searchError }}</p>
                        <button
                            v-for="r in results"
                            :key="`${r.food_id}`"
                            type="button"
                            class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg p-2 text-left hover:bg-muted"
                            :data-test="`food-${r.food_id}`"
                            @click="choose(r, 'search')"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm">{{ r.name }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ sourceLabel(r) }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ Math.round(r.per_100.kcal) }} kcal/100{{ r.unit }}</span>
                        </button>

                        <!-- Manual: para o que não aparece na busca -->
                        <button
                            v-if="!showManual"
                            type="button"
                            class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-dashed p-2.5 text-sm text-muted-foreground hover:bg-muted"
                            data-test="open-manual"
                            @click="openManual"
                        >
                            <PencilLine class="size-4" /> Não achou? Cadastrar manualmente
                        </button>
                        <form v-else class="mt-3 grid gap-3 rounded-lg border p-3" data-test="manual-form" @submit.prevent="submitManual">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium">Cadastro manual</p>
                                <button type="button" class="text-xs text-muted-foreground underline underline-offset-4" @click="showManual = false">Fechar</button>
                            </div>
                            <div class="grid gap-1">
                                <Label for="m-name">Alimento</Label>
                                <Input id="m-name" v-model="manual.name" placeholder="Ex.: Arroz branco cozido" required />
                                <InputError :message="errors.food_name" />
                            </div>
                            <div class="grid grid-cols-[1fr_6rem] gap-2">
                                <div class="grid gap-1">
                                    <Label for="m-qty">Quantidade</Label>
                                    <Input id="m-qty" v-model="manual.quantity" inputmode="decimal" required />
                                </div>
                                <div class="grid gap-1">
                                    <Label for="m-unit">Unidade</Label>
                                    <select id="m-unit" v-model="manual.unit" class="h-9 rounded-md border border-input bg-transparent px-2 text-sm">
                                        <option value="g">g</option>
                                        <option value="ml">ml</option>
                                    </select>
                                </div>
                            </div>
                            <InputError :message="errors.quantity" />
                            <p class="text-xs text-muted-foreground">Valores da porção consumida (não por 100 g):</p>
                            <div class="grid grid-cols-4 gap-2">
                                <label class="grid gap-1 text-xs">kcal<Input v-model="manual.kcal" inputmode="decimal" required /></label>
                                <label class="grid gap-1 text-xs">Prot. (g)<Input v-model="manual.protein" inputmode="decimal" /></label>
                                <label class="grid gap-1 text-xs">Carb. (g)<Input v-model="manual.carbs" inputmode="decimal" /></label>
                                <label class="grid gap-1 text-xs">Gord. (g)<Input v-model="manual.fat" inputmode="decimal" /></label>
                            </div>
                            <InputError :message="errors.kcal || errors.protein_g || errors.carbs_g || errors.fat_g" />
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="manual.save" type="checkbox" class="size-4" /> Salvar nos meus alimentos
                            </label>
                            <Button type="submit" :disabled="saving" data-test="manual-submit">Adicionar</Button>
                        </form>
                    </template>

                    <!-- Código de barras -->
                    <template v-if="tab === 'barcode'">
                        <BarcodeScanner v-if="open && tab === 'barcode'" @detected="lookup" />
                        <form class="mt-3 flex gap-2" @submit.prevent="lookup(manualCode)">
                            <Input v-model="manualCode" inputmode="numeric" placeholder="Ou digite o código (ex.: 7891000100103)" data-test="barcode-input" />
                            <Button type="submit" :disabled="lookingUp">{{ lookingUp ? '…' : 'Consultar' }}</Button>
                        </form>
                        <p v-if="barcodeMsg" class="mt-3 text-sm text-muted-foreground">{{ barcodeMsg }}</p>
                    </template>

                    <!-- Foto do prato (IA) -->
                    <MealPhotoTab v-if="open && tab === 'photo'" :date="date" :meal-type="mealType" :provider="photoAi ?? null" @done="open = false" />

                    <!-- Falar (voz transcrita no celular + IA) -->
                    <MealVoiceTab v-if="open && tab === 'voice'" :date="date" :meal-type="mealType" :provider="photoAi ?? null" @done="open = false" />

                </div>

                <p class="text-[11px] text-muted-foreground">
                    Alimentos genéricos: TACO 4ª ed. (NEPA-UNICAMP). Medidas caseiras: IBGE (POF 2008-2009). Produtos: <a href="https://br.openfoodfacts.org" target="_blank" rel="noopener" class="underline">Open Food Facts</a> (ODbL).
                </p>
            </template>
        </DialogContent>
    </Dialog>
</template>
