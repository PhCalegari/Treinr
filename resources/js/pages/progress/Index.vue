<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Camera, Pencil, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import LineChart from '@/components/charts/LineChart.vue';
import type { Point } from '@/components/charts/LineChart.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fmtLongDate, fmtNumber, fmtShortDate } from '@/lib/chart';
import { prepareImage } from '@/lib/image';
import { angleLabels } from '@/lib/labels';
import { destroy as photoDestroy, store as photoStore } from '@/routes/progress-photos';
import { destroy as entryDestroy, index as progressIndex, store as entryStore, update as entryUpdate } from '@/routes/progress';

type Photo = { id: string; angle: string; url: string };
type Entry = {
    id: string;
    entry_date: string;
    weight_kg: number | null;
    body_fat_pct: number | null;
    notes: string | null;
    measurements: Record<string, number>;
    photos: Photo[];
};
type Summary = {
    weight_kg: number;
    date: string;
    change_30d: number | null;
    change_30d_from: string | null;
    change_total: number | null;
    first_date: string;
    bmi: number | null;
} | null;

const props = defineProps<{
    today: string;
    heightCm: number | null;
    entries: Entry[];
    types: { code: string; name: string }[];
    series: { weight: Point[]; body_fat: Point[]; measurements: Record<string, Point[]> };
    summary: Summary;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Evolução física', href: progressIndex() }] },
});

const typeName = (code: string) => props.types.find((t) => t.code === code)?.name ?? code;
const signed = (v: number, unit: string) => `${v > 0 ? '+' : v < 0 ? '−' : ''}${fmtNumber(Math.abs(v))} ${unit}`;

// ---------- Formulário (novo registro ou edição) ----------
const emptyMeasurements = () => Object.fromEntries(props.types.map((t) => [t.code, null])) as Record<string, number | null>;
const editing = ref<Entry | null>(null);
const showMeasures = ref(false);
const form = useForm({
    entry_date: props.today,
    weight_kg: null as number | null,
    body_fat_pct: null as number | null,
    notes: '' as string,
    measurements: emptyMeasurements(),
});

const filledMeasures = computed(() => Object.values(form.measurements).filter((v) => v !== null && v !== ('' as unknown)).length);
const measureError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((k) => k.startsWith('measurements'));
    return key ? errors[key] : undefined;
});

// Já existe registro na data escolhida? O servidor completa esse registro.
const sameDay = computed(() => (editing.value ? null : props.entries.find((e) => e.entry_date === form.entry_date) ?? null));
watch(
    sameDay,
    (e) => {
        if (e) fill(e);
    },
    { immediate: true },
);

function fill(e: Entry, expand = false) {
    form.entry_date = e.entry_date;
    form.weight_kg = e.weight_kg;
    form.body_fat_pct = e.body_fat_pct;
    form.notes = e.notes ?? '';
    form.measurements = { ...emptyMeasurements(), ...e.measurements };
    if (expand && Object.keys(e.measurements).length) showMeasures.value = true;
}
function edit(e: Entry) {
    editing.value = e;
    fill(e, true);
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function reset() {
    editing.value = null;
    form.reset();
    form.measurements = emptyMeasurements();
    form.clearErrors();
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => reset() };
    if (editing.value) form.patch(entryUpdate(editing.value.id).url, opts);
    else form.post(entryStore().url, opts);
}
function remove(e: Entry) {
    const extra = e.photos.length ? ` e ${e.photos.length} foto(s)` : '';
    if (confirm(`Excluir o registro de ${fmtLongDate(e.entry_date)}${extra}?`)) {
        router.delete(entryDestroy(e.id).url, { preserveScroll: true, onSuccess: () => editing.value?.id === e.id && reset() });
    }
}

// ---------- Gráficos ----------
const measuredTypes = computed(() => props.types.filter((t) => (props.series.measurements[t.code]?.length ?? 0) > 0));
const chartType = ref<string | null>(null);
watch(
    measuredTypes,
    (types) => {
        if (!types.some((t) => t.code === chartType.value)) chartType.value = types[0]?.code ?? null;
    },
    { immediate: true },
);

// ---------- Fotos ----------
const uploadFor = ref<string | null>(null);
const uploadAngle = ref('front');
const uploadError = ref<string | null>(null);
const uploading = ref(false);
async function upload(entry: Entry, e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploadError.value = null;
    uploading.value = true;
    const prepared = await prepareImage(file);
    router.post(
        photoStore(entry.id).url,
        { photo: prepared, angle: uploadAngle.value },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => (uploadError.value = errors.photo ?? errors.angle ?? 'Não foi possível enviar a foto.'),
            onSuccess: () => (uploadFor.value = null),
            onFinish: () => {
                uploading.value = false;
                input.value = '';
            },
        },
    );
}
function removePhoto(p: Photo) {
    if (confirm('Excluir esta foto?')) router.delete(photoDestroy(p.id).url, { preserveScroll: true });
}

