<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Perfil', href: edit() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const sexOptions = [
    { value: 'male', label: 'Masculino' },
    { value: 'female', label: 'Feminino' },
];
const goalOptions = [
    { value: 'hypertrophy', label: 'Hipertrofia' },
    { value: 'weight_loss', label: 'Emagrecimento' },
    { value: 'maintenance', label: 'Manutenção' },
];
const levelOptions = [
    { value: 'beginner', label: 'Iniciante' },
    { value: 'intermediate', label: 'Intermediário' },
    { value: 'advanced', label: 'Avançado' },
];

const selectClass =
    'mt-1 h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none dark:bg-input/30';
</script>

<template>
    <Head title="Perfil" />

    <h1 class="sr-only">Perfil</h1>

    <div class="flex flex-col space-y-6">
        <Heading variant="small" title="Perfil" description="Seus dados pessoais e de treino" />

        <Form
            v-bind="ProfileController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nome</Label>
                <Input id="name" name="name" :default-value="user.name" required autocomplete="name" placeholder="Nome completo" />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">E-mail</Label>
                <Input id="email" type="email" name="email" :default-value="user.email" required autocomplete="username" placeholder="E-mail" />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="birth_date">Data de nascimento</Label>
                    <Input id="birth_date" type="date" name="birth_date" :default-value="user.birth_date ?? ''" />
                    <p v-if="user.age !== null" class="text-xs text-muted-foreground">{{ user.age }} anos</p>
                    <InputError :message="errors.birth_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="sex">Sexo</Label>
                    <select id="sex" name="sex" :class="selectClass">
                        <option value="" :selected="!user.sex">Não informar</option>
                        <option v-for="o in sexOptions" :key="o.value" :value="o.value" :selected="user.sex === o.value">{{ o.label }}</option>
                    </select>
                    <p class="text-xs text-muted-foreground">Usado só no cálculo estimado do gasto calórico.</p>
                    <InputError :message="errors.sex" />
                </div>

                <div class="grid gap-2">
                    <Label for="height_cm">Altura (cm)</Label>
                    <Input id="height_cm" type="number" name="height_cm" step="0.1" min="50" max="280" inputmode="decimal" :default-value="user.height_cm ?? ''" placeholder="Ex.: 175" />
                    <InputError :message="errors.height_cm" />
                </div>

                <div class="grid gap-2">
                    <Label for="goal">Objetivo</Label>
                    <select id="goal" name="goal" :class="selectClass">
                        <option value="" :selected="!user.goal">Não definido</option>
                        <option v-for="o in goalOptions" :key="o.value" :value="o.value" :selected="user.goal === o.value">{{ o.label }}</option>
                    </select>
                    <InputError :message="errors.goal" />
                </div>

                <div class="grid gap-2">
                    <Label for="experience_level">Nível de experiência</Label>
                    <select id="experience_level" name="experience_level" :class="selectClass">
                        <option value="" :selected="!user.experience_level">Não definido</option>
                        <option v-for="o in levelOptions" :key="o.value" :value="o.value" :selected="user.experience_level === o.value">{{ o.label }}</option>
                    </select>
                    <InputError :message="errors.experience_level" />
                </div>
            </div>

            <p class="text-xs text-muted-foreground">O peso é registrado na área de Evolução, para acompanhar o histórico.</p>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button">Salvar</Button>
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
