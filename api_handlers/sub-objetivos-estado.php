<?php
// api_handlers/sub_objetivos_estado.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en sub_objetivos_estado.php."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en sub_objetivos_estado.php."], 500);
    exit;
}

// Este endpoint solo espera solicitudes POST (el método original era POST, 
// y $handler_http_method ya consideró cualquier _method en el router si la solicitud original era POST
// pero para esta acción específica, el frontend siempre envía un POST directo).
if ($handler_http_method === 'POST') {
    try {
        // $data_for_handler ya contiene el cuerpo JSON decodificado por el router principal
        $sub_objetivo_db_id = $data_for_handler['idSubObjetivoDB'] ?? null;
        $completado = $data_for_handler['completado'] ?? null;

        if ($sub_objetivo_db_id === null || $completado === null) {
            jsonResponse(["error" => "Datos incompletos: se requiere 'idSubObjetivoDB' y 'completado'."], 400);
        }

        // Validar que idSubObjetivoDB sea un entero
        if (!filter_var($sub_objetivo_db_id, FILTER_VALIDATE_INT)) {
            jsonResponse(["error" => "'idSubObjetivoDB' debe ser un entero."], 400);
        }
        // Validar que completado sea booleano o interpretable como tal
        if (!is_bool($completado) && !in_array($completado, [0, 1, '0', '1', 'true', 'false'], true)) {
            jsonResponse(["error" => "'completado' debe ser un valor booleano (true/false o 1/0)."], 400);
        }


        $sql = "UPDATE sub_objetivos SET completado = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("DB Error (subobj_estado_u_prep): " . $mysqli->error . " SQL: " . $sql);
        }

        $completado_bool_int = filter_var($completado, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; 
        $stmt->bind_param("ii", $completado_bool_int, $sub_objetivo_db_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                jsonResponse([
                    "success" => true, 
                    "message" => "Estado de sub-objetivo actualizado.", 
                    "id_actualizado" => $sub_objetivo_db_id, 
                    "nuevo_estado" => (bool)$completado_bool_int // Enviar el estado booleano
                ]);
            } else {
                // Podría ser que el ID no exista o el estado ya era el mismo.
                jsonResponse([
                    "success" => false, 
                    "message" => "No se actualizó el estado del sub-objetivo (puede que el ID no exista o el estado ya fuera el mismo).",
                    "id_intentado" => $sub_objetivo_db_id,
                    "estado_intentado" => (bool)$completado_bool_int
                ], 200); // 200 OK porque la solicitud fue válida, pero no hubo cambios. O 404 si ID no existe es preferible.
            }
        } else {
            throw new Exception("Error al actualizar estado de sub-objetivo: " . $stmt->error . " SQL: " . $sql);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error en POST /sub-objetivos-estado: " . $e->getMessage());
        jsonResponse(["error" => "No se pudo actualizar el estado del sub-objetivo.", "details" => $e->getMessage()], 500);
    }
} else {
    jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /sub-objetivos-estado. Solo se acepta POST."], 405);
}

?>