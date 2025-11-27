<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

$orden_id = isset($_POST['orden_id']) ? intval($_POST['orden_id']) : 0;
$equipo_asignado = trim($_POST['equipo_asignado'] ?? '');
$firma_responsable = trim($_POST['firma_responsable'] ?? '');
$cantidad_final = isset($_POST['cantidad_final']) ? intval($_POST['cantidad_final']) : null;
$admin_id = $_SESSION['id'];

if (!$orden_id || $equipo_asignado === '' || $firma_responsable === '' || $cantidad_final === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan datos requeridos (orden, equipo, firma, cantidad_final)']);
    exit();
}

try {
    // Iniciar transacción para consistencia
    $pdo->beginTransaction();

    // Bloquear la fila de la orden para lectura consistente
    $stmt = $pdo->prepare("SELECT id, pieza_id, cantidad_inicio, cantidad_total_lote, estado FROM ordenes_produccion WHERE id = ? FOR UPDATE");
    $stmt->execute([$orden_id]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orden) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Orden no encontrada']);
        exit();
    }

    if ($orden['estado'] === 'cerrada') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La orden ya está cerrada']);
        exit();
    }

    // Asegurar cantidad_inicio numérica
    $cantidad_inicio = isset($orden['cantidad_inicio']) ? intval($orden['cantidad_inicio']) : 0;

    // Calcular total producido
    $total_producida = $cantidad_final - $cantidad_inicio;

    if ($total_producida < 0) {
        // Evitamos grabar valores negativos: abortar y devolver error para que el admin revise
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Cantidad final menor que cantidad de inicio. Verifica la entrada.']);
        exit();
    }

    // Actualizar la orden con cantidad_final y total_producida
    $upd = $pdo->prepare("UPDATE ordenes_produccion
                          SET equipo_asignado = :ea,
                              firma_responsable = :fr,
                              cantidad_final = :cf,
                              total_producida = :tp,
                              fecha_cierre = NOW(),
                              estado = 'cerrada',
                              admin_id = :aid
                          WHERE id = :id");
    $ok = $upd->execute([
        ':ea'  => $equipo_asignado,
        ':fr'  => $firma_responsable,
        ':cf'  => $cantidad_final,
        ':tp'  => $total_producida,
        ':aid' => $admin_id,
        ':id'  => $orden_id
    ]);

    if (!$ok) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al actualizar la orden']);
        exit();
    }

    // Opcional: deshabilitar prensas habilitadas relacionadas
    $stmtPh = $pdo->prepare("UPDATE prensas_habilitadas SET habilitado = 0 WHERE orden_id = ?");
    $stmtPh->execute([$orden_id]);

    // Marcar capturas_hora relacionadas como cerradas (histórico)
    $stmtCh = $pdo->prepare("UPDATE capturas_hora SET estado = 'cerrada' WHERE orden_id = ?");
    $stmtCh->execute([$orden_id]);

    // === Actualizar tabla rendimientos usando total_producida ===
    $fechaHoy = date('Y-m-d');
    $pieza_id = intval($orden['pieza_id']);

    // Buscar registro existente de rendimiento para la pieza y fecha
    $check = $pdo->prepare("SELECT id, producido, esperado FROM rendimientos WHERE pieza_id = ? AND fecha = ?");
    $check->execute([$pieza_id, $fechaHoy]);
    $r = $check->fetch(PDO::FETCH_ASSOC);

    if ($r) {
        $nuevoProducido = intval($r['producido']) + $total_producida;
        $esperado = floatval($r['esperado']);
        $rendimiento = $esperado > 0 ? ($nuevoProducido / $esperado) * 100 : 0;
        $updR = $pdo->prepare("UPDATE rendimientos SET producido = ?, rendimiento = ? WHERE id = ?");
        $updR->execute([$nuevoProducido, $rendimiento, $r['id']]);
    } else {
        // Insertar nuevo registro
        $insR = $pdo->prepare("INSERT INTO rendimientos (pieza_id, fecha, producido) VALUES (?, ?, ?)");
        $insR->execute([$pieza_id, $fechaHoy, $total_producida]);
    }

    $pdo->commit();

    // Responder con los datos finales
    echo json_encode([
        'success' => true,
        'id' => $orden_id,
        'fecha_cierre' => date('Y-m-d H:i:s'),
        'estado' => 'cerrada',
        'equipo_asignado' => $equipo_asignado,
        'firma_responsable' => $firma_responsable,
        'cantidad_final' => $cantidad_final,
        'cantidad_inicio' => $cantidad_inicio,
        'total_producida' => $total_producida
    ]);
    exit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("ajax_cerrar_error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
    exit();
}
