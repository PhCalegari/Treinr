<script setup lang="ts">
// Gráfico de linha de uma série ao longo do tempo (peso, medidas).
// Passe o mouse/dedo para ver cada registro; setas do teclado também navegam.
// "Ver tabela" mostra todos os valores sem precisar do gráfico.
import { useElementSize } from '@vueuse/core';
import { computed, ref, useId } from 'vue';
import { dayMs, fmtLongDate, fmtNumber, fmtShortDate, fmtTick, niceDomain, toTime } from '@/lib/chart';

export type Point = { date: string; value: number };

const props = withDefaults(defineProps<{ points: Point[]; unit: string; label: string; decimals?: number; height?: number }>(), {
    decimals: 1,
    height: 200,
});

const wrap = ref<HTMLElement | null>(null);
const { width } = useElementSize(wrap);
const showTable = ref(false);
const active = ref<number | null>(null);
const tableId = useId();

const M = { top: 12, right: 56, bottom: 24, left: 44 };

const geo = computed(() => {
    const w = Math.max(width.value, 240);
    const h = props.height;
    const pts = props.points;
    const times = pts.map((p) => toTime(p.date));
    let t0 = Math.min(...times);
    let t1 = Math.max(...times);
    if (t0 === t1) {
        t0 -= dayMs * 3;
        t1 += dayMs * 3;
    }
    const y = niceDomain(pts.map((p) => p.value));
    const sx = (t: number) => M.left + ((t - t0) / (t1 - t0)) * (w - M.left - M.right);
    const sy = (v: number) => M.top + (1 - (v - y.min) / (y.max - y.min)) * (h - M.top - M.bottom);
    const xy = pts.map((p, i) => ({ x: sx(times[i]), y: sy(p.value), ...p }));
    const line = xy.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('');
    const base = h - M.bottom;
    const area = xy.length > 1 ? `${line}L${xy[xy.length - 1].x.toFixed(1)},${base}L${xy[0].x.toFixed(1)},${base}Z` : '';

    // Datas no eixo X: primeira, última e uma no meio quando couber.
    const xTicks = [xy[0]];
    if (xy.length > 2 && w > 360) xTicks.push(xy[Math.floor((xy.length - 1) / 2)]);
    if (xy.length > 1) xTicks.push(xy[xy.length - 1]);

    return { w, h, xy, line, area, base, yTicks: y.ticks.map((v) => ({ v, y: sy(v) })), xTicks };
});

const last = computed(() => geo.value.xy[geo.value.xy.length - 1]);
const current = computed(() => (active.value === null ? null : geo.value.xy[active.value]));

function onPointer(e: PointerEvent) {
    const svg = e.currentTarget as SVGSVGElement;
    const x = e.clientX - svg.getBoundingClientRect().left;
    let best = 0;
    geo.value.xy.forEach((p, i) => {
        if (Math.abs(p.x - x) < Math.abs(geo.value.xy[best].x - x)) best = i;
    });
    active.value = best;
}
function onKey(e: KeyboardEvent) {
    const n = geo.value.xy.length;
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        const step = e.key === 'ArrowRight' ? 1 : -1;
        active.value = Math.min(n - 1, Math.max(0, (active.value ?? (step > 0 ? -1 : n)) + step));
    } else if (e.key === 'Escape') active.value = null;
}

const tooltipStyle = computed(() => {
    const p = current.value;
    if (!p) return {};
    const left = Math.min(Math.max(p.x, 70), geo.value.w - 70);
    return { left: `${left}px`, top: `${Math.max(p.y - 12, 0)}px` };
});
const fmt = (v: number) => `${fmtNumber(v, props.decimals)} ${props.unit}`;
</script>

<template>
    <div>
        <div ref="wrap" class="relative w-full">
            <svg
                :width="geo.w"
                :height="geo.h"
                class="block touch-pan-y outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md"
                role="img"
                :aria-label="`${label}: ${points.length} registros, último ${fmt(last.value)} em ${fmtLongDate(last.date)}. Use as setas para navegar.`"
                tabindex="0"
                @pointermove="onPointer"
                @pointerdown="onPointer"
                @pointerleave="active = null"
                @keydown="onKey"
                @blur="active = null"
            >
                <!-- grade e eixo Y -->
                <g>
                    <line
                        v-for="t in geo.yTicks"
                        :key="t.v"
                        :x1="M.left"
                        :x2="geo.w - M.right"
                        :y1="t.y"
                        :y2="t.y"
                        stroke="var(--viz-grid)"
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
                    <text
                        v-for="t in geo.xTicks"
                        :key="`x${t.date}`"
                        :x="t.x"
                        :y="geo.h - 6"
                        text-anchor="middle"
                        class="text-[11px]"
                        fill="var(--viz-muted)"
                    >
                        {{ fmtShortDate(t.date) }}
                    </text>
                </g>

                <path v-if="geo.area" :d="geo.area" fill="var(--viz-series)" fill-opacity="0.1" />
                <path :d="geo.line" fill="none" stroke="var(--viz-series)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                <!-- crosshair -->
                <line v-if="current" :x1="current.x" :x2="current.x" :y1="M.top" :y2="geo.base" stroke="var(--viz-axis)" stroke-width="1" />

                <!-- marcador final + rótulo do último valor -->
                <circle :cx="last.x" :cy="last.y" r="4" fill="var(--viz-series)" stroke="var(--background)" stroke-width="2" />
                <text :x="last.x + 8" :y="last.y" dominant-baseline="middle" class="text-[12px] font-medium" fill="currentColor">
                    {{ fmtNumber(last.value, decimals) }}
                </text>

                <circle v-if="current" :cx="current.x" :cy="current.y" r="5" fill="var(--viz-series)" stroke="var(--background)" stroke-width="2" />
            </svg>

            <div
                v-if="current"
                class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md border bg-popover px-2.5 py-1.5 text-xs shadow-md"
                :style="tooltipStyle"
                role="status"
            >
                <p class="text-sm font-semibold text-popover-foreground">{{ fmt(current.value) }}</p>
                <p class="text-muted-foreground">{{ fmtLongDate(current.date) }}</p>
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
            <caption class="sr-only">{{ label }}</caption>
            <thead>
                <tr class="text-left text-xs text-muted-foreground">
                    <th class="py-1 font-normal">Data</th>
                    <th class="py-1 text-right font-normal">{{ label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in [...points].reverse()" :key="p.date" class="border-t">
                    <td class="py-1">{{ fmtLongDate(p.date) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ fmt(p.value) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
