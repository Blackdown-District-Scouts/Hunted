<?php
/**
 * Scoring + small helpers. Pure functions where possible so the logic is easy to reason about.
 */

/** "HH:MM" -> minutes since midnight (0..1439), or null if blank/invalid. */
function hhmm_to_min(?string $t): ?int
{
    if ($t === null || $t === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    if ($h > 23 || $min > 59) {
        return null;
    }
    return $h * 60 + $min;
}

/**
 * Signed minute difference (return - target) folded into the range (-720, 720].
 * This is what makes scoring correct across midnight: with target 00:00,
 * a return at 23:58 yields -2 (2 min early), not +1438.
 */
function minute_offset(int $returnMin, int $targetMin): int
{
    $diff = (($returnMin - $targetMin) % 1440 + 1440) % 1440; // 0..1439
    if ($diff > 720) {
        $diff -= 1440;
    }
    return $diff;
}

/** Has this player been "caught out" (used up all lives)? */
function is_caught_out(array $p, array $cfg): bool
{
    return (int)$p['captures'] >= (int)$cfg['lives'];
}

/** Has an admin disqualified this player? Disqualified players score 0. */
function is_disqualified(array $p): bool
{
    return !empty($p['disqualified']);
}

/** Time score for one participant. Disqualified, caught-out or not-yet-returned => 0. */
function time_score(array $p, array $cfg): int
{
    if (is_disqualified($p) || is_caught_out($p, $cfg)) {
        return 0;
    }
    $ret = hhmm_to_min($p['return_time'] ?? null);
    if ($ret === null) {
        return 0; // not back yet
    }
    $target = hhmm_to_min($cfg['target_time']) ?? 0;
    $deviation = abs(minute_offset($ret, $target));
    $score = (int)$cfg['time_base_points'] - $deviation * (int)$cfg['penalty_per_minute'];
    if (!empty($cfg['floor_time_score_at_zero']) && $score < 0) {
        $score = 0;
    }
    return $score;
}

/** Loot score, clamped to the configured maximum number of balls. */
function loot_score(array $p, array $cfg): int
{
    if (is_disqualified($p)) {
        return 0;
    }
    $balls = max(0, min((int)$p['loot'], (int)$cfg['max_loot']));
    return $balls * (int)$cfg['loot_value'];
}

/** Flat bonus for collecting litter. Like loot, it still counts when caught out. */
function litter_score(array $p, array $cfg): int
{
    if (is_disqualified($p) || empty($p['litter'])) {
        return 0;
    }
    return (int)($cfg['litter_points'] ?? 20);
}

function total_score(array $p, array $cfg): int
{
    if (is_disqualified($p)) {
        return 0;
    }
    return time_score($p, $cfg) + loot_score($p, $cfg) + litter_score($p, $cfg) + (int)($p['discretionary'] ?? 0);
}

/** Human status label. */
function status_label(array $p, array $cfg): string
{
    if (is_disqualified($p)) {
        return 'Disqualified';
    }
    $caps = (int)$p['captures'];
    if (is_caught_out($p, $cfg)) {
        return 'Caught out';
    }
    if ($caps > 0) {
        return "Caught x{$caps}";
    }
    return 'Alive';
}

/** Describe timing, e.g. "On time", "3 min early", "5 min late". */
function timing_label(array $p, array $cfg): string
{
    $ret = hhmm_to_min($p['return_time'] ?? null);
    if ($ret === null) {
        return 'Not back';
    }
    $target = hhmm_to_min($cfg['target_time']) ?? 0;
    $off = minute_offset($ret, $target);
    if ($off === 0) {
        return 'On time';
    }
    return abs($off) . ' min ' . ($off < 0 ? 'early' : 'late');
}

function full_name(array $p): string
{
    return trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
}

/** First name + surname initial, e.g. "Ben C." — used where full names shouldn't show. */
function short_name(array $p): string
{
    $first = trim($p['first_name'] ?? '');
    $last  = trim($p['last_name'] ?? '');
    return $last !== '' ? $first . ' ' . mb_substr($last, 0, 1) . '.' : $first;
}

/** Anchor id for a team section. */
function team_anchor(int $teamId): string
{
    return 'team-' . $teamId;
}

/**
 * Sticky bar of team chips linking to each team's section.
 * $teams is an ordered list of ['id' => int, 'name' => string] (plus optional 'count').
 */
function team_jump_bar(array $teams): void
{
    if (count($teams) < 2) {
        return; // nothing to jump between
    }
    echo '<nav class="teamjump"><span class="muted small">Jump to:</span>';
    foreach ($teams as $t) {
        $label = e($t['name']);
        if (isset($t['count'])) {
            $label .= ' <span class="chipcount">' . (int)$t['count'] . '</span>';
        }
        echo '<a href="#' . e(team_anchor((int)$t['id'])) . '">' . $label . '</a>';
    }
    echo '</nav>';
}

/** Avatar <img> for a player: their photo, or a silhouette fallback. */
function avatar_html(array $p, string $cls = ''): string
{
    $c = trim('avatar ' . $cls);
    if (!empty($p['photo'])) {
        return '<img class="' . e($c) . '" src="uploads/' . e($p['photo']) . '" alt="">';
    }
    return '<img class="' . e($c) . ' silhouette" src="img/silhouette.svg" alt="No photo">';
}

/** Absolute path of the uploads directory (created on demand). */
function uploads_dir(): string
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * Validate and store an uploaded image. Returns the new filename, or null on
 * no-file / invalid / too-big. Optionally deletes an old photo afterwards.
 */
function save_photo(?array $file, ?string $oldPhoto = null): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || $file['size'] > 32 * 1024 * 1024) {
        return null; // upload failed or > 32 MB
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return null; // not an image
    }
    $ext = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ][$info[2]] ?? null;
    if ($ext === null) {
        return null; // unsupported image type
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], uploads_dir() . '/' . $name)) {
        return null;
    }
    if ($oldPhoto) {
        @unlink(uploads_dir() . '/' . basename($oldPhoto));
    }
    return $name;
}

