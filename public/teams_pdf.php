<?php
// Team rosters PDF: one A4 landscape page per team with a player table.
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/../lib/tcpdf/tcpdf.php';

$players = db()->query(
    'SELECT p.*, t.name AS team_name
     FROM participants p JOIN teams t ON t.id = p.team_id
     ORDER BY t.name, p.number'
)->fetchAll();

$teams = [];
foreach ($players as $p) {
    $tn = $p['team_name'];
    if (!isset($teams[$tn])) {
        $teams[$tn] = ['name' => $tn, 'players' => []];
    }
    $teams[$tn]['players'][] = $p;
}

// Subclass to add a continuation header when a team overflows to a second page.
class TeamsPDF extends TCPDF {
    public string $currentTeam = '';
    public bool $isFirstPage = true;

    public function Header(): void {
        if ($this->isFirstPage) return;
        $this->SetFont('helvetica', 'I', 9);
        $this->SetTextColor(120, 120, 120);
        $this->SetXY(12, 5);
        $this->Cell(0, 5, $this->currentTeam . ' (continued)', 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new TeamsPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Hunted');
$pdf->SetTitle('Hunted — Team Rosters');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(true, 5);
$pdf->SetMargins(12, 12, 12);
$pdf->setHeaderMargin(2);

$pageW = 297; // A4 landscape
$cw = $pageW - 24;

// Register fonts
$titleFont = 'helvetica';
try {
    $reg = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Capture it.ttf', 'TrueTypeUnicode', '', 32);
    if ($reg) $titleFont = $reg;
} catch (Throwable $e) {}

$bodyFont = 'helvetica';
try {
    $reg = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/JMH Typewriter.ttf', 'TrueTypeUnicode', '', 32);
    if ($reg) $bodyFont = $reg;
} catch (Throwable $e) {}

// Column widths: #, Name, Notes (remainder)
$colNum  = 18;
$colName = 65;
$colNotes = $cw - $colNum - $colName;
$rowH = 9;

foreach ($teams as $t) {
    $pdf->currentTeam = $t['name'];
    $pdf->isFirstPage = true;
    $pdf->AddPage();

    // Team title
    $pdf->SetFont($titleFont, '', 32);
    $pdf->SetXY(12, 12);
    $pdf->Cell($cw, 14, $t['name'], 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetX(12);
    $pdf->Cell($cw, 6, count($t['players']) . ' players', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    // Table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetX(12);
    $pdf->Cell($colNum, $rowH, '#', 1, 0, 'C', true);
    $pdf->Cell($colName, $rowH, 'Name', 1, 0, 'L', true);
    $pdf->Cell($colNotes, $rowH, 'Notes', 1, 1, 'L', true);

    // After the first page for this team, continuation pages show the header
    $pdf->isFirstPage = false;

    // Player rows
    $pdf->SetFont($bodyFont, '', 11);
    foreach ($t['players'] as $p) {
        $pdf->SetX(12);
        $pdf->Cell($colNum, $rowH, (int)$p['number'], 1, 0, 'C');
        $pdf->Cell($colName, $rowH, full_name($p), 1, 0, 'L');
        $pdf->Cell($colNotes, $rowH, '', 1, 1, 'L');
    }
}

if (ob_get_length()) {
    ob_end_clean();
}
$pdf->Output('hunted-teams.pdf', 'I');
