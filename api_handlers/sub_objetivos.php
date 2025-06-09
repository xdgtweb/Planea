<?php
// api_handlers/sub_objetivos.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en sub_objetivos.php."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en sub_objetivos.php."], 500);
    exit;
}
// $data_for_handler está disponible y contiene el cuerpo de la solicitud si el método es POST, PUT, DELETE

switch ($handler_http_method) {
    case 'POST': // Crear nuevo sub-objetivo
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado
            $objetivo_id = $data_for_handler['objetivo_id'] ?? '';
            $texto = $data_for_handler['texto'] ?? '';

            if (empty($objetivo_id) || empty($texto)) {
                jsonResponse(["error" => "ID de objetivo y texto son requeridos para crear un sub-objetivo."], 400);
            }
            $sql = "INSERT INTO sub_objetivos (objetivo_id, texto, completado) VALUES (?, ?, FALSE)";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { throw new Exception("DB Error (subobj_c_prep): " . $mysqli->error . " SQL: " . $sql); }
            $stmt->bind_param("ss", $objetivo_id, $texto);
            if ($stmt->execute()) {
                jsonResponse(["success" => true, "id" => $mysqli->insert_id, "texto" => $texto, "completado" => false, "objetivo_id" => $objetivo_id], 201);
            } else {
                throw new Exception("Error al añadir sub-objetivo: " . $stmt->error . " SQL: " . $sql);
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error en POST /sub_objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudo crear el sub-objetivo.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'PUT': // Actualizar sub-objetivo existente
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado
            $id_subobjetivo_put = $data_for_handler['id'] ?? null; // ID del sub-objetivo
            $texto_put = $data_for_handler['texto'] ?? '';

            if ($id_subobjetivo_put === null || empty($texto_put)) {
                jsonResponse(["error" => "ID de sub-objetivo y texto son requeridos para actualizar."], 400);
            }
            $sql_put = "UPDATE sub_objetivos SET texto = ? WHERE id = ?";
            $stmt_put = $mysqli->prepare($sql_put);
            if (!$stmt_put) { throw new Exception("DB Error (subobj_u_prep): " . $mysqli->error . " SQL: " . $sql_put); }
            $stmt_put->bind_param("si", $texto_put, $id_subobjetivo_put);
            if ($stmt_put->execute()) {
                if ($stmt_put->affected_rows > 0) {
                     jsonResponse(["success" => true, "message" => "Sub-objetivo actualizado."]);
                } else {
                     jsonResponse(["success" => false, "message" => "No se actualizó el sub-objetivo (ID no encontrado o datos iguales).", "id" => $id_subobjetivo_put], 200);
                }
            } else {
                throw new Exception("Error al actualizar sub-objetivo: " . $stmt_put->error . " SQL: " . $sql_put);
            }
            $stmt_put->close();
        } catch (Exception $e) {
            error_log("Error en PUT /sub_objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudo actualizar el sub-objetivo.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'DELETE': // Eliminar sub-objetivo
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado
            $id_subobjetivo_del = $data_for_handler['id'] ?? null; // ID del sub-objetivo
            if ($id_subobjetivo_del === null) {
                jsonResponse(["error" => "ID de sub-objetivo es requerido para eliminar."], 400);
            }
            $sql_del = "DELETE FROM sub_objetivos WHERE id = ?";
            $stmt_del = $mysqli->prepare($sql_del);
            if (!$stmt_del) { throw new Exception("DB Error (subobj_d_prep): " . $mysqli->error . " SQL: " . $sql_del); }
            $stmt_del->bind_param("i", $id_subobjetivo_del);
            if ($stmt_del->execute()) {
                if ($stmt_del->affected_rows > 0) {
                    jsonResponse(["success" => true, "message" => "Sub-objetivo eliminado."]);
                } else {
                     jsonResponse(["success" => false, "message" => "No se encontró el sub-objetivo para eliminar o ya fue eliminado."], 404);
                }
            } else {
                throw new Exception("Error al eliminar sub-objetivo: " . $stmt_del->error . " SQL: " . $sql_del);
            }
            $stmt_del->close();
        } catch (Exception $e) {
            error_log("Error en DELETE /sub_objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudo eliminar el sub-objetivo.", "details" => $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /sub_objetivos."], 405);
        break;
}

?>