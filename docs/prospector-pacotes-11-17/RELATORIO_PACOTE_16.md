# Relatório — Pacote 16 (segurança e dependências)

## Resultado

**PASS — validação técnica automatizada.** O build, as auditorias npm/Composer,
o CI PHP e as suítes Python passaram. O alias `vite` e o lockfile estão
alinhados. Nenhuma vulnerabilidade foi reportada pelo `npm audit` ou pelo
`composer audit`.

## Implementado

- A assinatura do webhook HubSpot é sempre verificada; a configuração legada
  não consegue desabilitar a validação.
- Foram reforçados os testes de assinatura e autorização de vendedor para
  oportunidades HubSpot.
- Dependências npm foram atualizadas sem `npm audit fix --force`; o Vite+ foi
  migrado para o alias `npm:@voidzero-dev/vite-plus-core@0.3.3`.
- `composer.lock` recebeu a atualização compatível de HttpFoundation e
  polyfills.
- Valores de acesso Receita deixaram de ter defaults não-placeholder no
  `.env.example` e no Compose.
- Inventário de CSS não indicou folhas sem referência (além do entrypoint
  esperado); não foram removidos CSS/scripts nem feitas alterações visuais.

## Validação

- `docker compose exec -T laravel.test npm run build`: **PASS**; 22 módulos
  transformados. O build emitiu avisos não bloqueantes sobre o pacote opcional
  `fontaine` e caminhos de imagens públicas preservados para resolução em
  runtime.
- `docker compose exec -T laravel.test npm audit`: **PASS**, 0 vulnerabilidades.
- `docker compose exec -T laravel.test composer audit`: **PASS**, sem advisories.
- `docker compose exec -T laravel.test composer ci:check`: **PASS** —
  Pint aprovou 542 arquivos; PHPStan sem erros; Pest **906 passaram, 2
  ignorados, 3.978 assertions**.
- Testes Python: IMAP **3**, parser DSN **8**, Receita **4** — todos passaram
  com fixtures. O teste Receita foi executado na imagem dedicada que contém
  DuckDB; nenhum pacote foi instalado na imagem Laravel.
- `docker compose config --quiet` e `git diff --check`: **PASS**.
- Verificação do manifesto/lock: ambos declaram Vite core `0.3.3`.

## Limites e pendências

- O build preserva caminhos de assets públicos para resolução em runtime; os
  arquivos de imagem correspondentes existem no repositório. Não foi feita
  validação visual em navegador.
- O serviço `receita-data` do Compose local está parado (status `Exited (255)`,
  criado há 10 dias). Não foi iniciado nem reiniciado; os testes Receita foram
  executados isoladamente na imagem dedicada.
- Não foram executadas chamadas reais HubSpot, envios SMTP, migrations
  operacionais, deploy ou alterações em produção.
- Dois testes Pest foram reportados como ignorados pelo runner; a saída
  resumida do CI não identificou seus nomes.

