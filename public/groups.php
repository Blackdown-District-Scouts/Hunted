<?php
require __DIR__ . '/auth.php';
// Groups: split players into 8 balanced groups (A-H) at the team level.
// Each team spans at most 4 groups. Stored as team→group counts.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

// Ensure table exists (for DBs created before this feature).
db()->exec("CREATE TABLE IF NOT EXISTS team_groups (
    team_id      INT NOT NULL,
    group_label  CHAR(1) NOT NULL,
    player_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (team_id, group_label),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_group (group_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cfg = config();
$msg = '';

// ---------- Allocation algorithm ----------
// Returns array of [team_id, team_name, group_label, player_count]
function calculate_groups(): array
{
    $teamRows = db()->query(
        'SELECT t.id, t.name, COUNT(p.id) AS size
         FROM teams t JOIN participants p ON p.team_id = t.id
         GROUP BY t.id'
    )->fetchAll();

    if (!$teamRows) return [];

    $totalPlayers = array_sum(array_column($teamRows, 'size'));
    $cap = (int)ceil($totalPlayers / 8);
    $groupLabels = range('A', 'H');
    $groupTotals = array_fill_keys($groupLabels, 0);
    $result = [];

    // Sort all teams by size descending
    usort($teamRows, fn($a, $b) => (int)$b['size'] <=> (int)$a['size']);

    foreach ($teamRows as $team) {
        $teamSize = (int)$team['size'];
        // Every team can split into up to 4 groups; no minimum chunk size
        $maxChunks = min(4, $teamSize);

        $bestAssignment = null;
        $bestScore = PHP_INT_MAX;

        for ($nChunks = 1; $nChunks <= $maxChunks; $nChunks++) {
            $base = intdiv($teamSize, $nChunks);
            $extra = $teamSize % $nChunks;
            $chunks = [];
            for ($i = 0; $i < $nChunks; $i++) $chunks[] = $base + ($i < $extra ? 1 : 0);
            $chunkMax = max($chunks);

            $available = $groupLabels;
            usort($available, fn($a, $b) => $groupTotals[$a] <=> $groupTotals[$b]);
            $eligible = array_values(array_filter($available, fn($g) => $groupTotals[$g] + $chunkMax <= $cap));
            if (count($eligible) < $nChunks) continue;

            // Try all combinations of nChunks groups from eligible (up to 6 candidates)
            $candidates = array_slice($eligible, 0, min(6, count($eligible)));
            $combos = [[]];
            foreach (range(0, $nChunks - 1) as $_) {
                $next = [];
                foreach ($combos as $combo) {
                    $start = $combo ? max($combo) + 1 : 0;
                    for ($ci = $start; $ci < count($candidates); $ci++) {
                        $next[] = array_merge($combo, [$ci]);
                    }
                }
                $combos = $next;
            }

            foreach ($combos as $combo) {
                $chosen = array_map(fn($i) => $candidates[$i], $combo);
                $trial = $groupTotals;
                $ok = true;
                foreach ($chosen as $ci => $g) {
                    $trial[$g] += $chunks[$ci];
                    if ($trial[$g] > $cap) { $ok = false; break; }
                }
                if (!$ok) continue;

                $spread = max($trial) - min($trial);
                $score = $spread + ($nChunks - 1) * 1.5;
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestAssignment = array_combine($chosen, $chunks);
                }
            }
        }

        // Fallback: least-full group
        if (!$bestAssignment) {
            $available = $groupLabels;
            usort($available, fn($a, $b) => $groupTotals[$a] <=> $groupTotals[$b]);
            $bestAssignment = [$available[0] => $teamSize];
        }

        foreach ($bestAssignment as $g => $c) {
            $result[] = ['team_id' => $team['id'], 'team_name' => $team['name'], 'group_label' => $g, 'player_count' => $c];
            $groupTotals[$g] += $c;
        }
    }

    return $result;
}

// ---------- Handle recalculate ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalculate') {
    $alloc = calculate_groups();
    db()->exec('DELETE FROM team_groups');
    $ins = db()->prepare('INSERT INTO team_groups (team_id, group_label, player_count) VALUES (?, ?, ?)');
    foreach ($alloc as $a) {
        $ins->execute([$a['team_id'], $a['group_label'], $a['player_count']]);
    }
    $msg = 'Groups recalculated.';
    if (!headers_sent()) {
        header('Location: groups.php?msg=' . urlencode($msg));
        exit;
    }
}
$msg = $msg ?: ($_GET['msg'] ?? '');

