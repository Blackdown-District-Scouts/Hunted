<?php
require __DIR__ . '/auth.php';
// Discretionary points admin: add as many signed point awards as you like to a
// player, each with its own reason. participants.discretionary is kept in sync
// (as the SUM of the awards) so the scoreboard and PDFs pick the total up.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['pid'] ?? 0);
    if ($action === 'add_award') {
        $pts    = (int)($_POST['points'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $reason = mb_substr($reason, 0, 255);
        if ($pts === 0 || $reason === '') {
            $msg = 'Enter non-zero points and a reason.';
        } else {
            db()->prepare('INSERT INTO point_awards (participant_id, points, reason) VALUES (?, ?, ?)')
                ->execute([$pid, $pts, $reason]);
            recompute_discretionary($pid);
            audit_log('award_add', $pid, [
                'points' => ['old' => '—', 'new' => ($pts > 0 ? '+' : '') . $pts],
                'reason' => ['old' => '—', 'new' => $reason],
            ]);
            $msg = 'Points added.';
        }
    } elseif ($action === 'delete_award') {
        $aid = (int)($_POST['aid'] ?? 0);
        $row = db()->prepare('SELECT participant_id, points, reason FROM point_awards WHERE id = ?');
        $row->execute([$aid]);
        if ($award = $row->fetch()) {
            db()->prepare('DELETE FROM point_awards WHERE id = ?')->execute([$aid]);
            recompute_discretionary((int)$award['participant_id']);
            audit_log('award_delete', (int)$award['participant_id'], [
                'points' => ['old' => ((int)$award['points'] > 0 ? '+' : '') . (int)$award['points'], 'new' => '—'],
                'reason' => ['old' => $award['reason'], 'new' => '—'],
            ]);
            $msg = 'Entry removed.';
        }
    }
    if (!headers_sent()) {
        header('Location: points.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
        exit;
    }
}
$msg = $msg ?: ($_GET['msg'] ?? '');

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// All awards, newest first, grouped by participant for quick lookup.
$awardsByPlayer = [];
foreach (db()->query('SELECT * FROM point_awards ORDER BY created_at DESC, id DESC')->fetchAll() as $a) {
    $awardsByPlayer[(int)$a['participant_id']][] = $a;
}

// Group players by team (keyed by id for unique anchors).
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

$nav = 'points';
$no_live = true;
require __DIR__ . '/header.php';
?>
<h2>Discretionary points <span class="muted">— bonuses &amp; penalties</span></h2>
<p class="muted">Add as many entries as you like for each player; each needs a reason.
    The running total feeds into their score.</p>
<?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?>

<?php if (!$players): ?>
    <p class="muted">No players yet. Add some on the <a href="participants.php">Players</a> screen.</p>
<?php else: ?>
<?php team_jump_bar($jump); ?>
<input type="search" id="playerfilter" class="filterbox" placeholder="Filter by team or name…" autocomplete="off">
<script src="<?= asset('filter.js') ?>" defer></script>
<?php foreach ($byTeam as $tid => $grp): ?>
    <h3 class="teamhead" id="<?= e(team_anchor($tid)) ?>"><?= e($grp['name']) ?></h3>
    <div class="catchgrid">
        <?php foreach ($grp['players'] as $p): ?>
            <?php
            $awards = $awardsByPlayer[(int)$p['id']] ?? [];
            $total  = (int)$p['discretionary'];
            $tcls   = $total > 0 ? 'ok' : ($total < 0 ? 'bad' : '');
            ?>
            <details class="checkcard" data-pid="<?= (int)$p['id'] ?>"
                     data-filter="<?= e(mb_strtolower($p['team_name'] . ' ' . (int)$p['number'] . ' ' . full_name($p))) ?>">
                <summary>
                    <?= avatar_html($p) ?>
                    <div class="who">
                        <div><strong><?= e($p['team_name']) ?> <?= (int)$p['number'] ?></strong></div>
                        <div class="muted small"><?= e(full_name($p)) ?></div>
                        <div class="summaryline">
                            <span class="badge <?= $tcls ?>"><?= $total > 0 ? '+' : '' ?><?= $total ?> pts</span>
                            <span class="muted small"><?= count($awards) ?> <?= count($awards) === 1 ? 'entry' : 'entries' ?></span>
                        </div>
                    </div>
                </summary>

                <?php if ($awards): ?>
                    <ul class="awardlist">
                        <?php foreach ($awards as $a): $pts = (int)$a['points']; ?>
                            <li class="awarditem">
                                <span class="badge <?= $pts > 0 ? 'ok' : 'bad' ?> awardpts"><?= $pts > 0 ? '+' : '' ?><?= $pts ?></span>
                                <span class="awardreason"><?= e($a['reason']) ?></span>
                                <span class="muted small nowrap"><?= e(substr((string)$a['created_at'], 0, 16)) ?></span>
                                <form method="post" class="inline" onsubmit="return confirm('Remove this entry?')">
                                    <input type="hidden" name="action" value="delete_award">
                                    <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="ghost small">✕</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted small awardempty">No entries yet.</p>
                <?php endif; ?>

                <form method="post" class="checkinpanel awardform">
                    <input type="hidden" name="action" value="add_award">
                    <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                    <label>Points <small>(+ bonus / − penalty)</small>
                        <input type="number" name="points" step="1" required placeholder="e.g. 10 or -5">
                    </label>
                    <label>Reason
                        <input type="text" name="reason" maxlength="255" required placeholder="Why?">
                    </label>
                    <div class="panelbtns">
                        <button type="submit" class="bigcheck">Add points</button>
                    </div>
                </form>
            </details>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php endif; ?>
<?php
require __DIR__ . '/footer.php';
?>
