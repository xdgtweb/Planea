<?php
// api_handlers/objetivos.php

if (!isset($_SESSION['usuario_id'])) {
    jsonResponse(["error" => "No autorizado. Por favor, inicie sesión."], 401);
    exit;
}
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

switch ($handler_http_method) {
    case 'GET':
        try {
            $mode_id_get = $_GET['mode'] ?? ''; 
            if (empty($mode_id_get)) { jsonResponse(["error" => "Parámetro 'mode' es requerido."], 400); }
            
            $objetivos_map = [];
            $sql_objetivos = "SELECT id, titulo, fecha_estimada, descripcion FROM objetivos WHERE mode_id = ? AND usuario_id = ? ORDER BY creado_en DESC, id DESC";
            $stmt_obj = $mysqli->prepare($sql_objetivos);
            if (!$stmt_obj) { throw new Exception("DB Error (obj_g1_prep): " . $mysqli->error); }
            $stmt_obj->bind_param("si", $mode_id_get, $usuario_id);
            if (!$stmt_obj->execute()) { throw new Exception("DB Error (obj_g1_exec): " . $stmt_obj->error); }
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
                $sql_sub = "SELECT id, objetivo_id, texto, completado FROM sub_objetivos WHERE objetivo_id IN ($placeholders) ORDER BY id ASC";
                $stmt_sub = $mysqli->prepare($sql_sub);
                if (!$stmt_sub) { throw new Exception("DB Error (obj_g2_prep): " . $mysqli->error); }
                $stmt_sub->bind_param($types, ...$objetivo_ids);
                if (!$stmt_sub->execute()) { throw new Exception("DB Error (obj_g2_exec): " . $stmt_sub->error); }
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
            error_log("Error en GET /objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudieron obtener los objetivos."], 500);
        }
        break;

    case 'POST':
        try {
            $id_objetivo = $data_for_handler['id'] ?? uniqid('obj_');
            $titulo = $data_for_handler['titulo'] ?? '';
            $fecha_estimada = $data_for_handler['fecha_estimada'] ?? '';
            $descripcion = $data_for_handler['descripcion'] ?? '';
            $mode_id_objetivo = $data_for_handler['mode_id'] ?? '';

            if (empty($titulo) || empty($mode_id_objetivo)) { jsonResponse(["error" => "Título y modo son requeridos."], 400); }
            $sql = "INSERT INTO objetivos (id, titulo, fecha_estimada, descripcion, mode_id, usuario_id) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) { throw new Exception("DB Error (obj_c_prep): " . $mysqli->error); }
            $stmt->bind_param("sssssi", $id_objetivo, $titulo, $fecha_estimada, $descripcion, $mode_id_objetivo, $usuario_id);
            if ($stmt->execute()) {
                jsonResponse(["success" => true, "message" => "Objetivo añadido.", "id" => $id_objetivo], 201);
            } else { throw new Exception("Error al añadir objetivo: " . $stmt->error); }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error en POST /objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudo crear el objetivo."], 500);
        }
        break;

    case 'PUT':
        try {
            $id_objetivo_put = $data_for_handler['id'] ?? '';
            $titulo_put = $data_for_handler['titulo'] ?? '';
            $fecha_estimada_put = $data_for_handler['fecha_estimada'] ?? '';
            $descripcion_put = $data_for_handler['descripcion'] ?? '';

            if (empty($id_objetivo_put) || empty($titulo_put)) { jsonResponse(["error" => "ID y título son requeridos."], 400); }
            $sql_put = "UPDATE objetivos SET titulo = ?, fecha_estimada = ?, descripcion = ? WHERE id = ? AND usuario_id = ?";
            $stmt_put = $mysqli->prepare($sql_put);
            if (!$stmt_put) { throw new Exception("DB Error (obj_u_prep): " . $mysqli->error); }
            $stmt_put->bind_param("ssssi", $titulo_put, $fecha_estimada_put, $descripcion_put, $id_objetivo_put, $usuario_id);
            if ($stmt_put->execute()) {
                 if ($stmt_put->affected_rows > 0) {
                    jsonResponse(["success" => true, "message" => "Objetivo actualizado."]);
                } else {
                    jsonResponse(["success" => false, "message" => "No se actualizó el objetivo (ID no encontrado o sin permiso)."], 404);
                }
            } else { throw new Exception("Error al actualizar objetivo: " . $stmt_put->error); }
            $stmt_put->close();
        } catch (Exception $e) {
            error_log("Error en PUT /objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudo actualizar el objetivo."], 500);
        }
        break;

    case 'DELETE':
        $id_objetivo_del = $data_for_handler['id'] ?? '';
        if (empty($id_objetivo_del)) { jsonResponse(["error" => "ID es requerido para eliminar."], 400); }
        
        $mysqli->begin_transaction();
        try {
            $sql_del_subs = "DELETE s FROM sub_objetivos s JOIN objetivos o ON s.objetivo_id = o.id WHERE o.id = ? AND o.usuario_id = ?";
            $stmt_subs = $mysqli->prepare($sql_del_subs);
            if(!$stmt_subs) throw new Exception("DB Error (obj_ds_prep): ".$mysqli->error);
            $stmt_subs->bind_param("si", $id_objetivo_del, $usuario_id);
            if(!$stmt_subs->execute()) throw new Exception("Error al borrar sub-objetivos: ".$stmt_subs->error);
            $stmt_subs->close();
            
            $sql_del_obj = "DELETE FROM objetivos WHERE id = ? AND usuario_id = ?";
            $stmt_obj_del = $mysqli->prepare($sql_del_obj);
            if(!$stmt_obj_del) throw new Exception("Error DB (obj_do_prep): ".$mysqli->error);
            $stmt_obj_del->bind_param("si", $id_objetivo_del, $usuario_id);
            if(!$stmt_obj_del->execute()) throw new Exception("Error al borrar objetivo: ".$stmt_obj_del->error);
            $affected_rows_obj = $stmt_obj_del->affected_rows;
            $stmt_obj_del->close();
            
            $mysqli->commit();
            if ($affected_rows_obj > 0) {
                 jsonResponse(["success" => true, "message" => "Objetivo y sus sub-objetivos eliminados."]);
            } else {
                 jsonResponse(["success" => false, "message" => "No se encontró el objetivo para eliminar o no tienes permiso."], 404);
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Error en DELETE /objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al eliminar objetivo."], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método no soportado."], 405);
        break;
}
?>