<?php
require_once('tcpdf/tcpdf.php');
require 'db.php';

$fecha = $_GET['fecha'] ?? date('Y-m-d');

// Consulta de datos
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

$ruta_base = dirname(__FILE__); // Debería ser C:\xampp\htdocs\erp

// Define la ruta completa para la imagen
$ruta_imagen = $ruta_base . '/img/logosinteq.jpg';

ob_end_clean();

class MYPDF extends TCPDF {
    public function Header() {
        // --- 1. CONFIGURACIÓN E IMAGEN ---
        $img_file = dirname(__FILE__) . '/img/logosinteq.jpg';
        if (file_exists($img_file)) {
            // Dibuja la imagen.
            $this->Image($img_file, 15, 8, 40);
        }
        
        // --- 2. POSICIONAR CURSOR PARA EL TÍTULO ---
        // Mueve el cursor Y a 10mm (un poco más que la posición inicial de la imagen)
        $this->SetY(10); 
        
        // --- 3. DIBUJAR TÍTULO ---
        $this->SetFont('helvetica', 'B', 14);
        // La celda de 15mm de alto, se dibujará CENTRADA a partir de Y=10.
        $this->Cell(0, 15, 'SinterQ México - Reporte de Producción', 0, 1, 'C'); 
        
        // --- 4. ESPACIO
        $this->Ln(2); 
    }
}

// Crear PDF
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

// Encabezados de tabla
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(60, 8, 'Prensa', 1, 0, 'C', 1);
$pdf->Cell(80, 8, 'Pieza', 1, 0, 'C', 1);
$pdf->Cell(35, 8, 'Total Producido', 1, 1, 'C', 1);

$pdf->SetFont('helvetica', '', 11);

// Llenar datos
if (!empty($datos)) {
    foreach ($datos as $fila) {
        $pdf->Cell(60, 8, $fila['prensa'], 1, 0, 'C');
        $pdf->Cell(80, 8, $fila['pieza'], 1, 0, 'C');
        $pdf->Cell(35, 8, number_format($fila['total_cantidad']), 1, 1, 'R');
    }
} else {
    $pdf->Cell(175, 10, 'No hay datos disponibles para esta fecha.', 1, 1, 'C');
}

$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 10, 'Generado automáticamente por el sistema de producción SinterQ', 0, 0, 'C');

$pdf->Output('Reporte_Produccion_' . $fecha . '.pdf', 'I');
?>
