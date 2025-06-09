<?php
// api_handlers/modos.php

// Este endpoint es público y no necesita validación de sesión,
// ya que los modos son globales para todos los usuarios.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500);
    exit;
}

if ($handler_http_method === 'GET') {
    try {
        $modos = [];
        $sql = "SELECT id, nombre FROM modos ORDER BY orden ASC";
        $result = $mysqli->query($sql);
        
        if (!$result) {
            throw new mysqli_sql_exception("Error en la consulta SQL para modos: " . $mysqli->error);
        }
        
        while ($row = $result->fetch_assoc()) {
            $modos[] = $row;
        }
        $result->free();
        
        jsonResponse($modos);

    } catch (Exception $e) {
        error_log("Error inesperado en /modos GET: " . $e->getMessage());
        jsonResponse(["error" => "Ocurrió un error al procesar su solicitud de modos."], 500);
    }
} else {
    jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no permitido."], 405);
}

?>