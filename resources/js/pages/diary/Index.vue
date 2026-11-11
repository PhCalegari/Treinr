<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus, Target, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AddFoodDialog from '@/components/AddFoodDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { destroy as itemDestroy, update as itemUpdate } from '@/routes/diary-items';
import { index as diaryIndex } from '@/routes/diary';
import { index as goalsIndex } from '@/routes/goals';

type Item = {
    id: string;
    food_name: string;
    quantity: number;
    unit: string;
    portion_label: string | null;
    portion_count: number | null;
    kcal: number;
    protein_g: number;
    carbs_g: number;
    fat_g: number;
    origin: string;
};
type Totals = { kcal: number; protein_g: number; carbs_g: number; fat_g: number };
type Goal = { kcal: number; protein_g: number; carbs_g: number; fat_g: number; valid_from: string };
type MealSection = { type: string; label: string; items: Item[]; totals: Totals };

const props = defineProps<{ date: string; today: string; meals: MealSection[]; totals: Totals; goal: Goal | null; photoAi: string | null }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Diário alimentar', href: diaryIndex() }] },
});

const fmt = (v: number) => (Math.round(v * 10) / 10).toLocaleString('pt-BR');
const fmtCount = (c: number) => (c === 0.5 ? '½' : Number.isInteger(c) ? String(c) : `${Math.floor(c)}½`);
const dateLabel = computed(() => {
    if (props.date === props.today) return 'Hoje';
    return new Date(`${props.date}T12:00:00`).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
});

function shift(days: number) {
    const d = new Date(`${props.date}T12:00:00`);
    d.setDate(d.getDate() + days);
    goTo(d.toISOString().slice(0, 10));
}
function goTo(date: string) {
    router.get(diaryIndex().url, { date }, { preserveScroll: true, replace: true });
}

// Distribuição das calorias entre os macros (proteína e carboidrato 4 kcal/g; gordura 9 kcal/g).
const macroSplit = computed(() => {
    const p = props.totals.protein_g * 4;
    const c = props.totals.carbs_g * 4;
    const f = props.totals.fat_g * 9;
    const sum = p + c + f;
    if (!sum) return null;
    return { p: Math.round((p / sum) * 100), c: Math.round((c / sum) * 100), f: Math.round((f / sum) * 100) };
});

// Progresso em relação à meta do dia.
const remaining = computed(() => (props.goal ? Math.round(props.goal.kcal - props.totals.kcal) : null));
const meters = computed(() => {
    const g = props.goal;
    if (!g) return [];
    return [
        { key: 'protein_g', label: 'Proteínas', value: props.totals.protein_g, goal: g.protein_g },
        { key: 'carbs_g', label: 'Carboidratos', value: props.totals.carbs_g, goal: g.carbs_g },
        { key: 'fat_g', label: 'Gorduras', value: props.totals.fat_g, goal: g.fat_g },
    ].map((m) => ({ ...m, pct: m.goal ? Math.min(100, (m.value / m.goal) * 100) : 0, over: m.goal > 0 && m.value > m.goal * 1.05 }));
});
const kcalPct = computed(() => (props.goal ? Math.min(100, (props.totals.kcal / props.goal.kcal) * 100) : 0));

const dialogOpen = ref(false);
const activeMeal = ref<MealSection | null>(null);
function openAdd(meal: MealSection) {
    activeMeal.value = meal;
    dialogOpen.value = true;
}

function changeQuantity(item: Item, e: Event) {
    const value = Number((e.target as HTMLInputElement).value.replace(',', '.'));
    if (!value || value <= 0 || value === item.quantity) return;
    router.patch(itemUpdate(item.id).url, { quantity: value }, { preserveScroll: true });
}

