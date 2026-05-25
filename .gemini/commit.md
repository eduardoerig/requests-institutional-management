# Regra do Agente: Commits Estruturados (Conventional Commits)

## Objetivo

O agente deve sempre fazer commits seguindo o padrão **Conventional Commits**, com mensagens claras, descritivas e rastreáveis.

---

## Formato obrigatório

```
tipo(escopo): descrição curta no imperativo

Corpo opcional explicando o "por quê" da mudança.
O diff já mostra "o quê" mudou — o corpo deve explicar a razão.

- detalhe adicional (opcional)
- detalhe adicional (opcional)
```

---

## Tipos permitidos

| Tipo       | Quando usar                                              |
|------------|----------------------------------------------------------|
| `feat`     | Nova funcionalidade visível ao usuário                   |
| `fix`      | Correção de bug ou comportamento incorreto               |
| `style`    | Ajuste visual/CSS sem impacto na lógica                  |
| `refactor` | Reestruturação de código sem mudar comportamento externo |
| `chore`    | Manutenção geral (limpeza, config, dependências)         |
| `docs`     | Documentação (README, comentários, etc.)                 |
| `test`     | Adição ou ajuste de testes                               |
| `build`    | Ajustes de build, assets ou ambiente                     |

---

## Escopos do projeto SisReq

| Escopo        | Área de responsabilidade                           |
|---------------|----------------------------------------------------|
| `(dashboard)` | home.php, pipeline de status, KPIs                 |
| `(detail)`    | request_detail.php, visualização de requisição     |
| `(layout)`    | CSS global, sidebar, topbar, estrutura de página   |
| `(mobile)`    | Responsividade, bottom nav, FAB, media queries     |
| `(auth)`      | Login, sessão, controle de acesso                  |
| `(api)`       | Endpoints AJAX, handlers PHP                       |
| `(accounts)`  | Gestão de usuários, setores, subdivisões           |
| `(approve)`   | Fluxo de aprovação/recusa de requisições           |
| `(print)`     | Impressão e exportação PDF                         |
| `(forward)`   | Cadeia de repasses entre setores                   |

---

## Exemplos corretos

```bash
feat(dashboard): distribui steps do flow-pipeline igualmente

Substitui min-width/flex-shrink fixos por flex:1 para que
os itens preencham o container sem scroll horizontal.
```

```bash
fix(detail): corrige altura do chat dentro do layout com sidebar

O dp-body com overflow:hidden colapsava o dp-chat-col para height:0.
Solução: position sticky na coluna com altura calculada via JS.
```

```bash
style(layout): remove hover e transition dos icones da pipeline
```

```bash
chore(layout): remove arquivos rejeitados pelo usuário (detail.css, _chat_feed.php)
```

---

## Regra de frequência (a cada 1 hora)

A cada 1 hora de trabalho ativo, o agente deve:

1. Verificar alterações com `git status` e `git diff`
2. Se houver mudanças relevantes, adicionar e commitar:

```bash
git add .
git commit -m "tipo(escopo): descrição clara no imperativo"
git push origin main
```

---

## Regras de segurança (nunca commitar)

- Arquivos `.env` com variáveis de ambiente
- Senhas, tokens, chaves de API
- Arquivos com dados privados de usuários
- Arquivos temporários ou de cache
- Logs do servidor

Verificar sempre se `.gitignore` cobre esses arquivos antes de commitar.

---

## Regra de não-commit

Não criar commit se:

- Não houver alterações reais no projeto
- O código estiver visivelmente quebrado sem necessidade
- Existirem arquivos sensíveis expostos
- O usuário pedir para pausar os commits automáticos
