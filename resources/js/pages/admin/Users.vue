<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { destroy as usersDestroy, index as usersIndex, password as usersPassword, status as usersStatus } from '@/routes/admin/users';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: 'user' | 'admin';
    status: 'pending' | 'active' | 'blocked';
    created_at: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: { q: string; status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuários', href: usersIndex() }],
    },
});

const page = usePage();
const me = computed(() => page.props.auth.user);
const q = ref(props.filters.q);
const statusFilter = ref(props.filters.status);
const busyId = ref<number | null>(null);

const statusLabel: Record<AdminUser['status'], string> = { pending: 'Aguardando', active: 'Ativo', blocked: 'Bloqueado' };

function search() {
    router.get(usersIndex().url, { q: q.value || undefined, status: statusFilter.value || undefined }, { preserveState: true, replace: true });
}

function setStatus(user: AdminUser, next: AdminUser['status'], question: string) {
    if (!confirm(question)) return;
    busyId.value = user.id;
    router.patch(usersStatus(user.id).url, { status: next }, { preserveScroll: true, onFinish: () => (busyId.value = null) });
}

function toggle(user: AdminUser) {
    const next = user.status === 'active' ? 'blocked' : 'active';
    setStatus(user, next, `${next === 'blocked' ? 'Bloquear' : 'Reativar'} ${user.name}?`);
}

function approve(user: AdminUser) {
    setStatus(user, 'active', `Aprovar o cadastro de ${user.name} (${user.email})?`);
}

function reject(user: AdminUser) {
    if (!confirm(`Recusar e apagar o cadastro de ${user.name}?`)) return;
    busyId.value = user.id;
    router.delete(usersDestroy(user.id).url, { preserveScroll: true, onFinish: () => (busyId.value = null) });
}

// ---------- Redefinir senha (não há "esqueci minha senha") ----------
const pwUser = ref<AdminUser | null>(null);
const pwValue = ref('');
const pwError = ref('');
const pwSaving = ref(false);

function openPassword(user: AdminUser) {
    pwUser.value = user;
    pwValue.value = '';
    pwError.value = '';
}

/** Senha aleatória de 12 caracteres que já atende a regra (maiúscula, minúscula, número e símbolo). */
function generatePassword() {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%*?'];
    const all = sets.join('');
    const rand = (n: number) => crypto.getRandomValues(new Uint32Array(1))[0] % n;
    const chars = [...sets.map((s) => s[rand(s.length)]), ...Array.from({ length: 8 }, () => all[rand(all.length)])];
    for (let i = chars.length - 1; i > 0; i--) {
        const j = rand(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    pwValue.value = chars.join('');
}

function savePassword() {
    if (!pwUser.value) return;
    pwSaving.value = true;
    pwError.value = '';
    router.patch(
        usersPassword(pwUser.value.id).url,
        { password: pwValue.value },
        {
            preserveScroll: true,
            onSuccess: () => (pwUser.value = null),
            onError: (e) => (pwError.value = e.password ?? 'Não foi possível salvar.'),
            onFinish: () => (pwSaving.value = false),
        },
    );
}

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="Usuários" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="Usuários" :description="`${users.total} cadastrado(s)`" />

        <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="search">
            <Input v-model="q" placeholder="Buscar por nome ou e-mail" class="sm:max-w-sm" />
            <select v-model="statusFilter" :class="selectClass" @change="search">
                <option value="">Todos</option>
                <option value="pending">Aguardando aprovação</option>
                <option value="active">Ativos</option>
                <option value="blocked">Bloqueados</option>
            </select>
            <Button type="submit" variant="secondary">Buscar</Button>
        </form>

        <InputError :message="page.props.errors?.status" />

        <div class="overflow-x-auto rounded-2xl bg-card">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3 font-medium">Nome</th>
                        <th class="hidden p-3 font-medium sm:table-cell">E-mail</th>
                        <th class="p-3 font-medium">Status</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id" class="border-t" :class="u.status === 'pending' && 'bg-primary/5'">
                        <td class="p-3">
                            <div class="font-medium">{{ u.name }}</div>
                            <div class="text-xs text-muted-foreground sm:hidden">{{ u.email }}</div>
                            <Badge v-if="u.role === 'admin'" variant="outline" class="mt-1">admin</Badge>
                        </td>
                        <td class="hidden p-3 sm:table-cell">{{ u.email }}</td>
                        <td class="p-3">
                            <Badge :variant="u.status === 'active' ? 'secondary' : u.status === 'pending' ? 'default' : 'destructive'">
                                {{ statusLabel[u.status] }}
                            </Badge>
                        </td>
                        <td class="p-3">
                            <div class="flex flex-wrap justify-end gap-2">
                                <template v-if="u.status === 'pending'">
                                    <Button size="sm" :disabled="busyId === u.id" :data-test="`approve-user-${u.id}`" @click="approve(u)">Aprovar</Button>
                                    <Button size="sm" variant="ghost" class="text-destructive" :disabled="busyId === u.id" @click="reject(u)">
                                        Recusar
                                    </Button>
                                </template>
                                <template v-else>
                                    <Button size="sm" variant="outline" :data-test="`password-user-${u.id}`" @click="openPassword(u)">Senha</Button>
                                    <Button
                                        v-if="u.id !== me.id"
                                        size="sm"
                                        :variant="u.status === 'active' ? 'destructive' : 'default'"
                                        :disabled="busyId === u.id"
                                        :data-test="`toggle-user-${u.id}`"
                                        @click="toggle(u)"
                                    >
                                        {{ u.status === 'active' ? 'Bloquear' : 'Reativar' }}
                                    </Button>
                                    <span v-else class="self-center text-xs text-muted-foreground">você</span>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="4" class="p-6 text-center text-muted-foreground">Nenhum usuário encontrado.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="users.last_page > 1" class="flex items-center justify-between text-sm">
            <Link v-if="users.prev_page_url" :href="users.prev_page_url" preserve-scroll class="underline">Anterior</Link>
            <span v-else></span>
            <span class="text-muted-foreground">Página {{ users.current_page }} de {{ users.last_page }}</span>
            <Link v-if="users.next_page_url" :href="users.next_page_url" preserve-scroll class="underline">Próxima</Link>
            <span v-else></span>
        </div>
    </div>

    <Dialog :open="!!pwUser" @update:open="(v: boolean) => !v && (pwUser = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Redefinir senha</DialogTitle>
                <DialogDescription>
                    Nova senha para {{ pwUser?.name }} ({{ pwUser?.email }}). A pessoa sai de todos os aparelhos e entra com a senha nova.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="savePassword">
                <div class="flex gap-2">
                    <Input v-model="pwValue" type="text" autocomplete="new-password" placeholder="Nova senha" class="font-mono" required />
                    <Button type="button" variant="secondary" @click="generatePassword">Gerar</Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Mínimo de 8 caracteres, com maiúscula, minúscula, número e símbolo. Copie e envie para a pessoa.
                </p>
                <InputError :message="pwError" />
                <Button type="submit" :disabled="pwSaving || !pwValue">Salvar nova senha</Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
