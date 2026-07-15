<?php
// Audit trail: browse the log of every game-state change (check-ins, captures).
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

// Ensure the table exists (for databases created before this feature).
db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NULL,
    action         VARCHAR(40) NOT NULL,
    detail         JSON NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_participant (participant_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$totalRows = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$rows = db()->prepare(
    'SELECT a.*, p.first_name, p.last_name, p.number AS player_number, t.name AS team_name
     FROM audit_log a
     LEFT JOIN participants p ON p.id = a.participant_id
     LEFT JOIN teams t ON t.id = p.team_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT ? OFFSET ?'
);
$rows->execute([$limit, $offset]);
$rows = $rows->fetchAll();

$actionLabels = [
    'checkin'          => 'Checked in',
    'undo_checkin'     => 'Undo check-in',
    'caught_inc'       => 'Caught +1',
    'caught_dec'       => 'Caught −1',
    'disqualify'       => 'Disqualified',
    'undo_disqualify'  => 'Reinstated',
    'award_add'        => 'Points added',
    'award_delete'     => 'Points removed',
    'game_reset'       => 'Game reset',
];

$nav = 'audit';
$no_live = true;
require __DIR__ . '/header.php';
?>
<h2>Audit trail <span class="muted">— <?= $totalRows ?> entries</span></h2>

<?php if (empty($rows)): ?>
    <p class="muted">No audit entries yet. Changes to check-ins and captures will appear here.</p>
<?php else: ?>

<table class="grid">
<thead>
<tr>
    <th>Time</th>
    <th>Action</th>
    <th>Player</th>
    <th>Changes</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td class="nowrap small"><?= e($r['created_at']) ?></td>
    <td>
        <span class="badge <?= match($r['action']) {
            'checkin' => 'ok', 'undo_checkin' => 'warn',
            'caught_inc' => 'bad', 'caught_dec' => 'warn',
            'disqualify' => 'bad', 'undo_disqualify' => 'warn',
            'award_add' => 'ok', 'award_delete' => 'warn',
            'game_reset' => 'bad',
            default => ''
        } ?>"><?= e($actionLabels[$r['action']] ?? $r['action']) ?></span>
    </td>
    <td>
        <?php if ($r['first_name']): ?>
            <?= e($r['team_name'] ?? '') ?> #<?= (int)$r['player_number'] ?>
            — <?= e($r['first_name'] . ' ' . ($r['last_name'] ?? '')) ?>
        <?php elseif ($r['participant_id'] === null): ?>
            <span class="muted">All players</span>
        <?php else: ?>
            <span class="muted">Player #<?= (int)$r['participant_id'] ?></span>
        <?php endif; ?>
    </td>
    <td class="small">
        <?php
        $detail = $r['detail'] ? json_decode($r['detail'], true) : [];
        foreach ($detail as $field => $change): ?>
            <span class="nowrap"><?= e($field) ?>: <strong><?= e((string)($change['old'] ?? '—')) ?></strong> → <strong><?= e((string)($change['new'] ?? '—')) ?></strong></span>
        <?php endforeach; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php if ($totalPages > 1): ?>
<div style="display:flex;gap:.5rem;align-items:center;margin-top:.8rem;">
    <?php if ($page > 1): ?>
        <a class="btnlink" href="?page=<?= $page - 1 ?>">← Newer</a>
    <?php endif; ?>
    <span class="muted small">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
        <a class="btnlink" href="?page=<?= $page + 1 ?>">Older →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
