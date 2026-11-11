<script setup lang="ts">
// Calorias por dia (colunas) com a meta de cada dia (linha de referência).
// Cada coluna mostra o valor ao passar o mouse/tocar; "Ver tabela" lista tudo.
import { useElementSize } from '@vueuse/core';
import { computed, ref, useId } from 'vue';
import { fmtLongDate, fmtNumber, fmtShortDate, fmtTick, niceDomain } from '@/lib/chart';

export type Day = { date: string; kcal: number; logged: boolean; goal_kcal: number | null };

const props = withDefaults(defineProps<{ days: Day[]; height?: number }>(), { height: 200 });

const wrap = ref<HTMLElement | null>(null);
const { width } = useElementSize(wrap);
const active = ref<number | null>(null);
const showTable = ref(false);
const tableId = useId();

const M = { top: 12, right: 36, bottom: 24, left: 44 };
const RADIUS = 4;

const geo = computed(() => {
    const w = Math.max(width.value, 240);
    const h = props.height;
    const n = props.days.length;
    const values = props.days.flatMap((d) => [d.kcal, d.goal_kcal ?? 0]);
    const y = niceDomain([...values, 100], { zero: true });
    const band = (w - M.left - M.right) / n;
    const barW = Math.min(24, Math.max(band - 2, 2)); // até 24px; 2px de espaço entre colunas
    const sy = (v: number) => M.top + (1 - (v - y.min) / (y.max - y.min)) * (h - M.top - M.bottom);
    const base = sy(0);

    const bars = props.days.map((d, i) => {
        const cx = M.left + band * i + band / 2;
        const top = sy(d.kcal);
        const bh = base - top;
        const r = Math.min(RADIUS, bh, barW / 2);
        const x = cx - barW / 2;
        // Topo arredondado, base reta.
        const path = bh > 0
            ? `M${x},${base}V${top + r}Q${x},${top} ${x + r},${top}H${x + barW - r}Q${x + barW},${top} ${x + barW},${top + r}V${base}Z`
            : '';
        return { ...d, i, cx, top, path, x: M.left + band * i, band };
    });

    // Meta como degraus (pode mudar no meio do período).
    const segs: { x1: number; x2: number; y: number; v: number }[] = [];
    bars.forEach((b) => {
        if (b.goal_kcal === null) return;
        const prev = segs[segs.length - 1];
        const x1 = b.x;
        const x2 = b.x + b.band;
        if (prev && prev.v === b.goal_kcal && Math.abs(prev.x2 - x1) < 0.5) prev.x2 = x2;
        else segs.push({ x1, x2, y: sy(b.goal_kcal), v: b.goal_kcal });
    });

    // Datas no eixo X: uma por semana aprox.
    const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor((w - M.left - M.right) / 64))));
    const xTicks = bars.filter((b) => (n - 1 - b.i) % every === 0);

    return { w, h, base, bars, segs, xTicks, yTicks: y.ticks.map((v) => ({ v, y: sy(v) })) };
});

const current = computed(() => (active.value === null ? null : geo.value.bars[active.value]));
const lastGoal = computed(() => geo.value.segs[geo.value.segs.length - 1] ?? null);

function onKey(e: KeyboardEvent) {
    const n = geo.value.bars.length;
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        const step = e.key === 'ArrowRight' ? 1 : -1;
        active.value = Math.min(n - 1, Math.max(0, (active.value ?? (step > 0 ? -1 : n)) + step));
    } else if (e.key === 'Escape') active.value = null;
}

const tooltipStyle = computed(() => {
    const b = current.value;
    if (!b) return {};
    return { left: `${Math.min(Math.max(b.cx, 80), geo.value.w - 80)}px`, top: `${Math.max(Math.min(b.top, geo.value.base) - 10, 0)}px` };
});

function diffText(d: Day): string | null {
    if (!d.logged || !d.goal_kcal) return null;
    const diff = Math.round(d.kcal - d.goal_kcal);
    if (Math.abs(diff) < 1) return 'na meta';
    return diff > 0 ? `${fmtNumber(diff, 0)} kcal acima da meta` : `${fmtNumber(-diff, 0)} kcal abaixo da meta`;
}
</script>

