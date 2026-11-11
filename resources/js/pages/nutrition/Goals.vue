<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Info, TriangleAlert } from '@lucide/vue';
import { computed, watch } from 'vue';
import CaloriesChart from '@/components/charts/CaloriesChart.vue';
import type { Day } from '@/components/charts/CaloriesChart.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fmtLongDate, fmtNumber, fmtShortDate } from '@/lib/chart';
import { formulaLabels, goalHints, goalLabels } from '@/lib/labels';
import { index as diaryIndex } from '@/routes/diary';
import { index as goalsIndex, store as goalStore } from '@/routes/goals';
import { edit as editProfile } from '@/routes/profile';
import { index as progressIndex, store as progressStore } from '@/routes/progress';

type Estimate = { formula: string; bmr: number; tdee: number; kcal: number; protein_g: number; carbs_g: number; fat_g: number; adjustment: number; warnings: string[] };
type GoalRecord = {
    id: string;
    valid_from: string;
    kcal: number;
    protein_g: number;
    carbs_g: number;
    fat_g: number;
    bmr_estimate: number | null;
    tdee_estimate: number | null;
    activity_level: string | null;
    formula: string | null;
    manual_override: boolean;
};
type Inputs = {
    sex: string | null;
    age: number | null;
    height_cm: number | null;
    weight_kg: number | null;
    weight_date: string | null;
    body_fat_pct: number | null;
    body_fat_date: string | null;
    missing: string[];
};
type Week = { week_start: string; days_logged: number; avg_kcal: number | null; avg_protein_g: number | null; avg_goal_kcal: number | null };

const props = defineProps<{
    today: string;
    inputs: Inputs;
    profileGoal: string | null;
    levels: { value: string; factor: number; label: string; hint: string }[];
    estimates: Record<string, Record<string, Estimate>> | null;
    current: GoalRecord | null;
    goalHistory: GoalRecord[];
    days: Day[];
    weeks: Week[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Alimentação', href: diaryIndex() }, { title: 'Metas', href: goalsIndex() }] },
});

const missingLabels: Record<string, string> = { sex: 'sexo', age: 'data de nascimento', height_cm: 'altura', weight_kg: 'peso' };
const missingProfile = computed(() => props.inputs.missing.filter((m) => m !== 'weight_kg'));

const form = useForm({
    goal: props.profileGoal ?? 'maintenance',
    activity_level: props.current?.activity_level ?? 'moderate',
    valid_from: props.today,
    manual: props.current?.manual_override ?? !props.estimates,
    kcal: props.current?.kcal ?? null as number | null,
    protein_g: props.current?.protein_g ?? null as number | null,
    carbs_g: props.current?.carbs_g ?? null as number | null,
    fat_g: props.current?.fat_g ?? null as number | null,
});

const estimate = computed<Estimate | null>(() => props.estimates?.[form.goal]?.[form.activity_level] ?? null);

// Sem ajuste manual, os campos acompanham a estimativa.
watch(
    [estimate, () => form.manual],
    ([e, manual]) => {
        if (e && !manual) {
            form.kcal = e.kcal;
            form.protein_g = e.protein_g;
            form.carbs_g = e.carbs_g;
            form.fat_g = e.fat_g;
        }
    },
    { immediate: true },
);

const macroKcal = computed(() => (form.protein_g ?? 0) * 4 + (form.carbs_g ?? 0) * 4 + (form.fat_g ?? 0) * 9);
const pct = (g: number | null, per: number) => (form.kcal ? Math.round((((g ?? 0) * per) / form.kcal) * 100) : 0);

const weightForm = useForm({ entry_date: props.today, weight_kg: null as number | null });
function saveWeight() {
    weightForm.post(progressStore().url, { preserveScroll: true });
}

function submit() {
    form.post(goalStore().url, { preserveScroll: true });
}

const levelLabel = (v: string | null) => props.levels.find((l) => l.value === v)?.label ?? '—';
const sameAsCurrent = computed(
    () =>
        !!props.current &&
        props.current.valid_from === form.valid_from &&
        props.current.kcal === form.kcal &&
        props.current.protein_g === form.protein_g &&
        props.current.carbs_g === form.carbs_g &&
        props.current.fat_g === form.fat_g,
);
</script>

