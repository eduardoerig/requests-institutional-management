# Requests 2.0 - Sistema de Gestão de Requisições Institucionais

Sistema robusto desenvolvido para centralizar e gerenciar requisições entre diferentes setores de uma instituição (ex: TI, Marketing, Compras, Manutenção, Xerox). O sistema permite o acompanhamento completo do ciclo de vida de uma solicitação, desde a abertura até a conclusão, incluindo funcionalidades avançadas de repasse entre setores.

**Desenvolvido por:** Eduardo Erig

---

## 🚀 Funcionalidades Principais

- **Ciclo de Vida Completo**: Fluxo de status organizado (Pendente -> Aprovada -> Em Andamento -> Concluída/Recusada).
- **Repasse Intersetorial (Cross-Sector Forwarding)**: Possibilidade de encaminhar uma requisição de um setor para outro, mantendo o histórico e a visibilidade para ambos os gestores.
- **Controle de Acesso (RBAC)**:
  - **Admin**: Visão total do sistema e gestão de contas.
  - **Adm_Sub (Subdivisão)**: Gestão focada em níveis específicos (ex: Ensino Fundamental, Médio).
  - **Gestor**: Responsável por atender as demandas de seus respectivos setores.
  - **Solicitante**: Abertura e acompanhamento de suas próprias requisições.
- **Dashboard em Tempo Real**: Painel com estatísticas e pipeline visual do fluxo de trabalho.
- **Sistema de Notificações**: Alertas visuais e contadores dinâmicos no menu lateral.
- **Histórico e Comentários**: Auditoria de mudanças de status e chat interno para cada requisição.

---

## 🛠️ Tecnologias Utilizadas

- **Linguagem**: PHP 8.x
- **Banco de Dados**: MySQL (utilizando PDO para segurança)
- **Frontend**: HTML5, Vanilla JavaScript, CSS3 (Design Moderno/Glassmorphism)
- **Ícones**: Font Awesome 6
- **Servidor Recomendado**: WAMP/XAMPP ou ambiente Linux com Apache/Nginx

---

## 📂 Estrutura do Projeto

- `/api`: Endpoints para ações assíncronas e processamento de formulários.
- `/classes`: Lógica de negócio encapsulada (Ex: `RequestManager`, `RequestForwardService`).
- `/components`: Elementos de UI reutilizáveis como a barra lateral e cabeçalhos.
- `/config`: Arquivos de configuração de banco de dados e rotinas de sistema.
- `/migrations`: Scripts para atualização da estrutura do banco de dados.
- `/pages`: Telas principais do sistema (Dashboard, Detalhes, Listagens).
- `/assets`: Recursos estáticos (CSS, Imagens, JS).

---

## ⚙️ Instalação e Configuração

1. **Clonar o projeto**:
   ```bash
   git clone https://github.com/eduardoerig/requests-institutional-management.git
   ```

2. **Configuração do Banco de Dados**:
   - Importe os arquivos SQL localizados na pasta `/migrations` (ou utilize os scripts PHP de criação de tabelas em `/config`).
   - Crie o arquivo `config/conn.php` e adicione suas credenciais:
     ```php
     <?php
     $host = 'seu_host';
     $dbname = 'seu_nome_banco';
     $user = 'seu_usuario';
     $pass = 'sua_senha';
     
     try {
         $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
         $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
         $pdo = $conn;
     } catch (PDOException $e) {
         die("Erro de conexão: " . $e->getMessage());
     }
     ?>
     ```

3. **Ambiente Web**:
   - Coloque a pasta do projeto no diretório `www` (WAMP) ou `htdocs` (XAMPP).
   - Certifique-se de que o módulo `rewrite` do Apache esteja ativo (suporte ao `.htaccess`).

---

## 🔐 Níveis de Status

- `P` (Pendente): Aguardando aprovação inicial.
- `Y` (Aprovada): Aprovada pela administração, aguardando início do atendimento.
- `W` (Em Andamento): Sendo atendida pelo setor responsável.
- `F` (Repassada): Encaminhada para outro setor complementar.
- `C` (Concluída): Atendimento finalizado com sucesso.
- `N` (Recusada): Solicitação negada.

---

© 2026 - Desenvolvido por **Eduardo Erig**.

