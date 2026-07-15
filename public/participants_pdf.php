<?php
require __DIR__ . '/auth.php';
// Generates a PDF: one participant per A4 portrait page — photo, player name,
// then full name. Uses TCPDF (vendored in ../lib/tcpdf).
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/../lib/tcpdf/tcpdf.php';

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare(
        'SELECT p.*, t.name AS team_name
         FROM participants p JOIN teams t ON t.id = p.team_id
         WHERE p.id = ?'
    );
    $stmt->execute([$id]);
    $players = $stmt->fetchAll();
    if ($players) {
        $p = $players[0];
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $p['team_name'] . '-' . $p['number'] . '-' . full_name($p));
        $filename = 'hunted-' . trim($slug, '-') . '.pdf';
    } else {
        $filename = 'hunted-participant.pdf';
    }
} else {
    // Mirror the roster filter so a bulk export matches what's on screen.
    $where = (($_GET['filter'] ?? '') === 'nophoto') ? "WHERE p.photo IS NULL OR p.photo = ''" : '';
    $players = db()->query(
        "SELECT p.*, t.name AS team_name
         FROM participants p JOIN teams t ON t.id = p.team_id
         $where
         ORDER BY t.name, p.number"
    )->fetchAll();
    $filename = 'hunted-participants.pdf';
}

// Design switch: 'photo' (default, A4 portrait per player), 'name' (A4 landscape,
// big text per player), or 'table' (a single roster table, names as "Ben C.").
$design = $_GET['design'] ?? '';
$design = in_array($design, ['name', 'table'], true) ? $design : 'photo';
if ($design === 'name') {
    $filename = preg_replace('/\.pdf$/', '-name.pdf', $filename);
} elseif ($design === 'table') {
    $filename = preg_replace('/\.pdf$/', '-table.pdf', $filename);
}

$pdf = new TCPDF($design === 'name' ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Hunted');
$pdf->SetTitle('Hunted participants');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(15, 15, 15);

const PAGE_W = 210;   // A4 portrait width (mm)
const CONTENT = 180;   // page width minus 15mm margins each side
const BOX = 180;   // photo box (square) edge
const BOX_X = 15;    // (210 - 180) / 2
const BOX_Y = 22;    // top of photo

// Register the "Capture it" display font for the team-number line.
// addTTFfont converts + caches it in TCPDF's fonts dir on first use, then reuses.
$teamFont = 'helvetica';
try {
    $registered = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Capture it.ttf', 'TrueTypeUnicode', '', 32);
    if ($registered) {
        $teamFont = $registered;
    }
} catch (Throwable $e) {
    // keep the helvetica fallback
}

// Register the "JMH Typewriter" font for the name and capture lines.
$bodyFont = 'helvetica';
try {
    $registeredBody = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/JMH Typewriter.ttf', 'TrueTypeUnicode', '', 32);
    if ($registeredBody) {
        $bodyFont = $registeredBody;
    }
} catch (Throwable $e) {
    // keep the helvetica fallback
}

if (!$players) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 18);
    $pdf->SetXY(15, 130);
    $pdf->Cell(CONTENT, 10, 'No participants yet.', 0, 1, 'C');
}

/** Largest font size (pt) where $text fits within $maxW mm (between $min and $start). */
function fit_size(TCPDF $pdf, string $text, string $font, float $maxW, float $start, float $min): float
{
    for ($s = $start; $s > $min; $s -= 2) {
        if ($pdf->GetStringWidth($text, $font, '', $s) <= $maxW) {
            return $s;
        }
    }
    return $min;
}

/** A4-landscape page: team number + full name, each auto-sized as large as possible. */
function render_name_page(TCPDF $pdf, array $p, string $teamFont, string $bodyFont): void
{
    $pageW = 297;
    $pageH = 210;
    $margin = 40;
    $cw = $pageW - 2 * $margin;
    $teamText = $p['team_name'] . ' ' . (int)$p['number'];
    $name1Text = $p['first_name'];
    $name2Text = $p['last_name'];

    $teamSize = fit_size($pdf, $teamText, $teamFont, $cw, 150, 24);
    $nameSize = min(
        fit_size($pdf, $name1Text, $bodyFont, $cw, 100, 16),
        fit_size($pdf, $name2Text, $bodyFont, $cw, 100, 16)
    );

    $ptmm = 0.3528;                       // points -> mm
    $teamH = $teamSize * $ptmm * 1.15;
    $name1H = $nameSize * $ptmm * 1.20;
    $name2H = $nameSize * $ptmm * 1.20;
    $gap = 8;
    $startY = max(8, ($pageH - ($teamH + $gap + $name2H + $gap + $name1H)) / 2);

    $pdf->SetFont($teamFont, '', $teamSize);
    $pdf->SetXY($margin, $startY);
    $pdf->Cell($cw, $teamH, $teamText, 0, 1, 'C', false, '', 0, false, 'T', 'M');

    $pdf->SetFont($bodyFont, '', $nameSize);
    $pdf->SetXY($margin, $startY + $teamH + $gap);
    $pdf->Cell($cw, $name1H, $name1Text, 0, 1, 'C', false, '', 0, false, 'T', 'M');

    $pdf->SetFont($bodyFont, '', $nameSize);
    $pdf->SetXY($margin, $startY +$teamH + $gap+ $name1H);
    $pdf->Cell($cw, $name2H, $name2Text, 0, 1, 'C', false, '', 0, false, 'T', 'M');


    $pdf->SetFont($teamFont, '', 40);
    $pdf->SetXY($margin, $pageH - 35);
    $pdf->Cell($cw, 0, 'Blackdown District Scout Camp 2026', 0, 1, 'C', false, '', 0, false, 'T', 'M');
}

