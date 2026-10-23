<?php
$roleHelp = $_SESSION['role'] ?? 'solicitante';
$isApproverHelp = isAdminRole($roleHelp);
$isGestorHelp = isGestorRole($roleHelp);
?>
<div class="narrow stack">
    <div class="page-head page-head--hide-mobile">
        <h1>Como usar o sistema</h1>
    </div>

    <section class="panel">
        <div class="panel__head"><h2>Para pedir algo</h2></div>
        <div class="panel__body">
        <ol class="help-steps">
            <li><span class="help-num">1</span><div><strong>Toque no botão laranja "Novo"</strong> (no computador: "Nova requisição", no menu).</div></li>
            <li><span class="help-num">2</span><div><strong>Escolha o tipo de pedido</strong>: TI, manutenção, compras, impressão colorida ou marketing.</div></li>
            <li><span class="help-num">3</span><div><strong>Preencha o formulário</strong>: o que você precisa, os detalhes e para quando. Se estiver parando uma aula ou o trabalho, marque "É urgente" e diga por quê.</div></li>
            <li><span class="help-num">4</span><div><strong>Clique em "Enviar pedido"</strong>. O pedido abre na tela com o número dele, e você recebe um aviso no sino quando a situação mudar.</div></li>
        </ol>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h2>Para acompanhar</h2></div>
        <div class="panel__body">
        <p>Abra <a href="myRequests">Minhas requisições</a>. "Em andamento" mostra onde cada pedido está agora; "Concluídas" guarda os que terminaram. Dentro do pedido, a situação aparece em palavras:</p>
        <ul class="help-status">
            <?php foreach (statusLabels() as $code => $label): ?>
                <li><span class="status-pill status-pill--<?= e($code) ?>"><?= e($label) ?></span>
                    <?= e([
                        'P' => 'a coordenação ainda vai analisar o pedido.',
                        'Y' => 'foi aprovado e está na fila do setor.',
                        'W' => 'o setor está trabalhando nele.',
                        'C' => 'o pedido foi atendido.',
                        'N' => 'não foi aprovado; o motivo aparece na conversa do pedido.',
                        'F' => 'outro setor vai continuar o atendimento.',
                    ][$code]) ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p>Quando algo mudar, o <strong>sino <i class="fa-solid fa-bell" aria-hidden="true"></i></strong> no topo da tela mostra um aviso.</p>
        <p>Tem dúvida sobre um pedido? Abra o pedido e escreva na <strong>Conversa sobre o pedido</strong>.</p>
        </div>
    </section>

    <?php if ($isApproverHelp): ?>
    <section class="panel">
        <div class="panel__head"><h2>Para aprovar</h2></div>
        <div class="panel__body">
        <p>Abra <a href="approve">Aprovar pedidos</a>, abra um pedido e escolha <strong>Aprovar</strong> ou <strong>Recusar</strong>. Ao recusar, escreva o motivo: o solicitante recebe essa explicação.</p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($isGestorHelp): ?>
    <section class="panel">
        <div class="panel__head"><h2>Caixa do setor</h2></div>
        <div class="panel__body">
        <ol class="help-steps">
            <li><span class="help-num">1</span><div>Na <a href="inbox">Caixa do setor</a>, aba <strong>Novos</strong>, abra o pedido e clique em <strong>Começar atendimento</strong>.</div></li>
            <li><span class="help-num">2</span><div>Use a conversa do pedido para falar com o solicitante.</div></li>
            <li><span class="help-num">3</span><div>Ao terminar, clique em <strong>Concluir</strong>.</div></li>
            <li><span class="help-num">4</span><div>Se o pedido for de outro setor, use <strong>Encaminhar</strong>: <em>Transferir</em> passa o pedido; <em>Pedir ajuda</em> pede uma parte e volta para vocês.</div></li>
        </ol>
        </div>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__head"><h2>Esqueci minha senha</h2></div>
        <div class="panel__body">
        <p>Fale com o administrador do sistema. Ele cria uma senha temporária e você troca no primeiro acesso, em <a href="myAccount">Minha conta</a>.</p>
        </div>
    </section>
</div>
