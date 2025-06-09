<?php
// api_handlers/objetivos.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    // Esta comprobación es una salvaguarda. El router principal ya debería haberla manejado.
    if (function_exists('jsonResponse')) {
        jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en objetivos.php."], 500);
    } else {
        http_response_code(500); // Fallback si jsonResponse no está definida aún
        echo '{"error":"Error crítico: Conexión a base de datos no disponible en objetivos.php."}';
    }
    exit;
}
if (!isset($handler_http_method)) {
    if (function_exists('jsonResponse')) {
        jsonResponse(["error" => "Error crítico: Método HTTP no determinado en objetivos.php."], 500);
    } else {
        http_response_code(500);
        echo '{"error":"Error crítico: Método HTTP no determinado en objetivos.php."}';
    }
    exit;
}

switch ($handler_http_method) {
    case 'GET':
        try {
            $mode_id_get = $_GET['mode'] ?? ''; 
            if (empty($mode_id_get)) {
                jsonResponse(["error" => "Parámetro 'mode' es requerido para GET /objetivos."], 400);
            }
            
            $objetivos_map = [];
            $sql_objetivos = "SELECT id, titulo, fecha_estimada, descripcion FROM objetivos WHERE mode_id = ? ORDER BY creado_en DESC, id DESC"; // Ordenar por creación o algún otro campo
            $stmt_obj = $mysqli->prepare($sql_objetivos);
            if (!$stmt_obj) { throw new Exception("DB Error (obj_g1_prep): " . $mysqli->error . " SQL: " . $sql_objetivos); }
            $stmt_obj->bind_param("s", $mode_id_get);
            if (!$stmt_obj->execute()) { throw new Exception("DB Error (obj_g1_exec): " . $stmt_obj->error . " SQL: " . $sql_objetivos); }
            $result_obj = $stmt_obj->get_result();
            $objetivo_ids = [];
            while ($row = $result_obj->fetch_assoc()) {
                $row['sub_objetivos'] = [];
                $objetivos_map[$row['id']] = $row;
                $objetivo_ids[] = $row['id'];
            }
            $stmt_obj->close();

            if (!empty($objetivo_ids)) {
                $placeholders = implode(',', array_fill(0, count($objetivo_ids), '?'));
                $types = str_repeat('s', count($objetivo_ids));
                $sql_sub = "SELECT id, objetivo_id, texto, completado FROM sub_objetivos WHERE objetivo_id IN ($placeholders) ORDER BY id ASC"; // Ordenar subobjetivos
                $stmt_sub = $mysqli->prepare($sql_sub);
                if (!$stmt_sub) { throw new Exception("DB Error (obj_g2_prep): " . $mysqli->error . " SQL: " . $sql_sub); }
                $stmt_sub->bind_param($types, ...$objetivo_ids);
                if (!$stmt_sub->execute()) { throw new Exception("DB Error (obj_g2_exec): " . $stmt_sub->error . " SQL: " . $sql_sub); }
                $result_sub = $stmt_sub->get_result();
                while ($sub_row = $result_sub->fetch_assoc()) {
                    $sub_row['completado'] = (bool)$sub_row['completado'];
                    if (isset($objetivos_map[$sub_row['objetivo_id']])) {
                        $objetivos_map[$sub_row['objetivo_id']]['sub_objetivos'][] = $sub_row;
                    }
                }
                $stmt_sub->close();
            }
            jsonResponse(array_values($objetivos_map));
        } catch (Exception $e) {
            error_log("Error en GET /objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudieron obtener los objetivos.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'POST': // Crear nuevo objetivo
        try {
            // $data_for_handler es el payload JSON decodificado por el router principal
            $id_objetivo = $data_for_handler['id'] ?? uniqid('obj_');
            $titulo = $data_for_handler['titulo'] ?? '';
            $fecha_estimada = $data_for_handler['fecha_estimada'] ?? '';
            $descripcion = $data_for_handler['descripcion'] ?? '';
            $mode_id_objetivo = $data_for_handler['mode_id'] ?? '';

            if (empty($titulo) || empty($mode_id_objetivo)) {
                jsonResponse(["error" => "Título y modo son requeridos para crear un objetivo."], 400);
            }
            $sql = "INSERT INTO objetivos (id, titulo, fecha_estimada, descripcion, mode_id) VALUES (?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { throw new Exception("DB Error (obj_c_prep): " . $mysqli->error . " SQL: " . $sql); }
            $stmt->bind_param("sssss", $id_objetivo, $titulo, $fecha_estimada, $descripcion, $mode_id_objetivo);
            if ($stmt->execute()) {
                jsonResponse(["success" => true, "message" => "Objetivo añadido.", "id" => $id_objetivo, "titulo" => $titulo, "fecha_estimada" => $fecha_estimada, "descripcion" => $descripcion, "mode_id" => $mode_id_objetivo], 201);
            } else {
                throw new Exception("Error al añadir objetivo: " . $stmt->error . " SQL: " . $sql);
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error en POST /objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudo crear el objetivo.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'PUT': // Actualizar objetivo existente
        try {
            // $data_for_handler es el payload JSON decodificado
            $id_objetivo_put = $data_for_handler['id'] ?? '';
            $titulo_put = $data_for_handler['titulo'] ?? '';
            $fecha_estimada_put = $data_for_handler['fecha_estimada'] ?? '';
            $descripcion_put = $data_for_handler['descripcion'] ?? '';

            if (empty($id_objetivo_put) || empty($titulo_put)) {
                jsonResponse(["error" => "ID y título son requeridos para actualizar el objetivo."], 400);
            }
            $sql_put = "UPDATE objetivos SET titulo = ?, fecha_estimada = ?, descripcion = ? WHERE id = ?";
            $stmt_put = $mysqli->prepare($sql_put);
            if (!$stmt_put) { throw new Exception("DB Error (obj_u_prep): " . $mysqli->error . " SQL: " . $sql_put); }
            $stmt_put->bind_param("ssss", $titulo_put, $fecha_estimada_put, $descripcion_put, $id_objetivo_put);
            if ($stmt_put->execute()) {
                 if ($stmt_put->affected_rows > 0) {
                    jsonResponse(["success" => true, "message" => "Objetivo actualizado."]);
                } else {
                    jsonResponse(["success" => false, "message" => "No se actualizó el objetivo (ID no encontrado o datos iguales)."], 200);
                }
            } else {
                throw new Exception("Error al actualizar objetivo: " . $stmt_put->error . " SQL: " . $sql_put);
            }
            $stmt_put->close();
        } catch (Exception $e) {
            error_log("Error en PUT /objetivos: " . $e->getMessage());
            jsonResponse(["error" => "No se pudo actualizar el objetivo.", "details" => $e->getMessage()], 500);
        }
        break;

    case 'DELETE': // Eliminar objetivo y sus sub-objetivos
        // $data_for_handler es el payload JSON decodificado
        $id_objetivo_del = $data_for_handler['id'] ?? '';
        if (empty($id_objetivo_del)) {
            jsonResponse(["error" => "ID es requerido para eliminar el objetivo."], 400);
        }
        
        $mysqli->begin_transaction();
        try {
            $sql_del_subs = "DELETE FROM sub_objetivos WHERE objetivo_id = ?";
            $stmt_subs = $mysqli->prepare($sql_del_subs);
            if(!$stmt_subs) throw new Exception("DB Error (obj_ds_prep): ".$mysqli->error. " SQL: ".$sql_del_subs);
            $stmt_subs->bind_param("s", $id_objetivo_del);
            if(!$stmt_subs->execute()) throw new Exception("Error al ejecutar borrado de sub-objetivos: ".$stmt_subs->error);
            $stmt_subs->close();
            
            $sql_del_obj = "DELETE FROM objetivos WHERE id = ?";
            $stmt_obj_del = $mysqli->prepare($sql_del_obj);
            if(!$stmt_obj_del) throw new Exception("Error DB (obj_do_prep): ".$mysqli->error. " SQL: ".$sql_del_obj);
            $stmt_obj_del->bind_param("s", $id_objetivo_del);
            if(!$stmt_obj_del->execute()) throw new Exception("Error al ejecutar borrado de objetivo: ".$stmt_obj_del->error);
            $affected_rows_obj = $stmt_obj_del->affected_rows;
            $stmt_obj_del->close();
            
            $mysqli->commit();
            if ($affected_rows_obj > 0) {
                 jsonResponse(["success" => true, "message" => "Objetivo y sus sub-objetivos eliminados."]);
            } else {
                 jsonResponse(["success" => false, "message" => "No se encontró el objetivo para eliminar o ya fue eliminado."], 404);
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Error en DELETE /objetivos: " . $e->getMessage());
            jsonResponse(["error" => "Error al eliminar objetivo: " . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /objetivos."], 405);
        break;
}

?>