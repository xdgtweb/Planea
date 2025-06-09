<?php
// api_handlers/anotaciones.php

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
            if (isset($_GET['fecha'])) { 
                $fecha = $_GET['fecha'];
                if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha)) { jsonResponse(["error" => "Formato de fecha inválido."], 400); }
                $sql = "SELECT fecha, emoji, descripcion FROM dia_anotaciones WHERE fecha = ? AND usuario_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("si", $fecha, $usuario_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $anotacion = $result->fetch_assoc();
                $stmt->close();
                jsonResponse($anotacion ?: null); 
            } elseif (isset($_GET['mes']) && isset($_GET['anio'])) { 
                $mes = filter_var($_GET['mes'], FILTER_VALIDATE_INT);
                $anio = filter_var($_GET['anio'], FILTER_VALIDATE_INT);
                if (!$mes || !$anio || $mes < 1 || $mes > 12 || $anio < 1900 || $anio > 2100) { jsonResponse(["error" => "Mes y año inválidos."], 400); }
                $fecha_inicio_mes = sprintf("%04d-%02d-01", $anio, $mes);
                $fecha_fin_mes = date("Y-m-t", strtotime($fecha_inicio_mes));
                
                $sql = "SELECT fecha, emoji, descripcion FROM dia_anotaciones WHERE fecha >= ? AND fecha <= ? AND usuario_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssi", $fecha_inicio_mes, $fecha_fin_mes, $usuario_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $anotaciones = [];
                while ($row = $result->fetch_assoc()) { $anotaciones[$row['fecha']] = $row; }
                $stmt->close();
                jsonResponse($anotaciones);
            } else {
                jsonResponse(["error" => "Parámetros 'fecha' o 'mes'/'año' requeridos."], 400);
            }
        } catch (Exception $e) {
            error_log("Error en GET /anotaciones (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al procesar solicitud de anotaciones."], 500);
        }
        break;

    case 'POST':
        try {
            $fecha = $data_for_handler['fecha'] ?? null;
            $emoji = $data_for_handler['emoji'] ?? null; 
            $descripcion = $data_for_handler['descripcion'] ?? null;

            if (!$fecha || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha)) { jsonResponse(["error" => "Fecha requerida y en formato YYYY-MM-DD."], 400); }
            
            if ( (empty($emoji) || $emoji === null) && (empty($descripcion) || $descripcion === null) ) {
                $sql_del = "DELETE FROM dia_anotaciones WHERE fecha = ? AND usuario_id = ?";
                $stmt_del = $mysqli->prepare($sql_del);
                $stmt_del->bind_param("si", $fecha, $usuario_id);
                $stmt_del->execute();
                $affected_rows_del = $stmt_del->affected_rows;
                $stmt_del->close();
                jsonResponse(["success" => true, "message" => $affected_rows_del > 0 ? "Anotación eliminada." : "No había anotación que eliminar."]);
            } else {
                $sql = "INSERT INTO dia_anotaciones (fecha, emoji, descripcion, usuario_id) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), descripcion = VALUES(descripcion)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssi", $fecha, $emoji, $descripcion, $usuario_id);
                $stmt->execute();
                $stmt->close();
                jsonResponse(["success" => true, "message" => "Anotación guardada."]);
            }
        } catch (Exception $e) {
            error_log("Error en POST /anotaciones (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al procesar la anotación."], 500);
        }
        break;

    case 'DELETE': 
        try {
            $fecha_del = $data_for_handler['fecha'] ?? null;
            if (!$fecha_del || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_del)) { jsonResponse(["error" => "Fecha requerida para eliminar."], 400); }

            $sql_delete = "DELETE FROM dia_anotaciones WHERE fecha = ? AND usuario_id = ?";
            $stmt_delete = $mysqli->prepare($sql_delete);
            $stmt_delete->bind_param("si", $fecha_del, $usuario_id);
            $stmt_delete->execute();
            $affected_rows = $stmt_delete->affected_rows;
            $stmt_delete->close();
            jsonResponse(["success" => true, "message" => $affected_rows > 0 ? "Anotación eliminada." : "No se encontró anotación para eliminar."]);
        } catch (Exception $e) {
            error_log("Error en DELETE /anotaciones (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al eliminar la anotación."], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método no soportado."], 405);
        break;
}
?>