<?php
// Export results as a PDF: summary page, then one page per team with full breakdown.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/../lib/tcpdf/tcpdf.php';

$cfg = config();

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

// Build team aggregates (same logic as scoreboard).
$teams = [];
$board = [];
foreach ($players as $p) {
    $total = total_score($p, $cfg);
    $tn = $p['team_name'];
    if (!isset($teams[$tn])) {
        $teams[$tn] = ['name' => $tn, 'total' => 0, 'players' => []];
    }
    $teams[$tn]['total'] += $total;
    $teams[$tn]['players'][] = $p + ['_total' => $total];
    $board[] = $p + ['_total' => $total];
}
foreach ($teams as &$t) {
    $n = count($t['players']);
    $t['avg'] = $n ? round($t['total'] / $n, 1) : 0;
}
unset($t);
usort($teams, fn($a, $b) => $b['avg'] <=> $a['avg']);
usort($board, fn($a, $b) => $b['_total'] <=> $a['_total']);

// --- PDF setup ---
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Hunted');
$pdf->SetTitle('Hunted — Results');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetMargins(12, 12, 12);

$pageW = 210;
$cw = $pageW - 24; // content width (margins 12 each side)

// Colours
$darkBg   = [15, 17, 21];
$panelBg  = [24, 28, 36];
$lineBg   = [42, 49, 64];
$ink      = [231, 235, 242];
$muted    = [139, 148, 167];
$accent   = [79, 140, 255];
$okCol    = [123, 209, 127];
$warnCol  = [224, 163, 90];
$badCol   = [239, 128, 121];

// Register display font if available.
$titleFont = 'helvetica';
try {
    $reg = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Capture it.ttf', 'TrueTypeUnicode', '', 32);
    if ($reg) $titleFont = $reg;
} catch (Throwable $e) {}

// ============================================================
// Helper: draw a table row (array of cells).
// $cols = [[text, width, align, bold], ...]
// ============================================================
function pdfRow(TCPDF $pdf, array $cols, float $h, array $fg, array $bg = null, float $fontSize = 8): void
{
    if ($bg) {
        $pdf->SetFillColor(...$bg);
    }
    $pdf->SetTextColor(...$fg);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    foreach ($cols as [$text, $w, $align, $bold]) {
        $pdf->SetFont('helvetica', $bold ? 'B' : '', $fontSize);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align, (bool)$bg);
        $x += $w;
    }
    $pdf->Ln($h);
}

// ============================================================
// PAGE 1: Summary
// ============================================================
$pdf->AddPage();
$pdf->SetFillColor(...$darkBg);
$pdf->Rect(0, 0, 210, 297, 'F');

