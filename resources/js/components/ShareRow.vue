<script setup lang="ts">
// Linha de opção no estilo de lista (ícone, título, descrição e seta), usada nas janelas de compartilhar.
import { ChevronRight } from '@lucide/vue';
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    title: string;
    description?: string;
    disabled?: boolean;
    tone?: 'default' | 'danger';
}>();
defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60 disabled:pointer-events-none disabled:opacity-50"
        :disabled="disabled"
        @click="$emit('click')"
    >
        <span
            class="flex size-9 shrink-0 items-center justify-center rounded-xl"
            :class="
                tone === 'danger'
                    ? 'bg-destructive/10 text-destructive'
                    : 'bg-primary/15 text-primary'
            "
        >
            <component :is="icon" class="size-5" />
        </span>
        <span class="min-w-0 flex-1">
            <span
                class="block font-medium"
                :class="tone === 'danger' ? 'text-destructive' : ''"
                >{{ title }}</span
            >
            <span
                v-if="description"
                class="block text-sm leading-snug text-muted-foreground"
                >{{ description }}</span
            >
        </span>
        <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
    </button>
</template>
