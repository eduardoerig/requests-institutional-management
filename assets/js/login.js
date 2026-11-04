// Tela de login: mostrar/ocultar senha e o cartão de exemplo que mostra um pedido andando pelas etapas
(function () {
    const toggle = document.querySelector('#toggle_password');
    const password = document.querySelector('#password');
    if (toggle && password) {
        toggle.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Ocultar' : 'Mostrar';
            toggle.setAttribute('aria-pressed', String(show));
            password.focus();
        });
    }

    const card = document.querySelector('.auth-demo__card');
    if (!card) return;
    // O cartão só aparece no computador; sem movimento quando a pessoa pede menos animação
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const $ = (name) => card.querySelector(`[data-demo="${name}"]`);
    const el = {
        num: $('num'), sector: $('sector'), pill: $('pill'), title: $('title'),
        steps: [...$('steps').children], avatar: $('avatar'), msg: $('msg'),
    };

    // Pedidos de exemplo, um por setor, cada um passando pelas 4 etapas
    const examples = [
        { sector: 'TI', avatar: 'TI', title: 'Projetor da sala 12 sem imagem', work: 'Trocamos o cabo HDMI. Passo aí às 14h para testar com você.' },
        { sector: 'Manutenção', avatar: 'MA', title: 'Torneira pingando no banheiro do 2º andar', work: 'A peça chegou. Fazemos a troca amanhã cedo.' },
        { sector: 'Compras', avatar: 'CO', title: '5 resmas de papel A4 para a secretaria', work: 'Pedido feito ao fornecedor. Chega até sexta.' },
        { sector: 'Reprografia', avatar: 'RE', title: '120 cópias da avaliação do 9º ano', work: 'Impressão em andamento, fica pronta às 10h.' },
    ];
    const states = [
        { pill: 'P', label: 'Aguardando aprovação', msg: () => 'Pedido enviado. A coordenação decide e você recebe um aviso.' },
        { pill: 'Y', label: 'Aprovado', msg: (ex) => `Aprovado. Agora está na fila de ${ex.sector}.` },
        { pill: 'W', label: 'Em atendimento', msg: (ex) => ex.work },
        { pill: 'C', label: 'Concluído', msg: () => 'Pronto! Quem pediu já foi avisado.' },
    ];

    let current = 0;
    let step = 2;
    let number = 427;

    function render() {
        const ex = examples[current];
        const st = states[step];
        el.num.textContent = String(number);
        el.sector.textContent = ex.sector;
        el.title.textContent = ex.title;
        el.avatar.textContent = ex.avatar;
        el.msg.textContent = st.msg(ex);
        el.pill.className = `status-pill status-pill--${st.pill}`;
        el.pill.textContent = st.label;
        el.steps.forEach((li, i) => {
            li.classList.toggle('is-done', i < step || (step === 3 && i === 3));
            li.classList.toggle('is-now', i === step && step !== 3);
        });
    }

    function tick() {
        if (step < 3) {
            step += 1;
            render();
            return;
        }
        // Troca de pedido: some, muda o conteúdo, volta
        card.classList.add('is-leaving');
        setTimeout(() => {
            current = (current + 1) % examples.length;
            number += 1;
            step = 0;
            render();
            card.classList.remove('is-leaving');
        }, 350);
    }

    let timer = setInterval(tick, 2600);
    // Aba em segundo plano: para o ciclo para não gastar processamento
    document.addEventListener('visibilitychange', () => {
        clearInterval(timer);
        if (!document.hidden) timer = setInterval(tick, 2600);
    });
})();
