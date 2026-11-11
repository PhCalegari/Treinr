<script setup lang="ts">
// Recuperação muscular: o boneco mostra cada grupo como cansado, recuperando ou pronto.
// Tocar num músculo (no desenho ou na lista) mostra a fadiga e quando volta a 95%.
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { index as workoutsIndex } from '@/routes/workouts';

type Muscle = {
    code: string;
    name: string;
    fatigue: number;
    hours_to_95: number;
    last_trained: string | null;
    status: 'fatigued' | 'recovering' | 'fresh';
};

const props = defineProps<{ muscles: Muscle[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Treinos', href: workoutsIndex() }] },
});

const STATUS = {
    fatigued: { label: 'Cansado', color: 'var(--destructive)' },
    recovering: { label: 'Recuperando', color: 'var(--primary)' },
    fresh: { label: 'Pronto', color: 'var(--success)' },
} as const;

const colors = computed(() =>
    Object.fromEntries(
        props.muscles.map((m) => [m.code, STATUS[m.status].color]),
    ),
);
// Começa mostrando o grupo mais cansado.
const sorted = computed(() =>
    [...props.muscles].sort((a, b) => b.fatigue - a.fatigue),
);
const selectedCode = ref<string | null>(
    sorted.value[0]?.fatigue ? sorted.value[0].code : null,
);
const selected = computed(
    () => props.muscles.find((m) => m.code === selectedCode.value) ?? null,
);
const readyCount = computed(
    () => props.muscles.filter((m) => m.status === 'fresh').length,
);

function backIn(hours: number): string {
    if (hours <= 0) return 'Já recuperado';
    if (hours < 24) return `${hours} h`;
    const d = hours / 24;
    return `${d.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ${d < 1.05 ? 'dia' : 'dias'}`;
}
function lastText(iso: string | null): string {
    if (!iso) return 'Sem treino nos últimos 10 dias';
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    return days === 0
        ? 'Treinado hoje'
        : days === 1
          ? 'Treinado ontem'
          : `Treinado há ${days} dias`;
}
</script>

<template>
    <Head title="Recuperação muscular" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 pb-28">
        <div class="flex items-center gap-2">
            <Link
                :href="workoutsIndex()"
                class="rounded-full p-1 text-muted-foreground hover:text-foreground"
                aria-label="Voltar aos treinos"
                ><ArrowLeft class="size-5"
            /></Link>
            <h1 class="font-display text-2xl font-bold tracking-tight uppercase">
                Recuperação muscular
            </h1>
        </div>
        <p class="-mt-2 text-sm text-muted-foreground">
            {{ readyCount }} de {{ muscles.length }} grupos prontos para
            treinar.
        </p>

        <section class="rounded-2xl bg-card p-4">
            <div class="grid grid-cols-2 gap-2">
                <MuscleMap
                    view="front"
                    :groups="[]"
                    :colors="colors"
                    :highlight="selectedCode"
                    interactive
                    class="h-72 w-full sm:h-80"
                    label="Frente: recuperação dos músculos"
                    @select="selectedCode = $event"
                />
                <MuscleMap
                    view="back"
                    :groups="[]"
                    :colors="colors"
                    :highlight="selectedCode"
                    interactive
                    class="h-72 w-full sm:h-80"
                    label="Costas: recuperação dos músculos"
                    @select="selectedCode = $event"
                />
            </div>

            <!-- Detalhe do músculo tocado -->
            <div
                class="mt-3 rounded-2xl bg-muted/50 p-4"
                aria-live="polite"
                data-test="recovery-detail"
            >
                <template v-if="selected">
                    <p class="font-semibold">{{ selected.name }}</p>
                    <div class="mt-2 flex items-end justify-between gap-3">
                        <p>
                            <span class="block text-xs text-muted-foreground"
                                >Fadiga</span
                            >
                            <span
                                class="font-display text-3xl leading-none font-bold"
                                :style="{
                                    color: STATUS[selected.status].color,
                                }"
                                >{{ selected.fatigue }}%</span
                            >
                        </p>
                        <p class="text-right">
                            <span class="block text-xs text-muted-foreground"
                                >Volta a 95%</span
                            >
                            <span
                                class="font-display text-3xl leading-none font-bold"
                                >{{ backIn(selected.hours_to_95) }}</span
                            >
                        </p>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ lastText(selected.last_trained) }}. Toque em outro
                        músculo para ver a recuperação dele.
                    </p>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Toque num músculo do desenho para ver a fadiga e quando ele
                    volta a 95%.
                </p>
            </div>

            <div
                class="mt-3 flex flex-wrap items-center justify-center gap-4 text-xs text-muted-foreground"
            >
                <span
                    v-for="(s, key) in STATUS"
                    :key="key"
                    class="flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-full"
                        :style="{ background: s.color }"
                    ></span
                    >{{ s.label }}</span
                >
            </div>
        </section>

        <!-- Lista de grupos -->
        <section class="overflow-hidden rounded-2xl bg-card">
            <p
                class="bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Todos os grupos
            </p>
            <ul class="divide-y divide-border">
                <li v-for="m in sorted" :key="m.code">
                    <button
                        type="button"
                        class="grid w-full grid-cols-[minmax(0,7rem)_1fr_auto] items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-muted/50"
                        :class="m.code === selectedCode ? 'bg-muted/50' : ''"
                        :aria-pressed="m.code === selectedCode"
                        @click="selectedCode = m.code"
                    >
                        <span class="truncate font-medium">{{ m.name }}</span>
                        <span
                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full"
                                :style="{
                                    width: Math.max(2, 100 - m.fatigue) + '%',
                                    background: STATUS[m.status].color,
                                }"
                            ></span>
                        </span>
                        <span
                            class="w-20 text-right text-xs text-muted-foreground"
                            >{{
                                m.status === 'fresh'
                                    ? 'Pronto'
                                    : backIn(m.hours_to_95)
                            }}</span
                        >
                    </button>
                </li>
            </ul>
            <p class="px-4 py-3 text-xs text-muted-foreground">
                A barra mostra quanto o músculo já recuperou. Estimativa a
                partir das séries feitas nos últimos 10 dias.
            </p>
        </section>
    </div>
</template>
