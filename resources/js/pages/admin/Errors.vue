<script setup lang="ts">
// Painel de erros do servidor (admin): o que deu errado nos últimos 7 dias, agrupado por mensagem.
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, CheckCircle2, XCircle } from '@lucide/vue';
import { index as adminUsers } from '@/routes/admin/users';
import { errors as adminErrors } from '@/routes/admin';

type LogError = {
    level: string;
    message: string;
    count: number;
    first: string;
    last: string;
};
defineProps<{
    errors: LogError[];
    totals: { errors: number; warnings: number; last24h: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Erros do servidor', href: adminErrors() }],
    },
});

const when = (s: string) =>
    new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Head title="Erros do servidor" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 pb-28">
        <div class="flex items-center gap-2">
            <Link
                :href="adminUsers()"
                class="rounded-full p-1 text-muted-foreground hover:text-foreground"
                aria-label="Voltar"
                ><ArrowLeft class="size-5"
            /></Link>
            <h1 class="font-display text-2xl font-bold tracking-tight uppercase">
                Erros do servidor
            </h1>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Erros (7 dias)</p>
                <p
                    class="font-display text-3xl font-bold"
                    :class="totals.errors ? 'text-destructive' : ''"
                >
                    {{ totals.errors }}
                </p>
            </div>
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Avisos</p>
                <p class="font-display text-3xl font-bold">
                    {{ totals.warnings }}
                </p>
            </div>
            <div class="rounded-2xl bg-card p-3">
                <p class="text-xs text-muted-foreground">Últimas 24 h</p>
                <p class="font-display text-3xl font-bold">
                    {{ totals.last24h }}
                </p>
            </div>
        </div>

        <section class="overflow-hidden rounded-2xl bg-card">
            <p
                class="bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Mais recentes primeiro
            </p>
            <ul v-if="errors.length" class="divide-y divide-border">
                <li
                    v-for="(e, i) in errors"
                    :key="i"
                    class="flex gap-3 px-4 py-3"
                >
                    <component
                        :is="e.level === 'warning' ? AlertTriangle : XCircle"
                        class="mt-0.5 size-5 shrink-0"
                        :class="
                            e.level === 'warning'
                                ? 'text-primary'
                                : 'text-destructive'
                        "
                        :aria-label="e.level === 'warning' ? 'Aviso' : 'Erro'"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-xs leading-relaxed break-words"
                        >
                            {{ e.message }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ e.count }}× · última {{ when(e.last)
                            }}<template v-if="e.count > 1">
                                · primeira {{ when(e.first) }}</template
                            >
                        </p>
                    </div>
                </li>
            </ul>
            <div
                v-else
                class="flex flex-col items-center gap-2 p-8 text-center"
            >
                <CheckCircle2 class="size-8 text-success" />
                <p class="font-medium">Nenhum erro nos últimos 7 dias</p>
                <p class="text-sm text-muted-foreground">
                    Quando algo falhar no servidor, aparece aqui.
                </p>
            </div>
        </section>
    </div>
</template>
