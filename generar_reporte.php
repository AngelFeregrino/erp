<?php
require_once('tcpdf/tcpdf.php');
require 'db.php';

$fecha = $_GET['fecha'] ?? date('Y-m-d');

// --- Consulta de producción ---
$stmt = $pdo->prepare("
    SELECT pr.nombre AS prensa, pi.nombre AS pieza,
           SUM(ch.cantidad) AS total_cantidad
    FROM capturas_hora ch
    JOIN prensas pr ON pr.id = ch.prensa_id
    JOIN piezas pi ON pi.id = ch.pieza_id
    WHERE ch.fecha = ?
    GROUP BY pr.nombre, pi.nombre
    ORDER BY pr.nombre, pi.nombre
");
$stmt->execute([$fecha]);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Consulta de rendimientos (ajustada a tu esquema) ---
$stmt2 = $pdo->prepare("
    SELECT r.id, r.pieza_id, pi.nombre AS pieza, r.esperado, r.producido, r.rendimiento, r.fecha_registro
    FROM rendimientos r
    JOIN piezas pi ON pi.id = r.pieza_id
    WHERE r.fecha = ?
    ORDER BY pi.nombre
");
$stmt2->execute([$fecha]);
$rendimientos = $stmt2->fetchAll(PDO::FETCH_ASSOC);

ob_end_clean();

class MYPDF extends TCPDF {
    public function Header() {
        $img_file = dirname(__FILE__) . '/img/logosinteq.jpg';
        if (file_exists($img_file)) {
            $this->Image($img_file, 15, 8, 40);
        }
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'SinterQ México - Reporte de Producción', 0, 1, 'C');
        $this->Ln(2);
    }
}

$pdf = new MYPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
$pdf->SetCreator('SinterQ');
$pdf->SetAuthor('SinterQ');
$pdf->SetTitle('Reporte de Producción');
$pdf->SetMargins(15, 30, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Fecha del reporte: ' . date('d/m/Y', strtotime($fecha)), 0, 1, 'R');
$pdf->Ln(5);

// --- Tabla principal: Producción ---
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(60, 8, 'Prensa', 1, 0, 'C', 1);
$pdf->Cell(80, 8, 'Pieza', 1, 0, 'C', 1);
$pdf->Cell(35, 8, 'Total Producido', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 11);
if (!empty($datos)) {
    foreach ($datos as $fila) {
        $pdf->Cell(60, 8, $fila['prensa'], 1, 0, 'C');
        $pdf->Cell(80, 8, $fila['pieza'], 1, 0, 'C');
        $pdf->Cell(35, 8, number_format((int)$fila['total_cantidad']), 1, 1, 'R');
    }
} else {
    $pdf->Cell(175, 10, 'No hay datos disponibles para esta fecha.', 1, 1, 'C');
}

$pdf->Ln(10);

// --- Tabla secundaria: Rendimientos (según tu tabla rendimientos) ---
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Rendimientos registrados para ' . date('d/m/Y', strtotime($fecha)), 0, 1, 'C');
$pdf->Ln(4);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(230, 230, 230);
// Ajusté anchos para caber en la misma anchura que la tabla anterior (175mm en total)
$pdf->Cell(90, 8, 'Pieza', 1, 0, 'C', 1);
$pdf->Cell(25, 8, 'Esperado', 1, 0, 'C', 1);
$pdf->Cell(25, 8, 'Producido', 1, 0, 'C', 1);
$pdf->Cell(35, 8, 'Rendimiento %', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 11);
if (!empty($rendimientos)) {
    foreach ($rendimientos as $r) {
        $pdf->Cell(90, 8, $r['pieza'], 1, 0, 'C');
        $pdf->Cell(25, 8, number_format((int)$r['esperado']), 1, 0, 'R');
        $pdf->Cell(25, 8, number_format((int)$r['producido']), 1, 0, 'R');
        $pdf->Cell(35, 8, number_format((float)$r['rendimiento'], 2) . ' %', 1, 1, 'R');
    }
} else {
    $pdf->Cell(175, 10, 'No hay rendimientos registrados para esta fecha.', 1, 1, 'C');
}

$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 10, 'Generado automáticamente por el sistema de producción SinterQ', 0, 0, 'C');

$pdf->Output('Reporte_Produccion_' . $fecha . '.pdf', 'I');
?>
