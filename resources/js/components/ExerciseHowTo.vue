<script setup lang="ts">
// "Como fazer": animação do movimento (as posições se alternando, como um GIF),
// músculos trabalhados e passo a passo. Abre por cima do treino, sem sair dele.
import { Pause, Play } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export type HowToExercise = {
    name: string;
    frames: string[];
    groups: string[];
    secondary_groups: string[];
    muscle_groups: string[];
    instructions: string[];
};

const props = defineProps<{ exercise: HowToExercise | null }>();
const open = defineModel<boolean>('open', { default: false });

const FRAME_MS = 900;
const frame = ref(0);
const playing = ref(true);
let timer: ReturnType<typeof setInterval> | undefined;

function stop() {
    clearInterval(timer);
    timer = undefined;
}
function play() {
    stop();
    if ((props.exercise?.frames.length ?? 0) < 2) return;
    timer = setInterval(() => (frame.value = (frame.value + 1) % props.exercise!.frames.length), FRAME_MS);
}
function toggle() {
    playing.value = !playing.value;
    if (playing.value) play();
    else stop();
}

watch(open, (isOpen) => {
    frame.value = 0;
    playing.value = true;
    if (isOpen) play();
    else stop();
});
onBeforeUnmount(stop);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="exercise" class="max-h-[92vh] overflow-y-auto p-4 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="pr-6 text-left leading-tight">{{ exercise.name }}</DialogTitle>
                <DialogDescription class="text-left">{{ exercise.muscle_groups.join(', ') || 'Como fazer' }}</DialogDescription>
            </DialogHeader>

            <!-- Animação: as posições do movimento se alternando -->
            <button
                type="button"
                class="relative block h-60 w-full shrink-0 overflow-hidden rounded-2xl bg-white sm:h-72"
                :aria-label="playing ? 'Pausar animação' : 'Rodar animação'"
                data-test="howto-animation"
                @click="toggle"
            >
                <img
                    v-for="(src, i) in exercise.frames"
                    :key="src"
                    :src="src"
                    :alt="i === 0 ? `Execução de ${exercise.name}` : ''"
                    class="absolute inset-0 size-full object-contain transition-opacity duration-300"
                    :class="i === frame ? 'opacity-100' : 'opacity-0'"
                />
                <span class="absolute right-2 bottom-2 flex size-9 items-center justify-center rounded-full bg-black/55 text-white">
                    <component :is="playing ? Pause : Play" class="size-4" />
                </span>
                <span v-if="exercise.frames.length > 1" class="absolute bottom-3 left-3 flex gap-1" aria-hidden="true">
                    <span v-for="(f, i) in exercise.frames" :key="f" class="h-1.5 w-5 rounded-full" :class="i === frame ? 'bg-primary' : 'bg-black/25'"></span>
                </span>
            </button>

            <div class="flex items-start gap-4">
                <MuscleMap :groups="exercise.groups" :secondary="exercise.secondary_groups" class="h-32 w-20 shrink-0" label="Músculos trabalhados" />
                <div class="min-w-0 text-sm">
                    <p class="font-display text-base font-bold tracking-tight uppercase">Músculos</p>
                    <p class="text-muted-foreground">
                        <span class="inline-block size-2 rounded-full bg-[var(--muscle-active)]"></span> principais: {{ exercise.muscle_groups.join(', ') || '—' }}
                    </p>
                    <p v-if="exercise.secondary_groups.length" class="text-muted-foreground">
                        <span class="inline-block size-2 rounded-full bg-[var(--muscle-secondary)]"></span> também trabalha outros grupos
                    </p>
                </div>
            </div>

            <div v-if="exercise.instructions.length">
                <p class="font-display text-base font-bold tracking-tight uppercase">Como fazer</p>
                <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm">
                    <li v-for="(step, i) in exercise.instructions" :key="i">{{ step }}</li>
                </ol>
            </div>
        </DialogContent>
    </Dialog>
</template>
