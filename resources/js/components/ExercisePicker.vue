<script setup lang="ts">
import { Dumbbell, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { search as exercisesSearch } from '@/routes/exercises';

/**
 * Janela para escolher um exercício da biblioteca (busca em português + grupo muscular).
 * Emite "select" com o id do exercício.
 */
type Result = { id: number; name: string; original_name: string | null; image: string | null; primary_groups: string[] };

const open = defineModel<boolean>('open', { default: false });
export type PickedExercise = Result;
const emit = defineEmits<{ select: [exerciseId: number, exercise?: Result] }>();

const groups = [
    ['CHEST', 'Peitoral'], ['BACK', 'Costas'], ['SHOULDERS', 'Ombros'], ['BICEPS', 'Bíceps'],
    ['TRICEPS', 'Tríceps'], ['FOREARMS', 'Antebraços'], ['ABS', 'Abdômen'], ['QUADRICEPS', 'Quadríceps'],
    ['HAMSTRINGS', 'Posterior'], ['GLUTES', 'Glúteos'], ['CALVES', 'Panturrilhas'],
];

const q = ref('');
const group = ref('');
const results = ref<Result[]>([]);
const loading = ref(false);
const error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

async function load() {
    const id = ++requestId;
    loading.value = true;
    error.value = '';
    try {
        const params: Record<string, string> = {};
        if (q.value.trim()) params.q = q.value.trim();
        if (group.value) params.group = group.value;
        const res = await fetch(exercisesSearch({ query: params }).url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const data = (await res.json()) as Result[];
        if (id === requestId) results.value = data;
    } catch {
        if (id === requestId) error.value = 'Não foi possível buscar os exercícios. Verifique sua conexão.';
    } finally {
        if (id === requestId) loading.value = false;
    }
}

watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(group, load);
watch(open, (isOpen) => {
    if (isOpen && results.value.length === 0) load();
});

function pick(id: number) {
    emit('select', id, results.value.find((r) => r.id === id));
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90vh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Adicionar exercício</DialogTitle>
                <DialogDescription>Busque pelo nome ou escolha um grupo muscular.</DialogDescription>
            </DialogHeader>

            <div class="relative">
                <Search class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input v-model="q" placeholder="supino, agachamento, rosca…" class="pl-8" autofocus data-test="picker-search" />
            </div>
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="[code, label] in groups"
                    :key="code"
                    type="button"
                    class="rounded-full border px-2 py-0.5 text-xs"
                    :class="group === code ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    @click="group = group === code ? '' : code"
                >
                    {{ label }}
                </button>
            </div>

            <div class="-mx-2 min-h-40 flex-1 overflow-y-auto px-2">
                <p v-if="error" class="py-6 text-center text-sm text-destructive">{{ error }}</p>
                <p v-else-if="loading && results.length === 0" class="py-6 text-center text-sm text-muted-foreground">Buscando…</p>
                <p v-else-if="results.length === 0" class="py-6 text-center text-sm text-muted-foreground">Nenhum exercício encontrado.</p>
                <button
                    v-for="r in results"
                    :key="r.id"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg p-2 text-left hover:bg-muted"
                    :data-test="`pick-${r.id}`"
                    @click="pick(r.id)"
                >
                    <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted">
                        <img v-if="r.image" :src="r.image" alt="" loading="lazy" class="size-full object-cover" />
                        <Dumbbell v-else class="size-4 text-muted-foreground" />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ r.name }}</p>
                        <div class="mt-0.5 flex flex-wrap gap-1">
                            <Badge v-for="g in r.primary_groups" :key="g" variant="secondary" class="text-[10px]">{{ g }}</Badge>
                        </div>
                    </div>
                </button>
            </div>

            <Button variant="secondary" @click="open = false">Fechar</Button>
        </DialogContent>
    </Dialog>
</template>
