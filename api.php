<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

final class StockConflict extends RuntimeException
{
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureIndex(PDO $pdo, string $table, string $indexName, string $ddl): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index_name'
    );
    $statement->execute([
        ':table' => $table,
        ':index_name' => $indexName,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        $pdo->exec($ddl);
    }
}

function ensureColumn(PDO $pdo, string $driver, string $table, string $column, string $definition): void
{
    if ($driver === 'sqlite') {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existingColumn) {
            if ($existingColumn['name'] === $column) {
                return;
            }
        }
    } else {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $statement->execute([':table' => $table, ':column' => $column]);
        if ((int) $statement->fetchColumn() > 0) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function migrateSqliteUsers(PDO $pdo): void
{
    $definition = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'"
    )->fetchColumn();
    if (!is_string($definition) || !str_contains($definition, 'CHECK (id = 1)')) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('CREATE TABLE users_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL
        )');
        $pdo->exec('INSERT INTO users_new (id, username, password_hash)
                    SELECT id, username, password_hash FROM users');
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function migrateMysqlUsers(PDO $pdo): void
{
    $statement = $pdo->query(
        "SELECT column_type, extra FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'id'"
    );
    $column = $statement->fetch();
    if ($column && ($column['column_type'] !== 'int unsigned'
        || stripos($column['extra'], 'auto_increment') === false)) {
        $pdo->exec('ALTER TABLE users MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
    }
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower((string) (getenv('DB_DRIVER') ?: 'mysql'));
    if ($driver === 'sqlite') {
        $directory = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de datos.');
        }

        $pdo = new PDO('sqlite:' . $directory . DIRECTORY_SEPARATOR . 'supermarket.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $schema = [
            'CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                category TEXT NOT NULL,
                price REAL NOT NULL CHECK (price > 0),
                stock INTEGER NOT NULL CHECK (stock >= 0),
                icon TEXT NOT NULL,
                sku TEXT UNIQUE,
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer TEXT NOT NULL,
                payment TEXT NOT NULL CHECK (payment IN (\'cash\', \'card\', \'transfer\')),
                subtotal REAL NOT NULL,
                tax REAL NOT NULL,
                discount_rate INTEGER NOT NULL DEFAULT 0,
                discount_amount REAL NOT NULL DEFAULT 0,
                total REAL NOT NULL,
                received REAL NOT NULL,
                change_amount REAL NOT NULL,
                seller TEXT NOT NULL DEFAULT \'No registrado\',
                created_at TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS sale_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_id INTEGER NOT NULL REFERENCES sales(id),
                product_id INTEGER NOT NULL REFERENCES products(id),
                name TEXT NOT NULL,
                icon TEXT NOT NULL,
                price REAL NOT NULL,
                quantity INTEGER NOT NULL CHECK (quantity > 0)
            )',
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'employee\'
            )',
            'CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id)',
            'CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)',
        ];
        foreach ($schema as $statement) {
            $pdo->exec($statement);
        }

        ensureColumn($pdo, 'sqlite', 'sales', 'seller', "TEXT NOT NULL DEFAULT 'No registrado'");
        ensureColumn($pdo, 'sqlite', 'sales', 'discount_rate', 'INTEGER NOT NULL DEFAULT 0');
        ensureColumn($pdo, 'sqlite', 'sales', 'discount_amount', 'REAL NOT NULL DEFAULT 0');
        ensureColumn($pdo, 'sqlite', 'users', 'role', "TEXT NOT NULL DEFAULT 'employee'");
        migrateSqliteUsers($pdo);
        $pdo->exec("UPDATE users SET role = 'admin'
                    WHERE id = (SELECT MIN(id) FROM users)
                      AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin')");
        return $pdo;
    }

    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'db-config.php';
    $config = is_file($configPath) ? require $configPath : [];
    if (!is_array($config)) {
        throw new RuntimeException('La configuración de la base de datos no es válida.');
    }

    $host = getenv('DB_HOST') ?: ($config['host'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($config['port'] ?? '3306');
    $database = getenv('DB_NAME') ?: ($config['database'] ?? 'sistema_venta');
    $username = getenv('DB_USER') ?: ($config['username'] ?? 'root');
    $password = getenv('DB_PASS') ?: ($config['password'] ?? '');
    if (!is_string($host) || $host === '' || !is_numeric($port)
        || !is_string($database) || !preg_match('/^[a-zA-Z0-9_]+$/', $database)
        || !is_string($username) || !is_string($password)) {
        throw new RuntimeException('La configuración de conexión MySQL no es válida.');
    }
    $charset = 'utf8mb4';

    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset), $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schema = [
        'CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            category VARCHAR(80) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            stock INT NOT NULL,
            icon VARCHAR(32) NOT NULL,
            sku VARCHAR(32) NULL UNIQUE,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS sales (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer VARCHAR(120) NOT NULL,
            payment ENUM(\'cash\', \'card\', \'transfer\') NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            tax DECIMAL(10,2) NOT NULL,
            discount_rate TINYINT UNSIGNED NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL,
            received DECIMAL(10,2) NOT NULL,
            change_amount DECIMAL(10,2) NOT NULL,
            seller VARCHAR(80) NOT NULL DEFAULT \'No registrado\',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS sale_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sale_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            icon VARCHAR(32) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_sale_items_sale_id (sale_id),
            CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM(\'admin\', \'employee\') NOT NULL DEFAULT \'employee\'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }

    migrateMysqlUsers($pdo);
    ensureColumn($pdo, 'mysql', 'users', 'role', "ENUM('admin', 'employee') NOT NULL DEFAULT 'employee'");
    $pdo->exec("UPDATE users SET role = 'admin'
                WHERE id = (SELECT first_user_id FROM (SELECT MIN(id) AS first_user_id FROM users) AS first_user)
                  AND NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin')");
    ensureColumn($pdo, 'mysql', 'sales', 'seller', "VARCHAR(80) NOT NULL DEFAULT 'No registrado'");
    ensureColumn($pdo, 'mysql', 'sales', 'discount_rate', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'mysql', 'sales', 'discount_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
    ensureIndex($pdo, 'sales', 'idx_sales_created_at', 'CREATE INDEX idx_sales_created_at ON sales(created_at)');

    return $pdo;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireAuthenticated(PDO $pdo): void
{
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if (!isset($_SESSION['user_id']) || !is_int($lastActivity) || time() - $lastActivity > 28800) {
        $_SESSION = [];
        session_regenerate_id(true);
        respond(['error' => 'Tu sesión venció. Inicia sesión nuevamente.'], 401);
    }

    $statement = $pdo->prepare('SELECT username, role FROM users WHERE id = :id');
    $statement->execute([':id' => $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        respond(['error' => 'La cuenta ya no está disponible. Inicia sesión nuevamente.'], 401);
    }

    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
}

function requireCsrfToken(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {
        respond(['error' => 'La sesión venció o la solicitud no es válida. Recarga e inicia sesión nuevamente.'], 403);
    }
}

function readJson(): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body === false ? '' : $body, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('El contenido enviado no es válido.');
    }
    return $data;
}

function categoryIcon(string $category): string
{
    return [
        'Lácteos' => '🥛',
        'Bebidas' => '🥤',
        'Frutas' => '🍎',
        'Panadería' => '🥖',
        'Abarrotes' => '🥫',
        'Hogar' => '🧼',
        'Snacks' => '🍪',
    ][$category] ?? '📦';
}

function fetchState(PDO $pdo, bool $includeSales): array
{
    $summary = $pdo->query(
        'SELECT COUNT(*) AS totalSales,
                COALESCE(SUM(total), 0) AS totalRevenue,
                COUNT(DISTINCT customer) AS totalClients
         FROM sales'
    )->fetch();
    $summary = [
        'totalSales' => (int) $summary['totalSales'],
        'totalRevenue' => (float) $summary['totalRevenue'],
        'totalClients' => (int) $summary['totalClients'],
    ];

    $products = $pdo->query(
        'SELECT id, name, category, price, stock, icon, sku FROM products ORDER BY id'
    )->fetchAll();
    foreach ($products as &$product) {
        $product['id'] = (int) $product['id'];
        $product['price'] = (float) $product['price'];
        $product['stock'] = (int) $product['stock'];
    }
    unset($product);

    $sales = $includeSales ? $pdo->query(
        'SELECT id, customer, seller, payment, subtotal,
                discount_rate AS discountRate, discount_amount AS discount, total, received,
                change_amount AS `change`, created_at AS createdAt
         FROM sales ORDER BY id DESC'
    )->fetchAll() : [];
    $items = $includeSales ? $pdo->query(
        'SELECT sale_id, product_id AS id, name, icon, price, quantity
         FROM sale_items ORDER BY id'
    )->fetchAll() : [];
    $itemsBySale = [];
    foreach ($items as $item) {
        $saleId = (int) $item['sale_id'];
        $item['id'] = (int) $item['id'];
        $item['price'] = (float) $item['price'];
        $item['quantity'] = (int) $item['quantity'];
        unset($item['sale_id']);
        $itemsBySale[$saleId][] = $item;
    }

    foreach ($sales as &$sale) {
        $databaseId = (int) $sale['id'];
        $sale['id'] = 'SV-' . str_pad((string) ($databaseId + 1000), 4, '0', STR_PAD_LEFT);
        $sale['date'] = substr($sale['createdAt'], 0, 10);
        $sale['discountRate'] = (int) $sale['discountRate'];
        foreach (['subtotal', 'discount', 'total', 'received', 'change'] as $amount) {
            $sale[$amount] = (float) $sale[$amount];
        }
        $sale['items'] = $itemsBySale[$databaseId] ?? [];
    }
    unset($sale);

    return ['products' => $products, 'sales' => $sales, 'summary' => $summary];
}

try {
    $pdo = database();
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'auth') {
        $firstUser = $pdo->query('SELECT id, username FROM users ORDER BY id LIMIT 1')->fetch();
        $authenticated = isset($_SESSION['user_id']) && is_int($_SESSION['last_activity'] ?? null)
            && time() - $_SESSION['last_activity'] <= 28800;
        $currentUser = null;
        if ($authenticated) {
            $statement = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id');
            $statement->execute([':id' => $_SESSION['user_id']]);
            $currentUser = $statement->fetch();
            $authenticated = (bool) $currentUser;
        }
        if (!$authenticated) {
            if (isset($_SESSION['user_id'])) {
                $_SESSION = [];
                session_regenerate_id(true);
            }
        } else {
            $_SESSION['last_activity'] = time();
            $_SESSION['username'] = $currentUser['username'];
            $_SESSION['role'] = $currentUser['role'];
        }

        respond([
            'authenticated' => $authenticated,
            'needsSetup' => !$firstUser,
            'username' => $authenticated ? $currentUser['username'] : null,
            'role' => $authenticated ? $currentUser['role'] : null,
            'csrfToken' => csrfToken(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['setup', 'login'], true)) {
        requireCsrfToken();
        $data = readJson();
        $user = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch();

        if ($action === 'setup') {
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');
            $passwordConfirm = (string) ($data['passwordConfirm'] ?? '');
            if ($user) {
                respond(['error' => 'La cuenta inicial ya fue configurada.'], 409);
            }
            if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $username)
                || strlen($password) < 6 || strlen($password) > 255
                || $password !== $passwordConfirm) {
                throw new InvalidArgumentException('Usa un usuario de 3 a 80 caracteres y una contraseña de al menos 6 caracteres. Confirma la contraseña correctamente.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, \'admin\')'
            );
            $statement->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['role'] = 'admin';
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            respond([
                'authenticated' => true,
                'username' => $username,
                'role' => 'admin',
                'csrfToken' => $_SESSION['csrf_token'],
            ], 201);
        }

        $now = time();
        if (($now < (int) ($_SESSION['login_blocked_until'] ?? 0))) {
            respond(['error' => 'Demasiados intentos. Espera unos minutos antes de intentar de nuevo.'], 429);
        }

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $statement = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = :username');
        $statement->execute([':username' => $username]);
        $account = $statement->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            $failures = (int) ($_SESSION['login_failures'] ?? 0) + 1;
            $_SESSION['login_failures'] = $failures;
            if ($failures >= 5) {
                $_SESSION['login_blocked_until'] = $now + 300;
                $_SESSION['login_failures'] = 0;
            }
            respond(['error' => 'Usuario o contraseña incorrectos.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['username'] = $account['username'];
        $_SESSION['role'] = $account['role'];
        $_SESSION['last_activity'] = $now;
        $_SESSION['login_failures'] = 0;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond([
            'authenticated' => true,
            'username' => $account['username'],
            'role' => $account['role'],
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
        requireAuthenticated($pdo);
        requireCsrfToken();
        $_SESSION = [];
        session_regenerate_id(true);
        respond(['authenticated' => false, 'csrfToken' => csrfToken()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
        requireAuthenticated($pdo);
        respond(fetchState($pdo, ($_SESSION['role'] ?? '') === 'admin'));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Método no permitido.'], 405);
    }

    $data = readJson();
    requireAuthenticated($pdo);
    requireCsrfToken();

    if ($action === 'products' && ($_SESSION['role'] ?? '') !== 'admin') {
        respond(['error' => 'No tienes permiso para modificar el inventario.'], 403);
    }

    if ($action === 'products') {
        $name = trim((string) ($data['name'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $price = filter_var($data['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($data['stock'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || $category === '' || strlen($name) > 120 || strlen($category) > 80
            || $price === false || $price <= 0 || $stock === false || $stock < 0) {
            throw new InvalidArgumentException('Completa correctamente todos los campos del producto.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO products (name, category, price, stock, icon, created_at)
             VALUES (:name, :category, :price, :stock, :icon, :created_at)'
        );
        $statement->execute([
            ':name' => $name,
            ':category' => $category,
            ':price' => round((float) $price, 2),
            ':stock' => $stock,
            ':icon' => categoryIcon($category),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $pdo->lastInsertId();
        $sku = 'SKU-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE products SET sku = :sku WHERE id = :id')
            ->execute([':sku' => $sku, ':id' => $id]);

        respond(['state' => fetchState($pdo, ($_SESSION['role'] ?? '') === 'admin')], 201);
    }

    if ($action === 'sales') {
        $customer = trim((string) ($data['customer'] ?? 'Cliente general'));
        $payment = (string) ($data['payment'] ?? '');
        $discountRate = filter_var($data['discountRate'] ?? 0, FILTER_VALIDATE_INT);
        $items = $data['items'] ?? null;
        $receivedInput = $data['received'] ?? 0;
        if ($customer === '') {
            $customer = 'Cliente general';
        }
        if (strlen($customer) > 120 || !in_array($payment, ['cash', 'card', 'transfer'], true)
            || $discountRate === false || !in_array($discountRate, [0, 5, 10, 15, 20], true)
            || !is_array($items) || count($items) === 0
            || !is_numeric($receivedInput) || !is_finite((float) $receivedInput)
            || (float) $receivedInput < 0) {
            throw new InvalidArgumentException('Los datos de la venta no son válidos.');
        }

        $quantities = [];
        foreach ($items as $item) {
            $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1) {
                throw new InvalidArgumentException('Hay un producto o cantidad no válida en la venta.');
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        }

        $pdo->beginTransaction();
        $productQuery = $pdo->prepare(
            'SELECT id, name, icon, price, stock FROM products WHERE id = :id'
        );
        $stockUpdate = $pdo->prepare(
            'UPDATE products SET stock = stock - :quantity
             WHERE id = :id AND stock >= :quantity'
        );
        $saleItems = [];
        $subtotal = 0.0;

        foreach ($quantities as $productId => $quantity) {
            $productQuery->execute([':id' => $productId]);
            $product = $productQuery->fetch();
            if (!$product || (int) $product['stock'] < $quantity) {
                throw new StockConflict('No hay suficiente stock para completar la venta.');
            }

            $stockUpdate->execute([':quantity' => $quantity, ':id' => $productId]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new StockConflict('El stock cambió. Actualiza el inventario e inténtalo otra vez.');
            }

            $price = round((float) $product['price'], 2);
            $subtotal += $price * $quantity;
            $saleItems[] = [
                'id' => (int) $product['id'],
                'name' => $product['name'],
                'icon' => $product['icon'],
                'price' => $price,
                'quantity' => $quantity,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = round($subtotal * $discountRate / 100, 2);
        $total = round($subtotal - $discount, 2);
        $received = $payment === 'cash' ? round((float) $receivedInput, 2) : $total;
        if ($payment === 'cash' && $received < $total) {
            throw new InvalidArgumentException('El monto recibido debe ser igual o mayor al total.');
        }
        $change = $payment === 'cash' ? round($received - $total, 2) : 0.0;
        $createdAt = date('Y-m-d H:i:s');

        $saleInsert = $pdo->prepare(
            'INSERT INTO sales
             (customer, seller, payment, subtotal, tax, discount_rate, discount_amount,
              total, received, change_amount, created_at)
             VALUES (:customer, :seller, :payment, :subtotal, 0, :discount_rate, :discount,
                     :total, :received, :change, :created_at)'
        );
        $saleInsert->execute([
            ':customer' => $customer,
            ':seller' => (string) $_SESSION['username'],
            ':payment' => $payment,
            ':subtotal' => $subtotal,
            ':discount_rate' => $discountRate,
            ':discount' => $discount,
            ':total' => $total,
            ':received' => $received,
            ':change' => $change,
            ':created_at' => $createdAt,
        ]);
        $saleId = (int) $pdo->lastInsertId();
        $itemInsert = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, name, icon, price, quantity)
             VALUES (:sale_id, :product_id, :name, :icon, :price, :quantity)'
        );
        foreach ($saleItems as $item) {
            $itemInsert->execute([
                ':sale_id' => $saleId,
                ':product_id' => $item['id'],
                ':name' => $item['name'],
                ':icon' => $item['icon'],
                ':price' => $item['price'],
                ':quantity' => $item['quantity'],
            ]);
        }

        $pdo->commit();
        $serverState = fetchState($pdo, ($_SESSION['role'] ?? '') === 'admin');
        $sale = [
            'id' => 'SV-' . str_pad((string) ($saleId + 1000), 4, '0', STR_PAD_LEFT),
            'customer' => $customer,
            'seller' => (string) $_SESSION['username'],
            'payment' => $payment,
            'subtotal' => $subtotal,
            'discountRate' => $discountRate,
            'discount' => $discount,
            'total' => $total,
            'received' => $received,
            'change' => $change,
            'createdAt' => $createdAt,
            'date' => substr($createdAt, 0, 10),
            'items' => $saleItems,
        ];
        respond(['sale' => $sale, 'state' => $serverState], 201);
    }

    respond(['error' => 'Acción no encontrada.'], 404);
} catch (InvalidArgumentException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['error' => $error->getMessage()], 422);
} catch (StockConflict $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['error' => $error->getMessage()], 409);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($error->getMessage());
    respond(['error' => 'Ocurrió un error en el servidor. Revisa la conexión y configuración de la base de datos.'], 500);
}
