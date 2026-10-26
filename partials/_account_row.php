<?php
/**
 * Uma pessoa na lista de Usuários e setores (pages/accounts.php).
 * Usa $p (pessoa já preparada pela página) e $accHasLastLogin.
 * Celular: cartão. PC: linha com Perfil, Área ou setor e Último acesso. Tocar abre a edição.
 */
?>
<li class="acc-row<?= $p['active'] ? '' : ' is-inactive' ?>" data-id="<?= (int)$p['id'] ?>" data-kind="<?= e($p['kind']) ?>" data-active="<?= $p['active'] ? '1' : '0' ?>"<?= $p['active'] ? '' : ' hidden' ?>>
    <button type="button" class="acc-person" data-acc-action="open-person" aria-haspopup="dialog">
        <span class="acc-avatar acc-avatar--<?= e($p['kind']) ?>" aria-hidden="true"><?= e($p['initials']) ?></span>
        <span class="acc-person__id">
            <span class="acc-person__name" data-acc-name><?= e($p['name']) ?></span>
            <span class="acc-person__login" data-acc-login><?= e($p['login']) ?></span>
        </span>
        <span class="acc-person__role">
            <span class="acc-role acc-role--<?= e($p['kind']) ?>"><i class="fa-solid <?= e($p['roleIcon']) ?>" aria-hidden="true"></i> <?= e($p['roleLabel']) ?></span>
            <?php if ($p['where'] !== '' && $p['kind'] !== 'admin'): ?>
                <span class="acc-where acc-only-mobile"><?= e($p['where']) ?></span>
            <?php endif; ?>
        </span>
        <span class="acc-person__where acc-only-desktop"><?= e($p['where'] !== '' ? $p['where'] : '—') ?></span>
        <span class="acc-person__last acc-only-desktop"><?= e($p['last']) ?></span>
        <i class="fa-solid fa-chevron-right acc-person__go acc-only-mobile" aria-hidden="true"></i>
    </button>
</li>
