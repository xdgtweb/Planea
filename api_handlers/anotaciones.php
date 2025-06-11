<?php
if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}
$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ... (lógica para obtener anotaciones)
    $mes = $_GET['mes'] ?? null;
    $anio = $_GET['anio'] ?? null;
    $fecha_especifica = $_GET['fecha'] ?? null;
    
    $anotaciones = [];
    
    if ($fecha_especifica) {
        $stmt = $mysqli->prepare("SELECT fecha, emoji, descripcion FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
        $stmt->bind_param("is", $usuario_id, $fecha_especifica);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $anotaciones = $row;
        }
    } elseif ($mes && $anio) {
        $stmt = $mysqli->prepare("SELECT fecha, emoji, descripcion FROM anotaciones WHERE usuario_id = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?");
        $stmt->bind_param("iii", $usuario_id, $mes, $anio);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $anotaciones[$row['fecha']] = $row;
        }
    }
    
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response($anotaciones);
    $stmt->close();

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    $fecha = $data->fecha ?? null;
    $emoji = $data->emoji ?? '';
    $descripcion = $data->descripcion ?? '';

    if (!$fecha) {
        json_response(['error' => 'Fecha requerida'], 400);
        return;
    }

    $stmt_check = $mysqli->prepare("SELECT id FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
    $stmt_check->bind_param("is", $usuario_id, $fecha);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        $stmt = $mysqli->prepare("UPDATE anotaciones SET emoji = ?, descripcion = ? WHERE usuario_id = ? AND fecha = ?");
        $stmt->bind_param("ssis", $emoji, $descripcion, $usuario_id, $fecha);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO anotaciones (usuario_id, fecha, emoji, descripcion) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $usuario_id, $fecha, $emoji, $descripcion);
    }
    
    if ($stmt->execute()) {
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['success' => true], 200);
    } else {
        json_response(['error' => 'No se pudo guardar la anotación'], 500);
    }
    $stmt->close();
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"));
    $fecha = $data->fecha ?? null;
    
    if (!$fecha) {
        json_response(['error' => 'Fecha requerida para eliminar'], 400);
        return;
    }
    
    $stmt = $mysqli->prepare("DELETE FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
    $stmt->bind_param("is", $usuario_id, $fecha);
    
    if ($stmt->execute()) {
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['success' => true], 200);
    } else {
        json_response(['error' => 'No se pudo eliminar la anotación'], 500);
    }
    $stmt->close();
} else {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Método no permitido'], 405);
}
?>