<template>
    <Head title="Metas nutricionais" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
        <Heading title="Metas nutricionais" description="Estimativa de gasto calórico e metas de calorias e macronutrientes" />

        <p class="flex gap-2 rounded-2xl bg-muted/40 p-3 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-4 shrink-0" />
            <span>
                Os valores são <strong>estimativas</strong> feitas com fórmulas para adultos saudáveis. Não são prescrição: para dieta com
                acompanhamento, gestação, doenças ou uso de medicamentos, procure um nutricionista ou médico.
            </span>
        </p>

        <!-- Meta vigente -->
        <section v-if="current" class="rounded-2xl bg-card p-4" data-test="current-goal">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-medium">Meta atual</h2>
                <p class="text-xs text-muted-foreground">desde {{ fmtLongDate(current.valid_from) }}{{ current.manual_override ? ' · ajustada à mão' : '' }}</p>
            </div>
            <div class="mt-3 grid grid-cols-4 gap-2 text-center">
                <div><p class="text-2xl font-semibold">{{ fmtNumber(current.kcal, 0) }}</p><p class="text-xs text-muted-foreground">kcal</p></div>
                <div><p class="text-lg font-semibold">{{ current.protein_g }} g</p><p class="text-xs text-muted-foreground">Proteínas</p></div>
                <div><p class="text-lg font-semibold">{{ current.carbs_g }} g</p><p class="text-xs text-muted-foreground">Carboidratos</p></div>
                <div><p class="text-lg font-semibold">{{ current.fat_g }} g</p><p class="text-xs text-muted-foreground">Gorduras</p></div>
            </div>
        </section>

        <!-- Dados que faltam -->
        <section v-if="inputs.missing.length" class="rounded-2xl border border-primary/40 bg-primary/10 p-4 text-sm">
            <p class="flex items-center gap-2 font-medium"><TriangleAlert class="size-4" /> Faltam dados para calcular</p>
            <p class="mt-1 text-muted-foreground">
                Precisamos de: {{ inputs.missing.map((m) => missingLabels[m]).join(', ') }}. Enquanto isso, você pode definir a meta manualmente.
            </p>
            <Link v-if="missingProfile.length" :href="editProfile()" class="mt-2 inline-block font-medium underline underline-offset-4">Completar perfil</Link>
            <form v-if="inputs.missing.includes('weight_kg')" class="mt-3 flex items-end gap-2" @submit.prevent="saveWeight">
                <div class="grid gap-1">
                    <Label for="quick-weight">Peso de hoje (kg)</Label>
                    <Input id="quick-weight" v-model="weightForm.weight_kg" type="number" step="0.1" min="20" max="400" inputmode="decimal" class="w-32" required />
                </div>
                <Button type="submit" size="sm" :disabled="weightForm.processing">Registrar peso</Button>
            </form>
            <InputError :message="weightForm.errors.weight_kg" />
        </section>

        <!-- Calculadora -->
        <form class="flex flex-col gap-4 rounded-2xl bg-card p-4" @submit.prevent="submit">
            <h2 class="font-medium">{{ current ? 'Atualizar meta' : 'Definir meta' }}</h2>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Objetivo</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="(label, value) in goalLabels"
                        :key="value"
                        class="flex cursor-pointer flex-col rounded-lg border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                    >
                        <span class="flex items-center gap-2 font-medium"><input v-model="form.goal" type="radio" name="goal" :value="value" /> {{ label }}</span>
                        <span class="mt-1 text-xs text-muted-foreground">{{ goalHints[value] }}</span>
                    </label>
                </div>
                <InputError :message="form.errors.goal" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Nível de atividade (somando treino e dia a dia)</legend>
                <label
                    v-for="l in levels"
                    :key="l.value"
                    class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                >
                    <input v-model="form.activity_level" type="radio" name="activity_level" :value="l.value" class="mt-1" />
                    <span>
                        <span class="font-medium">{{ l.label }}</span>
                        <span class="text-muted-foreground"> · ×{{ fmtNumber(l.factor, 3) }}</span>
                        <span class="block text-xs text-muted-foreground">{{ l.hint }}</span>
                    </span>
                </label>
            </fieldset>

            <!-- Resultado da estimativa -->
            <div v-if="estimate" class="rounded-lg bg-muted/40 p-3 text-sm" data-test="estimate">
                <dl class="grid grid-cols-3 gap-2 text-center">
                    <div>
                        <dt class="text-xs text-muted-foreground">Metabolismo basal</dt>
                        <dd class="font-semibold">{{ fmtNumber(estimate.bmr, 0) }} kcal</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Gasto total estimado</dt>
                        <dd class="font-semibold">{{ fmtNumber(estimate.tdee, 0) }} kcal</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Meta sugerida</dt>
                        <dd class="font-semibold">{{ fmtNumber(estimate.kcal, 0) }} kcal</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs text-muted-foreground">
                    Fórmula {{ formulaLabels[estimate.formula] }} com {{ fmtNumber(inputs.weight_kg ?? 0) }} kg
                    <template v-if="inputs.weight_date">(registrado em {{ fmtShortDate(inputs.weight_date) }})</template>,
                    {{ inputs.height_cm }} cm e {{ inputs.age }} anos<template v-if="estimate.formula === 'katch_mcardle'">, {{ fmtNumber(inputs.body_fat_pct ?? 0) }}% de gordura</template>.
                    Proteína: {{ form.goal === 'weight_loss' ? '2,0' : form.goal === 'hypertrophy' ? '1,8' : '1,6' }} g por kg; gorduras: 25% das calorias; carboidratos: o restante.
                </p>
                <p v-for="w in estimate.warnings" :key="w" class="mt-2 flex gap-2 text-xs text-amber-700 dark:text-amber-400">
                    <TriangleAlert class="size-4 shrink-0" /> {{ w }}
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.manual" type="checkbox" :disabled="!estimates" />
                Ajustar os valores manualmente
                <span v-if="!estimates" class="text-xs text-muted-foreground">(obrigatório enquanto faltam dados)</span>
            </label>

            <div class="grid grid-cols-2 items-start gap-3 sm:grid-cols-4">
                <div class="grid gap-1">
                    <Label for="kcal">Calorias (kcal)</Label>
                    <Input id="kcal" v-model.number="form.kcal" type="number" min="800" max="6000" :readonly="!form.manual" :class="!form.manual && 'bg-muted/50 text-muted-foreground'" required />
                    <InputError :message="form.errors.kcal" />
                </div>
                <div class="grid gap-1">
                    <Label for="protein">Proteínas (g)</Label>
                    <Input id="protein" v-model.number="form.protein_g" type="number" min="0" max="500" :readonly="!form.manual" :class="!form.manual && 'bg-muted/50 text-muted-foreground'" required />
                    <p class="text-xs text-muted-foreground">{{ pct(form.protein_g, 4) }}% das kcal</p>
                    <InputError :message="form.errors.protein_g" />
                </div>
                <div class="grid gap-1">
                    <Label for="carbs">Carboidratos (g)</Label>
                    <Input id="carbs" v-model.number="form.carbs_g" type="number" min="0" max="1000" :readonly="!form.manual" :class="!form.manual && 'bg-muted/50 text-muted-foreground'" required />
                    <p class="text-xs text-muted-foreground">{{ pct(form.carbs_g, 4) }}% das kcal</p>
                    <InputError :message="form.errors.carbs_g" />
                </div>
                <div class="grid gap-1">
                    <Label for="fat">Gorduras (g)</Label>
                    <Input id="fat" v-model.number="form.fat_g" type="number" min="0" max="400" :readonly="!form.manual" :class="!form.manual && 'bg-muted/50 text-muted-foreground'" required />
                    <p class="text-xs text-muted-foreground">{{ pct(form.fat_g, 9) }}% das kcal</p>
                    <InputError :message="form.errors.fat_g" />
                </div>
            </div>
            <p v-if="form.manual && form.kcal && Math.abs(macroKcal - form.kcal) > form.kcal * 0.05" class="text-xs text-amber-700 dark:text-amber-400">
                Os macronutrientes somam {{ fmtNumber(macroKcal, 0) }} kcal, diferente da meta de {{ fmtNumber(form.kcal, 0) }} kcal.
            </p>

            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <Label for="valid_from">Vale a partir de</Label>
                    <Input id="valid_from" v-model="form.valid_from" type="date" class="w-44" required />
                    <InputError :message="form.errors.valid_from" />
                </div>
                <Button type="submit" :disabled="form.processing || sameAsCurrent">Salvar meta</Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Ao salvar, o objetivo do seu perfil também passa a ser "{{ goalLabels[form.goal] }}". Metas antigas ficam no histórico e os dias
                anteriores continuam comparados com a meta que valia na época.
            </p>
        </form>

        <!-- Histórico diário -->
        <section class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Calorias nos últimos 28 dias</h2>
            <p class="text-xs text-muted-foreground">Colunas: consumo registrado no diário. Linha: meta do dia.</p>
            <div class="mt-3">
                <CaloriesChart :days="days" />
            </div>
        </section>

        <!-- Histórico semanal -->
        <section class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Médias por semana</h2>
            <p class="text-xs text-muted-foreground">Só contam os dias com algum registro no diário.</p>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted-foreground">
                            <th class="py-1 font-normal">Semana</th>
                            <th class="py-1 text-right font-normal">Dias</th>
                            <th class="py-1 text-right font-normal">Média</th>
                            <th class="py-1 text-right font-normal">Meta</th>
                            <th class="py-1 text-right font-normal">Proteína</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="w in [...weeks].reverse()" :key="w.week_start" class="border-t">
                            <td class="py-1.5">{{ fmtShortDate(w.week_start) }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ w.days_logged }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ w.avg_kcal !== null ? `${fmtNumber(w.avg_kcal, 0)} kcal` : '—' }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ w.avg_goal_kcal !== null ? `${fmtNumber(w.avg_goal_kcal, 0)} kcal` : '—' }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ w.avg_protein_g !== null ? `${fmtNumber(w.avg_protein_g)} g` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Histórico de metas -->
        <section v-if="goalHistory.length > 1" class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Metas anteriores</h2>
            <ul class="mt-2 divide-y text-sm">
                <li v-for="g in goalHistory" :key="g.id" class="flex flex-wrap justify-between gap-2 py-2">
                    <span>desde {{ fmtLongDate(g.valid_from) }}</span>
                    <span class="text-muted-foreground">
                        {{ fmtNumber(g.kcal, 0) }} kcal · P {{ g.protein_g }} · C {{ g.carbs_g }} · G {{ g.fat_g }}
                        · {{ g.manual_override ? 'manual' : levelLabel(g.activity_level) }}
                    </span>
                </li>
            </ul>
        </section>

        <p class="text-xs text-muted-foreground">
            O peso vem do último registro em <Link :href="progressIndex()" class="underline underline-offset-4">Evolução</Link>; atualize por lá
            para recalcular.
        </p>
    </div>
</template>
