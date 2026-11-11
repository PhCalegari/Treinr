# Treinr

App web de musculação, alimentação e evolução física, acessado por link.
Funciona em qualquer celular (Android e iPhone) e pode ser instalado na tela inicial.

**Tecnologias:** Laravel 13 + Inertia + Vue 3 (TypeScript, Tailwind) · MySQL 8 · Fortify (autenticação).

## Rodar no computador (desenvolvimento)

Pré-requisitos já instalados nesta máquina: PHP 8.4 (Laravel Herd Lite), Composer, Node 22 e MySQL 8.4 em `C:\dev`.

1. **Banco:** dê dois cliques em `C:\dev\iniciar-mysql.bat` e deixe a janela aberta (MySQL na porta 3399).
2. **Servidor:** na pasta do projeto, rode:
   ```bash
   php artisan serve --host=0.0.0.0 --port=8010
   ```
3. Abra **http://localhost:8010**.

Para editar as telas com atualização automática, rode também `npm run dev`.
Depois de mudar o código do frontend sem o `npm run dev`, gere os arquivos com `npm run build`.

### Primeira instalação (banco vazio)
```bash
php artisan migrate --seed                 # tabelas + grupos musculares + 876 exercícios
php artisan db:seed --class=DemoSeeder     # contas de demonstração (nunca em produção)
```

### Contas de demonstração
| E-mail | Senha | Papel |
|---|---|---|
| admin@treinr.local | Admin@1234 | administrador |
| demo@treinr.local | Demo@1234 | usuário |
| bloqueado@treinr.local | Demo@1234 | usuário bloqueado |

A conta demo já vem com 8 semanas de peso e medidas e uma meta calculada.

## Foto do prato (IA)
No diário, **Adicionar → Foto**: a IA (Google Gemini) sugere os alimentos e as porções, o app busca cada um na
TACO e o usuário confere antes de salvar. Configure `GEMINI_API_KEY` no `.env` (chave em https://aistudio.google.com).
Detalhes, limites e privacidade em `docs/fontes-de-dados.md`.

## Metas e evolução
- **Metas** (`/goals`): metabolismo basal por Mifflin-St Jeor (ou Katch-McArdle, quando há % de gordura
  registrado nos últimos 180 dias) × fator de atividade, com ajuste pelo objetivo (−20% até 750 kcal para
  emagrecer, +10% até 400 kcal para hipertrofia). Mínimo de 1.200 kcal (mulheres) / 1.500 kcal (homens).
  Macros: proteína de 1,6–2,0 g/kg (peso limitado ao de IMC 25), gordura em 25% das calorias, carboidrato no restante.
  São estimativas, não prescrição; o usuário pode ajustar à mão. Cada meta vale a partir de uma data, e o
  histórico compara cada dia com a meta que valia nele.
- **Evolução** (`/progress`): peso, % de gordura, 14 medidas, observação e fotos por dia.
  As fotos ficam no disco privado (`storage/app/private/progress-photos`) e só o dono abre.
  O navegador reduz a foto e regrava em JPEG; o servidor remove os metadados (EXIF/GPS) de novo, por garantia.

### Abrir no celular (mesma rede Wi-Fi)
Use `http://IP-DO-COMPUTADOR:8010` (ex.: `http://192.168.0.141:8010`).
Na primeira vez, o Windows pode perguntar se libera o PHP no firewall: permita em **redes privadas**.

> O modo offline e a instalação na tela inicial exigem **HTTPS**, ou seja, o app publicado em um servidor.
> Pela rede local (HTTP), o app funciona, mas só online.

## Testes
```bash
php artisan test
```

## Estrutura
| Pasta | Conteúdo |
|---|---|
| `app/` | Backend: controllers, models, middlewares (bloqueio de usuário, admin), enums |
| `app/Services` | Regras: treinos, diário, alimentos, cálculo de metas (`Nutrition/EnergyCalculator`), evolução |
| `resources/js/components/charts` | Gráficos SVG próprios (linha e colunas), com tabela acessível |
| `resources/js/pages` | Telas em Vue (login, cadastro, perfil, admin…) |
| `database/migrations` | Esquema do banco (inclui as colunas de sincronização offline) |
| `database/seeders` | Dados de referência, biblioteca de exercícios e contas demo |
| `database/seed-data` | Base de exercícios original (Free Exercise DB, Unlicense) |
| `docs/` | Fontes de dados e licenças |

## Configuração (`.env`)
- `SESSION_LIFETIME=43200`: login válido por 30 dias, renovados a cada uso.
- `MAIL_MAILER=log`: em desenvolvimento, os e-mails de recuperação de senha vão para `storage/logs/laravel.log`.
