<?php
session_start();
require 'db.php';

if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'operador') {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captura_id = $_POST['captura_id'];
    $cantidad = $_POST['cantidad'];
    $obs = $_POST['observaciones'];
    $firma = $_POST['firma'];

    try {
        $pdo->beginTransaction();

        // 1. Actualizar captura
        $stmt = $pdo->prepare("UPDATE capturas_hora SET cantidad=?, observaciones_op=?, firma_operador=?, estado='cerrada' WHERE id=?");
        $stmt->execute([$cantidad, $obs, $firma, $captura_id]);

        // 2. Guardar valores técnicos
        if (!empty($_POST['atributo'])) {
            foreach ($_POST['atributo'] as $atributo_id => $valor) {
                $stmt2 = $pdo->prepare("INSERT INTO valores_hora (captura_id, atributo_pieza_id, valor) VALUES (?, ?, ?)");
                $stmt2->execute([$captura_id, $atributo_id, $valor]);
            }
        }

        // 3. Obtener orden_id y pieza_id de la captura
        $stmt = $pdo->prepare("SELECT orden_id, pieza_id FROM capturas_hora WHERE id=?");
        $stmt->execute([$captura_id]);
        $cap = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cap) {
            throw new Exception("Captura de hora no encontrada.");
        }
        $orden_id = $cap['orden_id'];
        $pieza_id = $cap['pieza_id'];

        // 4. Actualizar cantidad_total_lote
        $stmt = $pdo->prepare("SELECT SUM(cantidad) AS total FROM capturas_hora WHERE orden_id=? AND pieza_id=? AND estado='cerrada'");
        $stmt->execute([$orden_id, $pieza_id]);
        $total_capturado = $stmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE ordenes_produccion SET cantidad_total_lote=? WHERE id=?");
        $stmt->execute([$total_capturado, $orden_id]);

        // 5. Actualizar operador_asignado con el que más se repite
        $stmt = $pdo->prepare("SELECT firma_operador, COUNT(*) AS veces FROM capturas_hora WHERE orden_id=? AND estado='cerrada' GROUP BY firma_operador ORDER BY veces DESC LIMIT 1");
        $stmt->execute([$orden_id]);
        $operador_mas_frecuente = $stmt->fetchColumn();

        if ($operador_mas_frecuente) {
            $stmt = $pdo->prepare("UPDATE ordenes_produccion SET operador_asignado=? WHERE id=?");
            $stmt->execute([$operador_mas_frecuente, $orden_id]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'mensaje' => '✅ Captura registrada correctamente.',
            'total_capturado' => $total_capturado
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Error al guardar la captura: ' . $e->getMessage()]);
    }
    exit();
}
