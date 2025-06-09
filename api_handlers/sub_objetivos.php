<?php
// api_handlers/sub_objetivos.php

if (!isset($_SESSION['usuario_id'])) { jsonResponse(["error" => "No autorizado."], 401); exit; }
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

switch ($handler_http_method) {
    case 'POST': // Crear nuevo sub-objetivo
        try {
            $objetivo_id = $data_for_handler['objetivo_id'] ?? '';
            $texto = $data_for_handler['texto'] ?? '';
            if (empty($objetivo_id) || empty($texto)) { jsonResponse(["error" => "ID de objetivo y texto son requeridos."], 400); }

            // Verificar que el objetivo padre pertenece al usuario
            $sql_check = "SELECT id FROM objetivos WHERE id = ? AND usuario_id = ?";
            $stmt_check = $mysqli->prepare($sql_check);
            $stmt_check->bind_param("si", $objetivo_id, $usuario_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows == 0) { jsonResponse(["error" => "Objetivo no encontrado o sin permiso."], 404); }
            $stmt_check->close();

            $sql = "INSERT INTO sub_objetivos (objetivo_id, texto, completado) VALUES (?, ?, FALSE)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ss", $objetivo_id, $texto);
            if ($stmt->execute()) {
                jsonResponse(["success" => true, "id" => $mysqli->insert_id], 201);
            } else { throw new Exception("Error al añadir sub-objetivo: " . $stmt->error); }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error en POST /sub_objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudo crear el sub-objetivo."], 500);
        }
        break;

    case 'PUT': // Actualizar sub-objetivo
        try {
            $id_subobjetivo_put = $data_for_handler['id'] ?? null;
            $texto_put = $data_for_handler['texto'] ?? '';
            if ($id_subobjetivo_put === null || empty($texto_put)) { jsonResponse(["error" => "ID de sub-objetivo y texto son requeridos."], 400); }

            // Actualizar solo si el objetivo padre pertenece al usuario
            $sql_put = "UPDATE sub_objetivos s JOIN objetivos o ON s.objetivo_id = o.id SET s.texto = ? WHERE s.id = ? AND o.usuario_id = ?";
            $stmt_put = $mysqli->prepare($sql_put);
            $stmt_put->bind_param("sii", $texto_put, $id_subobjetivo_put, $usuario_id);
            if ($stmt_put->execute()) {
                if ($stmt_put->affected_rows > 0) {
                     jsonResponse(["success" => true, "message" => "Sub-objetivo actualizado."]);
                } else {
                     jsonResponse(["success" => false, "message" => "No se actualizó (ID no encontrado o sin permiso)."], 404);
                }
            } else { throw new Exception("Error al actualizar sub-objetivo: " . $stmt_put->error); }
            $stmt_put->close();
        } catch (Exception $e) {
            error_log("Error en PUT /sub_objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudo actualizar el sub-objetivo."], 500);
        }
        break;

    case 'DELETE': // Eliminar sub-objetivo
        try {
            $id_subobjetivo_del = $data_for_handler['id'] ?? null;
            if ($id_subobjetivo_del === null) { jsonResponse(["error" => "ID de sub-objetivo es requerido."], 400); }

            // Eliminar solo si el objetivo padre pertenece al usuario
            $sql_del = "DELETE s FROM sub_objetivos s JOIN objetivos o ON s.objetivo_id = o.id WHERE s.id = ? AND o.usuario_id = ?";
            $stmt_del = $mysqli->prepare($sql_del);
            $stmt_del->bind_param("ii", $id_subobjetivo_del, $usuario_id);
            if ($stmt_del->execute()) {
                if ($stmt_del->affected_rows > 0) {
                    jsonResponse(["success" => true, "message" => "Sub-objetivo eliminado."]);
                } else {
                     jsonResponse(["success" => false, "message" => "No se encontró el sub-objetivo o no tienes permiso."], 404);
                }
            } else { throw new Exception("Error al eliminar sub-objetivo: " . $stmt_del->error); }
            $stmt_del->close();
        } catch (Exception $e) {
            error_log("Error en DELETE /sub_objetivos (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudo eliminar el sub-objetivo."], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método no soportado."], 405);
        break;
}
?>