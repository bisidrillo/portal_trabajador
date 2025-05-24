<?php
session_start();
require __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $contrasena = $_POST['contrasena'] ?? '';

    // Consultar en la base de datos el usuario
    $stmt = $pdo->prepare('SELECT id, usuario, contrasena_hash FROM usuarios WHERE usuario = ?');
    $stmt->execute([$usuario]);
    $user = $stmt->fetch();

    if ($user && password_verify($contrasena, $user['contrasena_hash'])) {
        // Credenciales correctas
        $_SESSION['usuario'] = $user['usuario'];
        $_SESSION['id_usuario'] = $user['id'];
        header('Location: panel.php');
        exit;
    } else {
        // Credenciales incorrectas
        header('Location: login.php?error=1');
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}