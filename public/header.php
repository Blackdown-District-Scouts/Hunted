<?php
$nav = $nav ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hunted — Game Tracker</title>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<?php if (empty($no_live)): ?><script src="<?= asset('live.js') ?>" defer></script><?php endif; ?>
<?= $head_extra ?? '' ?>
</head>
<body class="<?= isset($body_class) ? e($body_class) : '' ?>">
<header class="topbar">
    <h1>🎯 Hunted</h1>
    <?php if (empty($public_view)): ?>
    <nav>
        <span class="navgroup">Run</span>
        <a href="index.php" class="<?= $nav === 'score' ? 'active' : '' ?>">Scoreboard</a>
        <a href="caught.php" class="<?= $nav === 'caught' ? 'active' : '' ?>">Caught</a>
        <a href="checkin.php" class="<?= $nav === 'checkin' ? 'active' : '' ?>">Check-in</a>
        <a href="points.php" class="<?= $nav === 'points' ? 'active' : '' ?>">Points</a>
        <a href="status.php" class="<?= $nav === 'status' ? 'active' : '' ?>">Status</a>
        <a href="dashboard.php" class="<?= $nav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="audit.php" class="<?= $nav === 'audit' ? 'active' : '' ?>">Audit</a>
        <span class="navsep"></span>
        <span class="navgroup">Setup</span>
        <a href="participants.php" class="<?= $nav === 'players' ? 'active' : '' ?>">Players</a>
        <a href="teams.php" class="<?= $nav === 'teams' ? 'active' : '' ?>">Teams</a>
        <a href="groups.php" class="<?= $nav === 'groups' ? 'active' : '' ?>">Groups</a>
    </nav>
    <?php endif; ?>
</header>
<main>
