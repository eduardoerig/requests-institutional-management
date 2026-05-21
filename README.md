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

* 🔄 **Ciclo de Vida Completo**: Fluxo de status rigidamente orquestrado (`Pendente` → `Aprovada` → `Em Andamento` → `Concluída`/`Recusada`).
* 🔀 **Repasse Intersetorial (Cross-Sector)**: Capacidade de encaminhar uma requisição para outro departamento complementar, unificando a esteira e preservando todo o histórico do chat.
* 🔐 **Controle de Acesso (RBAC)**:
  * **Administrador**: Gestão total da plataforma, usuários, áreas e delegação de perfis.
  * **Admin de Subdivisão**: Autoridade delegada para sub-setores (ex: coordenações específicas).
  * **Gestor Operacional**: Focado inteiramente em resolver as demandas da sua área técnica.
  * **Solicitante**: Abertura guiada de demandas, visualização em tempo real e interação no chat.
* 📊 **Dashboard Analítico**: Painel administrativo com KPIs da operação, timeline de produtividade, status em tempo real e gráficos dinâmicos.
* 💬 **Chat Interativo e Logs**: Auditoria de movimentações e chat centralizado dentro de cada chamado para colaboração contínua.
* 📱 **Mobile UI/UX First**: Telas, painéis e cartões totalmente otimizados para smartphones, sem perdas visuais ou botões ocultos.

---

## 🛠️ Tecnologias Utilizadas

| Camada | Tecnologia / Padrão |
| :--- | :--- |
| **Backend** | PHP 8.x, PDO Seguro |
| **Banco de Dados** | MySQL Relacional |
| **Frontend UI** | HTML5, CSS3, Vanilla JS |
| **UX / Design** | Tipografia Outfit/Inter, Design Clean SaaS, Grids Fluídos |
| **Ícones / Gráficos**| FontAwesome 6, Chart.js |

---

## 📂 Arquitetura de Diretórios

```bash
📦 requests-institutional-management
 ┣ 📂 api/          # Endpoints assíncronos (AJAX) e processadores (JSON)
 ┣ 📂 assets/       # Estilos globais (style.css, mobile.css), Scripts e Imagens
 ┣ 📂 classes/      # Camada de Negócio e Repositórios (Ex: RequestManager.php)
 ┣ 📂 components/   # Fragmentos de Layout Reutilizáveis (Sidebar, Navbar)
 ┣ 📂 config/       # Variáveis de ambiente e conexão PDO
 ┣ 📂 migrations/   # Estrutura SQL do banco de dados relacional
 ┗ 📂 pages/        # Controladores e Views Principais da Aplicação
```

---

## ⚙️ Instalação e Configuração (Local)

1. **Clone o Repositório**:
   ```bash
   git clone https://github.com/eduardoerig/requests-institutional-management.git
   ```

2. **Prepare o Banco de Dados**:
   * Importe os esquemas SQL contidos no diretório `/migrations`.
   
3. **Configure a Conexão**:
   * Renomeie ou crie o arquivo em `/config/conn.php` e adicione os dados locais:
   ```php
   <?php
   $host = 'localhost';
   $dbname = 'nome_do_banco';
   $user = 'root';
   $pass = '';
   
   try {
       $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
       $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
       $pdo = $conn;
   } catch (PDOException $e) {
       die("System Offline.");
   }
   ?>
   ```

4. **Inicie o Servidor**:
   * Aloque o projeto no diretório base (`www` ou `htdocs`) do seu servidor web (Apache/Nginx).
   * Verifique se o módulo de **Rewrite (mod_rewrite)** do Apache está ligado para suportar as rotas amigáveis do `.htaccess`.

---

## 🚥 Pipeline e Nomenclatura de Status

O sistema adota letras chaves no backend para padronizar as condições globais de um chamado:

| Código | Nomenclatura | Descrição |
| :---: | :--- | :--- |
| `P` | **Pendente** | Na triagem da gerência geral. |
| `Y` | **Aprovada** | O.K. do administrador. A caminho da caixa do técnico. |
| `W` | **Em Andamento** | O técnico ou setor responsável aceitou e começou a tratar. |
| `F` | **Repassada** | Serviço delegado de um técnico para outro departamento. |
| `C` | **Concluída** | Fim da jornada. Solução registrada e notificada. |
| `N` | **Recusada** | Ticket invalidado pelos gestores. |

---

<div align="center">
  <p>Desenvolvido com foco na excelência de UI/UX e processos institucionais resolutos.</p>
  <b>Eduardo Erig &copy; 2026</b>
</div>
