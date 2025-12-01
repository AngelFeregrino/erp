<?php
require_once('tcpdf/tcpdf.php');
require 'db.php';

ob_end_clean(); // evitar problemas de salida previa

// === Recuperar filtros (mismos que en cerrar_orden.php) ===
$filtro_estado = $_GET['estado'] ?? '';
$filtro_orden  = $_GET['orden'] ?? '';
$filtro_lote   = $_GET['lote'] ?? '';
$fecha_inicio  = $_GET['fecha_inicio'] ?? '';
$fecha_fin     = $_GET['fecha_fin'] ?? '';
$filtro_prensas = $_GET['prensas'] ?? [];
if (!is_array($filtro_prensas)) {
    $filtro_prensas = $filtro_prensas !== '' ? [$filtro_prensas] : [];
}

// === Construir consulta (igual que en cerrar_orden) ===
$query = "SELECT op.*, p.nombre AS pieza, pr.nombre AS prensa,
                 COALESCE(op.cantidad_inicio, 0) AS cantidad_inicio,
                 op.cantidad_final,
                 COALESCE(op.total_producida, NULL) AS total_producida
          FROM ordenes_produccion op
          JOIN piezas p ON p.id = op.pieza_id
          JOIN prensas pr ON pr.id = op.prensa_id
          WHERE 1=1";

$params = [];

// Aplicar filtros
if ($filtro_estado !== '') {
    $query .= " AND op.estado = ?";
    $params[] = $filtro_estado;
}
if ($filtro_orden !== '') {
    $query .= " AND op.numero_orden LIKE ?";
    $params[] = "%$filtro_orden%";
}
if ($filtro_lote !== '') {
    $query .= " AND p.codigo LIKE ?";
    $params[] = "%$filtro_lote%";
}
if ($fecha_inicio !== '' && $fecha_fin !== '') {
    $query .= " AND DATE(op.fecha_inicio) BETWEEN ? AND ?";
    $params[] = $fecha_inicio;
    $params[] = $fecha_fin;
}
if (!empty($filtro_prensas)) {
    $prensa_ids = array_values(array_map('intval', $filtro_prensas));
    $placeholders = implode(',', array_fill(0, count($prensa_ids), '?'));
    $query .= " AND op.prensa_id IN ($placeholders)";
    foreach ($prensa_ids as $pid) $params[] = $pid;
}

$query .= " ORDER BY op.fecha_inicio DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular total (igual que en la pantalla)
$total_cantidad = 0;
if (!empty($ordenes)) {
    foreach ($ordenes as $o) {
        if ($o['total_producida'] !== null && $o['total_producida'] !== '') {
            $total_cantidad += (int)$o['total_producida'];
        } else {
            $total_cantidad += (int)$o['cantidad_total_lote'];
        }
    }
}

// === PDF ===
class MYPDF extends TCPDF {
    public function Header() {
        $img_file = dirname(__FILE__) . '/img/logosinteq.jpg';
        if (file_exists($img_file)) {
            $this->Image($img_file, 15, 8, 40);
        }
        $this->SetY(10);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'SinterQ México - Cerrar Órdenes (Reporte)', 0, 1, 'C');
        $this->Ln(2);
    }
}

