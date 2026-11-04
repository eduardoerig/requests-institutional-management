---
name: verificar
description: Verificação do requests2.0 antes de commit ou publicação — lint PHP 8.1, varredura de depuração/segredos/SQL interpolado, testes de integração no banco local e revisão do diff, com relatório PRONTO/NÃO PRONTO. Use ao terminar uma feature, correção ou refatoração, ou quando pedirem "verificar", "checar antes de commitar".
---

# Verificar (requests2.0)

Adaptado da skill `verification-loop` do ECC (affaan-m/ECC) para PHP puro + JS vanilla. Rode as fases em ordem; se o lint falhar, pare e corrija antes de seguir.

PHP do CI: `PHP=/c/wamp64/bin/php/php8.1.33/php.exe` (o CI usa 8.1; não use 8.2+ para validar).

## 1. Lint PHP (o que o CI roda)
```bash
PHP=/c/wamp64/bin/php/php8.1.33/php.exe
git diff --name-only --diff-filter=ACMR HEAD -- '*.php' ':!scratch/*' ':!vendor/*' | while read f; do "$PHP" -l "$f" | grep -v '^No syntax errors'; done
```
Antes de abrir PR, rode no projeto inteiro (igual ao `.github/workflows/lint.yml`):
```bash
find . -name '*.php' -not -path './scratch/*' -not -path './vendor/*' -print0 | xargs -0 -n1 "$PHP" -l | grep -v '^No syntax errors'
```

## 2. Varredura do diff
Olhe só as linhas adicionadas (`git diff HEAD -U0 | grep '^+'`) e procure:
- depuração esquecida: `var_dump`, `print_r(`, `die(`, `console.log`, `debugger`
- SQL com variável interpolada: `query("...$`, `prepare("...$`, `"SELECT ... " . $`
- segredo ou marca no código: `password =`, `token`, `api_key`, nome da instituição (white label)
- `innerHTML =` com dado vindo do servidor/usuário sem escapar
- endpoint novo em `api/` sem `requirePostJson()` / `requireLoginJson()`
- `<style>` inline em página

## 3. Banco
- Mudou esquema? Existe `database/migrations/00N_*.sql` **e** `database/schema.sql` foi atualizado.
- `php database/migrate.php --status` no banco local mostra a migração aplicada.

## 4. Testes de integração
> Só no banco local. Antes, confirme que `config/conn.local.php` aponta para `127.0.0.1` / `requests2_dev`. Nunca rode contra o remoto.

Com o WAMP no ar (`http://localhost/requests2.0/`):
```bash
/c/wamp64/bin/php/php8.1.33/php.exe tests/run.php
```
Informe passou/falhou por seção. Se o WAMP estiver parado, diga que a fase não rodou (não marque como PASSOU).

## 5. Revisão do diff
`git diff --stat` e leia cada arquivo alterado: mudança sem querer, caso de borda, pedido tratado como par (id, setor), permissão por perfil, tela no celular (mobile first). Para revisão mais funda, use os agentes `php-reviewer` e `silent-failure-hunter`.

## Relatório
```
VERIFICAÇÃO
===========
Lint PHP 8.1: [PASSOU/FALHOU] (N erros)
Varredura:    [PASSOU/FALHOU] (N achados)
Banco:        [OK/PENDENTE/sem mudança]
Testes:       [PASSOU/FALHOU/NÃO RODOU] (X/Y)
Diff:         N arquivos

Resultado: [PRONTO/NÃO PRONTO] para commit

A corrigir:
1. ...
```
