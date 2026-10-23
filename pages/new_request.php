<?php
/**
 * Nova requisição: escolher o tipo e preencher, na mesma tela.
 * Os tipos ficam em cima (viram uma faixa compacta depois da escolha) e o formulário abre logo abaixo.
 */
$qr = $pdo->query('SELECT * FROM ctd_requests_models WHERE `status` = "Y" ORDER BY id');
// O usuário escolhe pelo exemplo, não pelo nome do setor
$modelInfo = [
    'form_chamado_ti.php'  => ['icon' => 'fa-computer',           'sector' => 'ti',      'label' => 'TI',                 'examples' => 'Computador, internet, impressora, senha'],
    'form_servico.php'     => ['icon' => 'fa-screwdriver-wrench', 'sector' => 'service', 'label' => 'Manutenção',         'examples' => 'Conserto, elétrica, móveis, limpeza'],
    'form_compra.php'      => ['icon' => 'fa-cart-shopping',      'sector' => 'shop',    'label' => 'Compras',            'examples' => 'Materiais, produtos, orçamentos'],
    'form_color_print.php' => ['icon' => 'fa-print',              'sector' => 'xerox',   'label' => 'Impressão colorida', 'examples' => 'Certificados, cartazes coloridos'],
    'form_mkt.php'         => ['icon' => 'fa-bullhorn',           'sector' => 'mkt',     'label' => 'Marketing',          'examples' => 'Posts, banners, panfletos, convites'],
];
?>
<div class="page-head page-head--hide-mobile">
    <div>
        <h1>Nova requisição</h1>
    </div>
</div>

<div class="new-request" data-new-request>
    <section class="type-picker" aria-labelledby="tipo-titulo">
        <h2 id="tipo-titulo" class="type-picker__title">Que tipo de pedido?</h2>
        <div class="type-list">
            <?php foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $row):
                $info = $modelInfo[$row['form_name']] ?? ['icon' => 'fa-file-lines', 'sector' => 'mkt', 'label' => $row['name'], 'examples' => $row['descp']];
            ?>
                <button type="button" class="type-card" data-id="<?= (int)$row['id'] ?>" data-sector="<?= e($info['sector']) ?>" aria-pressed="false">
                    <i class="fa-solid <?= e($info['icon']) ?> type-card__icon" aria-hidden="true"></i>
                    <span class="type-card__text">
                        <strong><?= e($info['label']) ?></strong>
                        <span class="type-card__desc"><?= e($info['examples']) ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="form-panel" id="formContainer" aria-label="Formulário do pedido" hidden>
        <div id="dynamicForm"></div>
    </section>
</div>

<script>
    (function () {
        const layout = document.querySelector('[data-new-request]');
        const cards = layout.querySelectorAll('.type-card');
        const panel = document.getElementById('formContainer');
        const holder = document.getElementById('dynamicForm');
        const cache = {};
        const kept = ['title', 'details', 'end', 'obs'];

        cards.forEach(card => {
            card.addEventListener('click', async () => {
                if (card.getAttribute('aria-pressed') === 'true') return;

                // O que já foi escrito continua lá ao trocar de tipo
                const typed = {};
                kept.forEach(name => {
                    const field = holder.querySelector(`[name="${name}"]`);
                    if (field && field.value.trim() !== '') typed[name] = field.value;
                });

                cards.forEach(c => c.setAttribute('aria-pressed', c === card ? 'true' : 'false'));
                layout.classList.add('has-choice');
                panel.hidden = false;
                panel.dataset.sector = card.dataset.sector;

                let html = cache[card.dataset.id];
                if (!html) {
                    holder.innerHTML = '<div class="empty-state"><div class="spinner"></div><p>Abrindo formulário...</p></div>';
                    const formData = new FormData();
                    formData.append('action', 'get');
                    formData.append('id', card.dataset.id);
                    const data = await fetchAjax('api/get_form.php', formData);
                    if (!data || !data.success) {
                        holder.innerHTML = '<div class="empty-state"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>Não foi possível abrir o formulário. Tente de novo.</p></div>';
                        return;
                    }
                    html = cache[card.dataset.id] = data.form;
                }
                if (card.getAttribute('aria-pressed') !== 'true') return; // outro tipo foi escolhido enquanto carregava

                holder.innerHTML = html;
                Object.entries(typed).forEach(([name, value]) => {
                    const field = holder.querySelector(`[name="${name}"]`);
                    if (field) field.value = value;
                });
                if (typed.obs) holder.querySelector('.form-more')?.setAttribute('open', '');
                bindFormRequests();

                if (window.matchMedia('(min-width: 900px)').matches) {
                    holder.querySelector('#title')?.focus({ preventScroll: true });
                } else {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    })();
</script>
