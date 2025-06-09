<?php
// api_handlers/login.php

if (!isset($mysqli)) { jsonResponse(["error" => "DB connection not available"], 500); exit; }
if ($handler_http_method !== 'POST') { jsonResponse(["error" => "Method not allowed"], 405); exit; }

$email = $data_for_handler['email'] ?? null;
$password = $data_for_handler['password'] ?? null;

if (!$email || !$password) { jsonResponse(["error" => "Email y contraseña son requeridos."], 400); }

try {
    $sql = "SELECT id, nombre_usuario, password_hash FROM usuarios WHERE email = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['nombre_usuario'] = $user['nombre_usuario'];
        jsonResponse(["success" => true, "message" => "Inicio de sesión correcto.", "user" => ["id" => $user['id'], "username" => $user['nombre_usuario']]]);
    } else {
        jsonResponse(["error" => "Credenciales inválidas."], 401);
    }
} catch (Exception $e) {
    error_log("Error en /login: " . $e->getMessage());
    jsonResponse(["error" => "El inicio de sesión ha fallado."], 500);
}
?>