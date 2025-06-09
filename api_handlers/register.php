<?php
// api_handlers/register.php

if (!isset($mysqli)) { jsonResponse(["error" => "DB connection not available"], 500); exit; }
if ($handler_http_method !== 'POST') { jsonResponse(["error" => "Method not allowed"], 405); exit; }

$username = $data_for_handler['username'] ?? null;
$email = $data_for_handler['email'] ?? null;
$password = $data_for_handler['password'] ?? null;

if (!$username || !$email || !$password) { jsonResponse(["error" => "Nombre de usuario, email y contraseña son requeridos."], 400); }
if (strlen($password) < 6) { jsonResponse(["error" => "La contraseña debe tener al menos 6 caracteres."], 400); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { jsonResponse(["error" => "Formato de email inválido."], 400); }

$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $sql = "INSERT INTO usuarios (nombre_usuario, email, password_hash) VALUES (?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("sss", $username, $email, $password_hash);
    
    if ($stmt->execute()) {
        jsonResponse(["success" => true, "message" => "Usuario registrado correctamente."], 201);
    } else {
        if ($mysqli->errno == 1062) {
            jsonResponse(["error" => "El nombre de usuario o el email ya existen."], 409);
        } else {
            throw new Exception($stmt->error);
        }
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error en /register: " . $e->getMessage());
    jsonResponse(["error" => "El registro ha fallado."], 500);
}
?>