<?php
// Manejar la petición POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    // Validación básica de entrada
    if (!isset($data->username) || !isset($data->email) || !isset($data->password)) {
        json_response(['error' => 'Faltan campos requeridos'], 400);
        return;
    }

    $username = trim($data->username);
    $email = trim($data->email);
    $password = $data->password;

    if (empty($username) || empty($email) || empty($password)) {
        json_response(['success' => false, 'message' => 'Ningún campo puede estar vacío.'], 400);
        return;
    }

    if (strlen($password) < 8) {
        json_response(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'El formato del correo electrónico no es válido.'], 400);
        return;
    }

    // Hashear la contraseña
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insertar nuevo usuario en la base de datos
    $stmt = $mysqli->prepare("INSERT INTO usuarios (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $password_hash);

    if ($stmt->execute()) {
        json_response(['success' => true, 'message' => 'Usuario registrado exitosamente. Ahora puedes iniciar sesión.'], 201);
    } else {
        // Manejar error de duplicado (ej. email o username ya existen)
        if ($mysqli->errno == 1062) {
            json_response(['success' => false, 'message' => 'El nombre de usuario o el correo electrónico ya están en uso.'], 409);
        } else {
            json_response(['error' => 'Error al registrar el usuario: ' . $stmt->error], 500);
        }
    }

    $stmt->close();
}
?>