<template>
    <div>
        <div ref="wrap" class="relative w-full">
            <svg
                :width="geo.w"
                :height="geo.h"
                class="block touch-pan-y rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                role="img"
                :aria-label="`Calorias por dia nos últimos ${days.length} dias. Use as setas para navegar.`"
                tabindex="0"
                @keydown="onKey"
                @blur="active = null"
                @pointerleave="active = null"
            >
                <line
                    v-for="t in geo.yTicks"
                    :key="t.v"
                    :x1="M.left"
                    :x2="geo.w - M.right"
                    :y1="t.y"
                    :y2="t.y"
                    :stroke="t.v === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'"
                    stroke-width="1"
                />
                <text
                    v-for="t in geo.yTicks"
                    :key="`l${t.v}`"
                    :x="M.left - 8"
                    :y="t.y"
                    text-anchor="end"
                    dominant-baseline="middle"
                    class="text-[11px] tabular-nums"
                    fill="var(--viz-muted)"
                >
                    {{ fmtTick(t.v) }}
                </text>
                <text v-for="b in geo.xTicks" :key="`x${b.date}`" :x="b.cx" :y="geo.h - 6" text-anchor="middle" class="text-[11px]" fill="var(--viz-muted)">
                    {{ fmtShortDate(b.date) }}
                </text>

                <!-- colunas; a área de toque é a faixa inteira do dia -->
                <g v-for="b in geo.bars" :key="b.date">
                    <path v-if="b.path" :d="b.path" fill="var(--viz-series)" :fill-opacity="active === null || active === b.i ? 1 : 0.45" />
                    <rect
                        :x="b.x"
                        :y="M.top"
                        :width="b.band"
                        :height="geo.base - M.top"
                        fill="transparent"
                        @pointerenter="active = b.i"
                        @pointerdown="active = b.i"
                    />
                </g>

                <!-- meta -->
                <line
                    v-for="(s, i) in geo.segs"
                    :key="`g${i}`"
                    :x1="s.x1"
                    :x2="s.x2"
                    :y1="s.y"
                    :y2="s.y"
                    stroke="var(--viz-ref)"
                    stroke-width="1.5"
                    class="pointer-events-none"
                />
                <text v-if="lastGoal" :x="lastGoal.x2 + 4" :y="lastGoal.y" dominant-baseline="middle" class="text-[11px]" fill="var(--viz-ref)">meta</text>
            </svg>

            <div
                v-if="current"
                class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md border bg-popover px-2.5 py-1.5 text-xs shadow-md"
                :style="tooltipStyle"
                role="status"
            >
                <p class="text-sm font-semibold text-popover-foreground">
                    {{ current.logged ? `${fmtNumber(current.kcal, 0)} kcal` : 'Sem registro' }}
                </p>
                <p class="text-muted-foreground">{{ fmtLongDate(current.date) }}</p>
                <p v-if="current.goal_kcal" class="text-muted-foreground">Meta: {{ fmtNumber(current.goal_kcal, 0) }} kcal</p>
                <p v-if="diffText(current)" class="text-muted-foreground">{{ diffText(current) }}</p>
            </div>
        </div>

        <button
            type="button"
            class="mt-1 text-xs text-muted-foreground underline underline-offset-4"
            :aria-expanded="showTable"
            :aria-controls="tableId"
            @click="showTable = !showTable"
        >
            {{ showTable ? 'Ocultar tabela' : 'Ver tabela' }}
        </button>
        <table v-if="showTable" :id="tableId" class="mt-2 w-full text-sm">
            <caption class="sr-only">Calorias por dia</caption>
            <thead>
                <tr class="text-left text-xs text-muted-foreground">
                    <th class="py-1 font-normal">Dia</th>
                    <th class="py-1 text-right font-normal">Consumo</th>
                    <th class="py-1 text-right font-normal">Meta</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in [...days].reverse()" :key="d.date" class="border-t">
                    <td class="py-1">{{ fmtLongDate(d.date) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ d.logged ? `${fmtNumber(d.kcal, 0)} kcal` : '—' }}</td>
                    <td class="py-1 text-right tabular-nums">{{ d.goal_kcal ? `${fmtNumber(d.goal_kcal, 0)} kcal` : '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
