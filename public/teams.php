<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_team') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $msg = 'Team name required.';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO teams (name) VALUES (?)');
                $stmt->execute([$name]);
                $msg = "Team “{$name}” added.";
            } catch (PDOException $e) {
                $msg = 'Could not add team (name already exists?).';
            }
        }
    } elseif ($action === 'delete_team') {
        $stmt = db()->prepare('DELETE FROM teams WHERE id = ?');
        $stmt->execute([(int)($_POST['team_id'] ?? 0)]);
        $msg = 'Team deleted (and its players).';
    }
}

$teams = db()->query(
    'SELECT t.id, t.name, COUNT(p.id) AS players
     FROM teams t LEFT JOIN participants p ON p.team_id = t.id
     GROUP BY t.id, t.name ORDER BY t.name'
)->fetchAll();

$nav = 'teams';
require __DIR__ . '/header.php';
?>
<h2>Teams <a href="teams_pdf.php" target="_blank" class="btnlink small" style="margin-left:.6rem;font-size:.85rem;">Export PDF</a></h2>
<?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?>

<form method="post" class="card row">
    <input type="hidden" name="action" value="add_team">
    <label>New team name
        <input type="text" name="name" placeholder="e.g. North" required>
    </label>
    <button type="submit">Add team</button>
</form>

<table class="grid">
    <thead><tr><th>Team</th><th>Players</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($teams as $t): ?>
        <tr>
            <td><?= e($t['name']) ?></td>
            <td><?= (int)$t['players'] ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Delete this team and all its players?')">
                    <input type="hidden" name="action" value="delete_team">
                    <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                    <button class="danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$teams): ?><tr><td colspan="3" class="muted">No teams yet.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php require __DIR__ . '/footer.php'; ?>
