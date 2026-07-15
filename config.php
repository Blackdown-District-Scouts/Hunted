<?php
/**
 * Hunted game configuration.
 *
 * Edit these values to tune the game, then refresh the page (no rebuild needed —
 * this file lives outside the web root but is read live on every request).
 */

return [
    // Target return time, 24h "HH:MM". Scoring works correctly around midnight,
    // e.g. with "00:00" a return at "23:58" counts as 2 minutes early, not late.
    'target_time' => '00:30',

    // Time bonus for hitting the target exactly.
    'time_base_points' => 100,

    // Points lost per minute early OR late.
    'penalty_per_minute' => 1,

    // If true the time score can never go below 0. If false it may go negative
    // (a very early/late return could then drag the total down).
    'floor_time_score_at_zero' => true,

    // Number of lives. Being caught this many times = "caught out": no time points
    // (loot still counts).
    'lives' => 2,

    // Loot balls.
    'loot_value'    => 10,  // points per ball
    'max_loot'      => 10,  // most a player may collect

    // Flat bonus for a team/player that collected their litter.
    'litter_points' => 20,

    // URL embedded in the big iframe on the Dashboard (e.g. a map or live feed).
    // Leave blank to show a placeholder.
    'dashboard_iframe' => 'https://tracker.bluerhinos.co.uk/map/booking/da290c3c-d912-42d2-ab74-493ab52bd7f4/embed',

    // Basic auth credentials for admin pages (status + dashboard are public).
    // Set both to empty strings to disable auth entirely.
    'admin_user' => 'admin',
    'admin_pass' => 'hunted',
];
