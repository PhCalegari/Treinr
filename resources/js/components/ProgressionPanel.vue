<script setup lang="ts">
// Progressão de carga no treino: o que foi feito na última vez (série a série),
// a evolução da carga máxima nos últimos treinos e a meta de hoje.
import { Minus, TrendingDown, TrendingUp, Target } from '@lucide/vue';
import { computed } from 'vue';
import { fmtNumber, fmtShortDate } from '@/lib/chart';

export type Progression = {
    last: {
        date: string;
        days_ago: number;
        sets: { reps: number | null; load_kg: number | null; duration_sec?: number | null; distance_km?: number | null }[];
    };
    unit: 'kg' | 'min';
    trend: { date: string; value: number }[];
    change: number | null;
    since: string | null;
    suggestion: { load_kg: number; reps: number | null; reason: string } | null;
};

const props = defineProps<{ progression: Progression; kind?: string }>();
const timed = computed(() => (props.kind ?? 'strength') !== 'strength');
const unit = computed(() => props.progression.unit);
/** Série da última vez: "22,5 kg × 12" (força) ou "20 min · 3,2 km" (cardio). */
function setText(s: Progression['last']['sets'][number]): string {
    if (!timed.value) return `${kg(s.load_kg)} kg × ${s.reps ?? '–'}`;
    const min = s.duration_sec ? fmtNumber(s.duration_sec / 60, 1) + ' min' : '–';
    return s.distance_km ? `${min} · ${fmtNumber(s.distance_km, 2)} km` : min;
}
const emit = defineEmits<{ apply: [] }>();

const kg = (v: number | null) => (v === null ? '–' : fmtNumber(v, 2));
const ago = computed(() => {
    const d = props.progression.last.days_ago;
    return d === 0 ? 'hoje' : d === 1 ? 'ontem' : `há ${d} dias`;
});

// Minigráfico da carga máxima por treino (uma série; o último ponto em destaque).
const W = 240;
const H = 44;
const PAD = 6;
const spark = computed(() => {
    const t = props.progression.trend;
    if (t.length < 2) return null;
    const vals = t.map((p) => p.value);
    let lo = Math.min(...vals);
    let hi = Math.max(...vals);
    if (lo === hi) {
        lo -= 1;
        hi += 1;
    }
    const pts = t.map((p, i) => ({
        x: PAD + (i / (t.length - 1)) * (W - PAD * 2),
        y: PAD + (1 - (p.value - lo) / (hi - lo)) * (H - PAD * 2),
        ...p,
    }));
    return { pts, d: pts.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('') };
});
const change = computed(() => props.progression.change);
</script>

<template>
    <div class="flex flex-col gap-3 rounded-2xl bg-muted/50 p-3" data-test="progression">
        <!-- Última vez -->
        <div>
            <p class="font-display text-sm font-bold tracking-tight uppercase">
                Última vez <span class="font-sans text-xs font-normal tracking-normal text-muted-foreground normal-case">· {{ fmtShortDate(progression.last.date) }} ({{ ago }})</span>
            </p>
            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                <li v-for="(s, i) in progression.last.sets" :key="i" class="rounded-lg bg-card px-2 py-1 text-sm tabular-nums">
                    <span class="text-muted-foreground">{{ i + 1 }}ª</span> {{ setText(s) }}
                </li>
            </ul>
        </div>

        <!-- Evolução da carga máxima -->
        <div v-if="spark" class="flex items-center gap-3">
            <svg :viewBox="`0 0 ${W} ${H}`" class="h-11 min-w-0 flex-1" role="img" :aria-label="`${timed ? 'Tempo total' : 'Carga máxima'} nos últimos ${progression.trend.length} treinos: de ${kg(progression.trend[0].value)} para ${kg(progression.trend[progression.trend.length - 1].value)} ${unit}`">
                <path :d="spark.d" fill="none" stroke="var(--viz-series)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                <circle v-for="(p, i) in spark.pts" :key="p.date + i" :cx="p.x" :cy="p.y" :r="i === spark.pts.length - 1 ? 4 : 2.5" :fill="i === spark.pts.length - 1 ? 'var(--primary)' : 'var(--viz-series)'" stroke="var(--card)" stroke-width="1.5">
                    <title>{{ fmtShortDate(p.date) }}: {{ kg(p.value) }} {{ unit }}</title>
                </circle>
            </svg>
            <div class="shrink-0 text-right text-sm leading-tight">
                <p class="flex items-center justify-end gap-1 font-semibold" :class="change && change > 0 ? 'text-success' : change && change < 0 ? 'text-destructive' : ''">
                    <component :is="change && change > 0 ? TrendingUp : change && change < 0 ? TrendingDown : Minus" class="size-4" />
                    {{ change ? `${change > 0 ? '+' : '−'}${kg(Math.abs(change))} ${unit}` : 'igual' }}
                </p>
                <p class="text-xs text-muted-foreground">desde {{ fmtShortDate(progression.since!) }}</p>
            </div>
        </div>

        <!-- Meta de hoje -->
        <button
            v-if="progression.suggestion"
            type="button"
            class="flex items-start gap-2 rounded-xl border border-primary/40 bg-primary/10 p-2.5 text-left hover:bg-primary/15"
            data-test="apply-suggestion"
            @click="emit('apply')"
        >
            <Target class="mt-0.5 size-5 shrink-0 text-primary" />
            <span class="min-w-0">
                <span class="block font-semibold">
                    Meta de hoje: {{ kg(progression.suggestion.load_kg) }} kg<template v-if="progression.suggestion.reps"> × {{ progression.suggestion.reps }}</template>
                </span>
                <span class="block text-xs text-muted-foreground">{{ progression.suggestion.reason }} Toque para preencher as séries.</span>
            </span>
        </button>
    </div>
</template>
