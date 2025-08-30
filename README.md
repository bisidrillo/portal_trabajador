# Portal del Trabajador

Portal del Trabajador es una aplicación web sencilla en PHP que sirve como portal interno para trabajadores. Permite acceder a información de interés, enlaces y contenido protegido mediante inicio de sesión.

## Requisitos

- PHP 7.4 o superior con la extensión PDO habilitada
- Servidor web como Apache o Nginx
- MariaDB o MySQL

## Instalación

1. Clona este repositorio en tu servidor o entorno local.
2. Configura la base de datos y crea un usuario con permisos.
3. Copia el archivo `config/config.php` y ajusta los valores de conexión o define las variables de entorno descritas a continuación.
4. Coloca los archivos del directorio `public` en la raíz servida por tu servidor web o configura el documento raíz para apuntar a dicho directorio.

## Variables de entorno

El archivo `config/config.php` puede hacer uso de las siguientes variables de entorno para establecer la conexión a la base de datos:

- `DB_HOST`  – servidor de base de datos (por defecto `localhost`)
- `DB_NAME`  – nombre de la base de datos
- `DB_USER`  – usuario con permisos
- `DB_PASS`  – contraseña del usuario

Puedes definir estas variables en tu sistema o crear un archivo `.env` y cargarlas antes de ejecutar la aplicación.

## Ejecución

Una vez configurado todo, inicia el servidor web y accede al portal a través de tu navegador. Para comprobar que la conexión con la base de datos funciona, puedes visitar `public/test_db.php`.

