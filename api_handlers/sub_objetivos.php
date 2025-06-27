<?php
if (!isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

switch ($method) {
    case 'POST':
        // Determinar si es una actualización (PUT) o una creación (POST)
        if (isset($data->_method) && $data->_method === 'PUT') {
            // Lógica de actualización
            $id = $data->id ?? 0;
            $texto = $data->texto ?? '';

            if ($id <= 0 || empty($texto)) {
                json_response(['error' => 'Faltan datos para actualizar'], 400);
                return;
            }

            // Verificar que el sub-objetivo pertenece al usuario actual (a través del objetivo padre)
            $stmt = $mysqli->prepare("UPDATE sub_objetivos so JOIN objetivos o ON so.objetivo_id = o.id SET so.texto = ? WHERE so.id = ? AND o.usuario_id = ?");
            $stmt->bind_param("sii", $texto, $id, $usuario_id);
            
            if ($stmt->execute()) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'No se pudo actualizar el sub-objetivo'], 500);
            }
            $stmt->close();
        } else {
            // Lógica de creación
            $objetivo_id = $data->objetivo_id ?? 0;
            $texto = $data->texto ?? '';

            if ($objetivo_id <= 0 || empty($texto)) {
                json_response(['error' => 'Faltan datos para crear el sub-objetivo'], 400);
                return;
            }

            // INICIO DE LA MODIFICACIÓN: Añadir verificación de seguridad
            // Verificar que el objetivo_id pertenece al usuario_id actual
            $stmt_check_owner = $mysqli->prepare("SELECT id FROM objetivos WHERE id = ? AND usuario_id = ?");
            $stmt_check_owner->bind_param("ii", $objetivo_id, $usuario_id);
            $stmt_check_owner->execute();
            $result_check_owner = $stmt_check_owner->get_result();

            if ($result_check_owner->num_rows === 0) {
                json_response(['error' => 'Objetivo padre no encontrado o no autorizado'], 403);
                $stmt_check_owner->close();
                return;
            }
            $stmt_check_owner->close();
            // FIN DE LA MODIFICACIÓN

            $stmt = $mysqli->prepare("INSERT INTO sub_objetivos (objetivo_id, texto) VALUES (?, ?)");
            $stmt->bind_param("is", $objetivo_id, $texto);

            if ($stmt->execute()) {
                // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
                json_response(['success' => true, 'id' => $mysqli->insert_id], 201);
            } else {
                json_response(['error' => 'No se pudo crear el sub-objetivo'], 500);
            }
            $stmt->close();
        }
        break;

    case 'DELETE':
        $id = $data->id ?? 0;
        if ($id <= 0) {
            json_response(['error' => 'ID de sub-objetivo inválido'], 400);
            return;
        }

        // Verificar que el sub-objetivo pertenece al usuario actual antes de borrar
        $stmt = $mysqli->prepare("DELETE so FROM sub_objetivos so JOIN objetivos o ON so.objetivo_id = o.id WHERE so.id = ? AND o.usuario_id = ?");
        $stmt->bind_param("ii", $id, $usuario_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'Sub-objetivo no encontrado o no autorizado'], 404);
            }
        } else {
            json_response(['error' => 'No se pudo eliminar el sub-objetivo'], 500);
        }
        $stmt->close();
        break;

    default:
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['error' => 'Método no permitido para sub-objetivos'], 405);
        break;
}
?>