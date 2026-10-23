<?php
/**
 * Uma linha da tabela de Usuários e setores (pages/accounts.php).
 * Usa as variáveis da página: $u, $full_role_labels, $categories e $all_subdivisions.
 */
$isActive = ($u['status'] ?? 1) == 1;
$subIds = array_filter(explode(',', $u['all_subdivision_ids'] ?? ''));
?>
                    <tr class="user-row ac-row<?= $isActive ? '' : ' is-inactive' ?>"
                        data-role="<?= e($u['role']) ?>"
                        data-subdivisions="<?= htmlspecialchars(implode(',', $subIds)) ?>">

                        <!-- Usuário -->
                        <td class="ac-td">
                            <div class="ac-person">
                                <div class="ac-avatar" aria-hidden="true">
                                    <?= e(mb_strtoupper(mb_substr((string)$u['name'], 0, 1))) ?>
                                </div>
                                <div>
                                    <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                                    <div class="ac-id">Nº <?= (int)$u['id'] ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Login -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['login']) ?>"
                                   class="row-login-input ac-input"
                                   oninput="markRowDirty(this)">
                        </td>

                        <!-- WhatsApp -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                   class="row-phone-input ac-input"
                                   placeholder="5511999998888"
                                   maxlength="20"
                                   oninput="markRowDirty(this)"
                                   title="Formato internacional sem + (ex: 5511999998888)">
                        </td>

                        <!-- Perfil -->
                        <td class="ac-td">
                            <select class="row-role-select ac-input" onchange="markRowDirty(this)">
                                <?php foreach($full_role_labels as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $u['role'] == $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Setores -->
                        <td class="ac-td">
                            <?php if($u['all_sectors']): ?>
                                <div class="sector-list">
                                    <?php foreach(explode(', ', $u['all_sectors']) as $stitle):
                                        $lbl = $categories[$stitle] ?? ucfirst($stitle);
                                    ?>
                                        <span class="ac-pill"><?= e($lbl) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="sub-none">Nenhum</span>
                            <?php endif; ?>
                        </td>

                        <!-- Subdivisões -->
                        <td class="ac-td">
                            <div class="sub-popover-wrap">

                                <!-- Exibição compacta: pills + botão editar -->
                                <div class="sub-display" role="button" tabindex="0" onclick="toggleSubPopover(<?= $u['id'] ?>)">
                                    <?php
                                    $subNames = [];
                                    foreach ($all_subdivisions as $sub) {
                                        if (in_array((string)$sub['id'], $subIds, true)) {
                                            $subNames[] = htmlspecialchars($sub['name']);
                                        }
                                    }
                                    ?>
                                    <?php if ($subNames): ?>
                                        <?php foreach (array_slice($subNames, 0, 2) as $sn): ?>
                                            <span class="sub-pill"><?= $sn ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($subNames) > 2): ?>
                                            <span class="sub-pill sub-pill-more">+<?= count($subNames) - 2 ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="sub-none">Nenhuma</span>
                                    <?php endif; ?>
                                    <i class="fa-solid fa-pen-to-square sub-edit-icon" aria-hidden="true"></i>
                                </div>

                                <!-- Popover com checkboxes (oculto) -->
                                <div id="subPop_<?= $u['id'] ?>" class="sub-popover" style="display:none;">
                                    <div class="sub-pop-arrow"></div>
                                    <p class="sub-pop-title"><i class="fa-solid fa-layer-group"></i> Subdivisões</p>
                                    <!-- Editor oculto mantido para compatibilidade com saveRowAccount -->
                                    <div class="subdivision-editor" data-subdivision-editor data-user-id="<?= $u['id'] ?>">
                                        <?php foreach($all_subdivisions as $sub): ?>
                                            <label class="sub-pop-check-label">
                                                <input type="checkbox"
                                                       value="<?= $sub['id'] ?>"
                                                       <?= in_array((string)$sub['id'], $subIds, true) ? 'checked' : '' ?>
                                                       onchange="syncSubDisplay(<?= $u['id'] ?>)">
                                                <span><?= htmlspecialchars($sub['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="popover-actions">
                                        <button onclick="saveSubsAndClose(<?= $u['id'] ?>, this)" class="sub-pop-btn sub-pop-save"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                                        <button onclick="closeSubPopover(<?= $u['id'] ?>)" class="sub-pop-btn sub-pop-cancel">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Status -->
                        <td class="ac-td is-center">
                            <label class="switch">
                                <input type="checkbox" <?= $isActive ? 'checked' : '' ?> onchange="toggleUserStatus(<?= $u['id'] ?>, this.checked, this)" aria-label="Conta ativa">
                                <span class="slider round"></span>
                            </label>
                            <div class="ac-status<?= $isActive ? '' : ' ac-status--off' ?>">
                                <?= $isActive ? 'Ativa' : 'Inativa' ?>
                            </div>
                        </td>

                        <!-- Ações -->
                        <td class="ac-td is-center">
                            <div class="ac-actions">
                                <button onclick="saveRowAccount(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-save" title="Salvar alterações">
                                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                                </button>
                                <div class="pass-popover-wrap">
                                    <button onclick="togglePassPopover(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-key" title="Alterar senha">
                                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                                    </button>
                                    <div id="passPopover_<?= $u['id'] ?>" class="pass-popover" style="display:none;">
                                        <div class="pass-popover-arrow"></div>
                                        <p class="pass-popover-title">Nova senha</p>
                                        <input type="password" id="pop_newpass_<?= $u['id'] ?>" placeholder="Mínimo 8 caracteres" class="pass-popover-input" autocomplete="new-password">
                                        <div class="popover-actions">
                                            <button onclick="confirmPassChange(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-confirm">Confirmar</button>
                                            <button onclick="closePassPopover(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-cancel">Cancelar</button>
                                        </div>
                                    </div>
                                </div>
                                <button onclick="deleteUser(<?= $u['id'] ?>)" class="ac-btn ac-btn-del" title="Excluir usuário">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="row-save-state" aria-live="polite"></div>
                        </td>
                    </tr>