/**
 * Save a base64 data-URL image (the cropped result produced in the browser).
 * Returns the new filename, or null if absent/invalid. Deletes $oldPhoto on success.
 */
function save_data_url(?string $dataUrl, ?string $oldPhoto = null): ?string
{
    if (!$dataUrl || strpos($dataUrl, 'data:image/') !== 0) {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $dataUrl, $m)) {
        return null;
    }
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $raw = base64_decode(substr($dataUrl, strlen($m[0])), true);
    if ($raw === false || strlen($raw) === 0 || strlen($raw) > 32 * 1024 * 1024) {
        return null;
    }
    if (@getimagesizefromstring($raw) === false) {
        return null; // not a real image
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents(uploads_dir() . '/' . $name, $raw) === false) {
        return null;
    }
    if ($oldPhoto) {
        @unlink(uploads_dir() . '/' . basename($oldPhoto));
    }
    return $name;
}

/** Remove a player's image files (cropped + original) from disk. */
function delete_player_photos(array $p): void
{
    foreach (['photo', 'photo_original'] as $k) {
        if (!empty($p[$k])) {
            @unlink(uploads_dir() . '/' . basename($p[$k]));
        }
    }
}

/** Asset URL with a cache-busting ?v=<mtime> so updated JS/CSS always reloads. */
function asset(string $file): string
{
    $path = __DIR__ . '/' . $file;
    $v = @filemtime($path) ?: 0;
    return e($file) . '?v=' . $v;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Write an audit-log entry. $detail is an associative array of changes,
 * e.g. ['captures' => ['old' => 0, 'new' => 1]].
 */
function audit_log(string $action, int $participantId, array $detail = []): void
{
    db()->prepare(
        'INSERT INTO audit_log (participant_id, action, detail) VALUES (?, ?, ?)'
    )->execute([
        $participantId,
        $action,
        $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/**
 * Recalculate a player's cached discretionary total from the point_awards
 * ledger and store it on participants.discretionary (what scoring reads).
 */
function recompute_discretionary(int $pid): void
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(points), 0) FROM point_awards WHERE participant_id = ?');
    $stmt->execute([$pid]);
    $sum = (int)$stmt->fetchColumn();
    db()->prepare('UPDATE participants SET discretionary = ? WHERE id = ?')->execute([$sum, $pid]);
}

/** Fetch a single participant row by id, or null. */
function get_participant(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM participants WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
