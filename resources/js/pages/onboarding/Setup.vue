<script setup lang="ts">
// Primeiros passos (tela cheia, um assunto por vez): sexo, corpo, objetivo e experiência.
// Esses dados ajustam o boneco, a meta de calorias e o treino montado pela IA.
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Flame, Scale, TrendingUp } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MuscleMap from '@/components/MuscleMap.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store as onboardingStore } from '@/routes/onboarding';

type Initial = {
    sex: string | null;
    birth_date: string | null;
    height_cm: number | null;
    goal: string | null;
    experience_level: string | null;
};
const props = defineProps<{ initial: Initial }>();

const form = reactive({
    sex: props.initial.sex ?? '',
    birth_date: props.initial.birth_date ?? '',
    height_cm: props.initial.height_cm ? String(props.initial.height_cm) : '',
    weight_kg: '',
    goal: props.initial.goal ?? '',
    experience_level: props.initial.experience_level ?? '',
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);

const STEPS = ['Sexo', 'Seu corpo', 'Objetivo', 'Experiência'] as const;
const step = ref(0);
const canNext = computed(() => {
    if (step.value === 0) return !!form.sex;
    if (step.value === 1) return !!form.birth_date && !!form.height_cm;
    if (step.value === 2) return !!form.goal;
    return !!form.experience_level;
});

const goals = [
    {
        value: 'hypertrophy',
        title: 'Ganhar massa',
        text: 'Hipertrofia: mais músculo e força.',
        icon: TrendingUp,
    },
    {
        value: 'weight_loss',
        title: 'Emagrecer',
        text: 'Perder gordura mantendo os músculos.',
        icon: Flame,
    },
    {
        value: 'maintenance',
        title: 'Manter',
        text: 'Saúde e forma em dia.',
        icon: Scale,
    },
];
const levels = [
    {
        value: 'beginner',
        title: 'Iniciante',
        text: 'Menos de 6 meses de treino.',
    },
    {
        value: 'intermediate',
        title: 'Intermediário',
        text: 'De 6 meses a 2 anos treinando.',
    },
    {
        value: 'advanced',
        title: 'Avançado',
        text: 'Mais de 2 anos, treino consistente.',
    },
];

const num = (v: string) =>
    v.trim() === '' ? null : Number(v.replace(',', '.'));

function next() {
    if (!canNext.value) return;
    if (step.value < STEPS.length - 1) {
        step.value++;
        return;
    }
    saving.value = true;
    router.post(
        onboardingStore().url,
        {
            ...form,
            height_cm: num(form.height_cm),
            weight_kg: num(form.weight_kg),
        },
        {
            onError: (e) => {
                errors.value = e;
                // Volta para o passo com o primeiro erro.
                if (e.sex) step.value = 0;
                else if (e.birth_date || e.height_cm || e.weight_kg)
                    step.value = 1;
                else if (e.goal) step.value = 2;
            },
            onFinish: () => (saving.value = false),
        },
    );
}
function skip() {
    try {
        localStorage.setItem('treinr:onboarding-skipped', '1');
    } catch {
        // sem armazenamento: só segue
    }
}

const option = (selected: boolean) =>
    `flex w-full items-center gap-3 rounded-2xl border-2 p-4 text-left transition-colors ${selected ? 'border-primary bg-primary/10' : 'border-transparent bg-card hover:border-border'}`;
</script>

<template>
    <Head title="Primeiros passos" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <div
            class="mx-auto flex w-full max-w-md flex-1 flex-col gap-6 p-4 pb-8"
        >
            <!-- Topo: voltar, progresso e pular -->
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="rounded-full p-1 text-muted-foreground hover:text-foreground disabled:invisible"
                    :disabled="step === 0"
                    aria-label="Passo anterior"
                    @click="step--"
                >
                    <ArrowLeft class="size-5" />
                </button>
                <div
                    class="flex flex-1 gap-1.5"
                    role="progressbar"
                    :aria-valuenow="step + 1"
                    aria-valuemin="1"
                    :aria-valuemax="STEPS.length"
                    :aria-label="`Passo ${step + 1} de ${STEPS.length}`"
                >
                    <span
                        v-for="(s, i) in STEPS"
                        :key="s"
                        class="h-1.5 flex-1 rounded-full transition-colors"
                        :class="i <= step ? 'bg-primary' : 'bg-muted'"
                    ></span>
                </div>
                <Link
                    :href="dashboard()"
                    class="text-sm text-muted-foreground hover:text-foreground"
                    @click="skip"
                    >Pular</Link
                >
            </div>

            <div>
                <p class="text-sm font-medium text-primary">
                    Passo {{ step + 1 }} de {{ STEPS.length }}
                </p>
                <h1
                    class="mt-1 font-display text-3xl leading-tight font-bold tracking-tight uppercase"
                >
                    {{
                        [
                            'Qual é o seu sexo?',
                            'Conte sobre seu corpo',
                            'Qual é o seu objetivo?',
                            'Há quanto tempo você treina?',
                        ][step]
                    }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        [
                            'Usamos para o desenho dos músculos e para calcular suas calorias.',
                            'Com isso estimamos seu gasto de energia por dia.',
                            'A meta de calorias e os treinos da IA seguem essa escolha.',
                            'Ajuda a sugerir volume e exercícios do seu nível.',
                        ][step]
                    }}
                </p>
            </div>

            <!-- 1. Sexo -->
            <div v-if="step === 0" class="grid grid-cols-2 gap-3">
                <button
                    v-for="s in [
                        { value: 'male', label: 'Masculino' },
                        { value: 'female', label: 'Feminino' },
                    ]"
                    :key="s.value"
                    type="button"
                    class="flex flex-col items-center gap-2 rounded-2xl border-2 p-4 transition-colors"
                    :class="
                        form.sex === s.value
                            ? 'border-primary bg-primary/10'
                            : 'border-transparent bg-card hover:border-border'
                    "
                    :aria-pressed="form.sex === s.value"
                    :data-test="`sex-${s.value}`"
                    @click="form.sex = s.value"
                >
                    <MuscleMap
                        view="front"
                        :sex="s.value"
                        :groups="
                            form.sex === s.value
                                ? [
                                      'CHEST',
                                      'ABS',
                                      'QUADRICEPS',
                                      'SHOULDERS',
                                      'BICEPS',
                                  ]
                                : []
                        "
                        class="h-40 w-full"
                        :label="s.label"
                    />
                    <span class="font-semibold">{{ s.label }}</span>
                </button>
                <InputError class="col-span-2" :message="errors.sex" />
            </div>

            <!-- 2. Corpo -->
            <div
                v-else-if="step === 1"
                class="flex flex-col gap-4 rounded-2xl bg-card p-4"
            >
                <div class="grid gap-1.5">
                    <Label for="ob-birth">Data de nascimento</Label>
                    <Input
                        id="ob-birth"
                        v-model="form.birth_date"
                        type="date"
                        class="h-11"
                        :max="new Date().toISOString().slice(0, 10)"
                    />
                    <InputError :message="errors.birth_date" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="ob-height">Altura (cm)</Label>
                        <Input
                            id="ob-height"
                            v-model="form.height_cm"
                            inputmode="decimal"
                            placeholder="Ex.: 175"
                            class="h-11"
                        />
                        <InputError :message="errors.height_cm" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="ob-weight"
                            >Peso (kg)
                            <span class="font-normal text-muted-foreground"
                                >opcional</span
                            ></Label
                        >
                        <Input
                            id="ob-weight"
                            v-model="form.weight_kg"
                            inputmode="decimal"
                            placeholder="Ex.: 72,5"
                            class="h-11"
                        />
                        <InputError :message="errors.weight_kg" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    O peso entra como primeiro registro da sua evolução.
                </p>
            </div>

            <!-- 3. Objetivo -->
            <div v-else-if="step === 2" class="flex flex-col gap-3">
                <button
                    v-for="g in goals"
                    :key="g.value"
                    type="button"
                    :class="option(form.goal === g.value)"
                    :aria-pressed="form.goal === g.value"
                    @click="form.goal = g.value"
                >
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary"
                        ><component :is="g.icon" class="size-5"
                    /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold">{{ g.title }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            g.text
                        }}</span>
                    </span>
                    <Check
                        v-if="form.goal === g.value"
                        class="size-5 shrink-0 text-primary"
                    />
                </button>
                <InputError :message="errors.goal" />
            </div>

            <!-- 4. Experiência -->
            <div v-else class="flex flex-col gap-3">
                <button
                    v-for="l in levels"
                    :key="l.value"
                    type="button"
                    :class="option(form.experience_level === l.value)"
                    :aria-pressed="form.experience_level === l.value"
                    @click="form.experience_level = l.value"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold">{{ l.title }}</span>
                        <span class="block text-sm text-muted-foreground">{{
                            l.text
                        }}</span>
                    </span>
                    <Check
                        v-if="form.experience_level === l.value"
                        class="size-5 shrink-0 text-primary"
                    />
                </button>
                <InputError :message="errors.experience_level" />
            </div>

            <Button
                size="lg"
                class="mt-auto h-12 w-full text-base"
                :disabled="!canNext || saving"
                data-test="onboarding-next"
                @click="next"
            >
                {{
                    step < STEPS.length - 1
                        ? 'Continuar'
                        : saving
                          ? 'Salvando…'
                          : 'Concluir'
                }}
            </Button>
        </div>
    </div>
</template>
