---
trigger: always_on
---

# Regra do Agente: Commit Automático a Cada 1 Hora

## Objetivo

O agente deve registrar continuamente o progresso realizado no projeto e fazer um `git commit` no repositório a cada 1 hora, sempre que houver alterações relevantes.

## Regra principal

A cada 1 hora de trabalho ativo no projeto, o agente deve:

1. Analisar tudo o que foi feito no período.
2. Identificar os arquivos criados, editados ou removidos.
3. Revisar o contexto das alterações realizadas.
4. Verificar se há mudanças no Git.
5. Criar um commit com uma mensagem clara e descritiva.
6. Não fazer commit caso não existam alterações.

## Passos obrigatórios

Antes de realizar o commit, o agente deve executar:

```bash
git status
```

Depois, deve revisar as alterações com:

```bash
git diff
```

Se houver arquivos novos, modificados ou removidos, o agente deve adicionar as alterações:

```bash
git add .
```

Em seguida, deve criar um commit seguindo o padrão:

```bash
git commit -m "chore: registra progresso automático do projeto"
```

## Formato recomendado da mensagem de commit

A mensagem do commit deve resumir o que foi feito na última hora.

Exemplos:

```bash
git commit -m "feat: adiciona estrutura inicial do sistema"
```

```bash
git commit -m "fix: corrige validação do formulário de requisições"
```

```bash
git commit -m "style: ajusta layout e responsividade da interface"
```

```bash
git commit -m "refactor: organiza arquivos e melhora estrutura do projeto"
```

```bash
git commit -m "docs: atualiza documentação do projeto"
```

## Regra para descrição do commit

Sempre que possível, o agente deve usar uma mensagem baseada no contexto real do que foi feito.

Formato:

```bash
git commit -m "tipo: resumo curto da alteração"
```

Tipos permitidos:

- `feat`: nova funcionalidade
- `fix`: correção de erro
- `docs`: documentação
- `style`: ajustes visuais ou formatação
- `refactor`: melhoria de código sem mudar comportamento
- `chore`: tarefas gerais do projeto
- `test`: testes
- `build`: ajustes de build ou dependências

## Segurança

O agente nunca deve fazer commit de arquivos sensíveis, como:

- `.env`
- senhas
- tokens
- chaves de API
- arquivos com credenciais
- dados privados de usuários
- arquivos temporários desnecessários

Antes de commitar, o agente deve verificar se esses arquivos estão ignorados no `.gitignore`.

## Regra de não commit

O agente não deve criar commit se:

- Não houver alterações no projeto.
- As alterações forem apenas temporárias.
- Existirem arquivos sensíveis expostos.
- O código estiver claramente quebrado sem necessidade.
- O usuário pedir para pausar os commits automáticos.

## Push para o repositório remoto

Após o commit, o agente só deve executar o push se isso estiver autorizado no projeto.

Comando:

```bash
git push
```

Caso o push não seja autorizado, o agente deve apenas criar o commit local.

## Frequência

O agente deve repetir esse processo a cada 1 hora de trabalho ativo no projeto.

```bash
# Fluxo padrão
git status
git diff
git add .
git commit -m "chore: registra progresso automático do projeto"
```

## Resumo da regra

A cada 1 hora, o agente deve verificar o que foi feito, revisar as alterações, evitar arquivos sensíveis e criar um commit claro com o progresso do projeto.
