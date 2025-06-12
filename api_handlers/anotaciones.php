<?php
// api_handlers/anotaciones.php
if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD']; // Método HTTP real de la petición

// Handle based on actual method or _method override
switch ($method) {
    case 'GET':
        $mes = $_GET['mes'] ?? null;
        $anio = $_GET['anio'] ?? null;
        $fecha_single = $_GET['fecha'] ?? null; // Variable para la petición de una sola fecha

        // Si se solicita una sola fecha
        if ($fecha_single) {
            $stmt = $mysqli->prepare("
                SELECT id, fecha, emoji, descripcion
                FROM anotaciones
                WHERE usuario_id = ? AND fecha = ?
            ");
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta GET de anotación única: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param("is", $usuario_id, $fecha_single);
            $stmt->execute();
            $result = $stmt->get_result();
            $anotacion = $result->fetch_assoc(); // Obtener una sola fila
            json_response($anotacion ? $anotacion : null); // Devolver el objeto o null si no se encontró
            $stmt->close();
            break; // Salir del switch
        }
        
        // Si se solicitan por mes/año (para el calendario)
        if ($mes && $anio) {
            $stmt = $mysqli->prepare("
                SELECT fecha, emoji, descripcion
                FROM anotaciones
                WHERE usuario_id = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?
            ");
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta GET de anotaciones por mes/año: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param("iii", $usuario_id, $anio, $mes);
            $stmt->execute();
            $result = $stmt->get_result();

            $anotaciones = [];
            while ($row = $result->fetch_assoc()) {
                $anotaciones[$row['fecha']] = $row; // Clave por fecha para facilitar el acceso en el frontend
            }
            
            json_response($anotaciones);
            $stmt->close();
            break; // Salir del switch
        }
        
        // Si no se proporcionaron parámetros válidos
        json_response(['error' => 'Parámetros de fecha o mes/anio requeridos'], 400);
        break;


    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true); // Usar 'true' para obtener un array asociativo
        $_method_override = $data['_method'] ?? $method; // Obtener el método real, por defecto es POST si no se especifica _method

        // --- MANEJO DE OPERACIONES DE ELIMINACIÓN (DELETE SIMULADO) ---
        if ($_method_override === 'DELETE') {
            $fecha = $data['fecha'] ?? null; // Para eliminación, obtener 'fecha' del cuerpo de la petición

            if (!$fecha) {
                json_response(['error' => 'Fecha requerida para eliminar la anotación'], 400);
                return;
            }

            $stmt = $mysqli->prepare("DELETE FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta DELETE: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param("is", $usuario_id, $fecha);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    json_response(['success' => true, 'message' => 'Anotación eliminada.']);
                } else {
                    json_response(['error' => 'Anotación no encontrada o sin cambios.'], 404);
                }
            } else {
                json_response(['error' => 'Error al eliminar la anotación: ' . $stmt->error], 500);
            }
            $stmt->close();
            break; // Romper el caso POST después de manejar el DELETE

        // --- MANEJO DE OPERACIONES DE CREACIÓN/ACTUALIZACIÓN (POST normal) ---
        } else { 
            // Lógica existente para POST (crear/actualizar anotaciones)
            $fecha = $data['fecha'] ?? null;
            $emoji = $data['emoji'] ?? null;
            $descripcion = $data['descripcion'] ?? null;

            if (empty($fecha)) { // Fecha es obligatoria
                json_response(['error' => 'La fecha es obligatoria para la anotación'], 400);
                return;
            }

            // Verificar si la anotación ya existe para actualizar, de lo contrario insertar
            $stmt_check = $mysqli->prepare("SELECT id FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
            if (!$stmt_check) {
                json_response(['error' => 'Error al preparar verificación de anotación: ' . $mysqli->error], 500);
                return;
            }
            $stmt_check->bind_param("is", $usuario_id, $fecha);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                // Actualizar anotación existente
                $stmt_update = $mysqli->prepare("UPDATE anotaciones SET emoji = ?, descripcion = ? WHERE usuario_id = ? AND fecha = ?");
                if (!$stmt_update) {
                    json_response(['error' => 'Error al preparar actualización de anotación: ' . $mysqli->error], 500);
                    return;
                }
                $stmt_update->bind_param("ssis", $emoji, $descripcion, $usuario_id, $fecha);
                if ($stmt_update->execute()) {
                    json_response(['success' => true, 'message' => 'Anotación actualizada.']);
                } else {
                    json_response(['error' => 'No se pudo actualizar la anotación: ' . $stmt_update->error], 500);
                }
                $stmt_update->close();
            } else {
                // Insertar nueva anotación
                $stmt_insert = $mysqli->prepare("INSERT INTO anotaciones (usuario_id, fecha, emoji, descripcion) VALUES (?, ?, ?, ?)");
                if (!$stmt_insert) {
                    json_response(['error' => 'Error al preparar inserción de anotación: ' . $mysqli->error], 500);
                    return;
                }
                $stmt_insert->bind_param("isss", $usuario_id, $fecha, $emoji, $descripcion);
                if ($stmt_insert->execute()) {
                    json_response(['success' => true, 'message' => 'Anotación creada.', 'id' => $mysqli->insert_id]);
                } else {
                    json_response(['error' => 'No se pudo crear la anotación: ' . $stmt_insert->error], 500);
                }
                $stmt_insert->close();
            }
            $stmt_check->close();
            break; // Romper el caso POST después de manejar creación/actualización
        }

    case 'PUT': // Este caso ya no debería ser alcanzado directamente por el cliente si POST maneja _method=PUT
    case 'DELETE': // Este caso ya no debería ser alcanzado directamente por el cliente si POST maneja _method=DELETE
        json_response(['error' => 'Método no permitido directamente. Usa POST con _method en el cuerpo.'], 405);
        break;

    default:
        json_response(['error' => 'Método no permitido'], 405);
        break;
}
?>