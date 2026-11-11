<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Dumbbell, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { levelLabels } from '@/lib/labels';
import { index as exercisesIndex, show as exerciseShow } from '@/routes/exercises';

type Option = { code: string; name_pt: string };
type ExerciseSummary = {
    id: number;
    name: string;
    original_name: string | null;
    category: string | null;
    level: string | null;
    image: string | null;
    primary_groups: string[];
};
type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = { q: string; group: string; equipment: string; category: string; level: string };

const props = defineProps<{
    exercises: Paginated<ExerciseSummary>;
    filters: Filters;
    options: { groups: Option[]; equipment: Option[]; categories: Option[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Exercícios', href: exercisesIndex() }] },
});

const form = ref<Filters>({ ...props.filters });
const hasFilters = computed(() => Object.values(props.filters).some((v) => v));

function apply() {
    const params = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v));
    router.get(exercisesIndex().url, params, { preserveState: true, preserveScroll: false, replace: true });
}

function selectGroup(code: string) {
    form.value.group = form.value.group === code ? '' : code;
    apply();
}

function clear() {
    form.value = { q: '', group: '', equipment: '', category: '', level: '' };
    apply();
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="Exercícios" />

    <div class="flex flex-col gap-5 p-4">
        <Heading title="Exercícios" :description="`${exercises.total} exercício(s) encontrados`" />

        <form class="flex gap-2" @submit.prevent="apply">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input v-model="form.q" placeholder="Buscar: supino, agachamento, rosca…" class="pl-8" data-test="exercise-search" />
            </div>
            <Button type="submit">Buscar</Button>
        </form>

        <!-- Grupos musculares como atalhos -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="g in options.groups"
                :key="g.code"
                type="button"
                class="rounded-full border px-3 py-1 text-sm transition-colors"
                :class="form.group === g.code ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                @click="selectGroup(g.code)"
            >
                {{ g.name_pt }}
            </button>
        </div>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <select v-model="form.equipment" :class="selectClass" aria-label="Equipamento" @change="apply">
                <option value="">Qualquer equipamento</option>
                <option v-for="o in options.equipment" :key="o.code" :value="o.code">{{ o.name_pt }}</option>
            </select>
            <select v-model="form.category" :class="selectClass" aria-label="Categoria" @change="apply">
                <option value="">Qualquer categoria</option>
                <option v-for="o in options.categories" :key="o.code" :value="o.code">{{ o.name_pt }}</option>
            </select>
            <select v-model="form.level" :class="selectClass" aria-label="Nível" @change="apply">
                <option value="">Qualquer nível</option>
                <option v-for="(label, code) in levelLabels" :key="code" :value="code">{{ label }}</option>
            </select>
        </div>

        <div v-if="hasFilters">
            <Button variant="ghost" size="sm" @click="clear"><X class="size-4" /> Limpar filtros</Button>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="e in exercises.data"
                :key="e.id"
                :href="exerciseShow(e.id)"
                class="flex gap-3 rounded-2xl bg-card p-3 transition-colors hover:bg-muted/60"
                :data-test="`exercise-${e.id}`"
            >
                <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                    <img v-if="e.image" :src="e.image" :alt="e.name" loading="lazy" class="size-full object-cover" />
                    <Dumbbell v-else class="size-6 text-muted-foreground" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="leading-tight font-medium">{{ e.name }}</p>
                    <p v-if="e.original_name" class="text-xs text-muted-foreground">{{ e.original_name }}</p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <Badge v-for="g in e.primary_groups" :key="g" variant="secondary">{{ g }}</Badge>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ e.category }}<template v-if="e.level"> · {{ levelLabels[e.level] }}</template>
                    </p>
                </div>
            </Link>
        </div>

        <p v-if="exercises.data.length === 0" class="py-10 text-center text-muted-foreground">
            Nenhum exercício encontrado. Tente outro termo ou limpe os filtros.
        </p>

        <div v-if="exercises.last_page > 1" class="flex items-center justify-between text-sm">
            <Link v-if="exercises.prev_page_url" :href="exercises.prev_page_url" class="underline">Anterior</Link>
            <span v-else></span>
            <span class="text-muted-foreground">Página {{ exercises.current_page }} de {{ exercises.last_page }}</span>
            <Link v-if="exercises.next_page_url" :href="exercises.next_page_url" class="underline">Próxima</Link>
            <span v-else></span>
        </div>

        <p class="text-center text-xs text-muted-foreground">
            Base de exercícios: Free Exercise DB (domínio público). Nomes e instruções originais em inglês; a tradução está em andamento.
        </p>
    </div>
</template>
