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
    // Usaremos datos de la orden: pieza_id, cantidad_total_lote y numero_lote (si existe)
    $fechaHoy = date('Y-m-d');
    $pieza_id = intval($orden['pieza_id']);
    $cantidad_total_lote = isset($orden['cantidad_total_lote']) ? intval($orden['cantidad_total_lote']) : 0;
    $numero_lote = isset($orden['numero_lote']) ? $orden['numero_lote'] : null;

    // comprobar si la tabla rendimientos tiene columnas orden_id o numero_lote (no modificamos estructura)
    $colCheck = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'rendimientos'
          AND column_name IN ('orden_id', 'numero_lote')
    ");
    $colCheck->execute();
    $cols = $colCheck->fetchAll(PDO::FETCH_COLUMN);

    $hasOrdenId = in_array('orden_id', $cols, true);
    $hasNumeroLote = in_array('numero_lote', $cols, true);

    // Preferimos buscar por orden_id (si existe esa columna), luego por numero_lote
    $existing = false;
    $existingId = null;
    if ($hasOrdenId) {
        $chkR = $pdo->prepare("SELECT id, producido, esperado FROM rendimientos WHERE orden_id = ?");
        $chkR->execute([$orden_id]);
        $existing = (bool)$chkR->rowCount();
        $r = $chkR->fetch(PDO::FETCH_ASSOC);
        if ($existing) $existingId = $r['id'];
    } elseif ($hasNumeroLote && $numero_lote !== null) {
        $chkR = $pdo->prepare("SELECT id, producido, esperado FROM rendimientos WHERE numero_lote = ?");
        $chkR->execute([$numero_lote]);
        $existing = (bool)$chkR->rowCount();
        $r = $chkR->fetch(PDO::FETCH_ASSOC);
        if ($existing) $existingId = $r['id'];
    } else {
        // fallback: buscar por pieza y fecha (comportamiento anterior)
        $chkR = $pdo->prepare("SELECT id, producido, esperado FROM rendimientos WHERE pieza_id = ? AND fecha = ?");
        $chkR->execute([$pieza_id, $fechaHoy]);
        $existing = (bool)$chkR->rowCount();
        $r = $chkR->fetch(PDO::FETCH_ASSOC);
        if ($existing) $existingId = $r['id'];
    }

    // Calcular nuevo producido y rendimiento
    $esperado = $cantidad_total_lote > 0 ? $cantidad_total_lote : (isset($r['esperado']) ? intval($r['esperado']) : 0);
    if ($existing) {
        // sumar producido sobre lo existente
        $prevProducido = isset($r['producido']) ? intval($r['producido']) : 0;
        $nuevoProducido = $prevProducido + $total_producida;
        $rendimiento = ($esperado > 0) ? round(($nuevoProducido / $esperado) * 100, 2) : 0;

        // Actualizar: si existen columnas orden_id/numero_lote no las tocamos aquí (ya están)
        $updR = $pdo->prepare("UPDATE rendimientos SET producido = ?, rendimiento = ?, fecha_registro = NOW() WHERE id = ?");
        $updR->execute([$nuevoProducido, $rendimiento, $existingId]);
    } else {
        // Insertar nuevo registro. Insertaremos los campos que existan en la tabla.
        // Construimos INSERT dinámico según columnas disponibles (orden_id, numero_lote)
        $fields = ['pieza_id', 'fecha', 'esperado', 'producido', 'rendimiento', 'fecha_registro'];
        $values = [$pieza_id, $fechaHoy, $esperado, $total_producida, ($esperado > 0 ? round(($total_producida / $esperado) * 100, 2) : 0), date('Y-m-d H:i:s')];
        $placeholders = array_fill(0, count($fields), '?');

        // Si existen columnas opcionales, las agregamos y sus valores correspondientes
        if ($hasOrdenId) {
            array_splice($fields, 0, 0, 'orden_id'); // poner orden_id al inicio para claridad
            array_splice($placeholders, 0, 0, '?');
            array_splice($values, 0, 0, [$orden_id]);
        }
        if ($hasNumeroLote) {
            // insertar numero_lote si está disponible (si no, null igual se inserta)
            array_splice($fields, 1, 0, 'numero_lote'); // después de orden_id o al inicio si no existe orden_id
            array_splice($placeholders, 1, 0, '?');
            array_splice($values, 1, 0, [$numero_lote]);
        }

        $sqlIns = "INSERT INTO rendimientos (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $insR = $pdo->prepare($sqlIns);
        $insR->execute($values);
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
