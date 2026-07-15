<?php
require __DIR__ . '/auth.php';
// Check-in admin: players as team card grids (like the Caught screen).
// Two sections — "Still out" on top, "Checked in" at the bottom — each split by team.
// Click a card to open its form and register return time, loot and bonus points.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['pid'] ?? 0);
    if ($action === 'checkin') {
        $rt    = trim($_POST['return_time'] ?? '');
        $rt    = (hhmm_to_min($rt) === null) ? null : $rt;
        $loot  = max(0, min((int)($_POST['loot'] ?? 0), (int)$cfg['max_loot']));
        $caps  = max(0, (int)($_POST['captures'] ?? 0));
        $litter = empty($_POST['litter']) ? 0 : 1;
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET return_time = ?, loot = ?, litter = ?, captures = ? WHERE id = ?')
            ->execute([$rt, $loot, $litter, $caps, $pid]);
        if ($before) {
            $changes = [];
            foreach (['return_time' => $rt, 'loot' => $loot, 'litter' => $litter, 'captures' => $caps] as $f => $new) {
                $old = $before[$f];
                if ((string)$old !== (string)$new) {
                    $changes[$f] = ['old' => $old, 'new' => $new];
                }
            }
            audit_log('checkin', $pid, $changes);
        }
        $msg = 'Checked in.';
    } elseif ($action === 'undo_checkin') {
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET return_time = NULL WHERE id = ?')->execute([$pid]);
        audit_log('undo_checkin', $pid, [
            'return_time' => ['old' => $before['return_time'] ?? null, 'new' => null],
        ]);
        $msg = 'Check-in cleared.';
    } elseif ($action === 'disqualify') {
        $reason = trim($_POST['reason'] ?? '');
        $reason = $reason === '' ? null : mb_substr($reason, 0, 255);
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET disqualified = 1, dq_reason = ? WHERE id = ?')->execute([$reason, $pid]);
        audit_log('disqualify', $pid, [
            'disqualified' => ['old' => $before ? (int)$before['disqualified'] : '?', 'new' => 1],
            'dq_reason'    => ['old' => $before['dq_reason'] ?? null, 'new' => $reason],
        ]);
        $msg = 'Player disqualified.';
    } elseif ($action === 'undo_disqualify') {
        $before = get_participant($pid);
        db()->prepare('UPDATE participants SET disqualified = 0, dq_reason = NULL WHERE id = ?')->execute([$pid]);
        audit_log('undo_disqualify', $pid, [
            'disqualified' => ['old' => $before ? (int)$before['disqualified'] : '?', 'new' => 0],
        ]);
        $msg = 'Player reinstated.';
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    if (!headers_sent()) {
        header('Location: checkin.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
        exit;
    }
}
$msg = $msg ?: ($_GET['msg'] ?? '');

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// Split into still-out vs checked-in, each grouped by team id. Track the
// ordered set of all teams present (for the jump bar).
$pendingByTeam = [];
$doneByTeam    = [];
$allTeams      = []; // id => name, in display order
foreach ($players as $p) {
    $tid = (int)$p['team_id'];
    $allTeams[$tid] = $p['team_name'];
    $bucket = empty($p['return_time']) ? 'pendingByTeam' : 'doneByTeam';
    if (!isset(${$bucket}[$tid])) {
        ${$bucket}[$tid] = ['name' => $p['team_name'], 'players' => []];
    }
    ${$bucket}[$tid]['players'][] = $p;
}
$jump = [];
foreach ($allTeams as $tid => $name) {
    $jump[] = ['id' => $tid, 'name' => $name];
}

/**
 * Render the team-grouped card grids for one set of players.
 * $anchored is shared across sections so each team's anchor lands on its
 * first occurrence on the page (the "Still out" section when possible).
 */
function checkin_section(array $byTeam, array $cfg, string $emptyMsg, array &$anchored): void
{
    if (!$byTeam) {
        echo '<p class="muted">' . e($emptyMsg) . '</p>';
        return;
    }
    foreach ($byTeam as $tid => $grp) {
        $id = isset($anchored[$tid]) ? '' : ' id="' . e(team_anchor((int)$tid)) . '"';
        $anchored[$tid] = true;
        echo '<h3 class="teamhead"' . $id . '>' . e($grp['name']) . '</h3>';
        echo '<div class="catchgrid">';
        foreach ($grp['players'] as $p) {
            checkin_card($p, $cfg);
        }
        echo '</div>';
    }
}

/** One player card with its click-to-open check-in form. */
function checkin_card(array $p, array $cfg): void
{
    $out = is_caught_out($p, $cfg);
    $in  = !empty($p['return_time']);
    $dq  = is_disqualified($p);
    ?>
    <details class="checkcard <?= $in ? 'done' : '' ?> <?= $out ? 'caughtout' : '' ?> <?= $dq ? 'disqualified' : '' ?>" data-pid="<?= (int)$p['id'] ?>"
             data-filter="<?= e(mb_strtolower($p['team_name'] . ' ' . (int)$p['number'] . ' ' . full_name($p))) ?>">
        <summary>
            <?= avatar_html($p) ?>
            <div class="who">
                <div><strong><?= e($p['team_name']) ?> <?= (int)$p['number'] ?></strong></div>
                <div class="muted small"><?= e(full_name($p)) ?></div>
                <div class="summaryline">
                    <span class="badge <?= $dq || $out ? 'bad' : ((int)$p['captures'] ? 'warn' : 'ok') ?>"><?= e(status_label($p, $cfg)) ?></span>
                    <?php if ($in): ?>
                        <span class="ok-text">✓ <?= e(timing_label($p, $cfg)) ?> · <?= total_score($p, $cfg) ?> pts</span>
                    <?php else: ?>
                        <span class="muted">Not checked in</span>
                    <?php endif; ?>
                </div>
            </div>
        </summary>

        <form method="post" class="checkinpanel">
            <input type="hidden" name="action" value="checkin">
            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <label>Return time
                <input type="time" name="return_time" value="<?= e($p['return_time']) ?>" required>
            </label>
            <label>Treasure <small>(0–<?= (int)$cfg['max_loot'] ?>)</small>
                <span class="stepper">
                    <button type="button" class="ghost stepbtn" data-step="-1" aria-label="Less treasure">−</button>
                    <input type="number" name="loot" min="0" max="<?= (int)$cfg['max_loot'] ?>" value="<?= (int)$p['loot'] ?>" inputmode="numeric">
                    <button type="button" class="ghost stepbtn" data-step="1" aria-label="More treasure">+</button>
                </span>
            </label>
            <label class="checkrow">
                <input type="checkbox" name="litter" value="1" <?= !empty($p['litter']) ? 'checked' : '' ?>>
                Litter collected <small>(+<?= (int)$cfg['litter_points'] ?> pts)</small>
            </label>
            <label>Times caught <small>(<?= (int)$cfg['lives'] ?> = caught out)</small>
                <span class="stepper">
                    <button type="button" class="ghost stepbtn" data-step="-1" aria-label="Fewer catches">−</button>
                    <input type="number" name="captures" min="0" step="1" value="<?= (int)$p['captures'] ?>" inputmode="numeric">
                    <button type="button" class="ghost stepbtn" data-step="1" aria-label="More catches">+</button>
                </span>
            </label>
            <div class="panelbtns">
                <button type="submit" class="bigcheck"><?= $in ? 'Update' : 'Check in' ?></button>
            </div>
        </form>
        <?php if ($in): ?>
            <form method="post" class="undoform">
                <input type="hidden" name="action" value="undo_checkin">
                <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="ghost">Undo check-in</button>
            </form>
        <?php endif; ?>
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
            <form method="post" class="dqform">
                <input type="hidden" name="action" value="disqualify">
                <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                <input type="text" name="reason" maxlength="255" placeholder="Disqualify — reason" required>
                <button type="submit" class="danger small">Disqualify</button>
            </form>
        <?php endif; ?>
    </details>
    <?php
}

$inCount = 0;
foreach ($players as $p) { if (!empty($p['return_time'])) { $inCount++; } }
$anchored = [];

$fragment = isset($_GET['fragment']);
if (!$fragment) {
    $nav = 'checkin';
    require __DIR__ . '/header.php';
    ?>
    <h2>Check-in admin <span class="muted">— return time, treasure &amp; bonus</span></h2>
    <?php team_jump_bar($jump); ?>
    <input type="search" id="playerfilter" class="filterbox" placeholder="Filter by team or name…" autocomplete="off">
    <script src="<?= asset('filter.js') ?>" defer></script>
    <div id="live" data-page="checkin" data-wsport="8081">
    <?php
}
?>
<p class="muted">Click a player to register their return. <strong><?= $inCount ?></strong> of
    <strong><?= count($players) ?></strong> checked in.</p>

<?php if (!$players): ?>
    <p class="muted">No players yet. Add some on the <a href="participants.php">Players</a> screen.</p>
<?php else: ?>
    <h2 class="sectionhead">Still out</h2>
    <?php checkin_section($pendingByTeam, $cfg, 'Everyone is back at base.', $anchored); ?>

    <h2 class="sectionhead done-head">Checked in</h2>
    <?php checkin_section($doneByTeam, $cfg, 'No one has checked in yet.', $anchored); ?>
<?php endif; ?>
<?php
if (!$fragment) {
    echo '</div>'; // #live
    ?>
    <script>
    // Accordion: opening one check-in card closes the others.
    // Capture phase because the native "toggle" event does not bubble.
    document.addEventListener('toggle', function (e) {
        var d = e.target;
        if (d && d.classList && d.classList.contains('checkcard') && d.open) {
            document.querySelectorAll('details.checkcard[open]').forEach(function (other) {
                if (other !== d) { other.open = false; }
            });
        }
    }, true);

    // Stepper +/− buttons. Delegated so it keeps working after live refreshes
    // swap the card markup in. Buttons are type=button, so they never submit.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.stepbtn');
        if (!btn) { return; }
        var input = btn.parentNode.querySelector('input[type=number]');
        if (!input) { return; }
        var step = parseInt(btn.getAttribute('data-step'), 10) || 0;
        var val = parseInt(input.value, 10);
        if (isNaN(val)) { val = 0; }
        val += step;
        if (input.min !== '' && val < parseInt(input.min, 10)) { val = parseInt(input.min, 10); }
        if (input.max !== '' && val > parseInt(input.max, 10)) { val = parseInt(input.max, 10); }
        input.value = val;
    });
    </script>
    <?php
    require __DIR__ . '/footer.php';
}

