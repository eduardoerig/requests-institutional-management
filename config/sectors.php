<?php
/**
 * Registro único dos setores que atendem pedidos. Tudo que precisa do nome, ícone, formulário
 * ou apelidos de um setor lê daqui (sectorRegistry() em includes/auth.php).
 *
 * Chave = slug do setor; a tabela de pedidos é ctd_<slug>_frm (requestTableName()).
 * - label: nome que a pessoa lê ("Manutenção")
 * - label_to: com artigo, para "Enviando para a Manutenção"
 * - icon: Font Awesome
 * - form_type: valor de "type" que o formulário envia para api/add_request.php
 * - form_file: arquivo em components/forms/
 * - card_label e examples: cartão do passo "Para qual setor?" da Nova requisição
 * - aliases: títulos de ctd_area que valem por este setor (minúsculas, sem e com acento)
 *
 * A ordem aqui é a ordem em que os setores aparecem nas telas (Painel, Usuários e setores).
 * Setor novo precisa também de tabela, formulário e perfil: não basta acrescentar aqui.
 */
return [
    'ti' => [
        'label' => 'TI',
        'label_to' => 'a TI',
        'icon' => 'fa-computer',
        'form_type' => 'ti',
        'form_file' => 'form_chamado_ti.php',
        'card_label' => 'TI',
        'examples' => 'Computador, internet, projetor, senha',
        'aliases' => ['ti'],
    ],
    'service' => [
        'label' => 'Manutenção',
        'label_to' => 'a Manutenção',
        'icon' => 'fa-screwdriver-wrench',
        'form_type' => 'service',
        'form_file' => 'form_servico.php',
        'card_label' => 'Manutenção',
        'examples' => 'Elétrica, móveis, ar, limpeza',
        'aliases' => ['service', 'manutencao', 'manutenção'],
    ],
    'shop' => [
        'label' => 'Compras',
        'label_to' => 'Compras',
        'icon' => 'fa-cart-shopping',
        'form_type' => 'shopping',
        'form_file' => 'form_compra.php',
        'card_label' => 'Compras',
        'examples' => 'Materiais e orçamentos',
        'aliases' => ['shop', 'compras'],
    ],
    'xerox' => [
        'label' => 'Reprografia',
        'label_to' => 'a Reprografia',
        'icon' => 'fa-print',
        'form_type' => 'colorPrint',
        'form_file' => 'form_color_print.php',
        'card_label' => 'Impressão colorida',
        'examples' => 'Certificados, cartazes coloridos',
        'aliases' => ['xerox', 'reprografia'],
    ],
    'mkt' => [
        'label' => 'Marketing',
        'label_to' => 'o Marketing',
        'icon' => 'fa-bullhorn',
        'form_type' => 'mkt',
        'form_file' => 'form_mkt.php',
        'card_label' => 'Marketing',
        'examples' => 'Posts, banners, convites',
        'aliases' => ['mkt', 'marketing'],
    ],
];
