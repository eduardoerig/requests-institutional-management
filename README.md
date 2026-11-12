# SisReq — Requests 2.0

Sistema de pedidos entre setores de uma instituição (TI, Compras, Manutenção, Reprografia, Marketing). Quem precisa de algo abre um pedido, a coordenação aprova, o setor atende e todos acompanham o andamento, a conversa e o histórico no mesmo lugar.

É **white label**: o nome e o logo da instituição vêm da configuração (`ORG_NAME` / `ORG_LOGO`), nunca do código. Padrão: "SisReq".

Desenvolvido por [Eduardo Erig](https://github.com/eduardoerig).

---

## Stack

| Camada | O que usa |
| :--- | :--- |
| Backend | PHP 8.1 puro + PDO (prepared statements). Sem framework, sem Composer. |
| Banco | MySQL 5.7+ / MariaDB 10.4+ (InnoDB, utf8mb4) |
| Frontend | HTML, CSS e JavaScript sem framework e sem build. Celular primeiro (troca para PC em 900px). |
| Bibliotecas | Font Awesome 6.5.2 (ícones) e Tom Select 2.6.2 (combos com busca, local em `assets/vendor/`) |
| Servidor | Apache com `mod_rewrite` (e, de preferência, `mod_headers`) |

O CI (`.github/workflows/lint.yml`) roda `php -l` com PHP 8.1 em todos os `.php`. **Não use recursos de PHP mais novo que 8.1.**

---

## Rodar no local (WAMP)

1. **Código no Apache**: deixe a pasta acessível em `http://localhost/requests2.0/` (dentro de `C:\wamp64\www` ou por junção).
2. **PHP na linha de comando**: o do WAMP não fica no PATH. Use o 8.1, igual ao CI:
   ```bash
   C:\wamp64\bin\php\php8.1.33\php.exe -v
   ```
3. **Banco local**: crie um banco vazio `requests2_dev` no MySQL do WAMP.
4. **Conexão**: copie `config/conn.local.example.php` para `config/conn.local.php` e aponte para `requests2_dev`. Esse arquivo fica fora do git. (Alternativa: variáveis `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.)
5. **Criar as tabelas**:
   ```bash
   php database/migrate.php --fresh
   ```
6. **Subdivisões**: não há tela para cadastrá-las. Inclua ao menos uma direto no banco:
   ```sql
   INSERT INTO ctd_subdivision (name, slug) VALUES ('Coordenação Geral', 'coord-geral');
   ```
7. **Contas de teste** (uma por perfil; as senhas vão para `database/test_accounts.local.txt`, fora do git):
   ```bash
   php database/seed_test_users.php
   ```
8. Abra `http://localhost/requests2.0/` e entre com uma das contas.

> **Atenção:** `seed_test_users.php` e `tests/run.php` criam e apagam dados. Só rode com o `conn.local.php` apontando para um banco de teste, nunca para produção.

### Scripts de linha de comando (`database/`)

| Comando | Para quê |
| :--- | :--- |
| `php database/migrate.php` | Aplica as migrações que faltam |
| `php database/migrate.php --status` | Mostra o que já foi aplicado |
| `php database/migrate.php --fresh` | Cria tudo num banco **vazio** (usa `schema.sql`) |
| `php database/seed_admin.php <login> "<Nome>"` | Cria um administrador com senha temporária (troca no 1º acesso) |
| `php database/reset_password.php <login>` | Nova senha temporária e desbloqueio de login |
| `php database/seed_test_users.php` | Contas de teste, uma por perfil |

Toda migração nova vai em `database/migrations/NNN_nome.sql` **e** em `database/schema.sql`.

---

## Testes

Testes de integração por HTTP + banco, sem dependências. Precisam do Apache rodando e das contas de teste:

```bash
php tests/run.php
```

- Endereço testado: `TEST_BASE_URL` (padrão `http://localhost/requests2.0/`).
- Senha das contas: lida de `database/test_accounts.local.txt` ou de `TEST_PASSWORD`.
- Cobrem acesso sem login, arquivos internos bloqueados, login, CSRF, criar, quem vê o quê, aprovação, atendimento, conversa, transferir, pedir ajuda, setor parceiro, caixa do setor e todas as páginas por perfil. Os dados criados são apagados no fim.

---

## Pastas

```text
index.php          roteador: monta a tela de pages/ conforme o endereço (ex.: /inbox) e o perfil
print_request.php  folha A4 do pedido
api/               endpoints AJAX (respondem JSON)
classes/           regras de negócio: RequestManager, RequestInbox, RequestForwardService, RequestChat
components/        pedaços de tela PHP (menu lateral, cartões, página do pedido, formulários por setor)
partials/          linhas renderizadas no servidor
pages/             uma tela por arquivo
assets/            app.css e script.js globais; css/pages e js/pages por tela; vendor/; img/
config/            conexão PDO, sessão, segurança e registro de setores (sectors.php)
includes/          helpers.php (escape, JSON, CSRF, rótulos) e auth.php (perfis e permissões)
database/          schema.sql, migrations/, migrate.php e scripts CLI
tests/             run.php (testes de integração)
.claude/           agentes, skills e hook de lint para o Claude Code
```

Ficam fora do git: `config/conn.local.php`, `*.local.txt`, `scratch/`, `database/backups/`.

---

## Telas

| Tela | Quem usa |
| :--- | :--- |
| Minhas requisições / Nova requisição | quem pede |
| Aprovar | coordenação: lista + painel, com Perguntar, Recusar e Aprovar um pedido por vez (desfazer de alguns segundos) |
| Caixa do setor | gestores: abas por etapa, lista por prazo + painel do pedido |
| Painel | coordenação e admin: o que está urgente, atrasado ou parado |
| Página do pedido | todos: dados, conversa, histórico e ações |
| Usuários e setores | admin geral |
| Minha conta / Como usar | todos |

---

## Perfis

Campo `ctd_users.role`; regras em `includes/auth.php`.

| Perfil | Roles | O que faz |
| :--- | :--- | :--- |
| Admin geral | `admin`, `adm`, `coord` | Vê e faz tudo; gerencia usuários e setores |
| Admin de subdivisão | `adm_sub` | Aprova e acompanha os pedidos da própria subdivisão |
| Gestor de setor | `gestor`, `ti`, `shop`, `service`, `xerox`, `mkt`, `marketing` | Atende os pedidos do seu setor |
| Solicitante | `solicitante` (padrão) | Abre e acompanha os próprios pedidos |

Solicitante ou `adm_sub` sem subdivisão não consegue abrir pedido.

---

## Fluxo do pedido

Um pedido é identificado pelo par **(id, setor)**: cada setor tem sua tabela `ctd_<setor>_frm`.

```text
P Aguardando aprovação ──aprovar──▶ Y Aprovada ──começar──▶ W Em atendimento ──concluir──▶ C Concluída
        │                                                          ▲                              │
        └──recusar (com motivo)──▶ N Recusada                      └────────── reabrir ───────────┘
```

| Código | Nome na tela | Quem muda |
| :---: | :--- | :--- |
| `P` | Aguardando aprovação | — (situação inicial) |
| `Y` | Aprovada | coordenação (admin geral ou `adm_sub` da subdivisão) |
| `W` | Em atendimento | gestor do setor responsável |
| `C` | Concluída | gestor do setor responsável |
| `N` | Recusada | coordenação; o motivo vai para a conversa |
| `F` | Encaminhada a outro setor | durante uma transferência ainda não aceita |

Os nomes vêm de `statusLabel()` em `includes/helpers.php`; use sempre essa função.

**Entre setores** (`classes/RequestForwardService.php`):
- **Transferir**: o pedido vai para `F`; quando o outro setor aceita, ele passa a ser o responsável e o pedido volta para `Y`/`W`.
- **Pedir ajuda**: o outro setor faz uma parte e devolve com um retorno; o responsável e a situação não mudam.
- **Setores parceiros**: na aprovação, a coordenação pode incluir até **2 setores parceiros** além do principal, cada um com prazo próprio. Quem pede só sugere. O principal começa sem esperar, mas só conclui depois que os parceiros devolvem.

Cada pedido tem conversa com quem pediu e uma conversa só entre setores, histórico de cada mudança e avisos no sino.

---

## Publicar

1. **Pacote**: publique só o que está no git, nunca a pasta de trabalho:
   ```bash
   git archive --format=zip -o sisreq.zip HEAD
   ```
   Numa atualização, apague no servidor os arquivos que a versão nova removeu (`git diff --name-status <versão-anterior> HEAD | grep ^D`).
2. **Banco primeiro**: rode `php database/migrate.php` no servidor **antes** de subir o código novo.
3. **Credenciais**: variáveis `DB_*` no ambiente ou um `config/conn.local.php` só no servidor.
4. **Primeira instalação**: `migrate.php --fresh`, cadastre as subdivisões e crie o admin com `seed_admin.php`.
5. **Apache**: `AllowOverride All` e `mod_rewrite`; a proteção das pastas internas depende do `.htaccess`.
6. **PHP em produção**: `display_errors=Off`, `log_errors=On`, sem Xdebug, OPcache ligado.
7. **HTTPS**: o `.htaccess` redireciona para HTTPS fora de `localhost`; instale o certificado (ex.: AutoSSL no cPanel).
8. **Backup diário** do MySQL (cron com `mysqldump --single-transaction ... | gzip`). Teste a restauração ao menos uma vez.
9. **Anexos dos pedidos**: ficam em `uploads/` (ou em `UPLOAD_DIR`), fora do git e bloqueados pelo `.htaccess`; entram no backup junto com o banco. O sistema aceita até 5 arquivos de 20 MB (50 MB por pedido), mas o PHP corta antes se `upload_max_filesize` e `post_max_size` forem menores: use `upload_max_filesize=20M` e `post_max_size=55M`. A tela mostra sempre o limite que vale no servidor.

### Configuração opcional

| Variável (ou chave no `conn.local.php`) | Efeito |
| :--- | :--- |
| `ORG_NAME` / `org_name` | Nome da instituição na tela |
| `ORG_LOGO` / `org_logo` | Logo (caminho dentro de `assets/`) |
| `BOT_ENABLED=1` / `bot_enabled` | Liga a integração com WhatsApp (desligada por padrão) |
| `BOT_KEY` / `bot_key` | Chave longa exigida pelo bot no cabeçalho `X-Bot-Key` |
| `UPLOAD_DIR` / `upload_dir` | Pasta dos anexos (padrão `uploads/` na raiz; de preferência fora da pasta pública) |
