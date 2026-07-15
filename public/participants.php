<?php
require __DIR__ . '/auth.php';
// Roster setup: add / edit / delete players. No live-game controls here —
// captures live on caught.php, return time & loot on checkin.php.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg = config();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['pid'] ?? 0);

    if ($action === 'add_participant' || $action === 'update_participant') {
        $teamId    = (int)($_POST['team_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $age       = ($_POST['age'] ?? '') === '' ? null : (int)$_POST['age'];
        $number    = ($_POST['number'] ?? '') === '' ? null : (int)$_POST['number'];

        $cropData = trim($_POST['crop_data'] ?? '');
        $cropData = ($cropData !== '' && strlen($cropData) <= 255) ? $cropData : null;

        if (!$teamId || $firstName === '') {
            $msg = 'Team and first name are required.';
        } elseif ($action === 'add_participant') {
            if ($number === null) {
                $stmt = db()->prepare('SELECT COALESCE(MAX(number),0)+1 FROM participants WHERE team_id = ?');
                $stmt->execute([$teamId]);
                $number = (int)$stmt->fetchColumn();
            }
            // Save new files first; only delete/replace once the DB write succeeds.
            $original = save_photo($_FILES['original'] ?? null);          // raw upload
            $photo    = save_data_url($_POST['cropped_image'] ?? null);   // cropped result
            // Uploaded but not cropped: use a copy of the original as the display image.
            if (!$photo && $original) {
                $copy = bin2hex(random_bytes(8)) . '.' . pathinfo($original, PATHINFO_EXTENSION);
                if (@copy(uploads_dir() . '/' . $original, uploads_dir() . '/' . $copy)) {
                    $photo = $copy;
                }
            }
            try {
                db()->prepare(
                    'INSERT INTO participants (team_id, number, first_name, last_name, age, photo, photo_original, crop)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$teamId, $number, $firstName, $lastName, $age, $photo, $original, $cropData]);
                $msg = "Added player #{$number}.";
            } catch (PDOException $e) {
                delete_player_photos(['photo' => $photo, 'photo_original' => $original]); // no orphans
                $msg = 'Could not add player (that number may already exist in the team).';
            }
        } else { // update_participant
            $cur = db()->prepare('SELECT photo, photo_original, crop FROM participants WHERE id = ?');
            $cur->execute([$pid]);
            $row = $cur->fetch() ?: ['photo' => null, 'photo_original' => null, 'crop' => null];
            if ($number === null) { $number = (int)($_POST['orig_number'] ?? 1); }

            // Save any new files without touching the old ones yet.
            $newOriginal = save_photo($_FILES['original'] ?? null);
            $newPhoto    = save_data_url($_POST['cropped_image'] ?? null);
            $original = $newOriginal ?? $row['photo_original'];  // keep existing if no new upload
            $photo    = $newPhoto    ?? $row['photo'];           // keep existing if no new crop
            $crop     = ($cropData !== null) ? $cropData : $row['crop'];
            try {
                db()->prepare(
                    'UPDATE participants SET team_id=?, number=?, first_name=?, last_name=?, age=?, photo=?, photo_original=?, crop=? WHERE id=?'
                )->execute([$teamId, $number, $firstName, $lastName, $age, $photo, $original, $crop, $pid]);
                // Success: delete the files we actually replaced.
                if ($newOriginal && $row['photo_original']) { @unlink(uploads_dir() . '/' . basename($row['photo_original'])); }
                if ($newPhoto && $row['photo']) { @unlink(uploads_dir() . '/' . basename($row['photo'])); }
                $msg = 'Player updated.';
            } catch (PDOException $e) {
                delete_player_photos(['photo' => $newPhoto, 'photo_original' => $newOriginal]); // roll back new files
                $msg = 'Could not save (that number may already exist in the team).';
            }
        }
    } elseif ($action === 'remove_photo') {
        $cur = db()->prepare('SELECT photo, photo_original FROM participants WHERE id = ?');
        $cur->execute([$pid]);
        if ($row = $cur->fetch()) {
            delete_player_photos($row);
        }
        db()->prepare('UPDATE participants SET photo = NULL, photo_original = NULL, crop = NULL WHERE id = ?')
            ->execute([$pid]);
        // Stay in edit mode so further changes can be made.
        if (!headers_sent()) {
            header('Location: participants.php?edit=' . $pid . '&msg=' . urlencode('Photo removed.'));
            exit;
        }
    } elseif ($action === 'delete_participant') {
        $cur = db()->prepare('SELECT photo, photo_original FROM participants WHERE id = ?');
        $cur->execute([$pid]);
        if ($row = $cur->fetch()) {
            delete_player_photos($row);
        }
        db()->prepare('DELETE FROM participants WHERE id = ?')->execute([$pid]);
        $msg = 'Player removed.';
    } elseif ($action === 'reset_game') {
        // Wipe game state but keep the roster, teams and photos. Audit trail is
        // cleared too, then a single marker entry records the reset.
        try {
            db()->beginTransaction();
            db()->exec('UPDATE participants SET captures = 0, loot = 0, litter = 0, return_time = NULL,
                                                discretionary = 0, disqualified = 0, dq_reason = NULL');
            db()->exec('DELETE FROM point_awards');
            db()->exec('DELETE FROM audit_log');
            db()->commit();
            db()->prepare('INSERT INTO audit_log (participant_id, action, detail) VALUES (NULL, ?, NULL)')
                ->execute(['game_reset']);
            $msg = 'Game data reset. Players, teams and photos kept.';
        } catch (PDOException $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            $msg = 'Could not reset game data.';
        }
    }

    if (!headers_sent()) {
        header('Location: participants.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
        exit;
    }
}

$msg = $msg ?: ($_GET['msg'] ?? '');

$teams = db()->query('SELECT id, name FROM teams ORDER BY name')->fetchAll();

// Editing an existing player?
$edit = null;
if (($eid = (int)($_GET['edit'] ?? 0))) {
    $stmt = db()->prepare('SELECT * FROM participants WHERE id = ?');
    $stmt->execute([$eid]);
    $edit = $stmt->fetch() ?: null;
}

// Optional roster filter: 'nophoto' shows only players with no photo on file.
$filter = (($_GET['filter'] ?? '') === 'nophoto') ? 'nophoto' : '';
$where  = $filter === 'nophoto' ? "WHERE p.photo IS NULL OR p.photo = ''" : '';
$players = db()->query(
    "SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     $where
     ORDER BY t.name, p.number"
)->fetchAll();

$nav = 'players';
$head_extra = '<link rel="stylesheet" href="' . asset('vendor/cropper.min.css') . '">'
            . '<script src="' . asset('vendor/cropper.min.js') . '" defer></script>'
            . '<script src="' . asset('crop.js') . '" defer></script>';
require __DIR__ . '/header.php';
?>
<h2>Players <span class="muted">— roster setup</span>
    <?php if ($players): $pdfq = $filter === 'nophoto' ? 'filter=nophoto&' : ''; ?>
        <span style="float:right;font-weight:400">
            <a class="btnlink" href="participants_pdf.php<?= $pdfq ? '?' . rtrim($pdfq, '&') : '' ?>" target="_blank" rel="noopener">⬇ Photos PDF</a>
            <a class="btnlink" href="participants_pdf.php?<?= $pdfq ?>design=name" target="_blank" rel="noopener">⬇ Names PDF</a>
            <a class="btnlink" href="participants_pdf.php?<?= $pdfq ?>design=table" target="_blank" rel="noopener">⬇ Table PDF</a>
        </span>
    <?php endif; ?>
</h2>
<?php if ($msg): ?><p class="msg"><?= e($msg) ?></p><?php endif; ?>

<p class="row wrap" style="gap:.5rem;align-items:center">
    <span class="muted small">Show:</span>
    <a class="btnlink" href="participants.php"<?= $filter === '' ? ' style="font-weight:700;text-decoration:underline"' : '' ?>>All players</a>
    <a class="btnlink" href="participants.php?filter=nophoto"<?= $filter === 'nophoto' ? ' style="font-weight:700;text-decoration:underline"' : '' ?>>Without photo</a>
</p>

<?php if (!$teams): ?>
    <p class="muted">Create a <a href="teams.php">team</a> first.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card row wrap">
    <input type="hidden" name="action" value="<?= $edit ? 'update_participant' : 'add_participant' ?>">
    <?php if ($edit): ?>
        <input type="hidden" name="pid" value="<?= (int)$edit['id'] ?>">
        <input type="hidden" name="orig_number" value="<?= (int)$edit['number'] ?>">
    <?php endif; ?>
    <label>Team
        <select name="team_id" required>
            <?php foreach ($teams as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $edit && (int)$edit['team_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Number <small>(blank = next)</small>
        <input type="number" name="number" min="1" style="width:6rem" value="<?= $edit ? (int)$edit['number'] : '' ?>">
    </label>
    <label>First name
        <input type="text" name="first_name" required value="<?= $edit ? e($edit['first_name']) : '' ?>">
    </label>
    <label>Last name
        <input type="text" name="last_name" value="<?= $edit ? e($edit['last_name']) : '' ?>">
    </label>
    <label>Age
        <input type="number" name="age" min="0" max="120" style="width:5rem" value="<?= $edit && $edit['age'] !== null ? (int)$edit['age'] : '' ?>">
    </label>
    <div class="photofield" id="photoField"
         data-original="<?= $edit && $edit['photo_original'] ? 'uploads/' . e($edit['photo_original']) : '' ?>"
         data-photo="<?= $edit && $edit['photo'] ? 'uploads/' . e($edit['photo']) : '' ?>"
         data-crop="<?= $edit && $edit['crop'] ? e($edit['crop']) : '' ?>">
        <span class="muted small">Photo</span>
        <div class="photofield-row">
            <img id="photoPreview" class="avatar"
                 src="<?= $edit && $edit['photo'] ? 'uploads/' . e($edit['photo']) : 'img/silhouette.svg' ?>" alt="">
            <div class="photofield-btns">
                <label class="btnlink filebtn">Choose photo
                    <input type="file" id="photoInput" name="original" accept="image/*" hidden>
                </label>
                <button type="button" class="btnlink" id="editCropBtn" <?= $edit && ($edit['photo_original'] || $edit['photo']) ? '' : 'hidden' ?>>Edit crop</button>
                <?php if ($edit && $edit['photo']): ?>
                    <button type="submit" class="btnlink danger-outline" name="action" value="remove_photo"
                            formnovalidate onclick="return confirm('Remove this photo?')">Remove photo</button>
                <?php endif; ?>
            </div>
        </div>
        <input type="hidden" name="cropped_image" id="croppedImage">
        <input type="hidden" name="crop_data" id="cropData">
    </div>
    <button type="submit"><?= $edit ? 'Save changes' : 'Add player' ?></button>
    <?php if ($edit): ?><a class="btnlink" href="participants.php">Cancel</a><?php endif; ?>
</form>
<?php endif; ?>

<!-- Crop dialog (hidden until a photo is chosen or "Edit crop" is clicked) -->
<div class="cropmodal" id="cropModal" hidden>
    <div class="cropdialog">
        <div class="crophead">Crop photo <span class="muted small">— drag to position, pinch/scroll to zoom</span></div>
        <div class="cropstage"><img id="cropImage" alt=""></div>
        <div class="cropbar">
            <button type="button" class="ghost" id="cropCancel">Cancel</button>
            <button type="button" id="cropApply">Apply crop</button>
        </div>
    </div>
</div>

<table class="grid">
    <thead>
    <tr><th>Photo</th><th>Player</th><th>Name</th><th>Age</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($players as $p): ?>
        <tr class="<?= $edit && (int)$edit['id'] === (int)$p['id'] ? 'editing' : '' ?>">
            <td><?= avatar_html($p) ?></td>
            <td><strong><?= e($p['team_name']) ?> <?= (int)$p['number'] ?></strong></td>
            <td><?= e(full_name($p)) ?></td>
            <td><?= $p['age'] !== null ? (int)$p['age'] : '—' ?></td>
            <td class="nowrap">
                <a class="btnlink" href="participants.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                <a class="btnlink" href="participants_pdf.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" title="Photo PDF (A4 portrait)">PDF</a>
                <a class="btnlink" href="participants_pdf.php?id=<?= (int)$p['id'] ?>&design=name" target="_blank" rel="noopener" title="Name PDF (A4 landscape)">Name</a>
                <form method="post" class="inline" onsubmit="return confirm('Remove this player?')">
                    <input type="hidden" name="action" value="delete_participant">
                    <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="danger small">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$players): ?><tr><td colspan="5" class="muted"><?= $filter === 'nophoto' ? 'Every player has a photo.' : 'No players yet.' ?></td></tr><?php endif; ?>
    </tbody>
</table>

<section class="dangerzone">
    <h3>Danger zone</h3>
    <p class="muted">Reset all game data — captures, treasure, litter, check-ins, disqualifications and
        discretionary points — and clear the audit trail, ready for a new game.
        Players, teams and photos are kept.</p>
    <form method="post" onsubmit="return confirm('Reset ALL game data?\n\nScores, check-ins, points and the audit trail will be wiped. Players, teams and photos are kept.\n\nThis cannot be undone.')">
        <input type="hidden" name="action" value="reset_game">
        <button type="submit" class="danger">Reset game data</button>
    </form>
</section>
<?php require __DIR__ . '/footer.php'; ?>
