/**
 * Combos com busca: todo <select data-combo> vira Tom Select (assets/vendor/tom-select-*).
 * Carregado só nas telas listadas em $comboPages (index.php). Tema em assets/css/combo.css.
 * O <select> original continua no formulário e com o valor certo; para focar use el.tomselect.focus().
 * Opções: data-combo-empty="texto" quando a busca não acha nada.
 */
(function () {
    'use strict';

    if (typeof window.TomSelect !== 'function') return;

    const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function initCombo(select) {
        if (select.tomselect) return select.tomselect;
        const empty = select.querySelector('option[value=""]');
        const noResults = select.dataset.comboEmpty || 'Nada encontrado';
        // Campo obrigatório vazio: o <select> original fica escondido, então o foco vai para o combo
        select.addEventListener('invalid', () => {
            if (select.tomselect) setTimeout(() => select.tomselect.focus(), 0);
        });
        return new TomSelect(select, {
            create: false,
            maxOptions: null,
            placeholder: empty ? empty.textContent.trim() : '',
            allowEmptyOption: false,
            hidePlaceholder: true,
            selectOnTab: true,
            render: {
                no_results: () => `<div class="no-results">${escapeHtml(noResults)}</div>`,
            },
        });
    }

    window.initCombo = initCombo;
    document.querySelectorAll('select[data-combo]').forEach(initCombo);
})();
