# PACOTE 11 — Auditoria e recálculo controlado dos scores ICP

## Diagnóstico confirmado

- `app/Services/IcpScoringService.php`: `IcpScoringService::VERSION` já é `v3` na `main` `5b43e22`. `calculate(Company $company)` usa `CompanyIcpScore::updateOrCreate`, grava os fatores explicativos e chama `SdrScoringService::recalculate()`. **Não reimplementar o scoring**.
- `app/Models/CompanyIcpScore.php`: `version`, `factors`, `calculated_at`, `score` e `grade` disponíveis.
- `app/Services/CnpjEnrichmentService.php` e `CompanyService.php` foram alterados no Pacote 10 para preservar CNAEs parciais; não desfazer.
- Migração relevante: `database/migrations/2026_09_02_212019_create_company_icp_scores_table.php`.

## Código a implementar

1. Adicionar comando `app/Console/Commands/AuditIcpScoreVersions.php` (ou nome equivalente existente, sem duplicar). Assinatura sugerida: `icp:audit-scores {--limit=0} {--format=table}`. **Somente leitura**. Relatar totais agregados: empresas com ICP ausente, versões `v2`, `v3`, versão vazia/desconhecida, estabelecimentos com código de situação `2`/`02`/outros, ICP potencialmente obsoleto pela alteração de regra cadastral. Não imprimir CNPJs, nomes ou scores individuais por padrão.
2. Adicionar comando `app/Console/Commands/RecalculateIcpAffected.php` (ou ampliar um comando já existente sem quebrar interface pública). Opções mínimas: `--dry-run` (PADRÃO), `--apply` explícito, `--limit` pequeno por padrão, `--chunk=100`, `--reason=...`, `--company-id` opcional, `--include-unknown` somente com aprovação. `--apply` não deve ser chamado pelo pacote mestre automaticamente.
3. Candidatos: ICP em versão antiga comprovada (`version != IcpScoringService::VERSION`) e empresas com evidência específica de cálculo afetado pelo código cadastral. **Não recalcular indiscriminadamente todos os `v3`**. `v3` com `calculated_at` posterior à correção é normalmente atual; se dúvida, relatar para análise.
4. Gerar preview das diferenças potenciais sem gravar `CompanyIcpScore`, `CompanySdrScore`, fatores ou datas: se houver cálculo puro reutilizável, extrair `evaluate(Company $company): ScoreResult` / DTO sem efeitos colaterais; `calculate()` continua persistindo usando resultado da mesma função pura. Evitar duplicar lógica de pontuação no CLI. Nunca usar transação com rollback para simular escrita em sistema real (pode disparar eventos/jobs apesar do rollback).
5. Ao aplicar com aprovação, processar lotes ordenados por ID, transação por empresa, logs de auditoria sem PII (ID interno opcional). As pontuações e explicações devem seguir a regra `v3` e a atualização SDR já existente; não mudar status de HubSpot, elegibilidade comercial, estágio ou oportunidade.
6. Para recuperar auditabilidade, se necessário registrar `previous_version`, `old_score`, `new_score` e `applied_at` em mecanismo aditivo, sem perder `factors`. Não armazenar dados duplicados quando log atual já servir.
7. Validação de amostras: cadastro com código `2` e `02` deve contar da mesma forma, incluindo o CNAE; `ATIVA` textual e código ausente precisam seguir regra atual; código de situação inativa exclui pontos operacionais; cadastro sem qualquer situação mantém fallback existente; CNPJ alfanumérico preservado.

## Testes obrigatórios

- Criar `tests/Feature/Companies/IcpScoreControlledRecalculationTest.php` usando banco de testes.
- Asserções: comando sem `--apply` **não altera** `company_icp_scores`, `company_sdr_scores` nem timestamps; `--apply` somente à amostra aprovada; v3 inalterado; `--limit` cumpre limite; `--apply` idempotente; preserva todos os fatores e explicações; divergência antes/depois sinalizada; não chama HTTP/HubSpot.
- Executar também `tests/Feature/Companies/IcpScoringServiceTest.php`, `tests/Feature/Companies/SdrScoringServiceTest.php`, `tests/Feature/Companies/RegistrationStatusAndCnaeIntegrityTest.php` e CI.
- Rodar o script pronto de auditoria read-only: `./vendor/bin/sail artisan tinker --execute='require base_path("docs/prospector-pacotes-11-17/scripts/auditar_icp.php");'`.

## Critério de aceite

- Relatório com quantidades reais, mas sem dados identificáveis; nenhum recálculo real realizado sem autorização.
- Correção e testes implementados; nomes de comandos documentados em README do módulo.
- Dados de pontuação v3 e SDR não são alterados pela etapa de preparação.
- Após dry-run aprovado, o Codex **interrompe e pede autorização** antes de qualquer `--apply` ao banco real.

## Reversão

Antes de escrita real, revertível via Git. Depois de escrita real, restaurar o snapshot/backup específico dos scores com acompanhamento e regra de negócio, não executar `migrate:rollback` para desfazer recálculo de dados.