// ---------- Auto-calculate if empty ----------
$hasGroups = (int)db()->query('SELECT COUNT(*) FROM team_groups')->fetchColumn();
if (!$hasGroups) {
    $alloc = calculate_groups();
    if ($alloc) {
        $ins = db()->prepare('INSERT INTO team_groups (team_id, group_label, player_count) VALUES (?, ?, ?)');
        foreach ($alloc as $a) {
            $ins->execute([$a['team_id'], $a['group_label'], $a['player_count']]);
        }
        $hasGroups = count($alloc);
    }
}

// ---------- Load current data ----------
$rows = db()->query(
    'SELECT tg.group_label, tg.player_count, t.name AS team_name
     FROM team_groups tg
     JOIN teams t ON t.id = tg.team_id
     ORDER BY tg.group_label, t.name'
)->fetchAll();

// Build group summaries
$groups = [];
foreach ($rows as $r) {
    $g = $r['group_label'];
    if (!isset($groups[$g])) $groups[$g] = ['total' => 0, 'teams' => []];
    $groups[$g]['total'] += (int)$r['player_count'];
    $groups[$g]['teams'][$r['team_name']] = (int)$r['player_count'];
}
ksort($groups);

// Build team spread
$teamSpread = [];
foreach ($rows as $r) {
    $tn = $r['team_name'];
    if (!isset($teamSpread[$tn])) $teamSpread[$tn] = ['total' => 0, 'groups' => []];
    $teamSpread[$tn]['total'] += (int)$r['player_count'];
    $teamSpread[$tn]['groups'][$r['group_label']] = (int)$r['player_count'];
}
ksort($teamSpread);

$totalPlayers = (int)db()->query('SELECT COUNT(*) FROM participants')->fetchColumn();

// ---------- Render ----------
$nav = 'groups';
$no_live = true;
require __DIR__ . '/header.php';
?>
<h2>Groups
    <a href="groups_pdf.php" target="_blank" class="btnlink small" style="margin-left:.6rem;font-size:.85rem;">Export PDF</a>
</h2>
<?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?>

<form method="post" style="margin-bottom:1rem;">
    <input type="hidden" name="action" value="recalculate">
    <button type="submit" onclick="return confirm('Recalculate all group assignments?')">Recalculate groups</button>
    <span class="muted small" style="margin-left:.6rem;"><?= $totalPlayers ?> players across <?= count($groups) ?> groups</span>
</form>

<?php if (empty($groups)): ?>
    <p class="muted">No players yet. Add <a href="teams.php">teams</a> and <a href="participants.php">players</a> first.</p>
<?php else: ?>

<section class="cols">
    <div>
        <h3>Group sizes</h3>
        <table class="grid">
            <thead><tr><th>Group</th><th>Size</th><th>Teams</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $label => $g): ?>
                <tr>
                    <td><strong><?= e($label) ?></strong></td>
                    <td><?= $g['total'] ?></td>
                    <td class="small"><?php
                        $parts = [];
                        foreach ($g['teams'] as $tn => $c) { $parts[] = e($tn) . '&nbsp;(' . $c . ')'; }
                        echo implode(', ', $parts);
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <h3>Team spread</h3>
        <table class="grid">
            <thead><tr><th>Team</th><th>Players</th><th>Groups</th><th>Breakdown</th></tr></thead>
            <tbody>
            <?php foreach ($teamSpread as $tn => $info):
                ksort($info['groups']);
                $parts = [];
                foreach ($info['groups'] as $g => $c) { $parts[] = $g . ':' . $c; }
            ?>
                <tr>
                    <td><strong><?= e($tn) ?></strong></td>
                    <td><?= $info['total'] ?></td>
                    <td><?= count($info['groups']) ?></td>
                    <td class="small muted"><?= implode(', ', $parts) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
