    <div class="main">
        <div class="page-header">
            <div>
                <i class="fa-solid fa-file-circle-plus"></i>
                <h3>Nova requisição</h3>
            </div>
        </div>

        <p class="page-help"><strong>Passo 1:</strong> escolha o tipo de pedido. <strong>Passo 2:</strong> preencha o formulário e clique em "Enviar requisição".</p>

        <div class="home-split-layout">
            <!-- Passo 1: tipo de pedido -->
            <div class="request_models" role="list">
                <?php
                $qr = $pdo->query('SELECT * FROM ctd_requests_models WHERE `status` = "Y" ORDER BY id');
                // Ícone, cor e exemplos por formulário (texto simples para o usuário escolher sem dúvida)
                $modelInfo = [
                    'form_chamado_ti.php'   => ['icon' => 'fa-computer',          'color' => 'red',    'examples' => 'Computador, internet, impressora, senha'],
                    'form_servico.php'      => ['icon' => 'fa-screwdriver-wrench', 'color' => 'yellow', 'examples' => 'Conserto, elétrica, móveis, limpeza'],
                    'form_compra.php'       => ['icon' => 'fa-cart-shopping',      'color' => 'green',  'examples' => 'Materiais, produtos, orçamentos'],
                    'form_color_print.php'  => ['icon' => 'fa-print',              'color' => 'blue',   'examples' => 'Impressões coloridas, certificados'],
                    'form_mkt.php'          => ['icon' => 'fa-bullhorn',           'color' => 'gray',   'examples' => 'Posts, banners, panfletos, convites'],
                ];
                foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $info = $modelInfo[$row['form_name']] ?? ['icon' => 'fa-file-lines', 'color' => 'gray', 'examples' => $row['descp']];
                    ?>
                    <button type="button" class="card <?= e($info['color']) ?>" data-id="<?= (int)$row['id'] ?>" role="listitem">
                        <span class="card-icon"><i class="fa-solid <?= e($info['icon']) ?>"></i></span>
                        <span class="card-text">
                            <h3><?= e($row['name']) ?></h3>
                            <p><?= e($info['examples']) ?></p>
                        </span>
                        <i class="fa-solid fa-chevron-right card-arrow"></i>
                    </button>
                    <?php
                }
                ?>
            </div>

            <!-- Passo 2: formulário -->
            <div class="form-container" id="formContainer">
                <div class="form" id="dynamicForm">
                    <div class="empty-state">
                        <i class="fa-solid fa-hand-pointer"></i>
                        <p>Escolha ao lado o tipo de pedido.<br>O formulário aparece aqui.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        const cards = document.querySelectorAll('.request_models .card');
        const form = document.getElementById('dynamicForm');
        const formContainer = document.getElementById('formContainer');

        cards.forEach(card => {
            card.addEventListener('click', async (e) => {
                e.preventDefault();

                cards.forEach(c => { c.classList.remove('active'); c.setAttribute('aria-pressed', 'false'); });
                card.classList.add('active');
                card.setAttribute('aria-pressed', 'true');

                form.innerHTML = '<div class="empty-state"><div class="spinner" style="margin:0 auto 20px;"></div><p>Carregando formulário...</p></div>';

                const formData = new FormData();
                formData.append('action', 'get');
                formData.append('id', card.dataset.id);

                const data = await fetchAjax("config/getForm.php", formData);
                if (data && data.success) {
                    form.innerHTML = data.form;
                    bindFormRequests();
                    const title = form.querySelector('#title');
                    if (window.innerWidth <= 768 && formContainer) {
                        formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } else if (title) {
                        title.focus();
                    }
                } else {
                    form.innerHTML = '<div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>Não foi possível abrir o formulário. Tente de novo.</p></div>';
                }
            });
        });
    </script>
