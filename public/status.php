<?php
// Public status board: team breakdown showing captures and check-in time only.
// No scores, and players are shown by first name + surname initial.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// Group by team id (for unique anchors / jump bar)
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
    $nav = 'status';
    $public_view = true; // hide admin nav + scoring config — safe to display publicly
    $no_live = true;     // no live.js / websockets — this page is proxied publicly
    require __DIR__ . '/header.php';
    ?>
    <h2>Team breakdown <span class="muted">— captures &amp; check-in times</span></h2>
    <p class="muted small">Updated at <strong id="updated-at"><?= date('H:i:s') ?></strong></p>
    <?php team_jump_bar($jump); ?>
    <div id="board">
    <?php
}
?>
<?php if (!$players): ?>
    <p class="muted">No players yet.</p>
<?php endif; ?>
<?php foreach ($byTeam as $tid => $grp): ?>
    <?php
        $back = 0;
        foreach ($grp['players'] as $pp) { if (!empty($pp['return_time'])) { $back++; } }
    ?>
    <h3 class="teamhead" id="<?= e(team_anchor($tid)) ?>"><?= e($grp['name']) ?>
        <span class="muted small"><?= $back ?>/<?= count($grp['players']) ?> back</span></h3>
    <table class="grid">
        <thead><tr><th>Player</th><th>Status</th><th>Checked in</th></tr></thead>
        <tbody>
        <?php foreach ($grp['players'] as $p): ?>
            <tr>
                <td><strong><?= e(short_name($p)) ?></strong></td>
                <td><span class="badge <?= is_disqualified($p) || is_caught_out($p, $cfg) ? 'bad' : ((int)$p['captures'] ? 'warn' : 'ok') ?>"><?= e(status_label($p, $cfg)) ?></span></td>
                <td>
                    <?php if (!empty($p['return_time'])): ?>
                        <?= e($p['return_time']) ?>
                    <?php else: ?>
                        <span class="muted">Not back</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?php
if (!$fragment) {
    echo '</div>'; // #board
    ?>
    <script>
    // Self-contained refresh: re-fetch just the board every 60s via a plain AJAX poll.
    (function () {
        var el = document.getElementById('board');
        if (!el) return;
        setInterval(function () {
            fetch('status.php?fragment=1', { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html !== null) {
                        el.innerHTML = html;
                        var ts = document.getElementById('updated-at');
                        if (ts) { var d = new Date(); ts.textContent = [d.getHours(),d.getMinutes(),d.getSeconds()].map(function(n){return String(n).padStart(2,'0');}).join(':'); }
                    }
                })
                .catch(function () {});
        }, 60000);
    })();
    </script>
    <?php
    require __DIR__ . '/footer.php';
}
