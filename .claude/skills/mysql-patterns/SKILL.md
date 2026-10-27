---
name: mysql-patterns
description: Padrões MySQL/InnoDB para o requests2.0 — tipos de coluna, índices compostos e EXPLAIN, transações e travas, anti-padrões e o fluxo de migração do projeto. Use ao criar/alterar tabela, escrever migração, consulta lenta ou nova consulta em lista/caixa de pedidos.
---

# MySQL no requests2.0

Resumo da skill `mysql-patterns` do ECC (affaan-m/ECC), cortado para o que o projeto usa (MySQL do WAMP local, remoto compartilhado, PDO, sem ORM).

## Fluxo de mudança de esquema (do projeto)
1. Criar `database/migrations/00N_nome.sql` (o `migrate.php` divide por `;` no fim da linha e ignora linhas `--`; um comando por instrução).
2. Atualizar `database/schema.sql` no mesmo commit.
3. Aplicar no local: `php database/migrate.php`, conferir com `--status`.
4. Código que depende de coluna nova e pode rodar antes da migração no servidor: checar com `SHOW COLUMNS` (como na 003) ou garantir a ordem no deploy.

## Tipos
| Uso | Preferir | Evitar |
| --- | --- | --- |
| Texto do usuário | `utf8mb4` + **a mesma collation das outras tabelas** (UNION com collation diferente quebra; ver migração 001) | `utf8`/`utf8mb3` |
| Dinheiro / quantidade exata | `DECIMAL(p,s)` | `FLOAT`/`DOUBLE` |
| Data/hora | `DATETIME`, fuso tratado pela aplicação | supor que `DATETIME` guarda fuso |
| Status que muda com o tempo | `VARCHAR` com valores controlados no código | `ENUM` que vira migração a cada valor novo |
| Sim/não | `TINYINT(1)` 0/1 `NOT NULL` | `NULL` como terceiro estado sem querer |

## Índices
- Ordem do composto: colunas de igualdade primeiro, depois intervalo/ordenação. Ex.: caixa do setor filtra `status = ?` e ordena por data → `(status, created_at)`.
- Coluna usada em JOIN (ids estrangeiros) deve ter índice.
- Antes de criar índice, rode `EXPLAIN` na consulta real. Sinais ruins: `type = ALL` em tabela grande, `key = NULL` com filtro seletivo, `Using filesort`/`Using temporary` em tela interativa.
- Índice custa escrita e espaço: não criar "por via das dúvidas".

## Consultas
- Sempre PDO com parâmetros; nome de tabela dinâmico só via `normalizeRequestTable()`.
- Colunas explícitas em vez de `SELECT *` em listas e caixas.
- Nada de consulta dentro de loop (N+1): junte com `JOIN` ou `WHERE id IN (...)`.
- Lista longa: paginação por chave (`WHERE id < ? ORDER BY id DESC LIMIT 50`) em vez de `OFFSET` alto.
- Upsert: `INSERT ... ON DUPLICATE KEY UPDATE` (precisa de chave única).

## Transações
- Escritas que dependem umas das outras (encaminhar + histórico + notificação) em uma transação curta: `beginTransaction()` → ... → `commit()`, `rollBack()` no `catch`.
- Nada de chamada externa (WhatsApp, e-mail) dentro da transação: faça depois do `commit`.
- Travar linhas sempre na mesma ordem (`ORDER BY id FOR UPDATE`) para evitar deadlock; em deadlock, desfazer e tentar de novo poucas vezes.

## Segurança do banco
- Usuário da aplicação sem `ALL PRIVILEGES`; só `SELECT, INSERT, UPDATE, DELETE` no banco dela. Migrações com outro usuário, se possível.
- Credenciais só em `config/conn.local.php` (fora do git), nunca em exemplo, script ou nota.

## Anti-padrões
| Anti-padrão | Risco | Melhor |
| --- | --- | --- |
| `SELECT *` em tela quente | traz dado demais, quebra com coluna nova | colunas explícitas |
| `OFFSET` alto | varre tudo | paginação por chave |
| JOIN em coluna sem índice | lento e trava em DELETE | índice na coluna |
| transação longa | espera de trava | unidades pequenas |
| buscar pedido só pelo id | pega o pedido de outro setor | par (id, setor) |
