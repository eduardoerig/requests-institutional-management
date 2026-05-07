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
                    document.getElementById("modalDescp").innerHTML = data.data.descp || '';
                    document.getElementById("modalDate").innerText = 'Data de entrega: ' + data.data.date;
                    document.getElementById("modalDate1").innerText = 'Data solicitada: ' + data.data.created_at;
                    document.getElementById("modalObs").innerText = 'Obs: ' + data.data.obs;
                    
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
                    let btnDetail = document.getElementById("btnViewDetail");
                    if (btnDetail) {
                        btnDetail.href = 'request_detail?id=' + data.data.id + '&table=' + data.data.table;
                    }
                }
            } catch (err) {
                console.error("Erro ao carregar requisição:", err);
            }
        });
    });
}

bindPopUpRequests();