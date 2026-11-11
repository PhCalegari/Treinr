<script setup lang="ts">
// Logo do Treinr: fisiculturista de costas fazendo duplo bíceps (desenho original do app).
// Pinta com a cor do texto (currentColor); as linhas de definição ficam vazadas (máscara),
// então funciona sobre qualquer fundo.
import { useId } from 'vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

const maskId = `treinr-logo-${useId()}`;
const HALF =
    'M256 132 L284 132 C286 152 298 170 326 182 C346 170 376 170 392 184 C396 158 418 136 442 140 C452 142 458 148 460 156 C456 140 448 124 438 108 C424 92 428 62 452 58 C478 54 492 80 484 102 C480 116 478 124 480 142 C486 172 490 202 478 222 C466 240 438 246 412 244 C398 244 388 246 378 254 C374 300 346 342 324 376 C318 396 320 418 324 442 L256 442 Z';
const HEAD =
    'M256 66 C276 66 288 82 288 102 C288 124 274 140 256 140 C238 140 224 124 224 102 C224 82 236 66 256 66 Z';
const MIRROR = 'translate(512 0) scale(-1 1)';
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="20 50 472 400"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <mask
            :id="maskId"
            maskUnits="userSpaceOnUse"
            x="0"
            y="0"
            width="512"
            height="512"
        >
            <rect width="512" height="512" fill="white" />
            <g fill="none" stroke="black" stroke-linecap="round">
                <g>
                    <path
                        d="M298 206 C320 230 326 262 310 290"
                        stroke-width="9"
                    />
                    <path
                        d="M290 310 C306 336 310 362 302 390"
                        stroke-width="8"
                    />
                    <path
                        d="M352 192 C364 210 372 226 382 242"
                        stroke-width="8"
                    />
                    <path
                        d="M396 204 C418 214 444 214 466 200"
                        stroke-width="8"
                    />
                    <path
                        d="M262 180 C282 192 302 198 324 200"
                        stroke-width="8"
                    />
                    <path
                        d="M444 116 C456 126 470 126 480 118"
                        stroke-width="7"
                    />
                    <path
                        d="M414 230 C430 234 448 232 462 224"
                        stroke-width="6"
                    />
                </g>
                <g :transform="MIRROR">
                    <path
                        d="M298 206 C320 230 326 262 310 290"
                        stroke-width="9"
                    />
                    <path
                        d="M290 310 C306 336 310 362 302 390"
                        stroke-width="8"
                    />
                    <path
                        d="M352 192 C364 210 372 226 382 242"
                        stroke-width="8"
                    />
                    <path
                        d="M396 204 C418 214 444 214 466 200"
                        stroke-width="8"
                    />
                    <path
                        d="M262 180 C282 192 302 198 324 200"
                        stroke-width="8"
                    />
                    <path
                        d="M444 116 C456 126 470 126 480 118"
                        stroke-width="7"
                    />
                    <path
                        d="M414 230 C430 234 448 232 462 224"
                        stroke-width="6"
                    />
                </g>
                <path d="M256 190 L256 416" stroke-width="8" />
                <path d="M196 422 L316 422" stroke-width="9" />
                <path d="M234 140 C248 148 264 148 278 140" stroke-width="6" />
            </g>
        </mask>
        <g fill="currentColor" :mask="`url(#${maskId})`">
            <path :d="HALF" />
            <path :d="HALF" :transform="MIRROR" />
            <path :d="HEAD" />
        </g>
    </svg>
</template>
