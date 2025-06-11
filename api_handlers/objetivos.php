<?php
if (!isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Manejar la petición según el método HTTP
switch ($method) {
    case 'GET':
        $mode_id = $_GET['mode'] ?? null;
        if (!$mode_id) {
            // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
            json_response(['error' => 'Falta el parámetro mode'], 400);
            return;
        }

        $stmt = $mysqli->prepare("SELECT id, titulo, descripcion, fecha_estimada FROM objetivos WHERE usuario_id = ? AND mode_id = ? ORDER BY id DESC");
        $stmt->bind_param("is", $usuario_id, $mode_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $objetivos = [];
        while ($objetivo = $result->fetch_assoc()) {
            $stmt_sub = $mysqli->prepare("SELECT id, texto, completado FROM sub_objetivos WHERE objetivo_id = ? ORDER BY id ASC");
            $stmt_sub->bind_param("i", $objetivo['id']);
            $stmt_sub->execute();
            $result_sub = $stmt_sub->get_result();
            $sub_objetivos = [];
            while($sub = $result_sub->fetch_assoc()){
                $sub_objetivos[] = $sub;
            }
            $objetivo['sub_objetivos'] = $sub_objetivos;
            $stmt_sub->close();
            $objetivos[] = $objetivo;
        }
        
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response($objetivos);
        $stmt->close();
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        
        // Determinar si es una actualización (PUT) o una creación (POST)
        if (isset($data->_method) && $data->_method === 'PUT') {
            // Lógica de actualización (PUT)
            $id = $data->id ?? 0;
            $titulo = $data->titulo ?? '';
            $descripcion = $data->descripcion ?? '';
            $fecha_estimada = $data->fecha_estimada ?? '';

            if ($id <= 0 || empty($titulo)) {
                json_response(['error' => 'Faltan datos para actualizar'], 400);
                return;
            }

            $stmt = $mysqli->prepare("UPDATE objetivos SET titulo = ?, descripcion = ?, fecha_estimada = ? WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param("sssii", $titulo, $descripcion, $fecha_estimada, $id, $usuario_id);
            
            if ($stmt->execute()) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'No se pudo actualizar el objetivo'], 500);
            }
            $stmt->close();
        } else {
            // Lógica de creación (POST)
            $titulo = $data->titulo ?? '';
            $descripcion = $data->descripcion ?? '';
            $fecha_estimada = $data->fecha_estimada ?? '';
            $mode_id = $data->mode_id ?? null;

            if (empty($titulo) || empty($mode_id)) {
                json_response(['error' => 'El título y el modo son requeridos'], 400);
                return;
            }

            $stmt = $mysqli->prepare("INSERT INTO objetivos (usuario_id, titulo, descripcion, fecha_estimada, mode_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $usuario_id, $titulo, $descripcion, $fecha_estimada, $mode_id);

            if ($stmt->execute()) {
                // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
                json_response(['success' => true, 'id' => $mysqli->insert_id], 201);
            } else {
                json_response(['error' => 'No se pudo crear el objetivo'], 500);
            }
            $stmt->close();
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents("php://input"));
        $id = $data->id ?? 0;

        if ($id <= 0) {
            json_response(['error' => 'ID de objetivo inválido'], 400);
            return;
        }

        // Se asume que las claves foráneas con ON DELETE CASCADE se encargarán de los sub-objetivos.
        $stmt = $mysqli->prepare("DELETE FROM objetivos WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("ii", $id, $usuario_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'Objetivo no encontrado o no autorizado'], 404);
            }
        } else {
            json_response(['error' => 'No se pudo eliminar el objetivo'], 500);
        }
        $stmt->close();
        break;

    default:
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['error' => 'Método no permitido'], 405);
        break;
}
?>