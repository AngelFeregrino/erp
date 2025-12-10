<?php
require_once('tcpdf/tcpdf.php');
require 'db.php';

$fecha = $_GET['fecha'] ?? date('Y-m-d');

// --- Consulta de producción ---
// Usamos la misma lógica que en reportes.php: base en rendimientos y LEFT JOIN para asignar prensa por pieza.
// Esto evita duplicados (fila sin prensa + filas por prensa) y produce exactamente las mismas filas que en la vista HTML.
$stmt = $pdo->prepare("
    -- subconsulta que busca la prensa asociada a cada pieza (desde capturas_hora u ordenes cerradas)
    SELECT COALESCE(pn.prensa, '') AS prensa,
           pi.nombre AS pieza,
           COALESCE(SUM(r.producido), 0) AS total_cantidad
    FROM rendimientos r
    JOIN piezas pi ON pi.id = r.pieza_id
    LEFT JOIN (
        SELECT pieza_id, MAX(NULLIF(prensa, '')) AS prensa
        FROM (
            SELECT ch.pieza_id, pr.nombre AS prensa
            FROM capturas_hora ch
            JOIN prensas pr ON pr.id = ch.prensa_id
            WHERE ch.fecha = ?

            UNION ALL

            SELECT op.pieza_id, pr2.nombre AS prensa
            FROM ordenes_produccion op
            JOIN prensas pr2 ON pr2.id = op.prensa_id
            WHERE DATE(op.fecha_cierre) = ? AND op.estado = 'cerrada'
        ) AS t
        GROUP BY pieza_id
    ) pn ON pn.pieza_id = r.pieza_id
    WHERE r.fecha = ?
    GROUP BY pn.prensa, pi.nombre
    ORDER BY pn.prensa, pi.nombre
");
$stmt->execute([$fecha, $fecha, $fecha]);
$datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Consulta de rendimientos ---
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

class MYPDF extends TCPDF
{
    public function Header()
    {
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

// --------------------------------------------------
// --- GRÁFICA DE BARRAS ---
// Nota: aquí combinamos el porcentaje + nombre de pieza para que el % aparezca antes del nombre (solo en la gráfica de barras)
if (!empty($rendimientos)) {
    $width = 600;
    $height = 300;
    $im = imagecreatetruecolor($width, $height);

    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    $blue  = imagecolorallocate($im, 66, 135, 245);
    $green = imagecolorallocate($im, 66, 245, 96);

    imagefilledrectangle($im, 0, 0, $width, $height, $white);

    $margenIzq = 60;
    $margenInf = 40;
    $maxAltura = $height - $margenInf - 80; // CAMBIAR 20 por 60 (para dejar 60px arriba)

    // Eje Y ahora empieza en Y=60
    imageline($im, $margenIzq, 80, $margenIzq, $height - $margenInf, $black); // CAMBIAR 20 por 60
    imageline($im, $margenIzq, $height - $margenInf, $width - 20, $height - $margenInf, $black);
    $maxValor = 0;
    foreach ($rendimientos as $r) {
        $maxValor = max($maxValor, $r['esperado'], $r['producido']);
    }
    $maxValor = max(1, $maxValor);

    $numPiezas = count($rendimientos);
    $anchoBarra = 20;
    $espacio = 30;
    $x = $margenIzq + 30;

    foreach ($rendimientos as $r) {
        $esperadoAltura = ($r['esperado'] / $maxValor) * $maxAltura;
        $producidoAltura = ($r['producido'] / $maxValor) * $maxAltura;

        // Dibujar barras (esperado y producido)
        imagefilledrectangle($im, $x, $height - $margenInf - $esperadoAltura, $x + $anchoBarra, $height - $margenInf, $blue);
        imagefilledrectangle($im, $x + $anchoBarra + 5, $height - $margenInf - $producidoAltura, $x + 2 * $anchoBarra + 5, $height - $margenInf, $green);

        // Preparar etiqueta: porcentaje antes del nombre (solo aquí)
        $porcentaje = number_format((float)$r['rendimiento'], 1) . '%';
        // Limitar nombre para que no se salga; puedes ajustar 12 a lo que convenga
        $nombreCorto = substr($r['pieza'], 0, 12);
        $label = $porcentaje . ' ' . $nombreCorto;

        // Dibujar etiqueta vertical (rotada) centrada debajo de las dos barras
        // imagestringup coloca texto vertical; las coordenadas se deben ajustar
        $labelX = $x + $anchoBarra; // centro aproximado
        imagestringup($im, 2, $labelX, $height - 5, $label, $black);

        // avanzar la posición X para la siguiente pieza
        $x += 2 * $anchoBarra + $espacio;
    }

    // Leyenda - MOVIDA HACIA ABAJO (cambiar 15 por 35 y 12 por 32)
    imagefilledrectangle($im, 100, 35, 110, 45, $blue); // Y inicial 35, Y final 45 (10 pix de alto)
    imagestring($im, 3, 115, 32, 'Esperado', $black);
    imagefilledrectangle($im, 200, 35, 210, 45, $green);
    imagestring($im, 3, 215, 32, 'Producido', $black);

    $chart_path = __DIR__ . '/tmp_chart_barras.png';
    imagepng($im, $chart_path);
    imagedestroy($im);
}

// --------------------------------------------------
// --- GRÁFICA LINEAL ---
// (no cambiamos la lógica de la gráfica lineal; los porcentajes ya se mostraban sobre puntos)
if (!empty($rendimientos)) {
    $width = 600;
    $height = 300;
    $img = imagecreatetruecolor($width, $height);

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $red   = imagecolorallocate($img, 255, 99, 71);
    $blue  = imagecolorallocate($img, 66, 135, 245);

    imagefilledrectangle($img, 0, 0, $width, $height, $white);

    $margenIzq = 60;
    $margenInf = 40;
    $maxAltura = $height - $margenInf - 80; // CAMBIAR 20 por 60 (para dejar 60px arriba)

    // Eje Y ahora empieza en Y=60
    imageline($img, $margenIzq, 80, $margenIzq, $height - $margenInf, $black); // CAMBIAR 20 por 60
    imageline($img, $margenIzq, $height - $margenInf, $width - 20, $height - $margenInf, $black);

    $maxValor = 0;
    foreach ($rendimientos as $r) {
        $maxValor = max($maxValor, $r['esperado'], $r['producido']);
    }
    $maxValor = max(1, $maxValor);

    $numPiezas = count($rendimientos);
    $espacio = ($width - $margenIzq - 60) / max(1, $numPiezas - 1);

    // Dibujar líneas
    $prevX = $prevY1 = $prevY2 = null;
    foreach ($rendimientos as $i => $r) {
        $x = $margenIzq + $i * $espacio;
        $yEsperado = $height - $margenInf - ($r['esperado'] / $maxValor) * $maxAltura;
        $yProducido = $height - $margenInf - ($r['producido'] / $maxValor) * $maxAltura;

        if ($prevX !== null) {
            imageline($img, (int)$prevX, (int)$prevY1, (int)$x, (int)$yEsperado, $blue);
            imageline($img, (int)$prevX, (int)$prevY2, (int)$x, (int)$yProducido, $red);
        }

        // Mostrar porcentaje de rendimiento sobre los puntos (ya estaba así)
        $porcentaje = number_format((float)$r['rendimiento'], 1) . '%';
        imagestring(
            $img,
            3,
            (int)$x - 10,
            min((int)$yEsperado, (int)$yProducido) - 20,
            $porcentaje,
            $black
        );

        $prevX = $x;
        $prevY1 = $yEsperado;
        $prevY2 = $yProducido;
    }

    // Leyenda
    imagefilledrectangle($img, 100, 15, 110, 25, $blue);
    imagestring($img, 3, 115, 12, 'Esperado', $black);
    imagefilledrectangle($img, 200, 15, 210, 25, $red);
    imagestring($img, 3, 215, 12, 'Producido', $black);

    $line_path = __DIR__ . '/tmp_chart_lineas.png';
    imagepng($img, $line_path);
    imagedestroy($img);
}

// --------------------------------------------------
// --- CONTENIDO PDF ---
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 8, 'Fecha del reporte: ' . date('d/m/Y', strtotime($fecha)), 0, 1, 'R');
$pdf->Ln(5);

// Tabla de producción (ahora incluye columna Prensa, agrupada por prensa+pieza)
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

// Tabla de rendimientos (sin cambiar: porcentajes en su propia columna)
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Rendimientos registrados para ' . date('d/m/Y', strtotime($fecha)), 0, 1, 'C');
$pdf->Ln(4);

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(230, 230, 230);
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

$pdf->Ln(10);

// Insertar ambas gráficas
if (!empty($rendimientos)) {
    if (isset($chart_path) && file_exists($chart_path)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 70, 'Gráfica de Producción (Barras)', 0, 1, 'C');
        // Ajuste de posición/alto para que se vea bien en Letter
        $pdf->Image($chart_path, 20, 40, 170);
        $pdf->Ln(30);
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 20, 'Generado automáticamente por el sistema de producción SinterQ', 0, 0, 'C');
    }
    if (isset($line_path) && file_exists($line_path)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 70, 'Gráfica de Tendencia (Líneas)', 0, 1, 'C');
        $pdf->Image($line_path, 20, 40, 170);
    }
}

$pdf->Ln(20);
$pdf->SetFont('helvetica', 'I', 10);
$pdf->Cell(0, 20, 'Generado automáticamente por el sistema de producción SinterQ', 0, 0, 'C');

$pdf->Output('Reporte_Produccion_' . $fecha . '.pdf', 'I');

// Eliminar archivos temporales
if (isset($chart_path) && file_exists($chart_path)) unlink($chart_path);
if (isset($line_path) && file_exists($line_path)) unlink($line_path);
