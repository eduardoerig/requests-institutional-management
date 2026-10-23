// Tela de login: mostrar/ocultar senha e animação do caminho de um pedido (GSAP)
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

    const board = document.querySelector('.login-board');
    if (!board) return;

    const card = board.querySelector('.flow-card');
    const steps = [...board.querySelectorAll('.flow__step')];
    const fill = board.querySelector('.flow__fill');
    const packet = board.querySelector('.flow__packet');
    const badge = card.querySelector('.flow-card__done');
    const el = {
        num: card.querySelector('.flow-card__num'),
        status: card.querySelector('.flow-card__status'),
        statusText: card.querySelector('.flow-card__status-text'),
        title: card.querySelector('.flow-card__title'),
        sector: card.querySelector('.flow-card__sector'),
        sectorIcon: card.querySelector('.flow-card__sector > i'),
        sectorName: card.querySelector('.flow-card__sector-name'),
        log: card.querySelector('.flow-card__log'),
    };

    // Pedidos de exemplo, um por setor, que se revezam a cada volta
    const examples = [
        { sector: 'TI', icon: 'fa-desktop', title: 'Projetor da sala 12 sem imagem', who: 'pela professora Ana' },
        { sector: 'Manutenção', icon: 'fa-wrench', title: 'Torneira pingando no banheiro do 2º andar', who: 'pela coordenação do Fundamental' },
        { sector: 'Compras', icon: 'fa-cart-shopping', title: 'Papel A4 para a secretaria', who: 'pela secretaria' },
        { sector: 'Impressão', icon: 'fa-print', title: 'Provas do 9º ano, 120 cópias', who: 'pelo professor Carlos' },
        { sector: 'Marketing', icon: 'fa-bullhorn', title: 'Banner da Feira de Ciências', who: 'pela direção' },
    ];
    const states = [
        { cls: 'is-sent', label: 'Aguardando aprovação', log: (ex) => `Pedido enviado ${ex.who}.` },
        { cls: 'is-approved', label: 'Aprovado', log: () => 'Aprovado pela coordenação.' },
        { cls: 'is-working', label: 'Em andamento', log: (ex) => `Setor de ${ex.sector} começou o atendimento.` },
        { cls: 'is-done', label: 'Concluído', log: () => 'Pronto! Quem pediu já foi avisado.' },
    ];
    const positions = ['12.5%', '37.5%', '62.5%', '87.5%'];

    let current = 0;
    let number = 427;

    function showExample(ex) {
        el.num.textContent = `#${String(number).padStart(4, '0')}`;
        el.title.textContent = ex.title;
        el.sectorIcon.className = `fa-solid ${ex.icon}`;
        el.sectorName.textContent = ex.sector;
    }

    function setState(index) {
        const ex = examples[current];
        const state = states[index];
        states.forEach((s) => card.classList.remove(s.cls));
        card.classList.add(state.cls);
        el.statusText.textContent = state.label;
        el.log.textContent = state.log(ex);
        steps.forEach((step, i) => step.classList.toggle('is-on', i <= index));
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!window.gsap || reduceMotion) {
        // Sem animação: mostra o pedido já concluído, parado
        showExample(examples[0]);
        setState(3);
        fill.style.transform = 'scaleX(1)';
        return;
    }

    const gsap = window.gsap;

    function pulseStatus(tl, at) {
        tl.fromTo(el.status, { scale: 0.85 }, { scale: 1, duration: 0.45, ease: 'back.out(3)' }, at);
    }

    function advance(tl, index, at) {
        tl.to(packet, { left: positions[index], duration: 0.9, ease: 'power2.inOut' }, at)
          .to(fill, { scaleX: index / 3, duration: 0.9, ease: 'power2.inOut' }, at)
          .call(setState, [index], `${at}+=0.85`)
          .fromTo(steps[index].querySelector('.flow__dot'), { scale: 0.7 }, { scale: 1, duration: 0.5, ease: 'back.out(3)' }, `${at}+=0.85`);
        pulseStatus(tl, `${at}+=0.85`);
    }

    function runCycle() {
        const ex = examples[current];
        showExample(ex);
        setState(0);
        gsap.set(fill, { scaleX: 0 });
        gsap.set(packet, { left: positions[0], opacity: 0 });
        gsap.set(badge, { opacity: 0, scale: 0.4 });

        const tl = gsap.timeline({
            delay: 0.4,
            onComplete: () => {
                current = (current + 1) % examples.length;
                number += 1;
                runCycle();
            },
        });

        tl.fromTo(card, { y: 22, opacity: 0, scale: 0.97 }, { y: 0, opacity: 1, scale: 1, duration: 0.7, ease: 'power3.out' }, 0)
          .to(packet, { opacity: 1, duration: 0.3 }, 0.5);
        pulseStatus(tl, 0.5);

        advance(tl, 1, 1.8);
        advance(tl, 2, 3.8);
        advance(tl, 3, 5.8);

        tl.to(badge, { opacity: 1, scale: 1, duration: 0.5, ease: 'back.out(2.5)' }, 6.7)
          .to(packet, { opacity: 0, duration: 0.4 }, 7.2)
          .to(card, { y: -14, opacity: 0, duration: 0.5, ease: 'power2.in' }, 9)
          .call(() => steps.forEach((step) => step.classList.remove('is-on')), null, 9.4)
          .to(fill, { scaleX: 0, duration: 0.5, ease: 'power2.inOut' }, 9.2);
    }

    runCycle();

    // Aba em segundo plano: pausa tudo para não gastar processamento
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) gsap.globalTimeline.pause();
        else gsap.globalTimeline.resume();
    });
})();
