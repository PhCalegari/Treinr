# Fontes de dados e licenças

## Exercícios

### Free Exercise DB (em uso)
- Origem: https://github.com/yuhonas/free-exercise-db (`dist/exercises.json`)
- Licença: **The Unlicense** (domínio público). Permite uso comercial, cópia e armazenamento local sem exigir atribuição. Mesmo assim, citamos a fonte no app.
- Cópia local: `database/seed-data/free-exercise-db/` (JSON + LICENSE.md)
- Conteúdo: 876 exercícios, com músculos principais e secundários, equipamento, categoria, nível, mecânica, instruções (em inglês) e 2 imagens cada.
- Imagens: neste momento apontam para `raw.githubusercontent.com`. Antes da publicação, hospedá-las em servidor próprio (a licença permite).

**Mapeamento de músculos para os 11 grupos do app**

| Músculo (base) | Grupo |
|---|---|
| chest | Peitoral |
| lats, middle back, lower back, traps | Costas |
| shoulders | Ombros |
| biceps / triceps / forearms | Bíceps / Tríceps / Antebraços |
| abdominals | Abdômen |
| quadriceps | Quadríceps |
| hamstrings | Posterior de coxa |
| glutes, abductors | Glúteos (o glúteo médio é o principal abdutor do quadril) |
| calves | Panturrilhas |
| adductors, neck | sem grupo; continuam visíveis no detalhe do exercício |

O detalhe dos músculos fica guardado na tabela `muscles`, então nada da base original se perde.

**Séries e repetições sugeridas:** a base não traz esses valores. A migration aplica uma sugestão genérica por categoria (força: 3×8–12 em exercícios compostos e 3×10–15 em isoladores; alongamento: 2×30 s; cardio: 10 min). É um ponto de partida que o usuário ajusta.

**Tradução:** nomes e instruções estão em inglês. As colunas `name_pt` e `instructions_pt` já existem para a tradução gradual.

### wger (complemento opcional, ainda não importado)
- Dados sob licença CC-BY-SA, com atribuição por autor em imagens e vídeos. Conferir a versão da licença no momento da importação.
- Uso previsto: nomes em português e vídeos.

### ExerciseDB (RapidAPI/AscendAPI): não utilizada
- Licença proprietária. Os termos restringem cache e redistribuição dos GIFs, e o uso comercial exige plano pago.

## Alimentos

### Open Food Facts (Etapa 6)
- Banco de dados: **ODbL 1.0**; conteúdo individual: **DbCL 1.0**; imagens: **CC-BY-SA 3.0**.
- Exige atribuição visível no app. Se o banco derivado for redistribuído, ele precisa manter a ODbL.
- Limites: cerca de 100 req/min para leitura de produto, 10 req/min para busca. Exige um `User-Agent` identificado (`OFF_USER_AGENT`).
- Acesso só pelo backend, com cache na tabela `foods`.

### USDA FoodData Central (complemento planejado)
- **CC0** (domínio público). Cobre alimentos genéricos (arroz cozido, frango etc.), onde a Open Food Facts é fraca.

### TACO – 4ª edição (NEPA-UNICAMP, 2011) – em uso
- 591 alimentos brasileiros genéricos, crus e preparados (arroz cozido, feijão cozido, frango grelhado...). Valores por 100 g.
- Permissão: "É permitida a reprodução total ou parcial desta obra, desde que citada a fonte." A fonte é citada no app.
- **Pendente antes de lançamento comercial:** o texto não fala explicitamente de uso comercial. Confirmar com taco@unicamp.br.
- Arquivos e origem em `database/seed-data/taco/ORIGEM.md`; importação por `TacoFoodSeeder`.
- 6 itens estão sem energia na própria TACO (em reavaliação). Leite integral e leite desnatado entram com os valores
  da USDA FoodData Central (CC0) e as medidas caseiras do IBGE; os outros 4 não são importados.

### IBGE – Medidas caseiras (POF 2008-2009) – em uso
- *Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil* (IBGE, 2011). Dado público; fonte citada no app.
- Ligada à TACO por um mapeamento curado (204 alimentos, projeto `taco-br-mcp`, MIT): 585 medidas como
  "colher de sopa", "concha", "escumadeira", "unidade", "fatia".
