<?php
/**
 * Escolha do setor parceiro pela coordenação: setor, prazo da parte e recado (os dois últimos só com
 * um setor escolhido). Usado no cartão de Aprovar e na página do pedido; partnerFields() do script.js lê.
 */
if (!function_exists('renderPartnerPicker')) {
    /**
     * @param array $areas     setores possíveis (RequestForwardService::availableAreas)
     * @param string $uid      parte única dos ids dos campos
     * @param int|null $areaId setor já escolhido (sugestão de quem pediu)
     * @param string $due      prazo inicial da parte (Y-m-d)
     * @param string $noneLabel texto da opção sem parceiro
     */
    function renderPartnerPicker(array $areas, string $uid, ?int $areaId, string $due, string $noneLabel = 'Sem setor parceiro'): string
    {
        $options = '<option value="">' . e($noneLabel) . '</option>';
        foreach ($areas as $area) {
            $options .= '<option value="' . (int)$area['id'] . '"' . ((int)$area['id'] === (int)$areaId ? ' selected' : '') . '>' . e($area['label']) . '</option>';
        }
        $uid = e($uid);
        return '<div class="partner-picker" data-partner-picker>'
            . '<div class="partner-picker__field"><label for="pp-area-' . $uid . '">Setor parceiro</label>'
            . '<select id="pp-area-' . $uid . '" data-partner-area>' . $options . '</select></div>'
            . '<div class="partner-picker__more" data-partner-more' . ($areaId ? '' : ' hidden') . '>'
            . '<div class="partner-picker__field"><label for="pp-due-' . $uid . '">Prazo da parte</label>'
            . '<input type="date" id="pp-due-' . $uid . '" data-partner-due value="' . e($due) . '" min="' . e(date('Y-m-d')) . '"></div>'
            . '<div class="partner-picker__field"><label for="pp-note-' . $uid . '">Recado ao setor <small>(opcional)</small></label>'
            . '<input type="text" id="pp-note-' . $uid . '" data-partner-note maxlength="1000" autocomplete="off" placeholder="O que este setor precisa fazer ou decidir"></div>'
            . '</div></div>';
    }
}
