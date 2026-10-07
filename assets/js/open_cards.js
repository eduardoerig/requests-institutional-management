function bindPopUpRequests() {
    let cards = document.querySelectorAll(".card_req");
    const modalReq = document.querySelector(".modal_req_embedded");
    const emptyState = document.getElementById("modalEmptyState");

    cards.forEach(card => {
        card.addEventListener("click", async (e) => {
            // Evitar que este script global rode na página de "Minhas Requisições"
            // pois lá existe uma lógica específica de solicitante.
            if (window.location.href.includes('myRequests')) {
                return;
            }

            e.preventDefault();

            // Set active state on the card
            cards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            // Se o modal já estiver aberto, dar um fade out rápido para trocar os dados
            if (modalReq && modalReq.style.display === 'flex') {
                modalReq.style.opacity = '0.5';
            }

            let formData = new FormData();
            formData.append('action', 'get');
            formData.append('id', card.dataset.id);
            formData.append('table', card.dataset.table);

            try {
                // Usar fetch direto para evitar o overlay global que causa a "piscada"
                const response = await fetch("config/getRequest.php", {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data && data.success) {
                    // Preencher todos os campos ANTES de mostrar ou fazer o fade in
                    document.getElementById("modalNome").innerText = data.data.user.name + ' - #' + data.data.id;
                    document.getElementById("modalTitle").innerText = data.data.title;
                    document.getElementById("modalDescp").innerHTML = data.data.descp || 'Sem descrição';
                    document.getElementById("modalDate").innerText = 'Precisa para: ' + (data.data.date || 'sem prazo');
                    document.getElementById("modalDate1").innerText = 'Pedido em: ' + data.data.created_at;
                    document.getElementById("modalObs").innerText = data.data.obs ? 'Observações: ' + data.data.obs : '';
                    
                    let alertEl = document.querySelector(".modal_alert");
                    if(alertEl) alertEl.style.display = data.data.urgent ? 'block' : 'none';
                    
                    let ridEl = document.querySelector(".Rid");
                    if(ridEl) ridEl.value = data.data.id;
                    
                    let rtypeEl = document.querySelector(".Rtype");
                    if(rtypeEl) rtypeEl.value = data.data.table;

                    if (modalReq) {
                        modalReq.className = "modal_req_embedded " + data.data.color;
                        modalReq.style.display = "flex";
                        
                        // Animação suave de entrada ou volta de opacidade
                        modalReq.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        modalReq.style.opacity = '1';
                        modalReq.style.transform = 'translateY(0)';
                    }

                    if (emptyState) emptyState.style.display = "none";

                    // Atualizar link de detalhes
                    const detailHref = 'request_detail?id=' + data.data.id + '&table=' + data.data.table;
                    ['btnViewDetail', 'btnViewDetailForward'].forEach(btnId => {
                        const btn = document.getElementById(btnId);
                        if (btn) btn.href = detailHref;
                    });

                    // Pedido encaminhado por outro setor: mostra Aceitar/Recusar no lugar de "Começar"
                    const standardActions = document.getElementById('standardActions');
                    const forwardActions = document.getElementById('forwardActions');
                    const isForwarded = card.dataset.forwarded === 'true' && card.dataset.forwardId;
                    if (standardActions && forwardActions) {
                        standardActions.style.display = isForwarded ? 'none' : 'flex';
                        forwardActions.style.display = isForwarded ? 'flex' : 'none';
                        forwardActions.dataset.forwardId = isForwarded ? card.dataset.forwardId : '';
                    }

                    // Celular: leva o usuário até o painel com os botões
                    if (window.innerWidth <= 900 && modalReq) {
                        modalReq.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            } catch (err) {
                if (typeof showToast === 'function') showToast('Não foi possível abrir o pedido. Tente de novo.', 'error');
            }
        });
    });
}

bindPopUpRequests();