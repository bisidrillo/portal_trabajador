<?php
session_start();
if (isset($_SESSION['usuario'])) {
    header('Location: panel.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Login - Portal del Trabajador</title>
</head>
<body>
    <h2>Iniciar sesión</h2>
    <?php
    if (isset($_GET['error'])) {
        echo "<p style='color:red;'>Usuario o contraseña incorrectos.</p>";
    }
    ?>
    <form action="autenticar.php" method="post">
        <label>Usuario: <input type="text" name="usuario" required></label><br><br>
        <label>Contraseña: <input type="password" name="contrasena" required></label><br><br>
        <button type="submit">Entrar</button>
    </form>
</body>
</html>