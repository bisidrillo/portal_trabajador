<?php
// Inicia sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datos de conexión a MariaDB obtenidos de variables de entorno
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'portal_trabajador';
$user = getenv('DB_USER') ?: 'portaluser';
$pass = getenv('DB_PASS') ?: 'Portal2025#';
$charset = 'utf8mb4';

// Configuración DSN para PDO
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Opciones seguras para PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Intentar conectar
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('Error de conexión: ' . $e->getMessage());
}
?>