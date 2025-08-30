<?php
require __DIR__ . '/../config/config.php';

try {
    $stmt = $pdo->query('SELECT DATABASE()');
    $dbName = $stmt->fetchColumn();

    echo "✅ Conexión a la base de datos exitosa. Base de datos actual: " . htmlspecialchars($dbName);
} catch (PDOException $e) {
    echo "❌ Error en la conexión: " . htmlspecialchars($e->getMessage());
}

