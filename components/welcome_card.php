<?php
/**
 * Boas-vindas do primeiro acesso: três passos curtos do perfil, na tela inicial de cada um
 * (Minhas requisições, Caixa do setor, Painel). Nasce escondido; o script.js mostra se a pessoa
 * ainda não tocou em "Entendi" neste navegador (localStorage, chave por usuário).
 */
if (!function_exists('renderWelcomeCard')) {
    function renderWelcomeCard(string $role, int $userId): string
    {
        $b = fn(string $label) => '<strong>' . e($label) . '</strong>';
        if (isGestorRole($role)) {
            $steps = [
                'A ' . $b('Caixa') . ' mostra o que é do seu setor agora, com os urgentes primeiro.',
                'Abra o pedido: a faixa laranja no topo diz o próximo passo e qual botão usar.',
                'Se não for com vocês, use ' . $b('Encaminhar') . '. O pedido muda de setor sem se perder.',
            ];
        } elseif (isAdminRole($role)) {
            $steps = [
                $b('Comece por aqui') . ', no Painel, mostra o que precisa de você.',
                'Em ' . $b('Aprovar pedidos') . ', decida um por vez. Dá para desfazer por alguns segundos.',
                'Depois de aprovado, o setor assume. Você acompanha tudo pelo Painel.',
            ];
        } else {
            $steps = [
                'Toque em ' . $b('Nova requisição') . ' e escolha o setor pelo exemplo.',
                'A coordenação aprova e o setor atende. Você recebe um aviso a cada etapa.',
                'Dúvidas? Escreva na conversa do pedido. Quem está resolvendo responde ali.',
            ];
        }
        $items = '';
        foreach ($steps as $i => $text) {
            $items .= '<li><span class="welcome__num" aria-hidden="true">' . ($i + 1) . '</span><span>' . $text . '</span></li>';
        }
        return '<section class="welcome card" data-welcome="' . $userId . '" aria-labelledby="welcomeTitle" hidden>'
            . '<div class="welcome__head">'
            .   '<h2 id="welcomeTitle">Boas-vindas ao SisReq</h2>'
            .   '<p>Três passos e você já sabe usar:</p>'
            . '</div>'
            . '<ol class="welcome__steps">' . $items . '</ol>'
            . '<div class="welcome__foot">'
            .   '<a href="ajuda" class="btn btn--ghost">Ver o guia</a>'
            .   '<button type="button" class="btn btn--primary" data-welcome-close>Entendi</button>'
            . '</div>'
            . '</section>';
    }
}