// Letter landscape para más ancho
$pdf = new MYPDF('L', 'mm', 'Letter', true, 'UTF-8', false);
$pdf->SetCreator('SinterQ');
$pdf->SetAuthor('SinterQ');
$pdf->SetTitle('Reporte Cerrar Órdenes');
$pdf->SetMargins(12, 30, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Fecha del reporte: ' . date('d/m/Y') . ($fecha_inicio && $fecha_fin ? ' | Rango: ' . date('d/m/Y', strtotime($fecha_inicio)) . ' - ' . date('d/m/Y', strtotime($fecha_fin)) : ''), 0, 1, 'R');
$pdf->Ln(4);

// Cantidad total
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(0, 8, 'Cantidad total: ' . number_format($total_cantidad), 0, 1, 'R', 0);
$pdf->Ln(4);

// === Tabla: cabezera ===
// Quitamos 'ID', 'Inicio' y 'Final' para que quepa
$w = [
    'numero_orden' => 28,
    'numero_lote' => 24,
    'pieza' => 40,
    'prensa' => 30,
    'total' => 18,
    'estado' => 15,
    'operador' => 24,
    'equipo' => 18,
    'firma' => 18,
    'fecha_inicio' => 25,
    'fecha_cierre' => 25
];

// Fila de encabezado
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(200,200,200);
$pdf->SetTextColor(0,0,0);

$pdf->Cell($w['numero_orden'], 8, 'Número Orden', 1, 0, 'C', 1);
$pdf->Cell($w['numero_lote'], 8, 'Lote', 1, 0, 'C', 1);
$pdf->Cell($w['pieza'], 8, 'Pieza', 1, 0, 'C', 1);
$pdf->Cell($w['prensa'], 8, 'Prensa', 1, 0, 'C', 1);
$pdf->Cell($w['total'], 8, 'Total', 1, 0, 'C', 1);
$pdf->Cell($w['estado'], 8, 'Estado', 1, 0, 'C', 1);
$pdf->Cell($w['operador'], 8, 'Operador', 1, 0, 'C', 1);
$pdf->Cell($w['equipo'], 8, 'Equipo', 1, 0, 'C', 1);
$pdf->Cell($w['firma'], 8, 'Firma', 1, 0, 'C', 1);
$pdf->Cell($w['fecha_inicio'], 8, 'Fecha Inicio', 1, 0, 'C', 1);
$pdf->Cell($w['fecha_cierre'], 8, 'Fecha Cierre', 1, 1, 'C', 1);

// === Filas ===
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(255,255,255);

if (empty($ordenes)) {
    $pdf->Cell(array_sum($w), 10, '⚠️ No hay órdenes encontradas para los filtros seleccionados.', 1, 1, 'C');
} else {
    foreach ($ordenes as $o) {
        // calcular cantidades: usamos total calculado como en la vista
        $cant_inicio = (int)$o['cantidad_inicio'];
        $cant_final = ($o['cantidad_final'] !== null && $o['cantidad_final'] !== '') ? (int)$o['cantidad_final'] : null;
        if ($o['total_producida'] !== null && $o['total_producida'] !== '') {
            $cant_total = (int)$o['total_producida'];
        } elseif ($cant_final !== null) {
            $cant_total = $cant_final - $cant_inicio;
            if ($cant_total < 0) $cant_total = 0;
        } else {
            $cant_total = (int)$o['cantidad_total_lote'];
        }

        // imprimir fila (sin ID, sin Inicio, sin Final)
        $pdf->Cell($w['numero_orden'], 7, substr($o['numero_orden'],0,25), 1, 0, 'L');
        $pdf->Cell($w['numero_lote'], 7, substr($o['numero_lote'] ?? '-',0,20), 1, 0, 'L');
        $pdf->Cell($w['pieza'], 7, substr($o['pieza'],0,40), 1, 0, 'L');
        $pdf->Cell($w['prensa'], 7, substr($o['prensa'],0,28), 1, 0, 'L');
        $pdf->Cell($w['total'], 7, number_format($cant_total), 1, 0, 'R');
        $pdf->Cell($w['estado'], 7, ucfirst($o['estado']), 1, 0, 'C');
        $pdf->Cell($w['operador'], 7, substr($o['operador_asignado'] ?? '-',0,25), 1, 0, 'L');
        $pdf->Cell($w['equipo'], 7, substr($o['equipo_asignado'] ?? '-',0,25), 1, 0, 'L');
        $pdf->Cell($w['firma'], 7, substr($o['firma_responsable'] ?? '-',0,25), 1, 0, 'L');
        $pdf->Cell($w['fecha_inicio'], 7, substr($o['fecha_inicio'] ?? '-',0,22), 1, 0, 'C');
        $pdf->Cell($w['fecha_cierre'], 7, substr($o['fecha_cierre'] ?? '-',0,22), 1, 1, 'C');
    }
}

// Pie de página / nota
$pdf->Ln(6);
$pdf->SetFont('helvetica', 'I', 9);
$pdf->Cell(0, 8, 'Generado automáticamente por el sistema de producción SinterQ', 0, 1, 'C');

// Salida
$pdf->Output('CerrarOrdenes_Reporte_' . ($fecha_inicio ?: date('Ymd')) . '_' . ($fecha_fin ?: date('Ymd')) . '.pdf', 'I');
exit;
