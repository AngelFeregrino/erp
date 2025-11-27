<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$captura_id = $_POST['captura_id'] ?? null;
$obs = trim($_POST['observaciones'] ?? '');
$firma = trim($_POST['firma'] ?? '');
$atributos_post = $_POST['atributo'] ?? [];

if (!$captura_id || $firma === '') {
    echo json_encode(['error' => 'Faltan datos requeridos (captura_id o firma)']);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1) Actualizar captura: sin campo cantidad (lo dejamos intacto)
    $stmt = $pdo->prepare("UPDATE capturas_hora SET observaciones_op = ?, firma_operador = ?, estado = 'cerrada' WHERE id = ?");
    $stmt->execute([$obs, $firma, $captura_id]);

    // 2) Guardar valores técnicos (valores_hora), pero recalculando densidad en servidor
    if (!empty($atributos_post) && is_array($atributos_post)) {
        // Preparar lista de ids enviados
        $ids = array_map('intval', array_keys($atributos_post));
        if (!empty($ids)) {
            // Obtener metadatos de esos atributos desde atributos_pieza
            $in = str_repeat('?,', count($ids) - 1) . '?';
            $stmtMeta = $pdo->prepare("SELECT id, nombre_atributo, valor_predeterminado FROM atributos_pieza WHERE id IN ($in)");
            $stmtMeta->execute($ids);
            $meta = $stmtMeta->fetchAll(PDO::FETCH_ASSOC);
            $metaById = [];
            foreach ($meta as $m) $metaById[$m['id']] = $m;

            // Buscar si hay un atributo "peso" entre los enviados
            $peso_val = null;
            foreach ($ids as $iid) {
                $nombre = strtolower($metaById[$iid]['nombre_atributo'] ?? '');
                if (strpos($nombre, 'peso') !== false) {
                    $raw = $atributos_post[$iid];
                    if (is_numeric($raw)) $peso_val = floatval($raw);
                    break;
                }
            }

            // Preparar statement para insertar/actualizar valores_hora
            // Asumo que existe clave única (captura_id, atributo_pieza_id); si no existe, el INSERT sin ON DUPLICATE funcionará
            $stmtIns = $pdo->prepare("
                INSERT INTO valores_hora (captura_id, atributo_pieza_id, valor)
                VALUES (:cid, :aid, :val)
                ON DUPLICATE KEY UPDATE valor = VALUES(valor)
            ");

            foreach ($ids as $aid) {
                $raw = $atributos_post[$aid];
                $nombre = strtolower($metaById[$aid]['nombre_atributo'] ?? '');
                $valor_pred = isset($metaById[$aid]['valor_predeterminado']) ? floatval($metaById[$aid]['valor_predeterminado']) : null;

                // Si es el atributo densidad, recalculamos en servidor
                if (strpos($nombre, 'densidad') !== false) {
                    if ($peso_val !== null && $valor_pred > 0) {
                        $valor_calc = $peso_val / $valor_pred;
                        $valor = number_format($valor_calc, 3, '.', '');
                    } else {
                        // no hay información suficiente para calcular densidad; guardamos NULL
                        $valor = null;
                    }
                } else {
                    // valor normal: si es numérico lo guardamos con 3 decimales, si no, lo limpiamos
                    if (is_numeric($raw)) {
                        $valor = number_format((float)$raw, 3, '.', '');
                    } else {
                        $valor = trim($raw);
                    }
                }

                // Ejecutar inserción/actualización
                $stmtIns->execute([
                    ':cid' => $captura_id,
                    ':aid' => intval($aid),
                    ':val' => $valor
                ]);
            }
        }
    }

    // 3) Obtener orden_id y pieza_id de la captura para actualizar operador asignado (no sumamos cantidades)
    $stmt = $pdo->prepare("SELECT orden_id, pieza_id FROM capturas_hora WHERE id = ?");
    $stmt->execute([$captura_id]);
    $cap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cap) {
        throw new Exception("Captura de hora no encontrada.");
    }
    $orden_id = $cap['orden_id'];
    $pieza_id = $cap['pieza_id'];

    // 4) Actualizar operador_asignado con el que más se repite entre capturas cerradas para esa orden
    $stmt = $pdo->prepare("SELECT firma_operador, COUNT(*) AS veces FROM capturas_hora WHERE orden_id = ? AND estado = 'cerrada' GROUP BY firma_operador ORDER BY veces DESC LIMIT 1");
    $stmt->execute([$orden_id]);
    $operador_mas_frecuente = $stmt->fetchColumn();

    if ($operador_mas_frecuente) {
        $stmt = $pdo->prepare("UPDATE ordenes_produccion SET operador_asignado = ? WHERE id = ?");
        $stmt->execute([$operador_mas_frecuente, $orden_id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'mensaje' => '✅ Captura registrada correctamente.',
        'orden_id' => $orden_id,
        'captura_id' => $captura_id
    ]);
    exit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("guardar_captura_ajax_error: " . $e->getMessage());
    echo json_encode(['error' => 'Error al guardar la captura: ' . $e->getMessage()]);
    exit();
}
