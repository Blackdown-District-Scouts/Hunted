<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// Aggregate by team and build a player leaderboard.
$teams = [];
$board = [];
foreach ($players as $p) {
    $total = total_score($p, $cfg);
    $tn = $p['team_name'];
    if (!isset($teams[$tn])) {
        $teams[$tn] = ['name' => $tn, 'total' => 0, 'dq' => 0, 'players' => []];
    }
    $teams[$tn]['total'] += $total;
    $teams[$tn]['dq'] += is_disqualified($p) ? 1 : 0;
    $teams[$tn]['players'][] = $p + ['_total' => $total];
    $board[] = $p + ['_total' => $total];
}

// Rank teams by AVERAGE score per player, so team size doesn't matter.
foreach ($teams as &$t) {
    $n = count($t['players']);
    $t['avg'] = $n ? round($t['total'] / $n, 1) : 0;
}
unset($t);

usort($teams, fn($a, $b) => $b['avg'] <=> $a['avg']);
usort($board, fn($a, $b) => $b['_total'] <=> $a['_total']);

$fragment = isset($_GET['fragment']);
if (!$fragment) {
    $nav = 'score';
    require __DIR__ . '/header.php';
    ?>
    <h2>Scoreboard <a href="results_pdf.php" target="_blank" class="btnlink small" style="margin-left:.6rem;font-size:.85rem;">Export PDF</a></h2>
    <div id="live" data-page="score" data-wsport="8081">
    <?php
}
?>

<?php if (!$players): ?>
    <p class="muted">No players yet. Add <a href="teams.php">teams</a> and <a href="participants.php">players</a> to begin.</p>
<?php else: ?>

<section class="cols">
    <div>
        <h3>Team standings</h3>
        <table class="grid">
            <thead><tr><th>#</th><th>Team</th><th>Players</th><th>DQ</th><th>Avg score</th><th>Total</th></tr></thead>
            <tbody>
            <?php
            $teamRank = 0; $teamPrev = null;
            foreach ($teams as $i => $t):
                if ($t['avg'] !== $teamPrev) { $teamRank = $i + 1; $teamPrev = $t['avg']; }
                $tied = isset($teams[$i + 1]) && $teams[$i + 1]['avg'] === $t['avg']
                     || ($i > 0 && $teams[$i - 1]['avg'] === $t['avg']);
            ?>
                <tr>
                    <td><?= $teamRank ?><?= $tied ? '=' : '' ?></td>
                    <td><strong><?= e($t['name']) ?></strong></td>
                    <td><?= count($t['players']) ?></td>
                    <td class="muted"<?= $t['dq'] ? ' style="color:#ef8079;font-weight:600"' : '' ?>><?= (int)$t['dq'] ?></td>
                    <td><strong><?= rtrim(rtrim(number_format($t['avg'], 1), '0'), '.') ?></strong></td>
                    <td class="muted"><?= (int)$t['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <h3>Top players</h3>
        <table class="grid">
            <thead><tr><th>#</th><th>Player</th><th>Total</th></tr></thead>
            <tbody>
            <?php
            $top = array_slice(array_filter($board, fn($p) => $p['_total'] > 0), 0, 10);
            $pRank = 0; $pPrev = null;
            foreach ($top as $i => $p):
                if ($p['_total'] !== $pPrev) { $pRank = $i + 1; $pPrev = $p['_total']; }
                $tied = isset($top[$i + 1]) && $top[$i + 1]['_total'] === $p['_total']
                     || ($i > 0 && $top[$i - 1]['_total'] === $p['_total']);
            ?>
                <tr>
                    <td><?= $pRank ?><?= $tied ? '=' : '' ?></td>
                    <td>
                        <?= avatar_html($p, 'sm') ?>
                        <?= e($p['team_name']) ?> <?= (int)$p['number'] ?> <span class="muted">(<?= e(full_name($p)) ?>)</span>
                    </td>
                    <td><strong><?= (int)$p['_total'] ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<h3>Full breakdown</h3>
<?php foreach ($teams as $t): ?>
    <h4 class="teamhead"><?= e($t['name']) ?> — avg <?= rtrim(rtrim(number_format($t['avg'], 1), '0'), '.') ?> <span class="muted">(<?= (int)$t['total'] ?> total, <?= count($t['players']) ?> players)</span></h4>
    <table class="grid">
        <thead><tr><th>Player</th><th>Name</th><th>Status</th><th>Timing</th><th>Time pts</th><th>Treasure pts</th><th>Litter</th><th>Bonus</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($t['players'] as $p): ?>
            <?php $pdq = is_disqualified($p); ?>
            <tr class="<?= $pdq || is_caught_out($p, $cfg) ? 'caughtout' : '' ?>">
                <td><strong><?= e($p['team_name']) ?> <?= (int)$p['number'] ?></strong></td>
                <td><?= e(full_name($p)) ?></td>
                <td><span class="badge <?= $pdq || is_caught_out($p, $cfg) ? 'bad' : ((int)$p['captures'] ? 'warn' : 'ok') ?>"><?= e(status_label($p, $cfg)) ?></span></td>
                <td><?= $pdq ? '—' : e(timing_label($p, $cfg)) ?></td>
                <td><?= time_score($p, $cfg) ?></td>
                <td><?= loot_score($p, $cfg) ?></td>
                <td><?= litter_score($p, $cfg) ?></td>
                <td><?= $pdq ? 0 : ((int)$p['discretionary'] > 0 ? '+' : '') . (int)$p['discretionary'] ?></td>
                <td><strong><?= $p['_total'] ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>

<?php endif; ?>
<?php
if (!$fragment) {
    echo '</div>'; // #live
    require __DIR__ . '/footer.php';
}

