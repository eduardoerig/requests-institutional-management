<?php
$qr = $pdo->query('SELECT * FROM ctd_requests_models WHERE `status` = "Y" ORDER BY id');
// Ícone, cor e exemplos por formulário: o usuário escolhe pelo exemplo, não pelo nome do setor
$modelInfo = [
    'form_chamado_ti.php'  => ['icon' => 'fa-computer',           'color' => 'red',    'sector' => 'ti',      'label' => 'TI',             'examples' => 'Computador, internet, impressora, senha'],
    'form_servico.php'     => ['icon' => 'fa-screwdriver-wrench', 'color' => 'yellow', 'sector' => 'service', 'label' => 'Manutenção',     'examples' => 'Conserto, elétrica, móveis, limpeza'],
    'form_compra.php'      => ['icon' => 'fa-cart-shopping',      'color' => 'green',  'sector' => 'shop',    'label' => 'Compras',        'examples' => 'Materiais, produtos, orçamentos'],
    'form_color_print.php' => ['icon' => 'fa-print',              'color' => 'blue',   'sector' => 'xerox',   'label' => 'Impressão colorida', 'examples' => 'Certificados, cartazes coloridos'],
    'form_mkt.php'         => ['icon' => 'fa-bullhorn',           'color' => 'gray',   'sector' => 'mkt',     'label' => 'Marketing',      'examples' => 'Posts, banners, panfletos, convites'],
];
?>
<div class="page-head page-head--hide-mobile">
    <div>
        <h1>Nova requisição</h1>
        <p>Escolha o tipo de pedido e preencha o formulário.</p>
    </div>
</div>

<div class="new-request">
    <section aria-labelledby="passo1">
        <h2 id="passo1" class="muted" style="font-size:1rem; font-weight:700; margin-bottom:10px;">1. O que você precisa?</h2>
        <div class="request_models">
            <?php foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $row):
                $info = $modelInfo[$row['form_name']] ?? ['icon' => 'fa-file-lines', 'color' => 'gray', 'sector' => 'mkt', 'label' => $row['name'], 'examples' => $row['descp']];
            ?>
                <button type="button" class="card <?= e($info['color']) ?>" data-id="<?= (int)$row['id'] ?>" data-sector="<?= e($info['sector']) ?>" aria-pressed="false">
                    <span class="card-icon" aria-hidden="true"><i class="fa-solid <?= e($info['icon']) ?>"></i></span>
                    <span class="card-text">
                        <strong class="card-title"><?= e($info["label"]) ?></strong>
                        <span class="card-desc"><?= e($info["examples"]) ?></span>
                    </span>
                    <i class="fa-solid fa-chevron-right card-arrow" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section aria-labelledby="passo2" class="form-panel" id="formContainer">
        <h2 id="passo2" class="visually-hidden">2. Preencha o pedido</h2>
        <div id="dynamicForm">
            <div class="empty-state">
                <i class="fa-regular fa-hand-pointer" aria-hidden="true"></i>
                <p>Escolha o tipo de pedido.<br>O formulário aparece aqui.</p>
            </div>
        </div>
    </section>
</div>

<script>
    (function () {
        const cards = document.querySelectorAll('.request_models .card');
        const form = document.getElementById('dynamicForm');
        const formContainer = document.getElementById('formContainer');

        cards.forEach(card => {
            card.addEventListener('click', async () => {
                const layout = document.querySelector('.new-request');
                // Celular: tocar no tipo já escolhido reabre a lista para trocar
                if (layout.classList.contains('has-choice') && card.classList.contains('active') && window.innerWidth < 900) {
                    layout.classList.remove('has-choice');
                    return;
                }
                layout.classList.add('has-choice');
                cards.forEach(c => { c.classList.remove('active'); c.setAttribute('aria-pressed', 'false'); });
                card.classList.add('active');
                card.setAttribute('aria-pressed', 'true');
                formContainer.dataset.sector = card.dataset.sector;

                form.innerHTML = '<div class="empty-state"><div class="spinner" style="margin:0 auto 16px;"></div><p>Abrindo formulário...</p></div>';
                window.scrollTo({ top: 0, behavior: 'smooth' });

                const formData = new FormData();
                formData.append('action', 'get');
                formData.append('id', card.dataset.id);

                const data = await fetchAjax("config/getForm.php", formData);
                if (data && data.success) {
                    form.innerHTML = data.form;
                    bindFormRequests();
                    if (window.innerWidth >= 900) {
                        form.querySelector('#title')?.focus();
                    }
                } else {
                    form.innerHTML = '<div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>Não foi possível abrir o formulário. Tente de novo.</p></div>';
                }
            });
        });
    })();
</script>
