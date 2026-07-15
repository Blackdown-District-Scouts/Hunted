<?php
// Groups PDF: single-page summary of group allocations.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/../lib/tcpdf/tcpdf.php';

$rows = db()->query(
    'SELECT tg.group_label, tg.player_count, t.name AS team_name
     FROM team_groups tg
     JOIN teams t ON t.id = tg.team_id
     ORDER BY tg.group_label, t.name'
)->fetchAll();

$groups = [];
foreach ($rows as $r) {
    $g = $r['group_label'];
    if (!isset($groups[$g])) $groups[$g] = ['total' => 0, 'teams' => []];
    $groups[$g]['total'] += (int)$r['player_count'];
    $groups[$g]['teams'][$r['team_name']] = (int)$r['player_count'];
}
ksort($groups);

$teamSpread = [];
foreach ($rows as $r) {
    $tn = $r['team_name'];
    if (!isset($teamSpread[$tn])) $teamSpread[$tn] = ['total' => 0, 'groups' => []];
    $teamSpread[$tn]['total'] += (int)$r['player_count'];
    $teamSpread[$tn]['groups'][$r['group_label']] = (int)$r['player_count'];
}
ksort($teamSpread);

$totalPlayers = 0;
foreach ($groups as $g) $totalPlayers += $g['total'];

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Hunted');
$pdf->SetTitle('Hunted — Groups');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(true, 10);
$pdf->SetMargins(12, 12, 12);

$pageW = 297;
$cw = $pageW - 24;

$titleFont = 'helvetica';
try {
    $reg = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Capture it.ttf', 'TrueTypeUnicode', '', 32);
    if ($reg) $titleFont = $reg;
} catch (Throwable $e) {}

$rowH = 8;

$pdf->AddPage();

// Title
$pdf->SetFont($titleFont, '', 30);
$pdf->SetXY(12, 10);
$pdf->Cell($cw, 14, 'Hunted - Group Allocations v2', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(120, 120, 120);
$pdf->SetX(12);
$pdf->Cell($cw, 6, $totalPlayers . ' players across ' . count($groups) . ' groups', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(4);

// Group sizes table
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetX(12);
$pdf->Cell($cw, 8, 'Group Sizes', 0, 1, 'L');
$pdf->Ln(1);

$colG = 16; $colS = 16; $colT = $cw - $colG - $colS;
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->SetX(12);
$pdf->Cell($colG, $rowH, 'Group', 1, 0, 'C', true);
$pdf->Cell($colS, $rowH, 'Size', 1, 0, 'C', true);
$pdf->Cell($colT, $rowH, 'Teams', 1, 1, 'L', true);

$pdf->SetFont('helvetica', '', 9);
foreach ($groups as $label => $g) {
    $parts = [];
    foreach ($g['teams'] as $tn => $c) { $parts[] = $tn . ' (' . $c . ')'; }
    $pdf->SetX(12);
    $pdf->Cell($colG, $rowH, $label, 1, 0, 'C');
    $pdf->Cell($colS, $rowH, (string)$g['total'], 1, 0, 'C');
    $pdf->Cell($colT, $rowH, implode(', ', $parts), 1, 1, 'L');
}


$pdf->AddPage();

// Team spread table
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetX(12);
$pdf->Cell($cw, 8, 'Team Spread', 0, 1, 'L');
$pdf->Ln(1);

$colTn = 50; $colPl = 20; $colGr = 20; $colBd = $cw - $colTn - $colPl - $colGr;
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(230, 230, 230);
$pdf->SetX(12);
$pdf->Cell($colTn, $rowH, 'Team', 1, 0, 'L', true);
$pdf->Cell($colPl, $rowH, 'Players', 1, 0, 'C', true);
$pdf->Cell($colGr, $rowH, 'Groups', 1, 0, 'C', true);
$pdf->Cell($colBd, $rowH, 'Breakdown', 1, 1, 'L', true);

$pdf->SetFont('helvetica', '', 9);
foreach ($teamSpread as $tn => $info) {
    ksort($info['groups']);
    $parts = [];
    foreach ($info['groups'] as $gl => $c) { $parts[] = $gl . ': ' . $c; }
    $pdf->SetX(12);
    $pdf->Cell($colTn, $rowH, $tn, 1, 0, 'L');
    $pdf->Cell($colPl, $rowH, (string)$info['total'], 1, 0, 'C');
    $pdf->Cell($colGr, $rowH, (string)count($info['groups']), 1, 0, 'C');
    $pdf->Cell($colBd, $rowH, implode(',  ', $parts), 1, 1, 'L');
}

if (ob_get_length()) {
    ob_end_clean();
}
$pdf->Output('hunted-groups.pdf', 'I');
