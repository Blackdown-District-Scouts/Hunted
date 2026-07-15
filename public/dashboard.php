<?php
// Big-screen dashboard: live aggregate statistics for display on a large screen.
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$cfg   = config();
$lives = (int)$cfg['lives'];

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id'
)->fetchAll();

$total = count($players);
$alive = $caughtSome = $caughtOut = $checkedIn = $stillOut = $disqualified = 0;
$teams = [];
foreach ($players as $p) {
    $teams[$p['team_id']] = true;
    $c = (int)$p['captures'];
    if ($c >= $lives)             { $caughtOut++; }
    elseif ($c > 0)               { $caughtSome++; }
    elseif (!is_disqualified($p)) { $alive++; }
    if (!empty($p['return_time'])) { $checkedIn++; } else { $stillOut++; }
    if (is_disqualified($p)) { $disqualified++; }
}
$teamCount = count($teams);

// The four stat boxes on the right: [label, value, colour-class]
$cards = [
    ['Still alive', $alive,      'ok'],
    ['Caught once', $caughtSome, 'warn'],
    ['Caught out',  $caughtOut,  'bad'],
    ['Checked in',  $checkedIn,  'accent'],
    ['Disqualified', $disqualified, 'bad'],
];

$iframeUrl = trim((string)($cfg['dashboard_iframe'] ?? ''));

$fragment = isset($_GET['fragment']);
if (!$fragment) {
    $nav = 'dashboard';
    $public_view = true; // clean full-screen view: no admin nav / scoring footer
    $body_class  = 'dash';
    $head_extra  = '<meta http-equiv="refresh" content="300">'; // full reload every 5 min
    require __DIR__ . '/header.php';
    ?>
    <div class="dashhead">
        <h2>Live dashboard <span class="muted">— <?= $teamCount ?> teams · <?= $total ?> players</span></h2>
        <div class="dashclock" id="clock" aria-label="Current time">--:--:--</div>
<!--        <button type="button" class="btnlink fsbtn"-->
<!--                onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()">⛶ Fullscreen</button>-->
    </div>
    <div class="dashmain">
        <?php if ($iframeUrl !== ''): ?>
            <iframe class="dashframe" src="<?= e($iframeUrl) ?>" title="Dashboard view" allowfullscreen></iframe>
        <?php else: ?>
            <div class="dashframe dashframe-empty"><span>Set <code>dashboard_iframe</code> in <code>config.php</code></span></div>
        <?php endif; ?>
        <div id="live" data-page="dashboard" data-wsport="8081" class="dashstats">
    <?php
}
?>
<?php foreach ($cards as [$label, $value, $cls]): ?>
    <div class="statcard stat-<?= $cls ?>">
        <div class="statnum"><?= (int)$value ?></div>
        <div class="statlabel"><?= e($label) ?></div>
    </div>
<?php endforeach; ?>
<?php $pct = $total > 0 ? round($checkedIn / $total * 100) : 0; ?>
<div class="dashbar">
    <div class="dashbar-fill" style="width:<?= $pct ?>%"></div>
    <div class="dashbar-text"><?= $checkedIn ?> / <?= $total ?> checked in (<?= $pct ?>%)</div>
</div>
<?php
if (!$fragment) {
    echo '</div>'; // #live (.dashstats)
    echo '</div>'; // .dashmain
    ?>
    <script>
    // Live clock, ticking every second (independent of the data refresh).
    (function () {
        function pad(n) { return String(n).padStart(2, '0'); }
        function tick() {
            var el = document.getElementById('clock');
            if (!el) return;
            var d = new Date();
            el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
    <?php
    require __DIR__ . '/footer.php';
}
