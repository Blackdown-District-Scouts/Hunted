<?php
// Caught admin: log captures while the game is running. Big, fast controls.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['pid'] ?? 0);
    if ($action === 'caught_inc') {
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET captures = captures + 1 WHERE id = ?')->execute([$pid]);
        $old = $before ? (int)$before['captures'] : '?';
        audit_log('caught_inc', $pid, ['captures' => ['old' => $old, 'new' => $old !== '?' ? $old + 1 : '?']]);
    } elseif ($action === 'caught_dec') {
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET captures = GREATEST(captures - 1, 0) WHERE id = ?')->execute([$pid]);
        $old = $before ? (int)$before['captures'] : '?';
        audit_log('caught_dec', $pid, ['captures' => ['old' => $old, 'new' => $old !== '?' ? max($old - 1, 0) : '?']]);
    } elseif ($action === 'disqualify') {
        $reason = trim($_POST['reason'] ?? '');
        $reason = $reason === '' ? null : mb_substr($reason, 0, 255);
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET disqualified = 1, dq_reason = ? WHERE id = ?')->execute([$reason, $pid]);
        audit_log('disqualify', $pid, [
            'disqualified' => ['old' => $before ? (int)$before['disqualified'] : '?', 'new' => 1],
            'dq_reason'    => ['old' => $before['dq_reason'] ?? null, 'new' => $reason],
        ]);
    } elseif ($action === 'undo_disqualify') {
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET disqualified = 0, dq_reason = NULL WHERE id = ?')->execute([$pid]);
        audit_log('undo_disqualify', $pid, [
            'disqualified' => ['old' => $before ? (int)$before['disqualified'] : '?', 'new' => 0],
        ]);
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    if (!headers_sent()) {
        header('Location: caught.php');
        exit;
    }
}

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// Group by team (keyed by id so anchors are unique)
$byTeam = [];
foreach ($players as $p) {
    $tid = (int)$p['team_id'];
    if (!isset($byTeam[$tid])) {
        $byTeam[$tid] = ['name' => $p['team_name'], 'players' => []];
    }
    $byTeam[$tid]['players'][] = $p;
}
$jump = [];
foreach ($byTeam as $tid => $grp) {
    $jump[] = ['id' => $tid, 'name' => $grp['name'], 'count' => count($grp['players'])];
}

$fragment = isset($_GET['fragment']);
if (!$fragment) {
    $nav = 'caught';
    require __DIR__ . '/header.php';
    ?>
    <h2>Caught admin <span class="muted">— log captures</span></h2>
    <p class="muted">Tap <strong>Caught</strong> when a player is taken. <?= (int)$cfg['lives'] ?> lives — being caught
        <?= (int)$cfg['lives'] ?> times means no time points (treasure still counts).</p>
    <?php team_jump_bar($jump); ?>
    <input type="search" id="playerfilter" class="filterbox" placeholder="Filter by team or name…" autocomplete="off">
    <script src="<?= asset('filter.js') ?>" defer></script>
    <div id="live" data-page="caught" data-wsport="8081">
    <?php
}
?>
<?php if (!$players): ?>
    <p class="muted">No players yet. Add some on the <a href="participants.php">Players</a> screen.</p>
<?php endif; ?>
<?php foreach ($byTeam as $tid => $grp): ?>
    <h3 class="teamhead" id="<?= e(team_anchor($tid)) ?>"><?= e($grp['name']) ?></h3>
    <div class="catchgrid">
        <?php foreach ($grp['players'] as $p): ?>
            <?php $out = is_caught_out($p, $cfg); $dq = is_disqualified($p); ?>
            <div class="catchcard <?= $out ? 'caughtout' : '' ?> <?= $dq ? 'disqualified' : '' ?>" data-pid="<?= (int)$p['id'] ?>"
                 data-filter="<?= e(mb_strtolower($p['team_name'] . ' ' . (int)$p['number'] . ' ' . full_name($p))) ?>">
                <div class="catchhead">
                    <?= avatar_html($p) ?>
                    <div>
                        <div class="who"><strong><?= e($p['team_name']) ?> <?= (int)$p['number'] ?></strong></div>
                        <div class="muted small"><?= e(full_name($p)) ?></div>
                    </div>
                </div>
                <div class="catchstatus">
                    <span class="badge <?= $dq || $out ? 'bad' : ((int)$p['captures'] ? 'warn' : 'ok') ?>"><?= e(status_label($p, $cfg)) ?></span>
                    <span class="muted small">caught <?= (int)$p['captures'] ?>×</span>
                </div>
                <div class="catchbtns">
                    <form method="post" class="inline">
                        <input type="hidden" name="action" value="caught_dec">
                        <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="ghost" title="Undo">−</button>
                    </form>
                    <form method="post" class="inline grow">
                        <input type="hidden" name="action" value="caught_inc">
                        <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="bigcatch">Caught</button>
                    </form>
                </div>
                <?php if ($dq): ?>
                    <div class="dqnote">
                        <span class="muted small">Disqualified<?= $p['dq_reason'] ? ': ' . e($p['dq_reason']) : '' ?></span>
                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="undo_disqualify">
                            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btnlink">Reinstate</button>
                        </form>
                    </div>
                <?php else: ?>
                    <details class="dqbox">
                        <summary class="muted small">Disqualify…</summary>
                        <form method="post" class="dqform">
                            <input type="hidden" name="action" value="disqualify">
                            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                            <input type="text" name="reason" maxlength="255" placeholder="Reason" required>
                            <button type="submit" class="danger small">Disqualify</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php
if (!$fragment) {
    echo '</div>'; // #live
    require __DIR__ . '/footer.php';
}
