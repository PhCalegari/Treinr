# TACO – Tabela Brasileira de Composição de Alimentos (4ª edição, 2011)

**Fonte e autoria:** NEPA – Núcleo de Estudos e Pesquisas em Alimentação, UNICAMP.
TACO – Tabela Brasileira de Composição de Alimentos. 4ª ed. rev. e ampl. Campinas: NEPA-UNICAMP, 2011.
Site: https://www.nepa.unicamp.br/ · Contato: taco@unicamp.br

**Permissão:** a publicação declara: "É permitida a reprodução total ou parcial desta obra, desde que citada a fonte."
O texto não trata explicitamente de uso comercial. Antes de um lançamento comercial, confirme com taco@unicamp.br.

**Estes arquivos:** CSVs extraídos da planilha oficial (`Taco_4a_edicao_2011.xls`) pelo projeto
https://github.com/raulfdm/taco-api (licença MIT do código), pasta `references/csv/`, baixados em 2026-11-03.
Valores conferidos por amostragem com a tabela oficial (ex.: feijão carioca cozido 76 kcal; peito de frango grelhado 159 kcal).

- `food.csv`: id, categoria, nome
- `categories.csv`: categorias
- `nutrients.csv`: composição por 100 g de parte comestível

6 alimentos estão sem energia na própria TACO (análise em reavaliação). Dois deles, comuns demais para faltar,
entram com a energia e os macros da USDA FoodData Central (domínio público, CC0): "Leite, de vaca, integral"
(FDC 171265) e "Leite, de vaca, desnatado, UHT" (FDC 171269). Os outros 4 (iogurte de abacaxi, 2 sais e coco verde)
não são importados.
