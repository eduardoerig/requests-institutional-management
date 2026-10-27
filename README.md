<div align="center">
  <h1>Institucional Requests 2.0</h1>
  <p><em>Sistema Moderno de Gestão de Fluxo e Requisições Intersetoriais</em></p>
  <p>
    <img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=flat-square&logo=php" alt="PHP" />
    <img src="https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql" alt="MySQL" />
    <img src="https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=flat-square&logo=javascript" alt="JavaScript" />
    <img src="https://img.shields.io/badge/Design-SaaS-blueviolet?style=flat-square" alt="SaaS Design" />
  </p>
</div>

---

## 🚀 Sobre o Projeto

**Requests 2.0** é um sistema robusto desenvolvido para centralizar e gerenciar requisições entre diferentes departamentos de uma instituição (ex: TI, Marketing, Compras, Manutenção, Reprografia). Ele permite o acompanhamento completo do ciclo de vida de uma demanda, garantindo transparência, controle de SLA e produtividade no fluxo de trabalho.

Desenvolvido com uma interface moderna, sofisticadamente clean e responsiva (Mobile First), o sistema se comporta como uma aplicação SaaS (Software as a Service) focada em performance e alta usabilidade administrativa.

**Desenvolvido por:** [Eduardo Erig](https://github.com/eduardoerig)

---

## ✨ Funcionalidades Principais

* 🔄 **Ciclo de Vida Completo**: Fluxo de status rigidamente orquestrado (`Aguardando aprovação` → `Aprovada` → `Em atendimento` → `Concluída`, ou `Recusada`).
* 🔀 **Repasse Intersetorial (Cross-Sector)**: **transferir** o pedido para outro setor (que passa a ser o responsável depois de aceitar) ou **pedir ajuda** (o outro setor faz uma parte e devolve com um retorno), preservando todo o histórico.
* 🔐 **Controle de Acesso (RBAC)**:
  * **Administrador**: Gestão total da plataforma, usuários, áreas e delegação de perfis.
  * **Admin de Subdivisão**: Autoridade delegada para sub-setores (ex: coordenações específicas).
  * **Gestor Operacional**: Focado inteiramente em resolver as demandas da sua área técnica.
  * **Solicitante**: Abertura guiada de demandas, visualização em tempo real e interação no chat.
* 📊 **Painel e Caixa do setor**: cada perfil vê o próximo passo (urgentes, atrasados, parados com outro setor); a Caixa separa o que é para fazer, o que espera outro setor e o histórico. Números da operação ficam recolhidos e há exportação CSV.
* 💬 **Conversa e histórico**: conversa em cada pedido com dois canais (com quem pediu e só entre setores), histórico de cada mudança e avisos no sino.
* 📱 **Mobile UI/UX First**: Telas, painéis e cartões totalmente otimizados para smartphones, sem perdas visuais ou botões ocultos.

---

## 🛠️ Tecnologias Utilizadas

| Camada | Tecnologia / Padrão |
| :--- | :--- |
| **Backend** | PHP 8.1+, PDO com prepared statements, sem framework e sem Composer |
| **Banco de Dados** | MySQL / MariaDB (InnoDB, utf8mb4) |
| **Frontend UI** | HTML5, CSS3, Vanilla JS (sem build) |
| **UX / Design** | Tipografia Inter, design system próprio em `assets/css/app.css` |
| **Bibliotecas** | FontAwesome 6.5.2 (ícones), Tom Select 2.6.2 local em `assets/vendor/` (combos com busca) |

---

## 📂 Arquitetura de Diretórios

```bash
📦 requests-institutional-management
 ┣ 📂 api/          # Endpoints assíncronos (AJAX) e processadores (JSON)
 ┣ 📂 assets/       # app.css e script.js globais, css/ e js/ por tela em pages/, vendor/ (bibliotecas locais), imagens
 ┣ 📂 classes/      # Regras de negócio (RequestManager, RequestInbox, RequestForwardService)
 ┣ 📂 components/   # Pedaços de tela PHP (menu lateral, cartões, página do pedido, formulários por setor)
 ┣ 📂 partials/     # Linhas renderizadas no servidor (ex.: _account_row.php)
 ┣ 📂 config/       # Variáveis de ambiente, conexão PDO e sessão
 ┣ 📂 includes/     # Funções de apoio: helpers.php (escape, JSON, CSRF) e auth.php (perfis e permissões)
 ┣ 📂 database/     # schema.sql, migrations/, migrate.php e scripts de linha de comando
 ┣ 📂 tests/        # Testes de integração (php tests/run.php)
 ┗ 📂 pages/        # Uma tela por arquivo, montada pelo index.php
```

---

## ⚙️ Instalação

Requisitos: PHP 8.1+ (pdo_mysql, mbstring, curl), MySQL 5.7+ ou MariaDB 10.4+, Apache com `mod_rewrite` (e, de preferência, `mod_headers`).

1. **Código**: copie o projeto para a pasta pública (raiz do domínio ou subpasta). **Não envie** `scratch/`, `database/backups/` nem `*.local.*`.
2. **Credenciais do banco** (nunca no git), uma das opções:
   * variáveis de ambiente `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`; ou
   * `config/conn.local.php` a partir de `config/conn.local.example.php`.
3. **Banco de dados** (pela linha de comando):
   ```bash
   php database/migrate.php --fresh    # banco NOVO e vazio
   php database/migrate.php            # banco existente: aplica só o que falta
   php database/migrate.php --status   # mostra o que já foi aplicado
   ```
4. **Primeiro administrador** (senha temporária exibida uma vez; troca obrigatória no 1º acesso):
   ```bash
   php database/seed_admin.php admin "Nome do Administrador"
   ```
5. **PHP em produção**: `display_errors=Off`, `log_errors=On`, sem Xdebug, OPcache ligado.
6. **HTTPS**: o `.htaccess` redireciona para HTTPS fora de `localhost`. Instale o certificado (cPanel → SSL/TLS ou AutoSSL).
7. **Backup diário** do MySQL (cPanel → Cron Jobs), por exemplo:
   ```bash
   mysqldump --single-transaction -u USUARIO -p'SENHA' BANCO | gzip > ~/backups/sisreq_$(date +\%F).sql.gz
   ```
   Teste a restauração ao menos uma vez antes do lançamento.

### Integração WhatsApp
Desligada por padrão. Para ativar: `BOT_ENABLED=1` e `BOT_KEY=<chave longa>` no ambiente (ou `bot_enabled`/`bot_key` em `config/conn.local.php`).

## ✅ Testes

Em um banco de **homologação** com o servidor rodando:
```bash
php database/seed_test_users.php     # contas de teste (credenciais em database/test_accounts.local.txt)
php tests/run.php                    # 117 verificações de fluxo, permissão e segurança
```
`TEST_BASE_URL` muda o endereço testado (padrão `http://localhost/requests2.0/`).

O CI (`.github/workflows/lint.yml`) roda `php -l` com PHP 8.1 em todos os `.php`. Ferramentas para o Claude Code (revisor PHP, checagem antes do commit, hook de lint) ficam em `.claude/`.

## 🚥 Situações da requisição

Toda tela usa os mesmos nomes (`statusLabel()` em `includes/helpers.php`):

| Código | Nome na tela | Significado |
| :---: | :--- | :--- |
| `P` | **Aguardando aprovação** | A coordenação ainda vai analisar. |
| `Y` | **Aprovada** | Na fila do setor responsável. |
| `W` | **Em atendimento** | O setor está trabalhando no pedido. |
| `F` | **Encaminhada a outro setor** | Outro setor vai continuar o atendimento. |
| `C` | **Concluída** | Pedido atendido. |
| `N` | **Recusada** | Não aprovada; o motivo fica na conversa do pedido. |

Transições permitidas: `P→Y/N` (coordenação/admin, recusa exige motivo), `Y→W` (setor), `W→C` (setor), `C→W` (reabrir), `Y/W→F` ao transferir para outro setor, voltando a `Y/W` quando o destino aceita ou recusa, ou quando o envio é cancelado. Pedir ajuda não muda a situação.

---

<div align="center">
  <p>Desenvolvido com foco na excelência de UI/UX e processos institucionais resolutos.</p>
  <b>Eduardo Erig &copy; 2026</b>
</div>
