</main>
<footer class="foot">
    <span>Target return time: <strong><?= e(config()['target_time']) ?></strong></span>
    <?php if (empty($public_view)): ?>
        <span>Hit target = <?= (int)config()['time_base_points'] ?> pts · −<?= (int)config()['penalty_per_minute'] ?>/min · treasure <?= (int)config()['loot_value'] ?> pts (max <?= (int)config()['max_loot'] ?>) · <?= (int)config()['lives'] ?> lives</span>
    <?php endif; ?>
</footer>
</body>
</html>
