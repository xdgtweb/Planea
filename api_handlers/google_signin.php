<?php
// api_handlers/google_signin.php

if (!isset($mysqli)) { jsonResponse(["error" => "DB connection not available"], 500); exit; }
if ($handler_http_method !== 'POST') { jsonResponse(["error" => "Method not allowed"], 405); exit; }

$id_token = $data_for_handler['token'] ?? null;
if (!$id_token) {
    jsonResponse(["error" => "No se recibió el token de Google."], 400);
}

// TU ID DE CLIENTE YA ESTÁ AQUÍ
$CLIENT_ID = "356486997376-1ctts9tjobjh4bl2b70lcms4dq98se3l.apps.googleusercontent.com";

try {
    $client = new Google_Client(['client_id' => $CLIENT_ID]);
    $payload = $client->verifyIdToken($id_token);
} catch (Exception $e) {
    error_log("Google Token Verification Error: " . $e->getMessage());
    jsonResponse(["error" => "No se pudo verificar el token con Google."], 500);
}

if ($payload) {
    $google_id = $payload['sub'];
    $email = $payload['email'];
    $nombre_usuario = $payload['name'];

    try {
        $stmt = $mysqli->prepare("SELECT id, nombre_usuario FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['nombre_usuario'] = $user['nombre_usuario'];
            jsonResponse(["success" => true, "message" => "Login con Google exitoso."]);
        } else {
            $random_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $stmt_insert = $mysqli->prepare("INSERT INTO usuarios (nombre_usuario, email, password_hash, google_id) VALUES (?, ?, ?, ?)");
            $stmt_insert->bind_param("ssss", $nombre_usuario, $email, $random_password, $google_id);
            if ($stmt_insert->execute()) {
                $new_user_id = $mysqli->insert_id;
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = $new_user_id;
                $_SESSION['nombre_usuario'] = $nombre_usuario;
                jsonResponse(["success" => true, "message" => "Usuario creado y sesión iniciada con Google."]);
            } else {
                throw new Exception("No se pudo crear el nuevo usuario.");
            }
            $stmt_insert->close();
        }
    } catch (Exception $e) {
        jsonResponse(["error" => "Error de base de datos: " . $e->getMessage()], 500);
    }
} else {
    jsonResponse(["error" => "Token de Google inválido."], 401);
}
?>