- Só medidas confiáveis: o IBGE estimou algumas medidas a partir de outras (ex.: escumadeira com o peso da
  colher de arroz); essas são descartadas. Script: `database/scripts/build-taco-portions.mjs`.
- As gramas são médias de referência. O app mostra "medida média do IBGE" e permite ajustar os gramas.

### Porção do rótulo (Open Food Facts)
- Vem só na consulta por código de barras (a busca por nome da OFF não traz esse campo).

### IBGE – Composição de pratos prontos (possível complemento)
- ~1.900 itens, incluindo pratos (arroz com feijão, bife acebolado...). Dado público; confirmar os termos antes de importar.

### TBCA (USP) – não utilizada
- Consulta gratuita, mas o uso da base em outra ferramenta exige licença paga.

### Ordem da busca de alimentos
1. Alimentos do próprio usuário → 2. TACO (versões cruas por último) → 3. Open Food Facts.
Produtos da OFF com valores impossíveis (mais de 900 kcal/100 g ou macros acima de 100 g) são descartados.

## Foto do prato (IA)

Provedor escolhido em `MEAL_AI_PROVIDER` (`openai` ou `gemini`).

### OpenAI (em uso)
- Modelo `gpt-5.4-mini` (`OPENAI_MODELS`), Chat Completions com saída JSON estrita.
- **Poucos tokens:** foto de no máximo 512 px (reduzida no celular) em detail "low", instrução curta,
  campos de 1 letra na resposta (com nome e certeza de cada item), sem raciocínio (`reasoning_effort=none`) e no máximo 600 tokens de saída.
  Medido: ~440 tokens de entrada e ~290 de saída por foto (~730 no total) (o consumo de cada análise fica em `raw_response.usage`).
- A OpenAI não usa dados enviados pela API para treinar modelos (padrão da API). É paga por uso.

### Google Gemini (alternativa)
- API `generateContent` com saída em JSON. Modelos em `GEMINI_MODELS`, em ordem: hoje `gemini-3.5-flash-lite`
  (rápido, ~5 s) e `gemini-3.5-flash` como reserva. Se um estiver sobrecarregado (503/429), o servidor tenta o próximo.
- A chave fica só no `.env` do servidor (`GEMINI_API_KEY`). O navegador nunca fala com a IA.
- **Plano gratuito:** tem cota diária, e o Google pode usar o conteúdo enviado para melhorar os produtos dele.
  O app avisa isso na tela da foto. Para uso comercial com muitos usuários, usar o plano pago (o conteúdo deixa de ser usado).
- **Privacidade:** a foto é reduzida no celular, os metadados (EXIF/GPS) são removidos e a imagem **não é guardada**.
  Fica só o resultado da análise (`meal_photo_analyses` / `meal_photo_items`).
- **Limites:** `MEAL_AI_DAILY_LIMIT` análises por usuário por dia (padrão 30) e 6 por minuto.
- Os itens são procurados **só na TACO** (a Open Food Facts não é usada aqui). Com correspondência, os valores vêm da
  TACO; sem ela, valem os da IA. Uma correspondência com calorias 3× diferentes da estimativa é descartada.
- Tudo é sugestão: o usuário confere, ajusta as gramas e desmarca itens antes de salvar.
- Trocar de provedor (ex.: Claude): nova classe que implementa `App\Services\MealPhoto\MealPhotoAnalyzer`,
  registrada no `AppServiceProvider` conforme `MEAL_AI_PROVIDER`.

## Mapa muscular (desenho do corpo)
- Desenhos de frente e costas do **react-native-body-highlighter** 3.1.3, de Hicham Elabbassi
  (https://github.com/HichamELBSI/react-native-body-highlighter), licença **MIT**.
- Só os caminhos SVG foram extraídos para `resources/js/data/bodyMap.ts`, com o aviso de copyright e a licença
  no topo do arquivo; a licença também é publicada em `public/licenses/react-native-body-highlighter.txt`
  (treinr.space/licenses/react-native-body-highlighter.txt).
- As cores e o mapeamento das partes para os 11 grupos musculares do Treinr são do app.
