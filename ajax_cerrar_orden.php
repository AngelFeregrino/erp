<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orden_id = $_POST['orden_id'] ?? null;
    $equipo_asignado = trim($_POST['equipo_asignado'] ?? '');
    $firma_responsable = trim($_POST['firma_responsable'] ?? '');
    $admin_id = $_SESSION['id'];

    if (!$orden_id || !$equipo_asignado || !$firma_responsable) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos']);
        exit();
    }

    $stmt = $pdo->prepare("UPDATE ordenes_produccion 
                           SET equipo_asignado = ?, firma_responsable = ?, 
                               fecha_cierre = NOW(), estado = 'cerrada', admin_id = ?
                           WHERE id = ?");
    $ok = $stmt->execute([$equipo_asignado, $firma_responsable, $admin_id, $orden_id]);

    if ($ok) {
        echo json_encode([
            'success' => true,
            'id' => $orden_id,
            'fecha_cierre' => date('Y-m-d H:i:s'),
            'estado' => 'cerrada',
            'equipo_asignado' => $equipo_asignado,
            'firma_responsable' => $firma_responsable
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cerrar la orden']);
    }
    // === ACTUALIZAR RENDIMIENTO ===
$fechaHoy = date('Y-m-d');

// Obtener pieza y total producido
$stmt = $pdo->prepare("SELECT pieza_id, cantidad_total_lote FROM ordenes_produccion WHERE id = ?");
$stmt->execute([$orden_id]);
$orden = $stmt->fetch();

if ($orden) {
    // Ver si ya existe registro de rendimiento para hoy y esa pieza
    $check = $pdo->prepare("SELECT * FROM rendimientos WHERE pieza_id = ? AND fecha = ?");
    $check->execute([$orden['pieza_id'], $fechaHoy]);
    $r = $check->fetch();

    if ($r) {
        $nuevoProducido = $r['producido'] + $orden['cantidad_total_lote'];
        $rendimiento = $r['esperado'] > 0 ? ($nuevoProducido / $r['esperado']) * 100 : 0;
        $upd = $pdo->prepare("UPDATE rendimientos SET producido = ?, rendimiento = ? WHERE id = ?");
        $upd->execute([$nuevoProducido, $rendimiento, $r['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO rendimientos (pieza_id, fecha, producido) VALUES (?, ?, ?)");
        $ins->execute([$orden['pieza_id'], $fechaHoy, $orden['cantidad_total_lote']]);
    }
}

}
