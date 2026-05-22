<?php
/**
 * partials/_chat_feed.php
 * Feed de mensagens do chat, reutilizado pelo mobile e desktop.
 * Requer: $comments, $_SESSION['id']
 */
if (!isset($comments)) $comments = [];

function avatarColor(string $name): string {
    static $colors = ['#2c2b31','#3b82f6','#10b981','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#ec4899'];
    return $colors[abs(crc32($name)) % count($colors)];
}

if (count($comments) > 0):
    $lastDate = '';
    foreach ($comments as $c):
        $cd = date('d/m/Y', strtotime($c['created_at']));
        if ($cd !== $lastDate): $lastDate = $cd; ?>
        <div class="dp-chat-date-sep"><?= $cd ?></div>
        <?php endif;
        $mine = ($c['user_id'] == ($_SESSION['id'] ?? -1));
        $init = strtoupper(substr($c['user_name'], 0, 1)); ?>
        <div class="dp-msg <?= $mine ? 'mine' : 'theirs' ?>">
            <div class="dp-avatar" style="background:<?= avatarColor($c['user_name']) ?>;color:#fff"><?= $init ?></div>
            <div class="dp-bubble">
                <div class="dp-bubble-name"><?= htmlspecialchars($c['user_name']) ?></div>
                <?= nl2br(htmlspecialchars($c['comment'])) ?>
                <div class="dp-bubble-time"><?= date('H:i', strtotime($c['created_at'])) ?></div>
            </div>
        </div>
    <?php endforeach;
else: ?>
    <div class="dp-chat-empty">
        <i class="fa-regular fa-comments"></i>
        Nenhuma mensagem ainda.
    </div>
<?php endif; ?>
