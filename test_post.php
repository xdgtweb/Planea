<?php
// test_post.php
// Este script simplemente imprime el contenido crudo de php://input

$rawInput = file_get_contents("php://input");

// Establecer el tipo de contenido para que el navegador no intente interpretarlo como HTML
header('Content-Type: text/plain');

echo "--- Raw POST input ---\n";
echo $rawInput; // Imprime lo que recibe de php://input
echo "\n----------------------\n\n";

echo "--- Decoded JSON ---\n";
$decoded = json_decode($rawInput, true);
if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    echo "Error decoding JSON: " . json_last_error_msg() . "\n";
} else {
    print_r($decoded); // Imprime el JSON decodificado si es válido
}
echo "\n--------------------\n";
?>