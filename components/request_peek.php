<?php
// components/request_peek.php — GAVETA DO PEDIDO (side peek, padrão clean)
// Abre sobre uma lista sem tirar a pessoa da triagem. Uma por página; o JS é assets/js/peek.js
// e o CSS assets/css/peek.css (os dois entram pelas telas listadas em $peekPages no index.php).
//
// Quem abre: qualquer link com [data-peek] e estes data-*:
//   data-id, data-table          pedido (para a conversa e para "Abrir pedido")
//   data-href                    link da tela completa do pedido
//   data-title, data-sector, data-who, data-desc
//   data-status, data-status-code (P/Y/W/F/C/N → .status-pill--X)
//   data-prazo, data-prazo-kind (bad/warn/…), data-prio, data-urgent (1/0), data-parado, data-parado-late
// Opcionais: sem o atributo, o item some da gaveta.
//   data-prazo, data-prio, data-parado   somem da linha de meta
//   data-who                     some a seção "Quem pediu"
//   data-step (0..3, -1 recusado), data-where, data-where-kind (in/ok/bad)  mostram "Onde está"
// Outro link pode abrir a gaveta de uma linha: data-peek-from="<id da linha>".
// Ctrl/Cmd/Shift/botão do meio seguem o link normal (outra aba).
// Não usar a classe .request-view aqui: o polling de 15s do script.js substitui esse bloco.
?>
<!-- Gaveta de visão rápida: abre sobre a lista, sem sair da triagem -->
<div class="peek" id="peek" aria-hidden="true">
    <div class="peek__scrim" data-peek-close></div>
    <aside class="peek__panel" role="dialog" aria-modal="true" aria-labelledby="peek-title">
        <div class="peek__top">
            <span class="peek__crumb">
                <span class="peek__ico" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></span>
                <span id="peek-crumb">#0 · Setor</span>
            </span>
            <div class="peek__acts">
                <a class="peek__more" id="peek-open" href="#">
                    Abrir pedido <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                </a>
                <button type="button" class="peek__x" data-peek-close aria-label="Fechar">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <h2 class="peek__title" id="peek-title">—</h2>

        <!-- Itens sem o data-* correspondente na linha ficam escondidos; os pontos entre eles vêm do CSS -->
        <p class="peek__meta">
            <span class="status-pill" id="peek-status">—</span>
            <span class="peek__m" id="peek-prazo"><i class="fa-regular fa-clock" aria-hidden="true"></i><span>—</span></span>
            <span class="peek__m" id="peek-prio">—</span>
            <span class="peek__m" id="peek-parado">—</span>
        </p>

        <div class="peek__body">
            <!-- Etapas (data-step): para quem pediu acompanhar onde o pedido está -->
            <section class="peek__sec" id="peek-step-sec" hidden>
                <h3>Onde está</h3>
                <ol class="steps4" id="peek-steps"></ol>
                <p class="peek__where" id="peek-where"></p>
            </section>

            <section class="peek__sec" id="peek-desc-sec">
                <h3>O que precisa</h3>
                <p id="peek-desc"></p>
            </section>

            <section class="peek__sec" id="peek-who-sec">
                <h3>Quem pediu</h3>
                <div class="peek__who">
                    <span class="peek__av" id="peek-av" aria-hidden="true">·</span>
                    <span class="peek__who-text">
                        <strong id="peek-who">—</strong>
                        <small id="peek-sector">—</small>
                    </span>
                </div>
            </section>

            <!-- Conversa do pedido: a mesma linha do tempo da tela do pedido (api/get_comments.php) -->
            <section class="peek__sec peek-talk">
                <h3>Conversa</h3>
                <div class="rv-feed peek-talk__feed" id="peek-feed" role="log" aria-label="Conversa do pedido">
                    <p class="peek-talk__hint">Abra um pedido para ver a conversa.</p>
                </div>
            </section>
        </div>

        <footer class="peek__foot">
            <form class="peek-talk__form" id="peek-form" autocomplete="off">
                <label class="visually-hidden" for="peek-msg">Mensagem para a conversa do pedido</label>
                <textarea id="peek-msg" name="comment" rows="1" maxlength="3000" placeholder="Escreva uma mensagem"></textarea>
                <button type="submit" class="peek-talk__send" aria-label="Enviar mensagem">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </button>
            </form>
        </footer>
    </aside>
</div>
