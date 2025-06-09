<?php
// api_handlers/anotaciones.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en anotaciones.php."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en anotaciones.php."], 500);
    exit;
}

switch ($handler_http_method) {
    case 'GET':
        try {
            if (isset($_GET['fecha'])) { 
                $fecha = $_GET['fecha'];
                if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha)) {
                    jsonResponse(["error" => "Formato de fecha inválido. Use YYYY-MM-DD."], 400);
                }
                $sql = "SELECT fecha, emoji, descripcion FROM dia_anotaciones WHERE fecha = ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) { throw new Exception("DB Prepare Error (anot_g1): " . $mysqli->error . " SQL: " . $sql); }
                $stmt->bind_param("s", $fecha);
                if (!$stmt->execute()) { throw new Exception("DB Execute Error (anot_g1): " . $stmt->error . " SQL: " . $sql); }
                $result = $stmt->get_result();
                $anotacion = $result->fetch_assoc();
                $stmt->close();
                jsonResponse($anotacion ?: null); 
            } elseif (isset($_GET['mes']) && isset($_GET['anio'])) { 
                $mes = filter_var($_GET['mes'], FILTER_VALIDATE_INT);
                $anio = filter_var($_GET['anio'], FILTER_VALIDATE_INT);
                if (!$mes || !$anio || $mes < 1 || $mes > 12 || $anio < 1900 || $anio > 2100) {
                    jsonResponse(["error" => "Parámetros de mes y año inválidos."], 400);
                }
                $fecha_inicio_mes = sprintf("%04d-%02d-01", $anio, $mes);
                $fecha_fin_mes = date("Y-m-t", strtotime($fecha_inicio_mes));
                
                $sql = "SELECT fecha, emoji, descripcion FROM dia_anotaciones WHERE fecha >= ? AND fecha <= ?";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) { throw new Exception("DB Prepare Error (anot_g2): " . $mysqli->error . " SQL: " . $sql); }
                $stmt->bind_param("ss", $fecha_inicio_mes, $fecha_fin_mes);
                if (!$stmt->execute()) { throw new Exception("DB Execute Error (anot_g2): " . $stmt->error . " SQL: " . $sql); }
                $result = $stmt->get_result();
                $anotaciones = [];
                while ($row = $result->fetch_assoc()) { $anotaciones[$row['fecha']] = $row; }
                $stmt->close();
                jsonResponse($anotaciones);
            } else {
                jsonResponse(["error" => "Parámetros 'fecha' o 'mes' y 'anio' requeridos para GET /anotaciones."], 400);
            }
        } catch (Exception $e) {
            error_log("Error en GET /anotaciones: " . $e->getMessage());
            jsonResponse(["error" => "Error al procesar la solicitud de anotaciones.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'POST': // Crear o Actualizar anotación
        try {
            // $data_for_handler contiene el cuerpo JSON decodificado del router principal
            $fecha = $data_for_handler['fecha'] ?? null;
            $emoji = $data_for_handler['emoji'] ?? null; 
            $descripcion = $data_for_handler['descripcion'] ?? null;

            if (!$fecha || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha)) { jsonResponse(["error" => "Fecha es requerida y debe estar en formato YYYY-MM-DD."], 400); }

            // Restricción: No permitir crear/actualizar anotaciones de días pasados
            // getTodayDate() se define en el api.php principal
            $hoy_obj = new DateTimeImmutable(getTodayDate(), new DateTimeZone('UTC')); 
            $fecha_obj = new DateTimeImmutable($fecha, new DateTimeZone('UTC')); 
            if ($fecha_obj < $hoy_obj) { jsonResponse(["error" => "No se pueden crear o modificar anotaciones de días pasados."], 403); }
            
            if ($emoji !== null && mb_strlen($emoji, 'UTF-8') > 30) { jsonResponse(["error" => "Cadena de emojis demasiado larga (máx 30 caracteres)."], 400); }
            if ($descripcion !== null && mb_strlen($descripcion, 'UTF-8') > 255) { jsonResponse(["error" => "Descripción demasiado larga (máx 255 caracteres)."], 400); }

            if ( (empty($emoji) || $emoji === null) && (empty($descripcion) || $descripcion === null) ) {
                $sql_del = "DELETE FROM dia_anotaciones WHERE fecha = ?";
                $stmt_del = $mysqli->prepare($sql_del);
                if (!$stmt_del) { throw new Exception("DB Prepare Error (anot_post_del): " . $mysqli->error . " SQL: " . $sql_del); }
                $stmt_del->bind_param("s", $fecha);
                if (!$stmt_del->execute()) { throw new Exception("DB Execute Error (anot_post_del): " . $stmt_del->error . " SQL: " . $sql_del); }
                $affected_rows_del = $stmt_del->affected_rows; // Guardar antes de cerrar
                $stmt_del->close();
                jsonResponse(["success" => true, "message" => $affected_rows_del > 0 ? "Anotación eliminada (campos vacíos)." : "No había anotación que eliminar."]);
            } else {
                $sql = "INSERT INTO dia_anotaciones (fecha, emoji, descripcion) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), descripcion = VALUES(descripcion)";
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) { throw new Exception("DB Prepare Error (anot_post_upsert): " . $mysqli->error . " SQL: " . $sql); }
                $stmt->bind_param("sss", $fecha, $emoji, $descripcion);
                if (!$stmt->execute()) { throw new Exception("DB Execute Error (anot_post_upsert): " . $stmt->error . " SQL: " . $sql); }
                $stmt->close();
                jsonResponse(["success" => true, "message" => "Anotación guardada."]);
            }
        } catch (Exception $e) {
            error_log("Error en POST /anotaciones: " . $e->getMessage());
            jsonResponse(["error" => "Error al procesar la anotación.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'DELETE': 
        try {
            // $data_for_handler contiene el cuerpo JSON decodificado
            $fecha_del = $data_for_handler['fecha'] ?? null;
            if (!$fecha_del || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_del)) { jsonResponse(["error" => "Fecha requerida (YYYY-MM-DD) para eliminar."], 400); }

            $hoy_obj_del = new DateTimeImmutable(getTodayDate(), new DateTimeZone('UTC')); 
            $fecha_del_obj_del = new DateTimeImmutable($fecha_del, new DateTimeZone('UTC'));
            if ($fecha_del_obj_del < $hoy_obj_del) { jsonResponse(["error" => "No se pueden eliminar anotaciones de días pasados."], 403); }

            $sql_delete = "DELETE FROM dia_anotaciones WHERE fecha = ?";
            $stmt_delete = $mysqli->prepare($sql_delete);
            if (!$stmt_delete) { throw new Exception("DB Prepare Error (anot_del): " . $mysqli->error . " SQL: " . $sql_delete); }
            $stmt_delete->bind_param("s", $fecha_del);
            if (!$stmt_delete->execute()) { throw new Exception("DB Execute Error (anot_del): " . $stmt_delete->error . " SQL: " . $sql_delete); }
            $affected_rows = $stmt_delete->affected_rows;
            $stmt_delete->close();
            jsonResponse(["success" => true, "message" => $affected_rows > 0 ? "Anotación eliminada." : "No se encontró anotación para eliminar."]);
        } catch (Exception $e) {
            error_log("Error en DELETE /anotaciones: " . $e->getMessage());
            jsonResponse(["error" => "Error al eliminar la anotación.", "details" => $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /anotaciones."], 405);
        break;
}

?>