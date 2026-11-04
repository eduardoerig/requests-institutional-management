<?php
/**
 * Testes de integração (HTTP real + banco), sem dependências.
 *
 *   php tests/run.php
 *
 * Requisitos: servidor rodando (TEST_BASE_URL, padrão http://localhost/requests2.0/),
 * contas de teste criadas por database/seed_test_users.php (senha em
 * database/test_accounts.local.txt ou TEST_PASSWORD). Use SOMENTE em banco de homologação.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://localhost/requests2.0/', '/') . '/';
$password = getenv('TEST_PASSWORD') ?: '';
if ($password === '') {
    $file = dirname(__DIR__) . '/database/test_accounts.local.txt';
    if (is_file($file) && preg_match('/:\s*(\S+)/', (string)file_get_contents($file), $m)) {
        $password = $m[1];
    }
}
if ($password === '') {
    fwrite(STDERR, "Senha das contas de teste não encontrada. Rode: php database/seed_test_users.php\n");
    exit(1);
}

// ------------------------------------------------------------------
// Infra mínima
// ------------------------------------------------------------------
final class Client
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'sisreq');
    }

    public function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $location];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST com o token CSRF da sessão */
    public function post(string $path, array $data = []): array
    {
        if ($this->csrf === '') {
            $this->refreshCsrf();
        }
        return $this->request('POST', $path, $data + ['csrf_token' => $this->csrf]);
    }

    public function refreshCsrf(): void
    {
        $page = $this->get('login');
        if (preg_match('/window\.CSRF_TOKEN = "([a-f0-9]+)"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
    }

    public function login(string $login, string $password): array
    {
        $this->refreshCsrf();
        $res = $this->post('api/login.php', ['action' => 'get', 'login' => $login, 'password' => $password]);
        // Após login a sessão ganha novo id e novo token
        $page = $this->get('myAccount');
        if (preg_match('/window\.CSRF_TOKEN = "([a-f0-9]+)"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $res;
    }
}

$results = ['pass' => 0, 'fail' => 0];
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failures;
    if ($ok) {
        $results['pass']++;
        echo "  ok   $name\n";
    } else {
        $results['fail']++;
        $failures[] = $name . ($detail !== '' ? " — $detail" : '');
        echo "  FALHA $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function snippet(array $res): string
{
    return 'HTTP ' . $res['status'] . ' ' . mb_substr(trim(strip_tags($res['body'])), 0, 160);
}

$createdIds = [];
$future = date('Y-m-d', strtotime('+5 days'));

// ------------------------------------------------------------------
section('1. Acesso sem login');
$anon = new Client($baseUrl);
foreach (['api/request_view.php?id=1&table=ti', 'api/request_forwards.php?action=areas', 'api/get_notifications.php', 'api/export_requests.php', 'print_request.php?id=1&table=ti'] as $path) {
    $res = $anon->get($path);
    check("sem login bloqueia $path", $res['status'] === 401, snippet($res));
}
$res = $anon->get('home');
check('página interna sem login mostra o login', str_contains($res['body'], 'frm_login'));

// ------------------------------------------------------------------
section('2. Arquivos internos não são servidos');
foreach (['scratch/', 'database/schema.sql', 'database/migrate.php', 'classes/RequestManager.php', 'pages/home.php', 'README.md', 'config/conn.local.php', 'config/conn.local.example.php', '.git/config', 'tests/run.php'] as $path) {
    $res = $anon->get($path);
    check("bloqueia $path", in_array($res['status'], [403, 404], true), 'HTTP ' . $res['status']);
}

// ------------------------------------------------------------------
section('3. Login');
$c = new Client($baseUrl);
$res = $c->login('teste_solicitante', 'senha-errada-123');
check('senha errada recusada com mensagem genérica', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'incorretos'), snippet($res));
$res = $c->login('usuario_que_nao_existe', 'x');
check('usuário inexistente recebe a MESMA mensagem', ($res['json']['message'] ?? '') === 'Usuário ou senha incorretos.', snippet($res));
$pdo->prepare('DELETE FROM login_attempts WHERE login IN (?, ?)')->execute(['teste_solicitante', 'usuario_que_nao_existe']);

$sol = new Client($baseUrl);
$res = $sol->login('teste_solicitante', $password);
check('solicitante entra', ($res['json']['success'] ?? false) === true, snippet($res));
$apr = new Client($baseUrl);
check('coordenação entra', ($apr->login('teste_aprovador', $password)['json']['success'] ?? false) === true);
$ti = new Client($baseUrl);
check('gestor TI entra', ($ti->login('teste_gestor_ti', $password)['json']['success'] ?? false) === true);
$shop = new Client($baseUrl);
check('gestor Compras entra', ($shop->login('teste_gestor_compras', $password)['json']['success'] ?? false) === true);
$adm = new Client($baseUrl);
check('admin entra', ($adm->login('teste_admin', $password)['json']['success'] ?? false) === true);

// ------------------------------------------------------------------
section('4. Proteções de segurança');
$res = $sol->request('POST', 'api/request_actions.php', ['id' => 1, 'table' => 'ti', 'action' => 'finish']);
check('POST sem token CSRF é recusado', $res['status'] === 403, snippet($res));

$solId = (int)$pdo->query("SELECT id FROM ctd_users WHERE login = 'teste_solicitante'")->fetchColumn();
$sol->post('api/update_user_admin.php?self=1', ['role' => 'admin', 'status' => 1, 'login' => 'hackeado']);
$row = $pdo->query("SELECT role, login FROM ctd_users WHERE id = $solId")->fetch();
check('solicitante NÃO vira admin pelo modo self', $row['role'] === 'solicitante' && $row['login'] === 'teste_solicitante', json_encode($row));

$res = $sol->post('api/update_user_admin.php', ['id' => $solId, 'role' => 'admin']);
check('solicitante não acessa edição de usuários', ($res['json']['success'] ?? true) === false);

// Senha própria: as regras da janela (8+, maiúscula, número, símbolo, diferente da atual)
$hashBefore = (string)$pdo->query("SELECT password FROM ctd_users WHERE id = $solId")->fetchColumn();
$res = $sol->post('api/update_user_admin.php?self=1', ['currentPass' => $password, 'password' => 'semregras1']);
check('senha própria fraca é recusada', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'maiúscula'), snippet($res));
$res = $sol->post('api/update_user_admin.php?self=1', ['currentPass' => $password, 'password' => $password]);
check('senha própria igual à atual é recusada', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'diferente'), snippet($res));
$res = $sol->post('api/update_user_admin.php?self=1', ['currentPass' => 'errada-123', 'password' => 'Nova@Senha99']);
check('senha atual errada é recusada', ($res['json']['success'] ?? true) === false, snippet($res));
check('senha não muda quando recusada', (string)$pdo->query("SELECT password FROM ctd_users WHERE id = $solId")->fetchColumn() === $hashBefore);

$res = $sol->post('api/create_user.php', ['new_name' => 'X', 'new_login' => 'x_hack', 'new_password' => '12345678']);
check('solicitante não cria usuários', ($res['json']['success'] ?? true) === false);

$res = $sol->get('home?status=' . urlencode("';alert(1)//"));
check('parâmetro status não é refletido sem escape', !str_contains($res['body'], "';alert(1)//"));

$res = $sol->post('api/approve_request.php', ['action' => 'post', 'id' => 1, 'table' => "ti_frm WHERE 1=1; --", 'value' => 'Y']);
check('solicitante não aprova (e tabela maliciosa não passa)', ($res['json']['success'] ?? true) === false, snippet($res));

// Sair: só POST + CSRF (sessão própria, para não derrubar a de $sol)
$out = new Client($baseUrl);
$out->login('teste_solicitante', $password);
$stillIn = fn() => $out->get('api/get_notifications.php')['status'] === 200;
$out->get('logout');
check('endereço logout por GET não desloga', $stillIn());
$res = $out->get('api/logout.php');
check('api/logout.php por GET é recusado', $res['status'] === 405 && $stillIn(), snippet($res));
$res = $out->request('POST', 'api/logout.php');
check('api/logout.php sem token CSRF é recusado', $res['status'] === 403 && $stillIn(), snippet($res));
$res = $out->post('api/logout.php');
check('api/logout.php com POST + CSRF desloga', ($res['json']['success'] ?? false) === true && !$stillIn(), snippet($res));

// ------------------------------------------------------------------
section('5. Criar requisição');
$base = ['type' => 'ti', 'title' => 'Teste automático', 'end' => $future, 'details' => 'Detalhes', 'priority' => 2];
$res = $sol->post('api/add_request.php', ['title' => ''] + $base);
check('sem título é recusado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'título'));
$res = $sol->post('api/add_request.php', ['end' => '2020-01-01'] + $base);
check('data no passado é recusada', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'passado'));
$res = $sol->post('api/add_request.php', ['type' => 'colorPrint', 'qtd' => '0', 'model' => 'A4', 'file' => 'a.pdf'] + $base);
check('impressão com 0 cópias é recusada', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/add_request.php', ['type' => 'mkt', 'model' => ''] + $base);
check('marketing sem aplicação é recusado', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/add_request.php', ['urgent' => '1', 'urgent_reason' => ''] + $base);
check('urgente sem motivo é recusado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'urgente'));

$res = $sol->post('api/add_request.php', ['title' => 'Teste automático <script>x</script>'] + $base);
$reqId = (int)($res['json']['id'] ?? 0);
check('requisição válida é criada', ($res['json']['success'] ?? false) === true && $reqId > 0, snippet($res));
$createdIds[] = ['ti', $reqId];
$hist = $pdo->query("SELECT COUNT(*) FROM request_history WHERE request_id = $reqId AND request_table = 'ti' AND action = 'Requisição criada'")->fetchColumn();
check('histórico registra a criação', (int)$hist === 1);
$notif = $pdo->query("SELECT COUNT(*) FROM notifications WHERE link = 'request_detail?id=$reqId&table=ti' AND title = 'Pedido nº $reqId para aprovar'")->fetchColumn();
check('aprovadores são avisados', (int)$notif >= 1);

$res = $sol->get("request_detail?id=$reqId&table=ti");
check('título com HTML aparece escapado no detalhe', !str_contains($res['body'], '<script>x</script>') && str_contains($res['body'], '&lt;script&gt;'));

$res = $sol->post('api/add_request.php', ['title' => 'Teste automático urgente', 'urgent' => '1', 'urgent_reason' => 'turma sem projetor'] + $base);
$urgentId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $urgentId];
$urgentRow = $urgentId ? $pdo->query("SELECT priority, obs FROM ctd_ti_frm WHERE id = $urgentId")->fetch(PDO::FETCH_ASSOC) : null;
check('urgente com motivo vira prioridade alta e guarda o motivo', $urgentRow && (int)$urgentRow['priority'] === 3 && str_starts_with((string)$urgentRow['obs'], 'Urgente: turma sem projetor'), json_encode($urgentRow));

$res = $sol->get('myRequests');
check('Minhas requisições mostra o pedido novo em Em andamento, aguardando a coordenação', str_contains($res['body'], 'Aguardando a coordenação') && str_contains($res['body'], 'Teste automático urgente'));

// ------------------------------------------------------------------
section('6. Quem pode ver');
$res = $ti->get("api/request_view.php?id=$reqId&table=ti");
check('setor não vê pedido ainda pendente (só depois de aprovado)', $res['status'] === 403, 'HTTP ' . $res['status']);
$res = $shop->get("api/request_view.php?id=$reqId&table=ti");
check('painel bloqueado para outro setor', $res['status'] === 403, 'HTTP ' . $res['status']);
$res = $shop->get("print_request.php?id=$reqId&table=ti");
check('impressão bloqueada para quem não pode ver', $res['status'] === 403, 'HTTP ' . $res['status']);

// ------------------------------------------------------------------
section('7. Aprovação');
$res = $ti->post('api/approve_request.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'Y']);
check('gestor não aprova', ($res['json']['success'] ?? true) === false);
$res = $apr->post('api/approve_request.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'N', 'reason' => '']);
check('recusar sem motivo é bloqueado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'motivo'));
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('não inicia atendimento antes da aprovação', ($res['json']['success'] ?? true) === false);
$res = $apr->post('api/approve_request.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'Y']);
check('coordenação aprova', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ti->get("api/request_view.php?id=$reqId&table=ti");
check('gestor TI vê a requisição aprovada no painel', $res['status'] === 200 && str_contains($res['body'], 'request-view'), 'HTTP ' . $res['status']);
check('painel não contém hash de senha', !str_contains($res['body'], 'password') && !str_contains($res['body'], '$2y$'));
$res = $adm->post('api/approve_request.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'N', 'reason' => 'dup']);
check('segunda decisão sobre o mesmo pedido é bloqueada', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'delete']);
check('solicitante não exclui pedido já aprovado', ($res['json']['success'] ?? true) === false);

// ------------------------------------------------------------------
section('8. Atendimento');
$res = $shop->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('gestor de Compras não atende pedido de TI', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('gestor TI começa atendimento', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('começar duas vezes é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('gestor TI conclui', ($res['json']['success'] ?? false) === true, snippet($res));
$status = $pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $reqId")->fetchColumn();
check('banco registra Concluída', $status === 'C', (string)$status);
$steps = $pdo->query("SELECT new_value FROM request_history WHERE request_id = $reqId AND request_table = 'ti' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('histórico completo (Pendente→Aprovada→Em atendimento→Concluída)', $steps === ['Pendente', 'Aprovada', 'Em atendimento', 'Concluída'], json_encode($steps, JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------------
section('9. Conversa');
$res = $sol->post('api/add_comment.php', ['id' => $reqId, 'table' => 'ti', 'comment' => '<img src=x onerror=alert(1)>']);
check('solicitante envia mensagem', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $sol->get("api/get_comments.php?id=$reqId&table=ti");
check('mensagem aparece escapada', !str_contains($res['body'], '<img src=x') && str_contains($res['body'], '&lt;img'));
$res = $shop->post('api/add_comment.php', ['id' => $reqId, 'table' => 'ti', 'comment' => 'intruso']);
check('quem não vê o pedido não comenta', ($res['json']['success'] ?? true) === false);

// ------------------------------------------------------------------
section('10. Cancelar pedido pendente');
$res = $sol->post('api/add_request.php', ['title' => 'Para cancelar'] + $base);
$cancelId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $cancelId];
$res = $sol->post('api/request_actions.php', ['id' => $cancelId, 'table' => 'ctd_ti_frm', 'action' => 'delete']);
check('solicitante cancela o próprio pedido pendente', ($res['json']['success'] ?? false) === true, snippet($res));
$left = $pdo->query("SELECT COUNT(*) FROM request_history WHERE request_id = $cancelId AND request_table = 'ti'")->fetchColumn();
check('cancelamento não deixa histórico órfão', (int)$left === 0);

// ------------------------------------------------------------------
section('10b. Regras corrigidas na revisão de go-live');
$sp = new Client($baseUrl);
$res = $sp->login('teste_solicitante', ' ' . $password . ' ');
check('senha com espaço nas pontas (colada) entra', ($res['json']['success'] ?? false) === true, snippet($res));
$pdo->prepare('DELETE FROM login_attempts WHERE login = ?')->execute(['teste_solicitante']);

$res = $apr->post('api/add_request.php', ['title' => 'Teste regra própria'] + $base);
$ownId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $ownId];
$res = $apr->post('api/approve_request.php', ['action' => 'post', 'id' => $ownId, 'table' => 'ti', 'value' => 'Y']);
check('coordenação não aprova o próprio pedido', $ownId > 0 && ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'feito por você'), snippet($res));
$res = $apr->get('approve');
check('o próprio pedido não aparece na fila de Aprovar', !str_contains($res['body'], 'Teste regra própria'));

$res = $sol->post('api/add_request.php', ['title' => 'Teste regra exclusão'] + $base);
$delId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $delId];
$apr->post('api/approve_request.php', ['action' => 'post', 'id' => $delId, 'table' => 'ti', 'value' => 'Y']);
$res = $apr->post('api/request_actions.php', ['id' => $delId, 'table' => 'ctd_ti_frm', 'action' => 'delete']);
check('coordenação não apaga pedido já aprovado', ($res['json']['success'] ?? true) === false, snippet($res));
check('pedido aprovado continua no banco', (int)$pdo->query("SELECT COUNT(*) FROM ctd_ti_frm WHERE id = $delId")->fetchColumn() === 1);

// Gestor de TI pede a Compras: ali ele é só quem pediu e não escreve na conversa entre setores
$res = $ti->post('api/add_request.php', ['type' => 'shopping', 'title' => 'Teste regra conversa', 'end' => $future, 'details' => 'Comprar canetas']);
$chatId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['shop', $chatId];
$res = $ti->post('api/add_comment.php', ['id' => $chatId, 'table' => 'shop', 'comment' => 'tentando o canal interno', 'channel' => 'internal']);
$chan = $chatId ? $pdo->query("SELECT channel FROM request_comments WHERE request_id = $chatId AND request_table = 'shop' ORDER BY id DESC LIMIT 1")->fetchColumn() : null;
check('quem pediu (perfil de setor) não grava no canal interno', $chatId > 0 && ($res['json']['success'] ?? false) === true && $chan === 'public', snippet($res) . ' canal=' . var_export($chan, true));

// ------------------------------------------------------------------
section('10c. Sessão, login e regras de setor (itens 7 a 17 da revisão)');
$res = $sol->post('api/add_request.php', ['urgent' => '1'] + $base); // sem o campo do motivo
check('urgente SEM o campo do motivo é recusado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'urgente'), snippet($res));

$res = $adm->post('api/create_sector.php', ['sector_title' => 'Setor de teste automático']);
check('criar setor pela tela está desligado', ($res['json']['success'] ?? true) === false && (int)$pdo->query("SELECT COUNT(*) FROM ctd_area WHERE title = 'Setor de teste automático'")->fetchColumn() === 0, snippet($res));

// Setor não exporta pedidos pendentes
$res = $sol->post('api/add_request.php', ['title' => 'Teste regra export pendente'] + $base);
$pendId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $pendId];
$res = $ti->get('api/export_requests.php');
check('setor não exporta pedido pendente', $pendId > 0 && !str_contains($res['body'], 'Teste regra export pendente'), 'HTTP ' . $res['status']);
$res = $adm->get('api/export_requests.php');
check('administração exporta o pendente', str_contains($res['body'], 'Teste regra export pendente'), 'HTTP ' . $res['status']);

// Subdivisão tirada pelo admin vale na hora, sem sair do sistema
$aprId = (int)$pdo->query("SELECT id FROM ctd_users WHERE login = 'teste_aprovador'")->fetchColumn();
$aprSubs = $pdo->query("SELECT id_subdivision FROM cfg_user_subdivision WHERE id_user = $aprId")->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare('DELETE FROM cfg_user_subdivision WHERE id_user = ?')->execute([$aprId]);
$res = $apr->post('api/approve_request.php', ['action' => 'post', 'id' => $pendId, 'table' => 'ti', 'value' => 'Y']);
check('coordenação tirada da subdivisão não aprova mais (sem sair do sistema)', $res['status'] === 403, snippet($res));
$ins = $pdo->prepare('INSERT INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)');
foreach ($aprSubs as $subId) {
    $ins->execute([$aprId, (int)$subId]);
}
$res = $apr->get('api/get_notifications.php');
check('coordenação devolvida à subdivisão continua com a sessão', $res['status'] === 200, 'HTTP ' . $res['status']);

// Troca de senha derruba as outras sessões; redefinição pelo admin derruba todas
$tmpLogin = 'tmp_teste_sessao';
$pdo->prepare('DELETE FROM ctd_users WHERE login = ?')->execute([$tmpLogin]);
$pdo->prepare("INSERT INTO ctd_users (name, login, password, role, status, force_reset) VALUES ('Teste Sessão', ?, ?, 'solicitante', 1, 0)")
    ->execute([$tmpLogin, password_hash($password, PASSWORD_DEFAULT)]);
$tmpId = (int)$pdo->lastInsertId();
$ca = new Client($baseUrl);
$cb = new Client($baseUrl);
$okA = ($ca->login($tmpLogin, $password)['json']['success'] ?? false) === true;
$okB = ($cb->login($tmpLogin, $password)['json']['success'] ?? false) === true;
check('conta temporária entra em dois aparelhos', $okA && $okB);
$res = $ca->request('POST', 'api/update_user_admin.php?self=1', ['password' => 'Outra#Senha9', 'currentPass' => $password, 'csrf_token' => $ca->csrf]);
check('troca a própria senha', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $cb->get('api/get_notifications.php');
check('a outra sessão cai depois da troca de senha', $res['status'] === 401 && ($res['json']['session_expired'] ?? false) === true, snippet($res));
$res = $ca->get('api/get_notifications.php');
check('a sessão que trocou a senha continua', $res['status'] === 200, 'HTTP ' . $res['status']);
$res = $adm->post('api/update_user_admin.php', ['action' => 'reset_password', 'id' => $tmpId]);
check('admin redefine a senha', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ca->get('api/get_notifications.php');
check('redefinição pelo admin derruba a sessão da pessoa', $res['status'] === 401, 'HTTP ' . $res['status']);
$res = $ca->get('myRequests');
check('página da sessão derrubada mostra a entrada com o motivo', str_contains($res['body'], 'frm_login') && str_contains($res['body'], 'Sua senha foi trocada'), 'HTTP ' . $res['status']);
$pdo->prepare('DELETE FROM ctd_users WHERE id = ?')->execute([$tmpId]);

// Sessão expirada: token novo e entrar de novo devolvem o token da sessão nova
$cx = new Client($baseUrl);
$res = $cx->get('api/csrf.php');
check('api/csrf.php devolve um token sem login', $res['status'] === 200 && preg_match('/^[a-f0-9]{64}$/', (string)($res['json']['csrf_token'] ?? '')) === 1, snippet($res));
$res = $cx->request('POST', 'api/login.php', ['action' => 'get', 'login' => 'teste_solicitante', 'password' => $password, 'csrf_token' => (string)($res['json']['csrf_token'] ?? '')]);
check('login devolve o token novo e o id (janela "Entre de novo")', ($res['json']['success'] ?? false) === true && !empty($res['json']['csrf_token']) && (int)($res['json']['user_id'] ?? 0) === $solId, snippet($res));
$res = $anon->post('api/add_comment.php', ['id' => $reqId, 'table' => 'ti', 'comment' => 'x']);
check('sem sessão a API responde session_expired (o script.js abre a janela)', $res['status'] === 401 && ($res['json']['session_expired'] ?? false) === true, snippet($res));

// Limite por IP somando logins diferentes
$pdo->exec("DELETE FROM login_attempts WHERE login LIKE 'tmp_ip_%'");
$pdo->exec("DELETE FROM login_attempts WHERE attempted_at > (NOW() - INTERVAL 15 MINUTE)");
$cip = new Client($baseUrl);
for ($i = 1; $i <= 20; $i++) {
    $cip->login("tmp_ip_$i", 'senha-errada');
}
$res = $cip->login('tmp_ip_21', 'senha-errada');
check('20 erros do mesmo IP em logins diferentes bloqueiam o IP (429)', $res['status'] === 429, snippet($res));
$pdo->exec("DELETE FROM login_attempts WHERE login LIKE 'tmp_ip_%'");

// ------------------------------------------------------------------
section('11. Encaminhar: transferir');
$res = $sol->post('api/add_request.php', ['title' => 'Teste repasse'] + $base);
$fwdReqId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $fwdReqId];
$apr->post('api/approve_request.php', ['action' => 'post', 'id' => $fwdReqId, 'table' => 'ti', 'value' => 'Y']);
$ti->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
$shopAreaId = (int)$pdo->query("SELECT id FROM ctd_area WHERE LOWER(title) IN ('shop', 'compras') LIMIT 1")->fetchColumn();
$lastFwd = fn() => (int)$pdo->query("SELECT id FROM request_forwards WHERE request_id = $fwdReqId AND request_table = 'ti' ORDER BY id DESC LIMIT 1")->fetchColumn();
$reqStatus = fn() => (string)$pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $fwdReqId")->fetchColumn();
$fwd = fn(array $extra) => $ti->post('api/request_forwards.php', $extra + ['action' => 'forward', 'id' => $fwdReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'transfer', 'message' => 'Isso é com Compras, precisa comprar a peça.']);

$res = $fwd(['message' => 'curto']);
check('encaminhar sem explicar é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $fwd(['mode' => 'qualquer']);
check('modo inválido é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'forward', 'id' => $fwdReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'transfer', 'message' => 'Compras tentando encaminhar algo que não é dela']);
check('setor que não é responsável não encaminha', ($res['json']['success'] ?? true) === false);

$res = $ti->get("api/request_forwards.php?action=areas&id=$fwdReqId&table=ti");
$areas = array_column($res['json']['data'] ?? [], null, 'id');
check('setores do Encaminhar vêm com a descrição', ($areas[$shopAreaId]['description'] ?? '') !== '' && !isset($areas[(int)$pdo->query("SELECT id FROM ctd_area WHERE LOWER(title) = 'ti' LIMIT 1")->fetchColumn()]), snippet($res));
check('Encaminhar diz quem é o responsável atual', ($res['json']['from'] ?? '') === 'TI', snippet($res));
$shopDesc = (string)($areas[$shopAreaId]['description'] ?? '');
$res = $ti->post('api/update_sector.php', ['id' => $shopAreaId, 'description' => 'Gestor tentando mudar']);
check('só o admin muda a descrição do setor', $res['status'] === 403, snippet($res));
$res = $adm->post('api/update_sector.php', ['id' => $shopAreaId, 'description' => str_repeat('x', 161)]);
check('descrição longa demais é recusada', ($res['json']['success'] ?? true) === false);
$res = $adm->post('api/update_sector.php', ['id' => $shopAreaId, 'description' => 'Descrição de teste 7790']);
$saved = (string)$pdo->query("SELECT description FROM ctd_area WHERE id = $shopAreaId")->fetchColumn();
check('admin muda a descrição do setor', ($res['json']['success'] ?? false) === true && $saved === 'Descrição de teste 7790', snippet($res));
$adm->post('api/update_sector.php', ['id' => $shopAreaId, 'description' => $shopDesc]);

$res = $fwd([]);
check('TI transfere para Compras', ($res['json']['success'] ?? false) === true, snippet($res));
$fwdId = $lastFwd();
check('pedido fica Encaminhada enquanto aguarda aceite', $reqStatus() === 'F', $reqStatus());
$notif = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $solId AND link = 'request_detail?id=$fwdReqId&table=ti'")->fetchColumn();
check('solicitante é avisado da transferência', (int)$notif >= 1);
$res = $fwd(['message' => 'Segundo envio com o primeiro ainda aberto']);
check('encaminhar de novo com um aberto é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $fwdId]);
check('solicitante não aceita encaminhamento', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => '']);
check('recusar sem motivo é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => 'Não é com Compras']);
check('Compras recusa com motivo', ($res['json']['success'] ?? false) === true, snippet($res));
check('recusa devolve para Em atendimento', $reqStatus() === 'W', $reqStatus());
$note = $pdo->query("SELECT response_note FROM request_forwards WHERE id = $fwdId")->fetchColumn();
check('motivo da recusa fica registrado', $note === 'Não é com Compras', (string)$note);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => 'De novo não']);
check('recusar duas vezes é bloqueado', ($res['json']['success'] ?? true) === false);

$fwd(['message' => 'Mandando de novo para cancelar']);
$fwdId = $lastFwd();
$res = $shop->post('api/request_forwards.php', ['action' => 'cancel', 'forward_id' => $fwdId]);
check('destino não cancela o envio', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_forwards.php', ['action' => 'cancel', 'forward_id' => $fwdId]);
check('TI cancela envio pendente', ($res['json']['success'] ?? false) === true, snippet($res));
check('cancelar devolve para Em atendimento', $reqStatus() === 'W', $reqStatus());

$sol->post('api/add_comment.php', ['id' => $fwdReqId, 'table' => 'ti', 'comment' => 'Recado do solicitante para a TI 7750']);
$fwd(['message' => 'Agora sim, comprar a peça para a sala 12']);
$fwdId = $lastFwd();
$res = $shop->get("api/request_view.php?id=$fwdReqId&table=ti");
check('Compras vê o pedido que chegou', $res['status'] === 200 && str_contains($res['body'], 'Agora sim'), 'HTTP ' . $res['status']);
// Transferência ainda não aceita: Compras conversa só com a TI, como quem ajuda
check('antes de aceitar, Compras não lê a conversa com quem pediu', !str_contains($res['body'], '7750'));
$shop->post('api/add_comment.php', ['id' => $fwdReqId, 'table' => 'ti', 'comment' => 'Compras pergunta antes de aceitar 7751', 'channel' => 'public']);
$ch = $pdo->query("SELECT channel FROM request_comments WHERE request_id = $fwdReqId AND request_table = 'ti' AND comment LIKE '%7751%'")->fetchColumn();
check('antes de aceitar, mensagem de Compras vai só para a TI', $ch === 'internal', (string)$ch);
$res = $ti->get("request_detail?id=$fwdReqId&table=ti");
check('TI vê o cartão da transferência pendente e conversa com Compras', str_contains($res['body'], 'Transferência para Compras') && str_contains($res['body'], 'Esperando Compras aceitar') && str_contains($res['body'], 'Você pediu') && str_contains($res['body'], '7751'));
$res = $adm->get("request_detail?id=$fwdReqId&table=ti");
check('admin lê a transferência sem "vocês"', str_contains($res['body'], 'Como administração') && !str_contains($res['body'], 'para vocês'));
$res = $shop->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $fwdId]);
check('Compras aceita a transferência', ($res['json']['success'] ?? false) === true, snippet($res));
check('aceite volta o pedido para Em atendimento', $reqStatus() === 'W', $reqStatus());
$res = $shop->get("request_detail?id=$fwdReqId&table=ti");
check('depois de aceitar, Compras (responsável) lê a conversa com quem pediu', str_contains($res['body'], '7750'));
check('depois de assumir, Compras conversa "Com TI" e não consigo mesma', str_contains($res['body'], 'Mensagem para TI') && !str_contains($res['body'], 'Mensagem para Compras'));
$res = $ti->get("request_detail?id=$fwdReqId&table=ti");
check('TI que transferiu só consulta a conversa com quem pediu', str_contains($res['body'], 'O pedido foi transferido para Compras'));
$res = $ti->post('api/add_comment.php', ['id' => $fwdReqId, 'table' => 'ti', 'comment' => 'TI depois de transferir 7760', 'channel' => 'public']);
check('TI que transferiu não escreve para quem pediu', $res['status'] === 403, snippet($res));
$res = $ti->post('api/add_comment.php', ['id' => $fwdReqId, 'table' => 'ti', 'comment' => 'TI fala com Compras 7761', 'channel' => 'internal']);
check('TI que transferiu ainda fala com Compras', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ti->get("api/get_comments.php?id=$fwdReqId&table=ti&thread=main");
check('recado do encaminhamento não aparece na conversa com quem pediu', !str_contains($res['body'], 'Agora sim, comprar'));
$res = $ti->get("api/get_comments.php?id=$fwdReqId&table=ti&thread=internal");
check('recado do encaminhamento aparece na conversa entre setores', str_contains($res['body'], 'Agora sim, comprar') && str_contains($res['body'], '7761'));
$res = $ti->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('TI não conclui depois de transferir', ($res['json']['success'] ?? true) === false);
$res = $sol->get("request_detail?id=$fwdReqId&table=ti");
check('solicitante não vê a mensagem interna', $res['status'] === 200 && !str_contains($res['body'], 'Agora sim, comprar'));
$res = $shop->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('Compras conclui', ($res['json']['success'] ?? false) === true, snippet($res));
check('pedido transferido termina Concluída', $reqStatus() === 'C', $reqStatus());

// ------------------------------------------------------------------
section('11b. Encaminhar: pedir ajuda');
require_once dirname(__DIR__) . '/classes/RequestInbox.php';
$tiAreaId = (int)$pdo->query("SELECT id FROM ctd_area WHERE LOWER(title) = 'ti' LIMIT 1")->fetchColumn();
// Em qual aba/grupo da Caixa de um setor o pedido aparece, e com que frase
$inboxPlace = function (int $areaId, int $id) use ($pdo): array {
    RequestInbox::clearCache(); // a Caixa fica guardada por requisição; aqui o estado muda entre as checagens
    foreach (RequestInbox::build($pdo, [$areaId])['tabs'] as $tab => $rows) {
        foreach ($rows as $r) {
            if ($r['table'] === 'ti' && (int)$r['id'] === $id) return [$tab, $r['group'], $r['context'][0]];
        }
    }
    return [null, null, null];
};
$res = $sol->post('api/add_request.php', ['title' => 'Teste ajuda'] + $base);
$helpReqId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $helpReqId];
$apr->post('api/approve_request.php', ['action' => 'post', 'id' => $helpReqId, 'table' => 'ti', 'value' => 'Y']);
RequestInbox::clearCache();
$fresh = array_values(array_filter(RequestInbox::build($pdo, [$tiAreaId])['tabs']['comecar'], fn($r) => $r['table'] === 'ti' && (int)$r['id'] === $helpReqId));
check('aprovado agora: em Começar como "Chegou agora"', $fresh && !empty($fresh[0]['is_fresh']), json_encode($fresh[0]['is_fresh'] ?? null));
$ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
// Conversa antiga: quem pediu fala com a TI antes de pedirem ajuda (quem ajuda nunca vê)
$sol->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Recado publico do solicitante 7731']);
$res = $ti->post('api/request_forwards.php', ['action' => 'forward', 'id' => $helpReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'help', 'message' => 'Precisamos de 2 cabos HDMI para terminar.']);
check('TI pede ajuda a Compras', ($res['json']['success'] ?? false) === true, snippet($res));
$helpFwd = (int)$pdo->query("SELECT id FROM request_forwards WHERE request_id = $helpReqId AND request_table = 'ti' ORDER BY id DESC LIMIT 1")->fetchColumn();
$helpStatus = fn() => (string)$pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $helpReqId")->fetchColumn();
check('pedir ajuda não muda a situação', $helpStatus() === 'W', $helpStatus());
$place = $inboxPlace($tiAreaId, $helpReqId);
check('na TI, o pedido vai para Esperando', $place[0] === 'esperando' && str_contains((string)$place[2], 'Compras'), json_encode($place, JSON_UNESCAPED_UNICODE));
$place = $inboxPlace($shopAreaId, $helpReqId);
check('em Compras, chega em Aceitar', $place[0] === 'aceitar' && $place[1] === 'chegaram', json_encode($place, JSON_UNESCAPED_UNICODE));
$res = $ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('não conclui com ajuda em aberto', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $helpFwd]);
check('Compras aceita ajudar', ($res['json']['success'] ?? false) === true, snippet($res));

// Conversa interna: quem ajuda não vê a conversa com quem pediu; quem pediu não vê a interna
$res = $ti->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Combinado interno entre setores 7732', 'channel' => 'internal']);
check('TI escreve na conversa interna', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $shop->get("api/get_comments.php?id=$helpReqId&table=ti");
check('quem ajuda vê a conversa interna', str_contains($res['body'], '7732'));
check('quem ajuda não vê a conversa com quem pediu', !str_contains($res['body'], '7731'));
$res = $shop->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Compras tentando falar com todos 7733', 'channel' => 'public']);
$ch = $pdo->query("SELECT channel FROM request_comments WHERE request_id = $helpReqId AND request_table = 'ti' AND comment LIKE '%7733%'")->fetchColumn();
check('mensagem de quem ajuda fica sempre interna', $ch === 'internal', (string)$ch);
$res = $sol->get("request_detail?id=$helpReqId&table=ti");
check('quem pediu não vê a conversa interna', $res['status'] === 200 && !str_contains($res['body'], '7732') && !str_contains($res['body'], '7733'));
$res = $ti->get("request_detail?id=$helpReqId&table=ti");
check('setor responsável vê as duas conversas', str_contains($res['body'], '7731') && str_contains($res['body'], '7732'));
$n = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $solId AND title LIKE 'Mensagem entre setores%' AND link = 'request_detail?id=$helpReqId&table=ti'")->fetchColumn();
check('quem pediu não é avisado da conversa interna', $n === 0, (string)$n);

// Conversa de quem pediu com quem ajuda (canal helper): os dois escrevem; o responsável só lê
$res = $sol->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Solicitante fala com Compras 7734', 'channel' => 'helper', 'area' => $shopAreaId]);
check('quem pediu escreve para quem ajuda', ($res['json']['success'] ?? false) === true, snippet($res));
$row = $pdo->query("SELECT channel, helper_area_id FROM request_comments WHERE request_id = $helpReqId AND request_table = 'ti' AND comment LIKE '%7734%'")->fetch(PDO::FETCH_ASSOC);
check('mensagem vai para a conversa com Compras', ($row['channel'] ?? '') === 'helper' && (int)($row['helper_area_id'] ?? 0) === $shopAreaId, json_encode($row));
$res = $shop->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Compras responde ao solicitante 7735', 'channel' => 'helper', 'area' => $shopAreaId]);
check('quem ajuda responde a quem pediu', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $shop->get("api/get_comments.php?id=$helpReqId&table=ti&thread=helper-$shopAreaId");
check('quem ajuda vê a conversa com quem pediu', str_contains($res['body'], '7734') && str_contains($res['body'], '7735'));
$res = $shop->get("request_detail?id=$helpReqId&table=ti");
check('quem ajuda continua sem ver a conversa com o responsável', $res['status'] === 200 && !str_contains($res['body'], '7731'));
$res = $sol->get("request_detail?id=$helpReqId&table=ti");
check('quem pediu vê uma conversa só, com o responsável e com quem ajuda', str_contains($res['body'], '7731') && str_contains($res['body'], '7735') && substr_count($res['body'], 'data-chat-thread=') === 0);
check('quem pediu não escolhe destino', !str_contains($res['body'], 'name="to"') && str_contains($res['body'], 'TI e Compras recebem'));
// Sem dizer o canal: com ajuda aberta, vai para TI e Compras (helper)
$res = $sol->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Solicitante escreve sem escolher 7740']);
$row = $pdo->query("SELECT channel, helper_area_id FROM request_comments WHERE request_id = $helpReqId AND request_table = 'ti' AND comment LIKE '%7740%'")->fetch(PDO::FETCH_ASSOC);
check('durante a ajuda, mensagem de quem pediu vai para TI e Compras', ($row['channel'] ?? '') === 'helper' && (int)($row['helper_area_id'] ?? 0) === $shopAreaId, json_encode($row));
$res = $shop->get("request_detail?id=$helpReqId&table=ti");
check('quem ajuda recebe a mensagem de quem pediu', str_contains($res['body'], '7740'));
$n = (int)$pdo->query("SELECT COUNT(*) FROM notifications n JOIN cfg_user_area cua ON cua.id_user = n.user_id WHERE cua.id_area = $tiAreaId AND n.message LIKE '%7740%'")->fetchColumn();
check('a TI é avisada da mensagem de quem pediu durante a ajuda', $n > 0, (string)$n);
$res = $ti->get("request_detail?id=$helpReqId&table=ti");
check('responsável tem só duas abas', substr_count($res['body'], 'data-chat-thread=') === 2, (string)substr_count($res['body'], 'data-chat-thread='));
check('responsável vê a bolinha quando quem pediu escreve', str_contains($res['body'], 'chat-threads__tab has-new'));
check('com a ajuda aceita, o cartão mostra a ajuda aceita', str_contains($res['body'], 'Ajuda de Compras') && str_contains($res['body'], 'Aceita por'));
check('cabeçalho sem a linha de etapas e com o selo da situação', !str_contains($res['body'], 'steps4') && str_contains($res['body'], 'class="rv-pill rv-pill--'));
$res = $apr->get("request_detail?id=$helpReqId&table=ti");
check('coordenação acompanha sem bolinha', $res['status'] === 200 && !str_contains($res['body'], 'has-new'));
$res = $ti->get("api/get_comments.php?id=$helpReqId&table=ti&thread=main");
check('responsável lê a conversa de quem pediu com quem ajuda', str_contains($res['body'], '7734') && str_contains($res['body'], '7735') && str_contains($res['body'], '7740'));
check('mensagem de quem pediu durante a ajuda vem marcada', str_contains($res['body'], 'Compras também vê'));
$res = $ti->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'TI tentando escrever 7736', 'channel' => 'helper', 'area' => $shopAreaId]);
check('responsável não escreve na conversa de quem pediu com quem ajuda', ($res['json']['success'] ?? true) === false);
$n = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $solId AND message LIKE '%7735%'")->fetchColumn();
check('quem pediu é avisado da resposta de quem ajuda', $n === 1, (string)$n);
$res = $shop->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('quem ajuda não conclui o pedido', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'return', 'forward_id' => $helpFwd, 'note' => '']);
check('devolver sem retorno é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'return', 'forward_id' => $helpFwd, 'note' => 'Cabos comprados, estão na secretaria.']);
check('Compras devolve com retorno', ($res['json']['success'] ?? false) === true, snippet($res));
// Ajuda devolvida: a conversa de quem pediu com quem ajudou fica só para consulta
$res = $sol->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Depois da ajuda 7738', 'channel' => 'helper', 'area' => $shopAreaId]);
$ch = $pdo->query("SELECT channel FROM request_comments WHERE request_id = $helpReqId AND request_table = 'ti' AND comment LIKE '%7738%'")->fetchColumn();
check('ajuda devolvida: quem pediu volta a escrever só para a TI', $ch === 'public', (string)$ch);
$res = $shop->get("request_detail?id=$helpReqId&table=ti");
check('ajuda devolvida: quem ajudou não recebe mais', !str_contains($res['body'], '7738'));
$res = $shop->post('api/add_comment.php', ['id' => $helpReqId, 'table' => 'ti', 'comment' => 'Depois da ajuda 7739', 'channel' => 'helper', 'area' => $shopAreaId]);
check('ajuda devolvida: quem ajudou não escreve mais para quem pediu', ($res['json']['success'] ?? true) === false);
$res = $sol->get("request_detail?id=$helpReqId&table=ti");
check('ajuda devolvida: quem pediu ainda lê a conversa com quem ajudou', str_contains($res['body'], '7735') && str_contains($res['body'], 'TI recebe sua mensagem'));
$place = $inboxPlace($tiAreaId, $helpReqId);
check('ajuda devolvida: volta para Em andamento da TI com o recado', $place[0] === 'andamento' && $place[1] === 'andamento' && str_contains((string)$place[2], 'devolveu'), json_encode($place, JSON_UNESCAPED_UNICODE));
$res = $ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('TI conclui depois do retorno', ($res['json']['success'] ?? false) === true, snippet($res));

// ------------------------------------------------------------------
section('11c. Caixa do setor');
RequestInbox::clearCache();
$inbox = RequestInbox::build($pdo, [$tiAreaId]);
$mismatch = [];
foreach ($inbox['tabs'] as $tab => $rows) {
    if (count($rows) !== $inbox['counts'][$tab]) $mismatch[] = $tab;
}
check('contadores das abas batem com as listas', !$mismatch, implode(', ', $mismatch));
check('cada pedido aparece numa aba só', count(array_unique(array_merge(...array_map(fn($rows) => array_map(fn($r) => $r['table'] . ':' . $r['id'], $rows), array_values($inbox['tabs']))))) === array_sum(array_map('count', $inbox['tabs'])));
$res = $ti->get('inbox?tab=historico');
check('Histórico mostra a transferência da TI', $res['status'] === 200 && str_contains($res['body'], 'Teste repasse') && str_contains($res['body'], 'Transferido para Compras'), 'HTTP ' . $res['status']);
$res = $ti->get('inbox?tab=enviados');
check('aba antiga (Enviados) abre a aba nova', $res['status'] === 200 && str_contains($res['body'], 'aria-current="page"'), 'HTTP ' . $res['status']);
$res = $ti->get('inbox?q=' . urlencode('Teste repasse'));
check('busca procura em todas as abas', $res['status'] === 200 && str_contains($res['body'], 'Teste repasse'), 'HTTP ' . $res['status']);
$res = $ti->get("request_detail?id=$fwdReqId&table=ti&from=inbox&tab=historico");
check('pedido aberto da Caixa mostra a fila (Anterior/Próximo)', $res['status'] === 200 && str_contains($res['body'], 'queue-nav__pager') && str_contains($res['body'], 'data-back='), 'HTTP ' . $res['status']);
$res = $ti->get('inbox?tab=recebidos');
check('aba antiga (Recebidos) abre Começar', $res['status'] === 200 && preg_match('/class="tab is-active[^"]*"[^>]*>\s*Começar/u', $res['body']) === 1, 'HTTP ' . $res['status']);
$res = $ti->get('inbox?tab=transferidos');
check('aba antiga (Transferidos) abre Histórico', $res['status'] === 200 && preg_match('/class="tab is-active[^"]*"[^>]*>\s*Histórico/u', $res['body']) === 1, 'HTTP ' . $res['status']);
$res = $ti->get('inbox?tab=esperando&filtro=urgentes');
check('filtro de urgentes não vale em Esperando', $res['status'] === 200 && !str_contains($res['body'], 'Mostrando só'), 'HTTP ' . $res['status']);
$res = $ti->get('inbox?tab=comecar&filtro=atrasados');
check('filtro de atrasados em Começar mostra a frase', $res['status'] === 200 && str_contains($res['body'], 'Mostrando só atrasados'), 'HTTP ' . $res['status']);
// Ordem da lista: grupo de prazo, urgentes primeiro, prazo mais próximo
$day = fn(int $d) => date('Y-m-d', strtotime("$d days"));
$sample = [
    ['id' => 1, 'date' => '', 'created_at' => '2026-01-01', 'priority' => 2],
    ['id' => 2, 'date' => $day(10), 'created_at' => '2026-01-01', 'priority' => 2],
    ['id' => 3, 'date' => $day(3), 'created_at' => '2026-01-01', 'priority' => 2],
    ['id' => 4, 'date' => $day(4), 'created_at' => '2026-01-01', 'priority' => 2, 'is_urgent' => true],
    ['id' => 5, 'date' => $day(0), 'created_at' => '2026-01-01', 'priority' => 2],
    ['id' => 6, 'date' => $day(-2), 'created_at' => '2026-01-01', 'priority' => 2],
    ['id' => 7, 'date' => $day(1), 'created_at' => '2026-01-01', 'priority' => 2],
];
usort($sample, [RequestInbox::class, 'byListOrder']);
check('lista: atrasados, hoje, amanhã, semana (urgente antes), adiante, sem prazo', implode(',', array_column($sample, 'id')) === '6,5,7,4,3,2,1', implode(',', array_column($sample, 'id')));
$found = RequestInbox::rowsFor($inbox, 'comecar', 'Teste');
$seenHistory = false;
$historyLast = true;
foreach ($found as $r) {
    if ($r['tab'] === 'historico') $seenHistory = true;
    elseif ($seenHistory) $historyLast = false;
}
check('busca põe o histórico no fim', $historyLast);
$res = $ti->get('approved');
check('rota antiga leva para a Caixa', $res['status'] === 302 && str_contains($res['headers'] ?? '', 'inbox'), 'HTTP ' . $res['status']);

// ------------------------------------------------------------------
section('12. Todas as páginas abrem para cada perfil');
$pages = ['home', 'new_request', 'myRequests', 'myRequests?tab=concluidas', 'myRequests?q=teste', 'approve', 'approve?q=teste', 'inbox', 'inbox?tab=esperando', 'inbox?tab=historico', 'inbox?q=teste', 'myAccount', 'accounts', 'ajuda', "request_detail?id=$reqId&table=ti", "request_detail?id=$reqId&table=ti&from=inbox&tab=historico", "request_detail?id=$reqId&table=ti&from=approve", 'home?status=C', 'home?q=teste'];
foreach (['solicitante' => $sol, 'coordenação' => $apr, 'gestor TI' => $ti, 'admin' => $adm] as $who => $client) {
    $broken = [];
    foreach ($pages as $page) {
        $res = $client->get($page);
        $complete = str_contains($res['body'], '</html>');
        $phpError = preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $res['body']);
        if (!in_array($res['status'], [200, 302], true) || ($res['status'] === 200 && (!$complete || $phpError))) {
            $broken[] = "$page (HTTP {$res['status']})";
        }
    }
    check("$who: páginas carregam completas e sem erro de PHP", !$broken, implode(', ', $broken));
}

// ------------------------------------------------------------------
section('13. Limpeza');
// O banco remoto fecha conexões ociosas: reconecta antes de limpar
require dirname(__DIR__) . '/config/conn.php';
// Inclui sobras de rodadas anteriores interrompidas
$leftovers = $pdo->query("SELECT 'ti' AS t, id FROM ctd_ti_frm WHERE created_by = $solId AND title IN ('Teste automático <script>x</script>', 'Teste automático urgente', 'Teste regra export pendente', 'Para cancelar', 'Teste repasse', 'Teste ajuda')")->fetchAll(PDO::FETCH_NUM);
foreach ($leftovers as $row) {
    $createdIds[] = [$row[0], (int)$row[1]];
}
foreach ($createdIds as [$t, $id]) {
    if ($id <= 0) {
        continue;
    }
    $pdo->prepare("DELETE FROM ctd_{$t}_frm WHERE id = ?")->execute([$id]);
    foreach (['request_comments', 'request_history', 'request_forwards'] as $tbl) {
        $pdo->prepare("DELETE FROM $tbl WHERE request_id = ? AND request_table = ?")->execute([$id, $t]);
    }
    $pdo->prepare('DELETE FROM notifications WHERE link = ?')->execute(["request_detail?id=$id&table=$t"]);
}
echo "  dados de teste removidos\n";

echo "\nResultado: {$results['pass']} ok, {$results['fail']} falha(s)\n";
if ($failures) {
    echo "Falhas:\n - " . implode("\n - ", $failures) . "\n";
}
exit($results['fail'] > 0 ? 1 : 0);
