<?php
/**
 * Nova requisição (UI 2.0).
 * Celular: 3 passos curtos (setor → o que precisa → urgência e revisão), com Voltar/Continuar fixos embaixo.
 * Computador (≥900px): tudo numa página, com a coluna "O que acontece depois".
 * O passo a passo é só apresentação (assets/js/pages/new_request.js); o formulário do setor
 * vem de api/get_form.php e é enviado pelo bindFormRequests() de assets/js/script.js.
 */
$qr = $pdo->query('SELECT * FROM ctd_requests_models WHERE `status` = "Y" ORDER BY id');
// O usuário escolhe pelo exemplo, não pelo nome do setor
$modelInfo = [];
foreach (sectorRegistry() as $slug => $s) {
    $modelInfo[$s['form_file']] = ['icon' => $s['icon'], 'sector' => $slug, 'label' => $s['card_label'], 'box' => $s['label'], 'examples' => $s['examples']];
}
$backHref = 'myRequests';
?>
<div class="nr" data-new-request data-step="1">
    <header class="nr-head">
        <a class="nr-head__back" href="<?= e($backHref) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Meus pedidos</a>
        <p class="nr-head__count" data-step-count>Passo 1 de 3</p>
        <h1 class="nr-head__title" data-step-title>Para qual setor?</h1>
        <h1 class="nr-head__title nr-head__title--desk">Novo pedido</h1>
        <ol class="nr-progress" aria-hidden="true">
            <li class="is-on"></li><li></li><li></li>
        </ol>
    </header>

    <div class="nr-layout">
        <div class="nr-main">
            <section class="nr-sectors type-picker" aria-labelledby="tipo-titulo">
                <h2 id="tipo-titulo" class="nr-sectors__title">1. Para qual setor?</h2>
                <div class="nr-sector-grid">
                    <?php foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $row):
                        $info = $modelInfo[$row['form_name']] ?? ['icon' => 'fa-file-lines', 'sector' => 'mkt', 'label' => $row['name'], 'box' => $row['name'], 'examples' => $row['descp']];
                    ?>
                        <button type="button" class="type-card nr-sector" data-id="<?= (int)$row['id'] ?>" data-sector="<?= e($info['sector']) ?>" data-sector-name="<?= e($info['box']) ?>" data-label="<?= e($info['label']) ?>" aria-pressed="false">
                            <span class="nr-sector__name"><i class="fa-solid <?= e($info['icon']) ?>" aria-hidden="true"></i> <?= e($info['label']) ?></span>
                            <span class="nr-sector__hint"><?= e($info['examples']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="field-error nr-sectors__error" data-sector-error role="alert" hidden>Escolha um setor para continuar.</p>
            </section>

            <section class="nr-form" id="formContainer" aria-label="Formulário do pedido" hidden>
                <div id="dynamicForm"></div>
            </section>
        </div>

        <aside class="nr-after" aria-labelledby="depoisTitulo">
            <h2 id="depoisTitulo">O que acontece depois</h2>
            <ol>
                <li>A coordenação da sua área aprova.</li>
                <li>O pedido entra na Caixa de <strong data-sector-name-out>do setor escolhido</strong>.</li>
                <li>Se precisarem de outro setor, eles chamam. Você acompanha tudo aqui.</li>
                <li>Você recebe um aviso a cada etapa.</li>
            </ol>
        </aside>
    </div>

    <div class="nr-nav" data-step-nav>
        <button type="button" class="btn nr-nav__back" data-step-back hidden>Voltar</button>
        <button type="button" class="btn btn--primary nr-nav__next" data-step-next>Continuar</button>
        <button type="button" class="btn btn--primary nr-nav__send" data-step-send hidden><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
    </div>
</div>