/** A single roster table: one row per player, name shown as "Ben C.". */
function render_table(TCPDF $pdf, array $players, string $teamFont, string $bodyFont): void
{
    $pdf->SetAutoPageBreak(true, 15);   // let the table flow onto extra pages
    $pdf->AddPage();

    $pdf->SetFont($teamFont, '', 28);
    $pdf->Cell(CONTENT, 14, 'Players', 0, 1, 'C');
    $pdf->Ln(2);

    $rows = '';
    foreach ($players as $p) {
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars($p['team_name'] . ' ' . (int)$p['number'], ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars(short_name($p), ENT_QUOTES) . '</td>'
            . '</tr>';
    }

    $html = '<table border="1" cellpadding="5" cellspacing="0">'
        . '<thead><tr style="background-color:#dddddd;">'
        . '<th width="50%"><b>Team</b></th>'
        . '<th width="50%"><b>Name</b></th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';

    $pdf->SetFont($bodyFont, '', 13);
    $pdf->writeHTML($html, true, false, false, false, '');
}

if ($design === 'table') {
    if ($players) {
        render_table($pdf, $players, $teamFont, $bodyFont);
    }
    // Skip the per-player page loop below.
    $players = [];
}

foreach ($players as $p) {
    $pdf->AddPage();

    if ($design === 'name') {
        render_name_page($pdf, $p, $teamFont, $bodyFont);
        continue;
    }

    // --- photo (or silhouette) in a square box, centred horizontally ---
    $photoPath = !empty($p['photo']) ? __DIR__ . '/uploads/' . basename($p['photo']) : null;
    $drawn = false;
    if ($photoPath && is_file($photoPath)) {
        try {
            // fitbox 'CM' = centre/middle within the square if aspect differs
            $pdf->Rect(BOX_X, BOX_Y, BOX, BOX);
            $pdf->Image($photoPath, BOX_X, BOX_Y, BOX, BOX, '', '', '', true, 300, '', false, false, 1, 'CM');
            $drawn = true;
        } catch (Throwable $e) {
            $drawn = false;
        }
    }
    if (!$drawn) {
        try {
            $pdf->ImageSVG(__DIR__ . '/img/silhouette_light.svg', BOX_X, BOX_Y, BOX, BOX, '', 'C', '', 1);
            $drawn = true;
        } catch (Throwable $e) {
            // last resort: a simple outlined box
            $pdf->Rect(BOX_X, BOX_Y, BOX, BOX);
        }
    }

    // --- captions under the photo ---
    $y = BOX_Y + BOX + 5;
    $pdf->SetXY(15, $y);
    $pdf->SetFont($teamFont, '', 50);
    $pdf->Cell(CONTENT, 18, $p['team_name'] . ' ' . (int)$p['number'], 0, 1, 'C');

    $pdf->SetX(15);
    $pdf->SetFont($bodyFont, '', 30);
    $pdf->Cell(CONTENT, 12, full_name($p), 0, 1, 'C');

    $pdf->SetX(15);
    $pdf->SetFont($bodyFont, '', 14);
    $pdf->Cell(CONTENT / 2, 12, '1st capture at:', 0, 0, 'L');
    $pdf->Cell(CONTENT / 2, 12, 'notes:', 0, 1, 'L');
    $pdf->Ln(10);
    $pdf->Cell(CONTENT, 12, '2nd capture at:', 0, 1, 'L');
}

// Clear any stray output buffering before sending the binary PDF.
if (ob_get_length()) {
    ob_end_clean();
}
// 'I' = send inline so the browser displays it (in the new tab the link opens).
$pdf->Output($filename, 'I');
