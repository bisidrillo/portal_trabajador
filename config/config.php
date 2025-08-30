<?php
// Inicia sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datos de conexión a MariaDB
$host = 'localhost';          // O la IP de MariaDB si no está en el NAS
$db   = 'portal_trabajador';  // Nombre de la base de datos
$user = 'portaluser';            // Usuario de MariaDB (¡cámbialo!)
$pass = 'Portal2025#';         // Contraseña de ese usuario
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

