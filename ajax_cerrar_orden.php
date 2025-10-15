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
}