// Comparação: primeira e última foto do mesmo ângulo.
const photoAngles = computed(() => [...new Set(props.entries.flatMap((e) => e.photos.map((p) => p.angle)))]);
const compareAngle = ref<string | null>(null);
watch(
    photoAngles,
    (angles) => {
        if (!angles.includes(compareAngle.value ?? '')) compareAngle.value = angles[0] ?? null;
    },
    { immediate: true },
);
const comparison = computed(() => {
    const withAngle = [...props.entries]
        .reverse()
        .flatMap((e) => e.photos.filter((p) => p.angle === compareAngle.value).map((p) => ({ ...p, date: e.entry_date, weight: e.weight_kg })));
    if (withAngle.length < 2) return null;
    return { first: withAngle[0], last: withAngle[withAngle.length - 1] };
});
</script>

<template>
    <Head title="Evolução física" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
        <Heading title="Evolução física" description="Peso, medidas e fotos ao longo do tempo" />

        <!-- Resumo -->
        <section v-if="summary" class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-test="progress-summary">
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Peso atual</p>
                <p class="text-2xl font-semibold">{{ fmtNumber(summary.weight_kg) }} kg</p>
                <p class="text-xs text-muted-foreground">{{ fmtShortDate(summary.date) }}</p>
            </div>
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Em 30 dias</p>
                <p class="text-xl font-semibold">{{ summary.change_30d !== null ? signed(summary.change_30d, 'kg') : '—' }}</p>
                <p class="text-xs text-muted-foreground">{{ summary.change_30d_from ? `desde ${fmtShortDate(summary.change_30d_from)}` : 'registre por mais tempo' }}</p>
            </div>
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Desde o início</p>
                <p class="text-xl font-semibold">{{ summary.change_total !== null ? signed(summary.change_total, 'kg') : '—' }}</p>
                <p class="text-xs text-muted-foreground">desde {{ fmtShortDate(summary.first_date) }}</p>
            </div>
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">IMC</p>
                <p class="text-xl font-semibold">{{ summary.bmi !== null ? fmtNumber(summary.bmi) : '—' }}</p>
                <p class="text-xs text-muted-foreground">{{ summary.bmi !== null ? 'referência geral' : 'informe a altura no perfil' }}</p>
            </div>
        </section>

        <!-- Formulário -->
        <form class="flex flex-col gap-3 rounded-2xl bg-card p-4" @submit.prevent="submit">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-medium">{{ editing ? `Editando ${fmtLongDate(editing.entry_date)}` : 'Novo registro' }}</h2>
                <Button v-if="editing" type="button" variant="ghost" size="sm" @click="reset"><X class="size-4" /> Cancelar</Button>
            </div>
            <p v-if="sameDay" class="text-xs text-muted-foreground">Já existe um registro neste dia; os dados abaixo serão atualizados.</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="grid gap-1">
                    <Label for="entry_date">Data</Label>
                    <Input id="entry_date" v-model="form.entry_date" type="date" :max="today" required />
                    <InputError :message="form.errors.entry_date" />
                </div>
                <div class="grid gap-1">
                    <Label for="weight">Peso (kg)</Label>
                    <Input id="weight" v-model.number="form.weight_kg" type="number" step="0.1" min="20" max="400" inputmode="decimal" />
                    <InputError :message="form.errors.weight_kg" />
                </div>
                <div class="grid gap-1">
                    <Label for="body_fat">Gordura corporal (%)</Label>
                    <Input id="body_fat" v-model.number="form.body_fat_pct" type="number" step="0.1" min="3" max="70" inputmode="decimal" placeholder="opcional" />
                    <InputError :message="form.errors.body_fat_pct" />
                </div>
            </div>

            <button type="button" class="self-start text-sm font-medium underline underline-offset-4" :aria-expanded="showMeasures" @click="showMeasures = !showMeasures">
                {{ showMeasures ? 'Ocultar medidas' : filledMeasures ? `Medidas (${filledMeasures} preenchida${filledMeasures > 1 ? 's' : ''})` : 'Adicionar medidas (cm)' }}
            </button>
            <div v-if="showMeasures" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="t in types" :key="t.code" class="grid gap-1">
                    <Label :for="`m-${t.code}`" class="text-xs">{{ t.name }}</Label>
                    <Input :id="`m-${t.code}`" v-model.number="form.measurements[t.code]" type="number" step="0.1" min="10" max="300" inputmode="decimal" />
                </div>
            </div>
            <InputError :message="measureError" />

            <div class="grid gap-1">
                <Label for="notes">Observação</Label>
                <Input id="notes" v-model="form.notes" maxlength="500" placeholder="opcional (ex.: medido em jejum)" />
                <InputError :message="form.errors.notes" />
            </div>

            <div>
                <Button type="submit" :disabled="form.processing">{{ editing || sameDay ? 'Salvar alterações' : 'Registrar' }}</Button>
            </div>
            <p class="text-xs text-muted-foreground">Dica: pese-se sempre no mesmo horário (de manhã, em jejum) para comparar melhor.</p>
        </form>

        <!-- Gráficos -->
        <section v-if="series.weight.length" class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Peso (kg)</h2>
            <div class="mt-3"><LineChart :points="series.weight" unit="kg" label="Peso" /></div>
        </section>

        <section v-if="measuredTypes.length" class="rounded-2xl bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium">Medidas (cm)</h2>
                <select
                    v-model="chartType"
                    aria-label="Medida exibida"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                >
                    <option v-for="t in measuredTypes" :key="t.code" :value="t.code">{{ t.name }}</option>
                </select>
            </div>
            <div v-if="chartType" class="mt-3">
                <LineChart :key="chartType" :points="series.measurements[chartType]" unit="cm" :label="typeName(chartType)" />
            </div>
        </section>

        <section v-if="series.body_fat.length > 1" class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Gordura corporal (%)</h2>
            <div class="mt-3"><LineChart :points="series.body_fat" unit="%" label="Gordura corporal" /></div>
        </section>

        <!-- Comparação de fotos -->
        <section v-if="photoAngles.length" class="rounded-2xl bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium">Comparar fotos</h2>
                <select v-model="compareAngle" aria-label="Ângulo" class="h-8 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30">
                    <option v-for="a in photoAngles" :key="a" :value="a">{{ angleLabels[a] }}</option>
                </select>
            </div>
            <div v-if="comparison" class="mt-3 grid grid-cols-2 gap-3">
                <figure v-for="p in [comparison.first, comparison.last]" :key="p.id">
                    <img :src="p.url" :alt="`Foto ${angleLabels[p.angle]} de ${fmtLongDate(p.date)}`" class="aspect-[3/4] w-full rounded-lg object-cover" loading="lazy" />
                    <figcaption class="mt-1 text-center text-xs text-muted-foreground">
                        {{ fmtShortDate(p.date) }}<template v-if="p.weight"> · {{ fmtNumber(p.weight) }} kg</template>
                    </figcaption>
                </figure>
            </div>
            <p v-else class="mt-2 text-sm text-muted-foreground">Adicione fotos deste ângulo em dois dias diferentes para comparar.</p>
        </section>

        <!-- Registros -->
        <section class="rounded-2xl bg-card p-4">
            <h2 class="font-medium">Registros</h2>
            <p v-if="!entries.length" class="mt-2 text-sm text-muted-foreground">Nenhum registro ainda. Comece pelo peso de hoje.</p>
            <ul class="divide-y">
                <li v-for="e in entries" :key="e.id" class="py-3" data-test="progress-entry">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ fmtLongDate(e.entry_date) }}</p>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="e.weight_kg !== null">{{ fmtNumber(e.weight_kg) }} kg</template>
                                <template v-if="e.body_fat_pct !== null"> · {{ fmtNumber(e.body_fat_pct) }}% gordura</template>
                            </p>
                            <p v-if="Object.keys(e.measurements).length" class="text-xs text-muted-foreground">
                                {{ Object.entries(e.measurements).map(([c, v]) => `${typeName(c)} ${fmtNumber(v)} cm`).join(' · ') }}
                            </p>
                            <p v-if="e.notes" class="text-xs text-muted-foreground italic">{{ e.notes }}</p>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <Button variant="ghost" size="icon" class="size-8" aria-label="Adicionar foto" @click="uploadFor = uploadFor === e.id ? null : e.id"><Camera class="size-4" /></Button>
                            <Button variant="ghost" size="icon" class="size-8" aria-label="Editar" @click="edit(e)"><Pencil class="size-4" /></Button>
                            <Button variant="ghost" size="icon" class="size-8" aria-label="Excluir" @click="remove(e)"><Trash2 class="size-4" /></Button>
                        </div>
                    </div>

                    <div v-if="uploadFor === e.id" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-muted/40 p-2 text-sm">
                        <select v-model="uploadAngle" aria-label="Ângulo da foto" class="h-8 rounded-md border border-input bg-background px-2 text-sm">
                            <option v-for="(label, value) in angleLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <input type="file" accept="image/*" :disabled="uploading" aria-label="Escolher foto" class="text-sm" @change="upload(e, $event)" />
                        <span v-if="uploading" class="text-xs text-muted-foreground">Enviando…</span>
                        <p class="w-full text-xs text-muted-foreground">As fotos ficam privadas: só você vê. A localização gravada pelo celular é removida.</p>
                        <InputError :message="uploadError ?? undefined" class="w-full" />
                    </div>

                    <div v-if="e.photos.length" class="mt-2 flex flex-wrap gap-2">
                        <div v-for="p in e.photos" :key="p.id" class="relative">
                            <a :href="p.url" target="_blank" rel="noopener">
                                <img :src="p.url" :alt="`Foto ${angleLabels[p.angle]}`" class="h-24 w-18 rounded-md object-cover" loading="lazy" />
                            </a>
                            <span class="absolute bottom-1 left-1 rounded bg-black/60 px-1 text-[10px] text-white">{{ angleLabels[p.angle] }}</span>
                            <button
                                type="button"
                                class="absolute -top-1.5 -right-1.5 rounded-full border bg-background p-0.5"
                                aria-label="Excluir foto"
                                @click="removePhoto(p)"
                            >
                                <X class="size-3" />
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
