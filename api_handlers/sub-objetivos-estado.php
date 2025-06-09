<?php
// api_handlers/sub-objetivos-estado.php

if (!isset($_SESSION['usuario_id'])) { jsonResponse(["error" => "No autorizado."], 401); exit; }
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

if ($handler_http_method === 'POST') {
    try {
        $sub_objetivo_db_id = $data_for_handler['idSubObjetivoDB'] ?? null;
        $completado = $data_for_handler['completado'] ?? null;
        if ($sub_objetivo_db_id === null || $completado === null) { jsonResponse(["error" => "Datos incompletos."], 400); }

        // Actualizar estado solo si el objetivo padre pertenece al usuario
        $sql = "UPDATE sub_objetivos s JOIN objetivos o ON s.objetivo_id = o.id SET s.completado = ? WHERE s.id = ? AND o.usuario_id = ?";
        $stmt = $mysqli->prepare($sql);
        $completado_bool_int = filter_var($completado, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; 
        $stmt->bind_param("iii", $completado_bool_int, $sub_objetivo_db_id, $usuario_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                jsonResponse([ "success" => true, "message" => "Estado actualizado." ]);
            } else {
                jsonResponse([ "success" => false, "message" => "No se actualizó (ID no encontrado o sin permiso)." ], 404);
            }
        } else { throw new Exception("Error al actualizar estado: " . $stmt->error); }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error en POST /sub-objetivos-estado (user $usuario_id): " . $e->getMessage());
        jsonResponse(["error" => "No se pudo actualizar el estado."], 500);
    }
} else {
    jsonResponse(["error" => "Método no soportado. Solo se acepta POST."], 405);
}
?>