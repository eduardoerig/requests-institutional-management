
    <div class="main">
        <div class="page-header">
            <div>
                <i class="fa-solid fa-file-circle-plus"></i>
                <h3>Nova requisição</h3>
            </div>
        </div>
        <div class="home-split-layout">
            <!-- Coluna da Esquerda: Menu de Categorias -->
            <div class="request_models">
                <?php
                $qr = $pdo->query('SELECT * FROM ctd_requests_models WHERE `status` = "Y"');
                $colors = [
                    1 => 'yellow',
                    2 => 'green',
                    3 => 'blue',
                    4 => 'red',
                    5 => 'gray'
                ];
                foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $colorClass = $colors[$row['id']] ?? 'gray';
                    echo '
                    <div class="card ' . $colorClass . '" data-id="' . $row['id'] . '">
                        <h3>' . $row['name'] . '</h3>
                        <p>' . $row['descp'] . '</p>
                    </div>
                ';
                }
                ?>
            </div>

            <!-- Coluna da Direita: Formulário Dinâmico -->
            <div class="form-container" id="formContainer">
                <div class="form" id="dynamicForm">
                    <div class="empty-state">
                        <i class="fa-solid fa-hand-pointer"></i>
                        <p>Selecione a requisição desejada acima e o formulário será carregado aqui.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        let cards = document.querySelectorAll('.request_models .card');
        let form = document.getElementById('dynamicForm');
        const formContainer = document.getElementById('formContainer');

        cards.forEach(card => {
            card.addEventListener('click', async (e) => {
                e.preventDefault();

                // Remove active de todos e adiciona no clicado
                cards.forEach(c => c.classList.remove('active'));
                card.classList.add('active');

                // Estado de carregamento
                form.innerHTML = '<div class="empty-state"><div class="spinner" style="margin:0 auto 20px;"></div><p>Carregando formulário...</p></div>';

                // Mobile: scroll para o formulário
                if (window.innerWidth <= 768 && formContainer) {
                    setTimeout(() => {
                        formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }

                let formData = new FormData();
                formData.append('action', 'get');
                formData.append('id', card.dataset.id);

                const data = await fetchAjax("config/getForm.php", formData);
                if (data.success) {
                    form.innerHTML = data.form;
                    bindFormRequests();
                    
                    // Mobile: scroll novamente após o form carregar
                    if (window.innerWidth <= 768 && formContainer) {
                        setTimeout(() => {
                            formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 150);
                    }
                }
            });
        });
    </script>
