<script setup lang="ts">
// Página pública de um plano compartilhado por link (/t/{token}).
// Mostra os dias e exercícios; com login, copia o plano para os próprios treinos.
import { Head, Link, router } from '@inertiajs/vue3';
import { CopyPlus, Dumbbell } from '@lucide/vue';
import { computed, ref } from 'vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { copy as copyShared } from '@/routes/shared-plans';
import { index as workoutsIndex } from '@/routes/workouts';

type Exercise = {
    name: string;
    image: string | null;
    sets: number;
    reps_min: number | null;
    reps_max: number | null;
    kind: string;
    duration_sec: number | null;
};
type Day = {
    label: string;
    title: string;
    groups: string[];
    exercises: Exercise[];
};

const props = defineProps<{
    token: string;
    author: string;
    isOwner: boolean;
    loggedIn: boolean;
    plan: { name: string; split_type: string; days: Day[] };
}>();

const selected = ref(0);
const day = computed(() => props.plan.days[selected.value]);
const copying = ref(false);

function copy() {
    copying.value = true;
    router.post(
        copyShared(props.token).url,
        {},
        { onFinish: () => (copying.value = false) },
    );
}

function summary(e: Exercise): string {
    const sets = `${e.sets} ${e.sets === 1 ? 'série' : 'séries'}`;
    if (e.kind !== 'strength') {
        const t = e.duration_sec
            ? e.kind === 'cardio'
                ? `${Math.round(e.duration_sec / 60)} min`
                : `${e.duration_sec}s`
            : null;
        return [sets, t].filter(Boolean).join(' • ');
    }
    const reps =
        e.reps_min && e.reps_max
            ? e.reps_min === e.reps_max
                ? `${e.reps_min}`
                : `${e.reps_min}–${e.reps_max}`
            : null;
    return [sets, reps && `${reps} reps`].filter(Boolean).join(' • ');
}
</script>

<template>
    <Head :title="plan.name" />

    <div class="min-h-svh bg-background text-foreground">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 pb-16">
            <header class="flex items-center justify-between gap-3">
                <span class="flex items-center gap-2">
                    <img src="/favicon.svg" alt="" class="size-9 rounded-lg" />
                    <span
                        class="font-display text-2xl font-extrabold tracking-tight text-primary"
                        >TREINR</span
                    >
                </span>
                <Link
                    v-if="!loggedIn"
                    :href="login()"
                    class="text-sm font-medium underline underline-offset-4"
                    >Entrar</Link
                >
            </header>

            <section class="rounded-2xl bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    {{
                        author
                            ? `${author} compartilhou um treino`
                            : 'Treino compartilhado'
                    }}
                </p>
                <h1
                    class="mt-1 font-display text-3xl leading-tight font-bold tracking-tight uppercase"
                >
                    {{ plan.name }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ plan.days.length }}
                    {{ plan.days.length === 1 ? 'dia' : 'dias' }} ·
                    {{ plan.days.reduce((n, d) => n + d.exercises.length, 0) }}
                    exercícios
                </p>

                <div class="mt-4">
                    <Link
                        v-if="isOwner"
                        :href="workoutsIndex()"
                        class="text-sm text-muted-foreground underline"
                        >Este treino é seu</Link
                    >
                    <Button
                        v-else-if="loggedIn"
                        class="w-full sm:w-auto"
                        :disabled="copying"
                        data-test="copy-shared-plan"
                        @click="copy"
                    >
                        <CopyPlus class="size-4" /> Copiar para meus treinos
                    </Button>
                    <div v-else class="flex flex-col gap-2 sm:flex-row">
                        <Button as-child class="sm:w-auto"
                            ><Link :href="login()"
                                >Entrar para copiar o treino</Link
                            ></Button
                        >
                        <Button as-child variant="outline" class="sm:w-auto"
                            ><Link :href="register()">Criar conta</Link></Button
                        >
                    </div>
                </div>
            </section>

            <!-- Abas dos dias -->
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))] gap-2"
                role="tablist"
                aria-label="Dias do treino"
            >
                <button
                    v-for="(d, i) in plan.days"
                    :key="d.label + i"
                    type="button"
                    role="tab"
                    :aria-selected="i === selected"
                    class="flex min-w-0 flex-col items-center gap-1 rounded-2xl border-2 bg-card px-2 py-2.5"
                    :class="
                        i === selected
                            ? 'border-foreground'
                            : 'border-transparent'
                    "
                    @click="selected = i"
                >
                    <span
                        class="text-[10px] leading-none font-semibold tracking-wide text-muted-foreground uppercase"
                        >Treino</span
                    >
                    <span
                        class="font-display text-2xl leading-none font-extrabold"
                        >{{ d.label }}</span
                    >
                </button>
            </div>

            <section v-if="day" class="flex flex-col gap-3">
                <div class="flex items-center gap-4 rounded-2xl bg-card p-4">
                    <MuscleMap
                        :groups="day.groups"
                        class="h-36 w-20 shrink-0"
                        view="front"
                        :label="`Músculos do ${day.title}`"
                    />
                    <MuscleMap
                        :groups="day.groups"
                        class="h-36 w-20 shrink-0"
                        view="back"
                        :label="`Músculos do ${day.title} (costas)`"
                    />
                    <h2
                        class="min-w-0 font-display text-xl font-bold tracking-tight uppercase"
                    >
                        {{ day.title }}
                    </h2>
                </div>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="(e, i) in day.exercises"
                        :key="i"
                        class="flex items-center gap-3 rounded-2xl bg-card p-3"
                    >
                        <img
                            v-if="e.image"
                            :src="e.image"
                            :alt="e.name"
                            class="size-14 shrink-0 rounded-xl bg-white object-cover"
                            loading="lazy"
                        />
                        <span
                            v-else
                            class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-muted"
                            ><Dumbbell class="size-5 text-muted-foreground"
                        /></span>
                        <span class="min-w-0">
                            <span class="block font-medium break-words">{{
                                e.name
                            }}</span>
                            <span class="block text-sm text-muted-foreground">{{
                                summary(e)
                            }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
