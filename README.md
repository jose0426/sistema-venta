# Sistema de venta para tienda y supermercado

Aplicación de punto de venta en PHP, MySQL, HTML, CSS y JavaScript. Incluye:

- Registro de productos con categoría, precio y stock.
- Búsqueda y filtros por categoría.
- Carrito de compras y cobro en efectivo, tarjeta o transferencia.
- Selección de descuentos del 0%, 5%, 10%, 15% o 20% antes de finalizar una venta.
- Descuento de inventario y registro transaccional de ventas.
- Factura imprimible en papel carta.
- Panel con ingresos, ventas, productos y clientes.
- Interfaz adaptable a computadora, tablet y celular.
- Persistencia centralizada en una base de datos MySQL.

## Requisitos

- PHP 8.1 o posterior.
- Extensiones PHP `PDO` y `pdo_mysql` habilitadas.

## Iniciar en Windows

Abre PowerShell en la carpeta del proyecto y ejecuta:

```powershell
php -S localhost:8000 router.php
```

Abre <http://localhost:8000> en el navegador. No abras `index.php` directamente como archivo: debe cargarse desde el servidor PHP para que la API y la base de datos funcionen.

Si usas XAMPP y el proyecto está en `C:\xampp\htdocs\sitema de venta`, abre [SuperMarket POS](http://localhost/sitema%20de%20venta/). Este enlace local solo funciona en esta computadora; para compartir la tienda por internet se necesita publicarla en un servidor o usar un túnel.

Apache necesita permiso de escritura en `storage` para crear la base de datos.

## Base de datos MySQL remota o local

1. Crea la base de datos y el usuario MySQL en el panel de tu proveedor (o en phpMyAdmin para XAMPP). El usuario debe tener permisos para crear tablas e índices dentro de esa base.
2. Edita `storage/db-config.php` con el host, puerto, nombre de base, usuario y contraseña proporcionados por el servidor MySQL.
3. Publica la aplicación en un servidor PHP que tenga habilitado `pdo_mysql`. El servidor de base de datos debe permitir conexiones remotas desde el servidor web; algunas empresas requieren autorizar la IP del servidor web.
4. Abre la URL del proyecto. La aplicación crea automáticamente las tablas `products`, `sales` y `sale_items` dentro de la base existente.

La carpeta `storage` está bloqueada para acceso HTTP por `.htaccess` y por `router.php`. Nginx no lee `.htaccess`: agrega una regla `location ^~ /storage/ { deny all; }` o coloca los archivos privados fuera de la raíz pública. También se pueden configurar los valores con las variables de entorno `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`; estas tienen prioridad sobre `storage/db-config.php`.

En XAMPP local, la configuración inicial apunta a `127.0.0.1`, puerto `3306`, base `sistema_venta`, usuario `root` y contraseña vacía. Para usar SQLite, establece `DB_DRIVER=sqlite`.

## Acceso de usuarios

Antes de publicar el sistema:

1. Publica la aplicación únicamente con HTTPS y no compartas la dirección antes de configurar la cuenta.
2. Abre la aplicación y crea inmediatamente la cuenta administradora con un nombre de usuario y una contraseña de al menos 6 caracteres.

La primera persona que complete el registro obtiene la cuenta inicial; el registro inicial se cierra automáticamente después. Configura esa cuenta antes de compartir el enlace. La aplicación protege la API con sesión, token CSRF y cierre de sesión; las sesiones vencen tras 8 horas de inactividad.

Para añadir más empleados, genera primero un hash en el servidor con PHP (cambia el ejemplo por la contraseña elegida):

```powershell
php -r "echo password_hash('CONTRASENA_DEL_EMPLEADO', PASSWORD_DEFAULT), PHP_EOL;"
```

En phpMyAdmin abre la pestaña SQL y ejecuta el siguiente comando con el usuario y el hash resultante:

```sql
INSERT INTO users (username, password_hash) VALUES ('empleado1', 'PEGA_AQUI_EL_HASH');
```

Los usuarios nuevos quedan como `employee` automáticamente. Los empleados pueden usar Venta y consultar en el Panel principal únicamente el resumen agregado general (sin el detalle de ventas). No pueden abrir inventario ni reportes, y el servidor rechaza sus intentos de modificar productos. Pueden vender productos existentes; la API no les entrega ventas anteriores. Solo una cuenta `admin` puede acceder al historial completo y administrar productos. Para darle ese permiso a una cuenta de confianza, cambia su columna `role` a `admin` en phpMyAdmin. Cada venta guarda el nombre del usuario que la registró; las ventas antiguas muestran “No registrado”. Para quitar un acceso, elimina su fila de `users`.

Los datos existentes anteriormente en `localStorage` no se importan automáticamente; la base de datos nueva inicia vacía.

## Archivos principales

- `index.php`: interfaz del sistema.
- `api.php`: API y operaciones de base de datos.
- `router.php`: servidor PHP integrado y bloqueo de acceso HTTP a los datos.
- `storage/.htaccess`: bloquea la carpeta de datos en Apache.
- `storage/db-config.php`: configuración privada de conexión MySQL.
- `styles.css`: estilos.
- `script.js`: interacción del POS y comunicación con la API.