function remove(item: Item) {
    if (confirm(`Remover ${item.food_name}?`)) router.delete(itemDestroy(item.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Diário alimentar" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
        <Heading title="Diário alimentar" description="O que você comeu, por refeição" />

        <!-- Navegação de datas -->
        <div class="flex items-center justify-between gap-2 rounded-2xl bg-card p-2">
            <Button variant="ghost" size="icon" aria-label="Dia anterior" @click="shift(-1)"><ChevronLeft class="size-4" /></Button>
            <label class="flex flex-col items-center text-sm">
                <span class="font-medium capitalize">{{ dateLabel }}</span>
                <input type="date" :value="date" :max="today" class="text-xs text-muted-foreground bg-transparent" @change="goTo(($event.target as HTMLInputElement).value)" />
            </label>
            <Button variant="ghost" size="icon" aria-label="Próximo dia" :disabled="date >= today" @click="shift(1)"><ChevronRight class="size-4" /></Button>
        </div>

        <!-- Totais do dia -->
        <section class="rounded-2xl bg-card p-4">
            <div class="grid grid-cols-4 gap-2 text-center">
                <div><p class="text-2xl font-semibold tabular-nums">{{ Math.round(totals.kcal) }}</p><p class="text-xs text-muted-foreground">kcal</p></div>
                <div><p class="text-lg font-semibold tabular-nums">{{ fmt(totals.protein_g) }} g</p><p class="text-xs text-muted-foreground">Proteínas</p></div>
                <div><p class="text-lg font-semibold tabular-nums">{{ fmt(totals.carbs_g) }} g</p><p class="text-xs text-muted-foreground">Carboidratos</p></div>
                <div><p class="text-lg font-semibold tabular-nums">{{ fmt(totals.fat_g) }} g</p><p class="text-xs text-muted-foreground">Gorduras</p></div>
            </div>
            <div v-if="macroSplit" class="mt-3">
                <div class="flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                    <div class="bg-sky-500" :style="{ width: macroSplit.p + '%' }"></div>
                    <div class="bg-amber-500" :style="{ width: macroSplit.c + '%' }"></div>
                    <div class="bg-rose-500" :style="{ width: macroSplit.f + '%' }"></div>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Calorias vindas de: proteínas {{ macroSplit.p }}% · carboidratos {{ macroSplit.c }}% · gorduras {{ macroSplit.f }}%
                </p>
            </div>
            <div v-if="goal" class="mt-4 border-t pt-3" data-test="goal-progress">
                <div class="flex items-baseline justify-between gap-2 text-sm">
                    <span class="font-medium">
                        <template v-if="remaining !== null && remaining >= 0">Faltam {{ remaining.toLocaleString('pt-BR') }} kcal</template>
                        <span v-else class="inline-flex items-center gap-1"><TriangleAlert class="size-4 text-amber-600" /> {{ (-(remaining ?? 0)).toLocaleString('pt-BR') }} kcal acima da meta</span>
                    </span>
                    <span class="text-xs text-muted-foreground">meta {{ goal.kcal.toLocaleString('pt-BR') }} kcal</span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--viz-series)_18%,transparent)]" role="meter" :aria-valuenow="Math.round(totals.kcal)" aria-valuemin="0" :aria-valuemax="goal.kcal" aria-label="Calorias da meta">
                    <div class="h-full rounded-full bg-[var(--viz-series)]" :style="{ width: kcalPct + '%' }"></div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-3">
                    <div v-for="m in meters" :key="m.key" class="text-xs">
                        <p class="text-muted-foreground">{{ m.label }}</p>
                        <p class="font-medium tabular-nums">{{ fmt(m.value) }} / {{ m.goal }} g</p>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--viz-series)_18%,transparent)]" role="meter" :aria-valuenow="Math.round(m.value)" aria-valuemin="0" :aria-valuemax="m.goal" :aria-label="m.label">
                            <div class="h-full rounded-full bg-[var(--viz-series)]" :style="{ width: m.pct + '%' }"></div>
                        </div>
                        <p v-if="m.over" class="mt-0.5 flex items-center gap-1 text-amber-700 dark:text-amber-400"><TriangleAlert class="size-3" /> acima</p>
                    </div>
                </div>
                <Link :href="goalsIndex()" class="mt-3 inline-block text-xs text-muted-foreground underline underline-offset-4">Ver metas e histórico</Link>
            </div>
            <Link v-else :href="goalsIndex()" class="mt-3 flex items-center gap-2 text-sm font-medium underline underline-offset-4">
                <Target class="size-4" /> Definir uma meta de calorias
            </Link>
        </section>

        <!-- Refeições -->
        <section v-for="meal in meals" :key="meal.type" class="rounded-2xl bg-card p-3" :data-test="`meal-${meal.type}`">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="font-medium">{{ meal.label }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{ Math.round(meal.totals.kcal) }} kcal · P {{ fmt(meal.totals.protein_g) }} · C {{ fmt(meal.totals.carbs_g) }} · G {{ fmt(meal.totals.fat_g) }}
                    </p>
                </div>
                <Button size="sm" variant="outline" :data-test="`add-${meal.type}`" @click="openAdd(meal)"><Plus class="size-4" /> Adicionar</Button>
            </div>

            <div v-for="item in meal.items" :key="item.id" class="mt-2 flex items-center gap-2 border-t pt-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm">{{ item.food_name }}</p>
                    <p v-if="item.portion_label" class="text-xs text-muted-foreground">
                        {{ fmtCount(item.portion_count ?? 1) }} × {{ item.portion_label }} ({{ fmt(item.quantity) }} {{ item.unit }})
                    </p>
                    <p class="text-xs text-muted-foreground">{{ item.kcal }} kcal · P {{ fmt(item.protein_g) }} · C {{ fmt(item.carbs_g) }} · G {{ fmt(item.fat_g) }}</p>
                </div>
                <label class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                    <input
                        :value="item.quantity"
                        inputmode="decimal"
                        class="h-8 w-16 rounded-md border border-input bg-transparent px-2 text-right text-sm text-foreground"
                        aria-label="Quantidade"
                        @change="changeQuantity(item, $event)"
                    />
                    {{ item.unit }}
                </label>
                <Button variant="ghost" size="icon" class="size-8" aria-label="Remover" @click="remove(item)"><Trash2 class="size-4" /></Button>
            </div>
        </section>
    </div>

    <AddFoodDialog
        v-if="activeMeal"
        v-model:open="dialogOpen"
        :date="date"
        :meal-type="activeMeal.type"
        :meal-label="activeMeal.label"
        :photo-ai="photoAi"
    />
</template>