// Title
$pdf->SetFont($titleFont, '', 36);
$pdf->SetTextColor(...$ink);
$pdf->SetXY(12, 14);
$pdf->Cell($cw, 16, 'Hunted — Results', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(...$muted);
$pdf->SetX(12);
$pdf->Cell($cw, 6, count($teams) . ' teams   |   ' . count($players) . ' players   |   ' . date('j M Y, H:i'), 0, 1, 'C');
$pdf->Ln(6);

// Team standings table
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(...$accent);
$pdf->SetX(12);
$pdf->Cell($cw, 8, 'Team Standings', 0, 1, 'L');
$pdf->Ln(2);

$tCols = [12, 60, 22, 28, 24, 40]; // #, Team, Players, Avg, Total, Best Player
$hdr = [['#', $tCols[0], 'C', true], ['Team', $tCols[1], 'L', true], ['Players', $tCols[2], 'C', true],
        ['Avg Score', $tCols[3], 'C', true], ['Total', $tCols[4], 'C', true], ['Best Player', $tCols[5], 'L', true]];
pdfRow($pdf, $hdr, 7, $ink, $panelBg, 8);

$teamRank = 0; $teamPrev = null;
foreach ($teams as $i => $t) {
    if ($t['avg'] !== $teamPrev) { $teamRank = $i + 1; $teamPrev = $t['avg']; }
    $tied = (isset($teams[$i + 1]) && $teams[$i + 1]['avg'] === $t['avg'])
         || ($i > 0 && $teams[$i - 1]['avg'] === $t['avg']);
    $rankStr = $teamRank . ($tied ? '=' : '');

    // Find best player in team
    $best = null;
    foreach ($t['players'] as $tp) {
        if (!$best || $tp['_total'] > $best['_total']) $best = $tp;
    }
    $bestStr = $best ? full_name($best) . ' (' . $best['_total'] . ')' : '';
    $avg = rtrim(rtrim(number_format($t['avg'], 1), '0'), '.');

    $bg = ($i % 2 === 0) ? null : [20, 24, 32];
    pdfRow($pdf, [
        [$rankStr, $tCols[0], 'C', true],
        [$t['name'], $tCols[1], 'L', true],
        [(string)count($t['players']), $tCols[2], 'C', false],
        [$avg, $tCols[3], 'C', true],
        [(string)(int)$t['total'], $tCols[4], 'C', false],
        [$bestStr, $tCols[5], 'L', false],
    ], 7, $ink, $bg, 8);
}

$pdf->Ln(8);

// Top 10 players
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(...$accent);
$pdf->SetX(12);
$pdf->Cell($cw, 8, 'Top Players', 0, 1, 'L');
$pdf->Ln(2);

$pCols = [12, 50, 50, 24, 24, 26]; // #, Player, Name, Status, Time pts, Total
$hdr2 = [['#', $pCols[0], 'C', true], ['Player', $pCols[1], 'L', true], ['Name', $pCols[2], 'L', true],
         ['Status', $pCols[3], 'C', true], ['Time', $pCols[4], 'C', true], ['Total', $pCols[5], 'C', true]];
pdfRow($pdf, $hdr2, 7, $ink, $panelBg, 8);

$topBoard = array_filter($board, fn($p) => $p['_total'] > 0);
$topBoard = array_slice(array_values($topBoard), 0, 20);
$pRank = 0; $pPrev = null;
foreach ($topBoard as $i => $p) {
    if ($p['_total'] !== $pPrev) { $pRank = $i + 1; $pPrev = $p['_total']; }
    $tied = (isset($topBoard[$i + 1]) && $topBoard[$i + 1]['_total'] === $p['_total'])
         || ($i > 0 && $topBoard[$i - 1]['_total'] === $p['_total']);
    $rankStr = $pRank . ($tied ? '=' : '');
    $bg = ($i % 2 === 0) ? null : [20, 24, 32];
    pdfRow($pdf, [
        [$rankStr, $pCols[0], 'C', true],
        [$p['team_name'] . ' ' . (int)$p['number'], $pCols[1], 'L', false],
        [full_name($p), $pCols[2], 'L', false],
        [status_label($p, $cfg), $pCols[3], 'C', false],
        [(string)time_score($p, $cfg), $pCols[4], 'C', false],
        [(string)(int)$p['_total'], $pCols[5], 'C', true],
    ], 7, $ink, $bg, 8);
}

// ============================================================
// ONE PAGE PER TEAM
// ============================================================
foreach ($teams as $ti => $t) {
    $pdf->AddPage();
    $pdf->SetFillColor(...$darkBg);
    $pdf->Rect(0, 0, 210, 297, 'F');

    // Team header
    $pdf->SetFont($titleFont, '', 30);
    $pdf->SetTextColor(...$ink);
    $pdf->SetXY(12, 12);
    $pdf->Cell($cw, 14, $t['name'], 0, 1, 'C');

    // Team rank + stats line
    $teamRank2 = 0; $prev2 = null;
    foreach ($teams as $j => $tt) {
        if ($tt['avg'] !== $prev2) { $teamRank2 = $j + 1; $prev2 = $tt['avg']; }
        if ($tt['name'] === $t['name']) break;
    }
    $tied2 = false;
    foreach ($teams as $j => $tt) {
        if ($tt['name'] !== $t['name'] && $tt['avg'] === $t['avg']) { $tied2 = true; break; }
    }
    $avg = rtrim(rtrim(number_format($t['avg'], 1), '0'), '.');

    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(...$muted);
    $pdf->SetX(12);
    $pdf->Cell($cw, 6, 'Position: ' . $teamRank2 . ($tied2 ? '=' : '')
        . '   |   Avg: ' . $avg
        . '   |   Total: ' . (int)$t['total']
        . '   |   Players: ' . count($t['players']), 0, 1, 'C');
    $pdf->Ln(4);

    // Sort players within team by total desc for the table
    $tPlayers = $t['players'];
    usort($tPlayers, fn($a, $b) => $b['_total'] <=> $a['_total']);

    // Table header
    $c = [10, 38, 30, 24, 18, 18, 18, 18, 12]; // #, Name, Status, Timing, Time, Loot, Litter, Bonus, Total
    $hdrT = [
        ['#', $c[0], 'C', true], ['Name', $c[1], 'L', true], ['Status', $c[2], 'C', true],
        ['Timing', $c[3], 'C', true], ['Time', $c[4], 'C', true], ['Treasure', $c[5], 'C', true],
        ['Litter', $c[6], 'C', true], ['Bonus', $c[7], 'C', true], ['Total', $c[8], 'C', true],
    ];
    pdfRow($pdf, $hdrT, 7, $ink, $panelBg, 8);

    foreach ($tPlayers as $pi => $p) {
        $disc = (int)$p['discretionary'];
        $discStr = ($disc > 0 ? '+' : '') . $disc;
        if ($disc === 0) $discStr = '0';
        $statusCol = is_caught_out($p, $cfg) ? $badCol : ((int)$p['captures'] > 0 ? $warnCol : $okCol);

        $bg = ($pi % 2 === 0) ? null : [20, 24, 32];

        // Regular columns first (all in $ink)
        pdfRow($pdf, [
            [$t['name'] . ' ' . (int)$p['number'], $c[0], 'C', true],
            [full_name($p), $c[1], 'L', false],
            [status_label($p, $cfg), $c[2], 'C', false],
            [timing_label($p, $cfg), $c[3], 'C', false],
            [(string)time_score($p, $cfg), $c[4], 'C', false],
            [(string)loot_score($p, $cfg), $c[5], 'C', false],
            [(string)litter_score($p, $cfg), $c[6], 'C', false],
            [$discStr, $c[7], 'C', false],
            [(string)(int)$p['_total'], $c[8], 'C', true],
        ], 7, is_caught_out($p, $cfg) ? $muted : $ink, $bg, 8);
    }
}

if (ob_get_length()) {
    ob_end_clean();
}
$pdf->Output('hunted-results.pdf', 'I');
