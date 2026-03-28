--- START OF FILE Paste March 29, 2026 - 3:56AM ---

<?php
ob_start();
session_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'root');
define('DB_NAME', 'bookshop_management');
define('SUPERADMIN_SLUG', 'thesuperadmin');

$base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$requested_uri = $_SERVER['REQUEST_URI'];
$relative_uri = substr($requested_uri, strlen($base_path));
$relative_uri_parts = array_values(array_filter(explode('/', trim($relative_uri, '/'))));

$tenant_slug = null;
$current_page = 'home';
$is_superadmin_mode = false;
$is_public_main_site = true;

if (isset($relative_uri_parts[0]) && $relative_uri_parts[0] === SUPERADMIN_SLUG) {
    $is_superadmin_mode = true;
    $is_public_main_site = false;
    if (isset($relative_uri_parts[1])) {
        $current_page = $relative_uri_parts[1];
    } else {
        $current_page = 'dashboard';
    }
} elseif (isset($relative_uri_parts[0]) && $relative_uri_parts[0] !== '' && !in_array($relative_uri_parts[0], ['login', 'register', 'home', 'about', 'pricing', 'features', 'contact', 'customer-login', 'customer-register', 'policy'])) {
    $tenant_slug = $relative_uri_parts[0];
    $is_public_main_site = false;
    if (isset($relative_uri_parts[1])) {
        $current_page = $relative_uri_parts[1];
    } else {
        $current_page = 'home';
    }
} else {
    if (isset($relative_uri_parts[0])) {
        $current_page = $relative_uri_parts[0];
    }
}
if (empty($current_page) || !in_array($current_page, ['login', 'register', 'home', 'about', 'pricing', 'features', 'contact', 'customer-login', 'customer-register', 'policy'])) {
    if (!$is_superadmin_mode && !$tenant_slug && !empty($relative_uri_parts[0]) && strpos($relative_uri_parts[0], '.php') === false) {
        $tenant_slug = $relative_uri_parts[0];
        $is_public_main_site = false;
        $current_page = $relative_uri_parts[1] ?? 'home';
    } elseif (!$is_superadmin_mode && !$tenant_slug) {
        $current_page = $relative_uri_parts[0] ?? 'home';
    }
}

if ($tenant_slug) {
    define('TENANT_SLUG', $tenant_slug);
} else {
    define('TENANT_SLUG', null);
}

define('IS_SUPERADMIN_MODE', $is_superadmin_mode);
define('IS_PUBLIC_MAIN_SITE', $is_public_main_site);
define('CURRENT_PAGE', $current_page);
define('BASE_PATH', $base_path);
define('ROOT_URL', ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_PATH);
define('UPLOAD_DIR', __DIR__ . '/uploads');

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function generate_uuid_v4()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function table_exists($conn, $table)
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}

function column_exists($conn, $table, $column)
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function ensure_base_schema($conn)
{
    $conn->query('CREATE TABLE IF NOT EXISTS superadmin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS tenants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        contact_phone VARCHAR(50) NULL,
        address TEXT NULL,
        logo_path VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM("pending", "active", "suspended", "banned") NOT NULL DEFAULT "pending",
        registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        subscription_end_date DATE NULL,
        plan_id INT NULL,
        allow_uploads TINYINT(1) NOT NULL DEFAULT 0,
        invitation_code VARCHAR(190) UNIQUE NULL,
        created_by_superadmin INT NULL,
        FOREIGN KEY (created_by_superadmin) REFERENCES superadmin_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS subscription_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL UNIQUE,
        price_per_month DECIMAL(10,2) NOT NULL DEFAULT 499.00,
        enable_file_uploads TINYINT(1) NOT NULL DEFAULT 0,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS plan_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        page_key VARCHAR(190) NOT NULL,
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE CASCADE,
        UNIQUE(plan_id, page_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS subscription_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        plan_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        months_subscribed INT NOT NULL,
        payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        payment_proof_path VARCHAR(255) NULL,
        status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
        rejection_reason TEXT NULL,
        processed_by_superadmin INT NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE CASCADE,
        FOREIGN KEY (processed_by_superadmin) REFERENCES superadmin_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS superadmin_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS superadmin_news (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        media_path VARCHAR(255) NULL,
        media_type ENUM("image", "video_upload", "youtube_embed", "facebook_embed") NULL,
        visibility ENUM("all_users", "tenant_admins_only") NOT NULL DEFAULT "all_users",
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $conn->query('CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        user_id INT NULL,
        user_type ENUM("superadmin", "tenant_admin", "staff", "customer", "public") NOT NULL,
        action VARCHAR(255) NOT NULL,
        description TEXT NULL,
        ip_address VARCHAR(45) NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (!table_exists($conn, 'users')) {
        $conn->query('CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            username VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            UNIQUE(tenant_id, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'roles')) {
        $conn->query('CREATE TABLE roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(190) NOT NULL,
            is_tenant_admin TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            UNIQUE(tenant_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'role_page_permissions')) {
        $conn->query('CREATE TABLE role_page_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            page_key VARCHAR(190) NOT NULL,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            UNIQUE(role_id, page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'books')) {
        $conn->query('CREATE TABLE books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            product_type ENUM("book","general") NOT NULL DEFAULT "book",
            author VARCHAR(255) NULL,
            category VARCHAR(190) NOT NULL,
            isbn VARCHAR(120) UNIQUE NULL,
            barcode VARCHAR(120) NULL,
            publisher VARCHAR(255) NULL,
            year INT NULL,
            price DECIMAL(12,2) NOT NULL,
            purchase_price DECIMAL(12,2) NULL,
            retail_price DECIMAL(12,2) NULL,
            wholesale_price DECIMAL(12,2) NULL,
            stock INT NOT NULL DEFAULT 0,
            description TEXT NULL,
            cover_image VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            INDEX idx_books_barcode (barcode),
            INDEX idx_books_tenant_name (tenant_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'customers')) {
        $conn->query('CREATE TABLE customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NULL,
            address TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            UNIQUE(tenant_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'suppliers')) {
        $conn->query('CREATE TABLE suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(255) NULL,
            address TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            UNIQUE(tenant_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'purchase_orders')) {
        $conn->query('CREATE TABLE purchase_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            supplier_id INT NOT NULL,
            user_id INT NULL,
            order_date DATE NOT NULL,
            expected_date DATE NULL,
            status ENUM("pending","ordered","received","cancelled") NOT NULL DEFAULT "pending",
            total_cost DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'po_items')) {
        $conn->query('CREATE TABLE po_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            book_id INT NOT NULL,
            quantity INT NOT NULL,
            cost_per_unit DECIMAL(12,2) NOT NULL,
            FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'sales')) {
        $conn->query('CREATE TABLE sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            customer_id INT NULL,
            user_id INT NULL,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            subtotal DECIMAL(12,2) NOT NULL,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL,
            promotion_code VARCHAR(190) NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'sale_items')) {
        $conn->query('CREATE TABLE sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            book_id INT NOT NULL,
            quantity INT NOT NULL,
            price_per_unit DECIMAL(12,2) NOT NULL,
            discount_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'online_orders')) {
        $conn->query('CREATE TABLE online_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            customer_id INT NOT NULL,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            subtotal DECIMAL(12,2) NOT NULL,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL,
            promotion_code VARCHAR(190) NULL,
            status ENUM("pending","approved","rejected","delivered") NOT NULL DEFAULT "pending",
            sale_id INT NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'online_order_items')) {
        $conn->query('CREATE TABLE online_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            book_id INT NOT NULL,
            quantity INT NOT NULL,
            price_per_unit DECIMAL(12,2) NOT NULL,
            discount_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'promotions')) {
        $conn->query('CREATE TABLE promotions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            code VARCHAR(190) NOT NULL,
            type ENUM("percentage","fixed") NOT NULL,
            value DECIMAL(10,2) NOT NULL,
            applies_to ENUM("all","specific-book","specific-category") NOT NULL,
            applies_to_value VARCHAR(255) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            UNIQUE(tenant_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'expenses')) {
        $conn->query('CREATE TABLE expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            user_id INT NULL,
            expense_date DATE NOT NULL,
            category VARCHAR(190) NOT NULL,
            description TEXT NULL,
            amount DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'tenant_settings')) {
        $conn->query('CREATE TABLE tenant_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL UNIQUE,
            setting_key VARCHAR(190) NOT NULL,
            setting_value TEXT NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'public_news')) {
        $conn->query('CREATE TABLE public_news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'public_sale_links')) {
        $conn->query('CREATE TABLE public_sale_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            token VARCHAR(120) NOT NULL UNIQUE,
            link_name VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            price_mode ENUM("retail","wholesale") NOT NULL DEFAULT "retail",
            created_by INT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE(tenant_id, token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    if (!table_exists($conn, 'pwa_settings')) {
        $conn->query('CREATE TABLE pwa_settings (
            tenant_id INT PRIMARY KEY,
            app_name VARCHAR(255) NOT NULL,
            short_name VARCHAR(50) NOT NULL,
            theme_color VARCHAR(7) NOT NULL DEFAULT "#2a9d8f",
            background_color VARCHAR(7) NOT NULL DEFAULT "#ffffff",
            icon_path VARCHAR(255) NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    if (!column_exists($conn, 'books', 'barcode')) {
        $conn->query('ALTER TABLE books ADD COLUMN barcode VARCHAR(120) NULL AFTER isbn');
        $conn->query('ALTER TABLE books ADD INDEX idx_books_barcode (barcode)');
    }
    if (!column_exists($conn, 'books', 'retail_price')) {
        $conn->query('ALTER TABLE books ADD COLUMN retail_price DECIMAL(12,2) NULL AFTER price');
    }
    if (!column_exists($conn, 'books', 'wholesale_price')) {
        $conn->query('ALTER TABLE books ADD COLUMN wholesale_price DECIMAL(12,2) NULL AFTER retail_price');
    }
    if (!column_exists($conn, 'books', 'purchase_price')) {
        $conn->query('ALTER TABLE books ADD COLUMN purchase_price DECIMAL(12,2) NULL AFTER price');
    }
    $conn->query('UPDATE books SET retail_price = price WHERE retail_price IS NULL');
    $conn->query('UPDATE books SET wholesale_price = price WHERE wholesale_price IS NULL');
    $conn->query("UPDATE books SET barcode = REPLACE(isbn, '-', '') WHERE (barcode IS NULL OR barcode = '') AND isbn IS NOT NULL AND isbn <> ''");
    $conn->query('ALTER TABLE tenants ADD COLUMN plan_id INT NULL AFTER subscription_end_date');
    $conn->query('ALTER TABLE tenants ADD CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL');
    if (!column_exists($conn, 'tenants', 'allow_uploads')) {
        $conn->query('ALTER TABLE tenants ADD COLUMN allow_uploads TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!column_exists($conn, 'tenants', 'invitation_code')) {
        $conn->query('ALTER TABLE tenants ADD COLUMN invitation_code VARCHAR(190) UNIQUE NULL');
    }
    if (!column_exists($conn, 'tenants', 'created_by_superadmin')) {
        $conn->query('ALTER TABLE tenants ADD COLUMN created_by_superadmin INT NULL');
        $conn->query('ALTER TABLE tenants ADD CONSTRAINT fk_tenants_created_by_superadmin FOREIGN KEY (created_by_superadmin) REFERENCES superadmin_users(id) ON DELETE SET NULL');
    }
    if (!column_exists($conn, 'users', 'role_id')) {
        $conn->query('ALTER TABLE users ADD COLUMN role_id INT NULL AFTER password_hash');
        $conn->query('ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL');
        $conn->query("INSERT IGNORE INTO roles (tenant_id, name, is_tenant_admin) VALUES (1, 'Admin', 1)");
        $conn->query("INSERT IGNORE INTO roles (tenant_id, name, is_tenant_admin) VALUES (1, 'Staff', 0)");
        $conn->query("UPDATE users SET role_id = (SELECT id FROM roles WHERE tenant_id = users.tenant_id AND name = users.role) WHERE role_id IS NULL");
        $conn->query('ALTER TABLE users DROP COLUMN role');
    }
}
ensure_base_schema($conn);

function log_action($conn, $action, $description, $user_type, $tenant_id = null, $user_id = null)
{
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare('INSERT INTO audit_logs (tenant_id, user_id, user_type, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissss', $tenant_id, $user_id, $user_type, $action, $description, $ip_address);
    $stmt->execute();
    $stmt->close();
}

$tenant_id = null;
if (TENANT_SLUG) {
    $stmt = $conn->prepare('SELECT id, name, is_active, status, subscription_end_date, plan_id, allow_uploads, invitation_code FROM tenants WHERE slug = ?');
    $stmt->bind_param('s', TENANT_SLUG);
    $stmt->execute();
    $tenant_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($tenant_data) {
        $tenant_id = $tenant_data['id'];
        define('TENANT_ID', $tenant_id);
        define('TENANT_NAME', $tenant_data['name']);
        define('TENANT_IS_ACTIVE', $tenant_data['is_active']);
        define('TENANT_STATUS', $tenant_data['status']);
        define('TENANT_SUB_END_DATE', $tenant_data['subscription_end_date']);
        define('TENANT_PLAN_ID', $tenant_data['plan_id']);
        define('TENANT_ALLOW_UPLOADS', $tenant_data['allow_uploads']);
        define('TENANT_INVITATION_CODE', $tenant_data['invitation_code']);
    } else {
        header('Location: ' . ROOT_URL . '/404');
        exit();
    }
} else {
    define('TENANT_ID', null);
    define('TENANT_NAME', null);
    define('TENANT_IS_ACTIVE', 0);
    define('TENANT_STATUS', 'pending');
    define('TENANT_SUB_END_DATE', null);
    define('TENANT_PLAN_ID', null);
    define('TENANT_ALLOW_UPLOADS', 0);
    define('TENANT_INVITATION_CODE', null);
}

$settings = [];
if (TENANT_ID) {
    $stmt = $conn->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?');
    $stmt->bind_param('i', TENANT_ID);
    $stmt->execute();
    $settings_result = $stmt->get_result();
    if ($settings_result) {
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    $stmt->close();
}
$currency_symbol = html($settings['currency_symbol'] ?? 'PKR ');

$superadmin_settings = [];
$superadmin_settings_result = $conn->query('SELECT setting_key, setting_value FROM superadmin_settings');
if ($superadmin_settings_result) {
    while ($row = $superadmin_settings_result->fetch_assoc()) {
        $superadmin_settings[$row['setting_key']] = $row['setting_value'];
    }
}
define('DEFAULT_SUBSCRIPTION_PRICE_PER_MONTH', (float)($superadmin_settings['default_subscription_price_per_month'] ?? 499.00));

function get_redirect_url($page, $params = [])
{
    $queryString = http_build_query($params);
    if (IS_SUPERADMIN_MODE) {
        return ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . $page . ($queryString ? "?$queryString" : '');
    } elseif (TENANT_SLUG) {
        return ROOT_URL . '/' . TENANT_SLUG . '/' . $page . ($queryString ? "?$queryString" : '');
    } else {
        return ROOT_URL . '/' . $page . ($queryString ? "?$queryString" : '');
    }
}

function redirect($page, $params = [])
{
    header('Location: ' . get_redirect_url($page, $params));
    exit();
}

function html($text)
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function isSuperAdmin()
{
    return IS_SUPERADMIN_MODE && isset($_SESSION['superadmin_id']);
}

function isTenantAdmin()
{
    return !IS_SUPERADMIN_MODE && TENANT_ID && isset($_SESSION['user_id']) && isset($_SESSION['is_tenant_admin']) && $_SESSION['is_tenant_admin'];
}

function isStaff()
{
    return !IS_SUPERADMIN_MODE && TENANT_ID && isset($_SESSION['user_id']) && isset($_SESSION['user_role_name']) && $_SESSION['user_role_name'] === 'Staff';
}

function isCustomerLoggedIn()
{
    return !IS_SUPERADMIN_MODE && TENANT_ID && isset($_SESSION['customer_id']);
}

function isLoggedIn()
{
    return isSuperAdmin() || isTenantAdmin() || isStaff() || isCustomerLoggedIn();
}

function get_tenant_id_for_query()
{
    if (IS_SUPERADMIN_MODE) {
        return null;
    }
    return TENANT_ID;
}

function hasAccess($page)
{
    global $conn;
    if (isSuperAdmin()) {
        return true;
    }
    if (!TENANT_ID) {
        return false;
    }
    if (isTenantAdmin()) {
        return true;
    }
    if (isStaff()) {
        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return in_array($page, $_SESSION['permissions']);
        }
    }
    return false;
}

function hasPlanAccess($page_key)
{
    global $conn;
    if (isSuperAdmin()) {
        return true;
    }
    if (!TENANT_ID || !TENANT_PLAN_ID) {
        return false;
    }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM plan_permissions WHERE plan_id = ? AND page_key = ?');
    $stmt->bind_param('is', TENANT_PLAN_ID, $page_key);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count > 0;
}

function generate_uuid()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0xFFF) | 0x4000,
        mt_rand(0, 0x3FFF) | 0x8000,
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0xFFFF)
    );
}

function format_currency($amount)
{
    global $currency_symbol;
    return $currency_symbol . number_format($amount, 2);
}

function format_date($timestamp)
{
    return date('M d, Y h:i A', is_numeric($timestamp) ? $timestamp : strtotime($timestamp));
}

function format_short_date($timestamp)
{
    return date('Y-m-d', is_numeric($timestamp) ? $timestamp : strtotime($timestamp));
}

function has_public_sale_access($token)
{
    if (empty($token) || empty($_SESSION['public_sale_access'][TENANT_ID][$token]['granted_at'])) {
        return false;
    }
    $grantedAt = (int) $_SESSION['public_sale_access'][TENANT_ID][$token]['granted_at'];
    if ((time() - $grantedAt) > (8 * 60 * 60)) {
        unset($_SESSION['public_sale_access'][TENANT_ID][$token]);
        return false;
    }
    return true;
}

function current_public_sale_link($conn, $token)
{
    if (empty($token) || !TENANT_ID) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM public_sale_links WHERE tenant_id = ? AND token = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('is', TENANT_ID, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function get_tenant_status_info()
{
    if (!TENANT_ID) {
        return ['status' => 'not_a_tenant', 'message' => ''];
    }
    $current_date = date('Y-m-d');
    $expiry_date = TENANT_SUB_END_DATE;
    $status = TENANT_STATUS;
    $days_remaining = null;
    $grace_period_days = 6;
    $read_only_after_grace = false;

    if ($status === 'suspended' || $status === 'banned') {
        return ['status' => 'banned', 'message' => 'Your tenant account is ' . ucfirst($status) . '. Please contact support.'];
    }
    if ($expiry_date) {
        $expiry_datetime = new DateTime($expiry_date);
        $current_datetime = new DateTime($current_date);
        $interval = $current_datetime->diff($expiry_datetime);
        $days_remaining = (int)$interval->format('%r%a');

        if ($days_remaining < 0) {
            $days_after_expiry = abs($days_remaining);
            if ($days_after_expiry <= $grace_period_days) {
                return ['status' => 'grace_period', 'message' => 'Your subscription has expired! You are in a ' . $grace_period_days . '-day grace period. Please renew to avoid service interruption.', 'days_remaining' => -$days_after_expiry];
            } else {
                return ['status' => 'read_only', 'message' => 'Your subscription has expired and your account is now in read-only mode. Please renew to regain full access.', 'days_remaining' => $days_remaining];
            }
        } elseif ($days_remaining <= 7) {
            return ['status' => 'expiring_soon', 'message' => 'Your subscription will expire in ' . $days_remaining . ' days. Please renew soon!', 'days_remaining' => $days_remaining];
        }
    }
    return ['status' => 'active', 'message' => ''];
}

$tenant_status_info = get_tenant_status_info();
define('TENANT_APP_STATUS', $tenant_status_info['status']);
$app_in_read_only_mode = (TENANT_ID && (TENANT_APP_STATUS === 'read_only' || TENANT_APP_STATUS === 'banned' || TENANT_STATUS !== 'active'));

if (IS_PUBLIC_MAIN_SITE) {
    $APP_PAGES = ['home', 'login', 'register', 'features', 'pricing', 'about', 'contact', 'policy'];
} elseif (IS_SUPERADMIN_MODE) {
    $APP_PAGES = ['dashboard', 'tenants', 'plans', 'settings', 'news', 'logs', 'backup-restore'];
} else {
    $APP_PAGES = ['dashboard', 'books', 'users', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'online-orders', 'promotions', 'expenses', 'reports', 'live-sales', 'news', 'settings', 'public-sale-links', 'print-barcodes', 'backup-restore', 'customer-dashboard', 'online-shop-cart', 'my-orders', 'profile', 'subscription', 'customer-login', 'customer-register', 'home', 'about', 'contact', 'books-public', 'pwa-install'];
}

if (isset($_SESSION['auth_started_at']) && (time() - (int) $_SESSION['auth_started_at']) > (40 * 60)) {
    $session_user_type = 'unknown';
    $session_user_id = null;
    if (isSuperAdmin()) {
        $session_user_type = 'superadmin';
        $session_user_id = $_SESSION['superadmin_id'];
    } elseif (isTenantAdmin() || isStaff()) {
        $session_user_type = 'tenant_admin_or_staff';
        $session_user_id = $_SESSION['user_id'];
    } elseif (isCustomerLoggedIn()) {
        $session_user_type = 'customer';
        $session_user_id = $_SESSION['customer_id'];
    }
    log_action($conn, 'Logout (Idle)', 'User logged out due to idle session timeout (40 min).', $session_user_type, TENANT_ID, $session_user_id);
    session_destroy();
    session_start();
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'Your session expired due to inactivity. Please log in again.'];
    if ($session_user_type === 'superadmin') {
        redirect('login');
    } elseif ($session_user_type === 'customer') {
        redirect('customer-login');
    } else {
        redirect('login');
    }
}
if (isset($_SESSION['auth_absolute_start']) && (time() - (int) $_SESSION['auth_absolute_start']) > (8 * 60 * 60)) {
    $session_user_type = 'unknown';
    $session_user_id = null;
    if (isSuperAdmin()) {
        $session_user_type = 'superadmin';
        $session_user_id = $_SESSION['superadmin_id'];
    } elseif (isTenantAdmin() || isStaff()) {
        $session_user_type = 'tenant_admin_or_staff';
        $session_user_id = $_SESSION['user_id'];
    } elseif (isCustomerLoggedIn()) {
        $session_user_type = 'customer';
        $session_user_id = $_SESSION['customer_id'];
    }
    log_action($conn, 'Logout (Absolute)', 'User logged out due to absolute session limit (8 hours).', $session_user_type, TENANT_ID, $session_user_id);
    session_destroy();
    session_start();
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'Your session expired after 8 hours. Please log in again.'];
    if ($session_user_type === 'superadmin') {
        redirect('login');
    } elseif ($session_user_type === 'customer') {
        redirect('customer-login');
    } else {
        redirect('login');
    }
}
$_SESSION['auth_started_at'] = time();

if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        $session_user_type = 'unknown';
        $session_user_id = null;
        if (isSuperAdmin()) {
            $session_user_type = 'superadmin';
            $session_user_id = $_SESSION['superadmin_id'];
        } elseif (isTenantAdmin() || isStaff()) {
            $session_user_type = 'tenant_admin_or_staff';
            $session_user_id = $_SESSION['user_id'];
        } elseif (isCustomerLoggedIn()) {
            $session_user_type = 'customer';
            $session_user_id = $_SESSION['customer_id'];
        }
        log_action($conn, 'Logout', 'User manually logged out.', $session_user_type, TENANT_ID, $session_user_id);
        session_destroy();
        session_start();
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'You have been logged out.'];
        if (IS_SUPERADMIN_MODE) {
            redirect('login');
        } elseif (TENANT_ID && ($current_page === 'customer-dashboard' || $current_page === 'online-shop-cart' || $current_page === 'my-orders' || $current_page === 'profile')) {
            redirect('customer-login');
        } else {
            redirect('login');
        }
    }
    if (in_array($_GET['action'], ['get_public_books_json', 'get_online_order_status', 'get_book_by_barcode_json', 'get_sidebar_products_json', 'get_sale_details_json', 'captcha', 'verify_captcha', 'get_tenant_pwa_manifest', 'get_tenant_pwa_logo_icon', 'get_app_config_json'])) {
    } elseif (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    if ($app_in_read_only_mode && !in_array($_GET['action'], ['captcha', 'verify_captcha', 'get_public_books_json', 'get_book_by_barcode_json', 'get_sidebar_products_json', 'get_tenant_pwa_manifest', 'get_tenant_pwa_logo_icon'])) {
        echo json_encode(['success' => false, 'message' => 'Tenant account is in read-only mode. Renew subscription to regain full access.']);
        exit();
    }
    header('Content-Type: application/json');
    $action = $_GET['action'];
    switch ($action) {
        case 'get_app_config_json':
            echo json_encode(['root_url' => ROOT_URL]);
            exit();
        case 'captcha':
            $num1 = rand(1, 9);
            $num2 = rand(1, 9);
            $operators = ['+', '-', '*'];
            $op = $operators[rand(0, 2)];
            if ($op === '-') {
                if ($num1 <= $num2) {
                    $num1 = $num2 + rand(1, 5);
                }
                $_SESSION['captcha'] = (string) ($num1 - $num2);
            } elseif ($op === '+') {
                $_SESSION['captcha'] = (string) ($num1 + $num2);
            } else {
                $_SESSION['captcha'] = (string) ($num1 * $num2);
            }
            $code = "$num1 $op $num2 = ?";
            $image = imagecreatetruecolor(120, 40);
            $bg = imagecolorallocate($image, 245, 245, 245);
            $fg = imagecolorallocate($image, 42, 157, 143);
            $line_color = imagecolorallocate($image, 200, 200, 200);
            imagefill($image, 0, 0, $bg);
            for ($i = 0; $i < 8; $i++) {
                imageline($image, rand(0, 120), rand(0, 40), rand(0, 120), rand(0, 40), $line_color);
            }
            for ($i = 0; $i < 100; $i++) {
                imagesetpixel($image, rand(0, 120), rand(0, 40), $line_color);
            }
            imagestring($image, 5, 20, 12, $code, $fg);
            header('Cache-Control: no-cache, must-revalidate');
            header('Content-type: image/png');
            imagepng($image);
            imagedestroy($image);
            exit();
        case 'verify_captcha':
            $input = $_GET['captcha'] ?? '';
            $valid = (!empty($_SESSION['captcha']) && strtolower($input) === strtolower($_SESSION['captcha']));
            echo json_encode(['success' => $valid]);
            exit();
        case 'update_session_cart':
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['cart'])) {
                $_SESSION['cart'] = $data['cart'];
            }
            if (isset($data['promotion'])) {
                $_SESSION['applied_promotion'] = $data['promotion'];
            }
            echo json_encode(['success' => true]);
            exit();
        case 'get_books_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('books')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $book_id = $_GET['book_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $sort = $_GET['sort'] ?? 'popular';
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($book_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $book_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR author LIKE ? OR isbn LIKE ? OR barcode LIKE ? OR category LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
                $types .= 'sssss';
            }
            $order_by = '';
            switch ($sort) {
                case 'popular':
                    $order_by = 'total_sold DESC, name ASC';
                    break;
                case 'name-asc':
                    $order_by = 'name ASC';
                    break;
                case 'name-desc':
                    $order_by = 'name DESC';
                    break;
                case 'price-asc':
                    $order_by = 'price ASC';
                    break;
                case 'price-desc':
                    $order_by = 'price DESC';
                    break;
                case 'stock-asc':
                    $order_by = 'stock ASC';
                    break;
                case 'stock-desc':
                    $order_by = 'stock DESC';
                    break;
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM books $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT *, (SELECT IFNULL(SUM(quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.book_id = books.id AND s.tenant_id = books.tenant_id) AS total_sold FROM books $where_sql ORDER BY $order_by LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $books = [];
            while ($row = $result->fetch_assoc()) {
                $row['cover_image'] = $row['cover_image'] ? ROOT_URL . '/' . TENANT_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['cover_image']) : null;
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_public_books_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? 'all';
            $product_type = $_GET['product_type'] ?? 'all';
            $sort = $_GET['sort'] ?? 'popular';
            $limit = $_GET['limit'] ?? 12;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?', 'stock > 0'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR author LIKE ? OR isbn LIKE ? OR barcode LIKE ? OR description LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
                $types .= 'sssss';
            }
            if ($category !== 'all') {
                $where_clauses[] = 'category = ?';
                $params[] = $category;
                $types .= 's';
            }
            if ($product_type !== 'all') {
                $where_clauses[] = 'product_type = ?';
                $params[] = $product_type;
                $types .= 's';
            }
            $order_by = '';
            switch ($sort) {
                case 'popular':
                    $order_by = 'total_sold DESC, name ASC';
                    break;
                case 'name-asc':
                    $order_by = 'name ASC';
                    break;
                case 'name-desc':
                    $order_by = 'name DESC';
                    break;
                case 'price-asc':
                    $order_by = 'price ASC';
                    break;
                case 'price-desc':
                    $order_by = 'price DESC';
                    break;
            }
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM books $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT id, name, author, category, isbn, barcode, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price, stock, description, cover_image, product_type, (SELECT IFNULL(SUM(quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.book_id = books.id AND s.tenant_id = books.tenant_id) AS total_sold FROM books $where_sql ORDER BY $order_by LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $books = [];
            while ($row = $result->fetch_assoc()) {
                $row['cover_image'] = $row['cover_image'] ? ROOT_URL . '/' . TENANT_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['cover_image']) : null;
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_customers_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('customers')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $customer_id = $_GET['customer_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($customer_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $customer_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            if ($status !== 'all') {
                $where_clauses[] = 'is_active = ?';
                $params[] = ($status === 'active' ? 1 : 0);
                $types .= 'i';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM customers $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT id, name, phone, email, address, is_active FROM customers $where_sql ORDER BY name ASC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $customers = [];
            while ($row = $result->fetch_assoc()) {
                $customers[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'customers' => $customers, 'total_items' => $total_items]);
            exit();
        case 'get_suppliers_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('suppliers')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $supplier_id = $_GET['supplier_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($supplier_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $supplier_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
                $types .= 'ssss';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM suppliers $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT * FROM suppliers $where_sql ORDER BY name ASC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $suppliers = [];
            while ($row = $result->fetch_assoc()) {
                $suppliers[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'suppliers' => $suppliers, 'total_items' => $total_items]);
            exit();
        case 'get_pos_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('purchase-orders')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $po_id = $_GET['po_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['po.tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($po_id) {
                $where_clauses[] = 'po.id = ?';
                $params[] = $po_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(po.id LIKE ? OR s.name LIKE ? OR po.status LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            if ($status !== 'all') {
                $where_clauses[] = 'po.status = ?';
                $params[] = $status;
                $types .= 's';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT po.*, s.name AS supplier_name 
                    FROM purchase_orders po 
                    JOIN suppliers s ON po.supplier_id = s.id 
                    $where_sql ORDER BY po.order_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $purchase_orders = [];
            while ($row = $result->fetch_assoc()) {
                $po_items_stmt = $conn->prepare('SELECT poi.*, b.name FROM po_items poi JOIN books b ON poi.book_id = b.id WHERE poi.po_id = ?');
                $po_items_stmt->bind_param('i', $row['id']);
                $po_items_stmt->execute();
                $po_items_result = $po_items_stmt->get_result();
                $row['items'] = [];
                while ($item_row = $po_items_result->fetch_assoc()) {
                    $row['items'][] = $item_row;
                }
                $po_items_stmt->close();
                $purchase_orders[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'purchase_orders' => $purchase_orders, 'total_items' => $total_items]);
            exit();
        case 'get_books_for_cart_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('cart')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?', 'stock > 0'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR author LIKE ? OR isbn LIKE ? OR barcode LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
                $types .= 'ssss';
            }
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM books $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT id, name, author, barcode, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price, stock, category, product_type, cover_image, (SELECT IFNULL(SUM(quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.book_id = books.id AND s.tenant_id = books.tenant_id) AS total_sold FROM books $where_sql ORDER BY total_sold DESC, name ASC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $books = [];
            while ($row = $result->fetch_assoc()) {
                $row['cover_image'] = $row['cover_image'] ? ROOT_URL . '/' . TENANT_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['cover_image']) : null;
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_sales_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('sales-history')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['s.tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(s.id LIKE ? OR c.name LIKE ? OR b.name LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $join_sql = 'LEFT JOIN customers c ON s.customer_id = c.id LEFT JOIN sale_items si ON s.id = si.sale_id LEFT JOIN books b ON si.book_id = b.id';
            $group_by = 'GROUP BY s.id';
            $stmt = $conn->prepare("SELECT COUNT(DISTINCT s.id) AS total FROM sales s $join_sql $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT s.*, c.name AS customer_name,
                    GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                    FROM sales s
                    $join_sql
                    $where_sql
                    $group_by
                    ORDER BY s.sale_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $sales_records = [];
            while ($row = $result->fetch_assoc()) {
                $sales_records[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'sales' => $sales_records, 'total_items' => $total_items]);
            exit();
        case 'get_customer_details_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $customer_id = $_SESSION['customer_id'];
            $stmt = $conn->prepare('SELECT name, phone, email, address FROM customers WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $customer_id);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($customer) {
                echo json_encode(['success' => true, 'customer' => $customer]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Customer not found.']);
            }
            exit();
        case 'get_sale_details_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('sales-history')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $public_token = trim($_GET['token'] ?? '');
            if (!isTenantAdmin() && !isStaff() && !has_public_sale_access($public_token)) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $sale_id = $_GET['sale_id'] ?? '';
            $stmt = $conn->prepare('SELECT s.*, c.name AS customer_name 
                                    FROM sales s 
                                    LEFT JOIN customers c ON s.customer_id = c.id 
                                    WHERE s.tenant_id = ? AND s.id = ?');
            $stmt->bind_param('ii', TENANT_ID, $sale_id);
            $stmt->execute();
            $sale = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($sale) {
                $stmt_items = $conn->prepare('SELECT si.*, b.name FROM sale_items si JOIN books b ON si.book_id = b.id WHERE si.sale_id = ?');
                $stmt_items->bind_param('i', $sale_id);
                $stmt_items->execute();
                $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();
                $sale['items'] = $items;
                echo json_encode(['success' => true, 'sale' => $sale]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Sale not found.']);
            }
            exit();
        case 'get_customer_history_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!isTenantAdmin() && !isStaff() && !isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $customer_id = isCustomerLoggedIn() ? $_SESSION['customer_id'] : ($_GET['customer_id'] ?? '');
            if (empty($customer_id)) {
                echo json_encode(['success' => false, 'message' => 'Customer ID not provided.']);
                exit();
            }
            $stmt = $conn->prepare("SELECT s.*, 
                                    GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                                    FROM sales s
                                    JOIN sale_items si ON s.id = si.sale_id
                                    JOIN books b ON si.book_id = b.id
                                    WHERE s.tenant_id = ? AND s.customer_id = ?
                                    GROUP BY s.id
                                    ORDER BY s.sale_date DESC");
            $stmt->bind_param('ii', TENANT_ID, $customer_id);
            $stmt->execute();
            $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['success' => true, 'sales' => $sales]);
            exit();
        case 'get_online_orders_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!isTenantAdmin() && !isStaff() && !isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $order_id = $_GET['order_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            if (isTenantAdmin() || isStaff()) {
                $status = $_GET['status'] ?? 'pending';
                if (!hasPlanAccess('online-orders')) {
                    echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                    exit();
                }
            }
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['oo.tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if (isCustomerLoggedIn()) {
                $where_clauses[] = 'oo.customer_id = ?';
                $params[] = $_SESSION['customer_id'];
                $types .= 'i';
            }
            if ($order_id) {
                $where_clauses[] = 'oo.id = ?';
                $params[] = $order_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(oo.id LIKE ? OR c.name LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term]);
                $types .= 'ss';
            }
            if ($status !== 'all') {
                $where_clauses[] = 'oo.status = ?';
                $params[] = $status;
                $types .= 's';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM online_orders oo JOIN customers c ON oo.customer_id = c.id $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT oo.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone FROM online_orders oo JOIN customers c ON oo.customer_id = c.id $where_sql ORDER BY oo.order_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $online_orders = [];
            while ($row = $result->fetch_assoc()) {
                $order_items_stmt = $conn->prepare('SELECT ooi.*, b.name FROM online_order_items ooi JOIN books b ON ooi.book_id = b.id WHERE ooi.order_id = ?');
                $order_items_stmt->bind_param('i', $row['id']);
                $order_items_stmt->execute();
                $order_items_result = $order_items_stmt->get_result();
                $row['items'] = [];
                while ($item_row = $order_items_result->fetch_assoc()) {
                    $row['items'][] = $item_row;
                }
                $order_items_stmt->close();
                $online_orders[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'online_orders' => $online_orders, 'total_items' => $total_items]);
            exit();
        case 'get_promotions_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('promotions')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $promotion_id = $_GET['promotion_id'] ?? null;
            $where_clauses = ['p.tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($promotion_id) {
                $where_clauses[] = 'p.id = ?';
                $params[] = $promotion_id;
                $types .= 'i';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $promotions = [];
            $sql = "SELECT p.*, b.name AS book_name, b.author AS book_author 
                    FROM promotions p 
                    LEFT JOIN books b ON p.applies_to_value = b.id AND p.applies_to = 'specific-book' 
                    $where_sql
                    ORDER BY p.start_date DESC";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if ($row['applies_to'] === 'specific-book' && $row['book_name']) {
                        $row['applies_to_value_name'] = html($row['book_name'] . ($row['book_author'] ? ' by ' . $row['book_author'] : ''));
                    } else if ($row['applies_to'] === 'specific-category' && $row['applies_to_value']) {
                        $row['applies_to_value_name'] = html($row['applies_to_value']);
                    } else if ($row['applies_to'] === 'all') {
                        $row['applies_to_value_name'] = 'Entire Order';
                    } else {
                        $row['applies_to_value_name'] = 'N/A';
                    }
                    $promotions[] = $row;
                }
            }
            $stmt->close();
            echo json_encode(['success' => true, 'promotions' => $promotions]);
            exit();
        case 'get_expenses_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('expenses')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $expense_id = $_GET['expense_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $month = $_GET['month'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($expense_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $expense_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(description LIKE ? OR category LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term]);
                $types .= 'ss';
            }
            if ($month) {
                $where_clauses[] = "DATE_FORMAT(expense_date, '%Y-%m') = ?";
                $params[] = $month;
                $types .= 's';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM expenses $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT * FROM expenses $where_sql ORDER BY expense_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $expenses = [];
            while ($row = $result->fetch_assoc()) {
                $expenses[] = $row;
            }
            $stmt->close();
            $monthly_total = 0;
            if ($month) {
                $stmt_total = $conn->prepare("SELECT SUM(amount) AS total_amount FROM expenses WHERE tenant_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?");
                $stmt_total->bind_param('is', TENANT_ID, $month);
                $stmt_total->execute();
                $monthly_total = $stmt_total->get_result()->fetch_assoc()['total_amount'] ?? 0;
                $stmt_total->close();
            } else {
                $monthly_total = array_reduce($expenses, function ($sum, $item) {
                    return $sum + $item['amount'];
                }, 0);
            }
            echo json_encode(['success' => true, 'expenses' => $expenses, 'total_items' => $total_items, 'monthly_total' => $monthly_total]);
            exit();
        case 'get_settings_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('settings')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $result = $stmt->get_result();
            $settings_data = [];
            while ($row = $result->fetch_assoc()) {
                $settings_data[$row['setting_key']] = $row['setting_value'];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'settings' => $settings_data]);
            exit();
        case 'get_report_data_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('reports')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $report_type = $_GET['report_type'] ?? '';
            $report_data = ['table_html' => '<tr><td colspan="3">No data generated.</td></tr>', 'chart_data' => null, 'raw_data' => []];
            switch ($report_type) {
                case 'sales-daily':
                    $selected_date = $_GET['date'] ?? date('Y-m-d');
                    $start_of_day = $selected_date . ' 00:00:00';
                    $end_of_day = $selected_date . ' 23:59:59';
                    $stmt = $conn->prepare('SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.tenant_id = ? AND s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('iss', TENANT_ID, $start_of_day, $end_of_day);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $daily_sales = [];
                    while ($row = $result->fetch_assoc()) {
                        $daily_sales[] = $row;
                    }
                    $stmt->close();
                    $total_sales = array_sum(array_column($daily_sales, 'total'));
                    $total_items_sold = array_sum(array_column($daily_sales, 'quantity'));
                    $total_discount_applied = array_sum(array_column($daily_sales, 'discount'));
                    $num_sales = count(array_unique(array_column($daily_sales, 'total')));
                    $report_data['table_html'] = '
                        <tr><td>Date</td><td>' . html($selected_date) . '</td><td></td></tr>
                        <tr><td>Total Sales</td><td>' . format_currency($total_sales) . '</td><td></td></tr>
                        <tr><td>Number of Sales</td><td>' . html($num_sales) . '</td><td></td></tr>
                        <tr><td>Total Items Sold</td><td>' . html($total_items_sold) . '</td><td></td></tr>
                        <tr><td>Total Discount Applied</td><td>' . format_currency($total_discount_applied) . '</td><td></td></tr>
                    ';
                    $report_data['chart_data'] = [
                        'labels' => ['Total Sales', 'Total Discount'],
                        'datasets' => [
                            ['label' => 'Amount', 'data' => [$total_sales, $total_discount_applied], 'backgroundColor' => ['#2a9d8f', '#f4a261'], 'borderColor' => ['#2a9d8f', '#f4a261'], 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Daily Sales Report for ' . html($selected_date)
                    ];
                    $report_data['raw_data'] = [
                        ['Metric' => 'Date', 'Value' => $selected_date],
                        ['Metric' => 'Total Sales', 'Value' => format_currency($total_sales)],
                        ['Metric' => 'Number of Sales', 'Value' => $num_sales],
                        ['Metric' => 'Total Items Sold', 'Value' => $total_items_sold],
                        ['Metric' => 'Total Discount Applied', 'Value' => format_currency($total_discount_applied)],
                    ];
                    break;
                case 'sales-weekly':
                    $selected_month_str = $_GET['month'] ?? date('Y-m');
                    $year = (int) substr($selected_month_str, 0, 4);
                    $month = (int) substr($selected_month_str, 5, 2);
                    $first_day_of_month = new DateTime("$year-$month-01");
                    $last_day_of_month = new DateTime("$year-$month-" . $first_day_of_month->format('t'));
                    $week_starts = [];
                    $current_week_start = clone $first_day_of_month;
                    $current_week_start->modify('last sunday');
                    if ($current_week_start > $first_day_of_month && (int) $current_week_start->format('m') === $month && (int) $current_week_start->format('d') > (int) $first_day_of_month->format('d')) {
                        $current_week_start = clone $first_day_of_month;
                    }
                    if ((int) $current_week_start->format('m') < $month) {
                        $current_week_start = clone $first_day_of_month;
                    }
                    while ($current_week_start <= $last_day_of_month || (int) $current_week_start->format('m') === $month) {
                        $week_end = clone $current_week_start;
                        $week_end->modify('+6 days');
                        $week_key = $current_week_start->format('Y-m-d') . ' - ' . $week_end->format('Y-m-d');
                        $week_starts[$week_key] = ['start' => clone $current_week_start, 'end' => clone $week_end, 'total' => 0, 'count' => 0, 'items' => 0];
                        $current_week_start->modify('+7 days');
                    }
                    $sales = [];
                    $stmt = $conn->prepare('SELECT s.total, si.quantity, s.sale_date FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.tenant_id = ? AND s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('iss', TENANT_ID, $first_day_of_month->format('Y-m-d 00:00:00'), $last_day_of_month->format('Y-m-d 23:59:59'));
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $sales[] = $row;
                    }
                    $stmt->close();
                    foreach ($sales as $sale) {
                        $sale_date = new DateTime($sale['sale_date']);
                        foreach ($week_starts as $key => &$week_data) {
                            if ($sale_date >= $week_data['start'] && $sale_date <= $week_data['end']) {
                                $week_data['total'] += $sale['total'];
                                $week_data['count']++;
                                $week_data['items'] += $sale['quantity'];
                                break;
                            }
                        }
                    }
                    $html = '';
                    $chart_labels = [];
                    $chart_data_sales = [];
                    $raw_data_array = [['Metric' => 'Month', 'Value' => $selected_month_str]];
                    foreach ($week_starts as $key => $data) {
                        $html .= '<tr><td>' . html(format_short_date($data['start']->getTimestamp())) . ' - ' . html(format_short_date($data['end']->getTimestamp())) . '</td><td>' . format_currency($data['total']) . '</td><td>' . html($data['count']) . ' sales, ' . html($data['items']) . ' items</td></tr>';
                        $chart_labels[] = html(format_short_date($data['start']->getTimestamp()));
                        $chart_data_sales[] = $data['total'];
                        $raw_data_array[] = ['Metric' => $key, 'Value' => format_currency($data['total']) . ' (' . $data['count'] . ' sales, ' . $data['items'] . ' items)'];
                    }
                    $report_data['table_html'] = $html ?: `<tr><td colspan="3">No weekly sales found for ` . html($selected_month_str) . `.</td></tr>`;
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Weekly Sales', 'data' => $chart_data_sales, 'backgroundColor' => 'rgba(42, 157, 143, 0.7)', 'borderColor' => 'rgba(42, 157, 143, 1)', 'borderWidth' => 1, 'fill' => false, 'tension' => 0.3]
                        ],
                        'type' => 'line',
                        'title' => 'Weekly Sales Report for ' . html($selected_month_str)
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'sales-monthly':
                    $selected_month_str = $_GET['month'] ?? date('Y-m');
                    $year = (int) substr($selected_month_str, 0, 4);
                    $month = (int) substr($selected_month_str, 5, 2);
                    $start_of_month = $selected_month_str . '-01 00:00:00';
                    $end_of_month = date('Y-m-t', strtotime($selected_month_str)) . ' 23:59:59';
                    $stmt = $conn->prepare('SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.tenant_id = ? AND s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('iss', TENANT_ID, $start_of_month, $end_of_month);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $monthly_sales = [];
                    while ($row = $result->fetch_assoc()) {
                        $monthly_sales[] = $row;
                    }
                    $stmt->close();
                    $total_sales = array_sum(array_column($monthly_sales, 'total'));
                    $total_items_sold = array_sum(array_column($monthly_sales, 'quantity'));
                    $total_discount_applied = array_sum(array_column($monthly_sales, 'discount'));
                    $num_sales = count(array_unique(array_column($monthly_sales, 'total')));
                    $report_data['table_html'] = '
                        <tr><td>Month</td><td>' . html($selected_month_str) . '</td><td></td></tr>
                        <tr><td>Total Sales</td><td>' . format_currency($total_sales) . '</td><td></td></tr>
                        <tr><td>Number of Sales</td><td>' . html($num_sales) . '</td><td></td></tr>
                        <tr><td>Total Items Sold</td><td>' . html($total_items_sold) . '</td><td></td></tr>
                        <tr><td>Total Discount Applied</td><td>' . format_currency($total_discount_applied) . '</td><td></td></tr>
                    ';
                    $report_data['chart_data'] = [
                        'labels' => ['Total Sales', 'Total Discount'],
                        'datasets' => [
                            ['label' => 'Amount', 'data' => [$total_sales, $total_discount_applied], 'backgroundColor' => ['#2a9d8f', '#f4a261'], 'borderColor' => ['#2a9d8f', '#f4a261'], 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Monthly Sales Report for ' . html($selected_month_str)
                    ];
                    $report_data['raw_data'] = [
                        ['Metric' => 'Month', 'Value' => $selected_month_str],
                        ['Metric' => 'Total Sales', 'Value' => format_currency($total_sales)],
                        ['Metric' => 'Number of Sales', 'Value' => $num_sales],
                        ['Metric' => 'Total Items Sold', 'Value' => $total_items_sold],
                        ['Metric' => 'Total Discount Applied', 'Value' => format_currency($total_discount_applied)],
                    ];
                    break;
                case 'best-selling':
                    $stmt = $conn->prepare('SELECT b.name, b.author, SUM(si.quantity) AS total_quantity_sold, SUM((si.price_per_unit * si.quantity) - si.discount_per_unit) AS total_revenue 
                                            FROM sale_items si 
                                            JOIN sales s ON si.sale_id = s.id
                                            JOIN books b ON si.book_id = b.id 
                                            WHERE s.tenant_id = ?
                                            GROUP BY b.id 
                                            ORDER BY total_quantity_sold DESC 
                                            LIMIT 10');
                    $stmt->bind_param('i', TENANT_ID);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $best_selling_books = [];
                    while ($row = $result->fetch_assoc()) {
                        $best_selling_books[] = $row;
                    }
                    $stmt->close();
                    $html = '';
                    $chart_labels = [];
                    $chart_data_sales = [];
                    $chart_data_revenue = [];
                    $raw_data_array = [];
                    foreach ($best_selling_books as $index => $book) {
                        $html .= '<tr><td>' . html($index + 1) . '</td><td>' . html($book['name']) . ' (' . html($book['author']) . ')</td><td>' . html($book['total_quantity_sold']) . ' units sold, ' . format_currency($book['total_revenue']) . ' revenue</td></tr>';
                        $chart_labels[] = html($book['name']);
                        $chart_data_sales[] = $book['total_quantity_sold'];
                        $chart_data_revenue[] = $book['total_revenue'];
                        $raw_data_array[] = ['Rank' => $index + 1, 'Name' => $book['name'], 'Units Sold' => $book['total_quantity_sold'], 'Revenue' => format_currency($book['total_revenue'])];
                    }
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No sales data to generate best-selling report.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Units Sold', 'data' => $chart_data_sales, 'backgroundColor' => 'rgba(42, 157, 143, 0.7)', 'borderColor' => 'rgba(42, 157, 143, 1)', 'borderWidth' => 1],
                            ['label' => 'Revenue', 'data' => $chart_data_revenue, 'backgroundColor' => 'rgba(244, 162, 97, 0.7)', 'borderColor' => 'rgba(244, 162, 97, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Top 10 Best-Selling Products'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'best-selling-authors':
                    $stmt = $conn->prepare("SELECT b.author, SUM(si.quantity) AS total_quantity_sold, SUM((si.price_per_unit * si.quantity) - si.discount_per_unit) AS total_revenue 
                                            FROM sale_items si 
                                            JOIN sales s ON si.sale_id = s.id
                                            JOIN books b ON si.book_id = b.id 
                                            WHERE s.tenant_id = ? AND b.product_type = 'book' AND b.author IS NOT NULL AND b.author != ''
                                            GROUP BY b.author 
                                            ORDER BY total_quantity_sold DESC 
                                            LIMIT 10");
                    $stmt->bind_param('i', TENANT_ID);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $best_selling_authors = [];
                    while ($row = $result->fetch_assoc()) {
                        $best_selling_authors[] = $row;
                    }
                    $stmt->close();
                    $html = '';
                    $chart_labels = [];
                    $chart_data_sales = [];
                    $chart_data_revenue = [];
                    $raw_data_array = [];
                    foreach ($best_selling_authors as $index => $author) {
                        $html .= '<tr><td>' . html($index + 1) . '</td><td>' . html($author['author']) . '</td><td>' . html($author['total_quantity_sold']) . ' units sold, ' . format_currency($author['total_revenue']) . ' revenue</td></tr>';
                        $chart_labels[] = html($author['author']);
                        $chart_data_sales[] = $author['total_quantity_sold'];
                        $chart_data_revenue[] = $author['total_revenue'];
                        $raw_data_array[] = ['Rank' => $index + 1, 'Author' => $author['author'], 'Units Sold' => $author['total_quantity_sold'], 'Revenue' => format_currency($author['total_revenue'])];
                    }
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No sales data to generate best-selling author report.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Units Sold', 'data' => $chart_data_sales, 'backgroundColor' => 'rgba(42, 157, 143, 0.7)', 'borderColor' => 'rgba(42, 157, 143, 1)', 'borderWidth' => 1],
                            ['label' => 'Revenue', 'data' => $chart_data_revenue, 'backgroundColor' => 'rgba(244, 162, 97, 0.7)', 'borderColor' => 'rgba(244, 162, 97, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Top 10 Best-Selling Authors'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'low-stock':
                    $stmt = $conn->prepare('SELECT name, author, stock, isbn, product_type FROM books WHERE tenant_id = ? AND stock < 5 ORDER BY stock ASC');
                    $stmt->bind_param('i', TENANT_ID);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $low_stock_books = [];
                    while ($row = $result->fetch_assoc()) {
                        $low_stock_books[] = $row;
                    }
                    $stmt->close();
                    $html = '';
                    $chart_labels = [];
                    $chart_data_stock = [];
                    $raw_data_array = [];
                    foreach ($low_stock_books as $index => $book) {
                        $html .= "<tr class='low-stock'><td>" . html($index + 1) . '</td><td>' . html($book['name']) . ($book['author'] ? ' (' . html($book['author']) . ')' : '') . '</td><td>' . html($book['stock']) . ' in stock</td></tr>';
                        $chart_labels[] = html($book['name']);
                        $chart_data_stock[] = $book['stock'];
                        $raw_data_array[] = ['Rank' => $index + 1, 'Name' => $book['name'], 'Author' => $book['author'], 'Stock' => $book['stock'], 'ISBN' => $book['isbn'], 'Product Type' => $book['product_type']];
                    }
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No products currently low in stock.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Stock Quantity', 'data' => $chart_data_stock, 'backgroundColor' => 'rgba(231, 111, 81, 0.7)', 'borderColor' => 'rgba(231, 111, 81, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Products Low in Stock (< 5 units)'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'expenses-summary':
                    $selected_month_str = $_GET['month'] ?? date('Y-m');
                    $start_of_month = $selected_month_str . '-01 00:00:00';
                    $end_of_month = date('Y-m-t', strtotime($selected_month_str)) . ' 23:59:59';
                    $stmt = $conn->prepare('SELECT category, SUM(amount) AS total_amount FROM expenses WHERE tenant_id = ? AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total_amount DESC');
                    $stmt->bind_param('iss', TENANT_ID, $start_of_month, $end_of_month);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $expenses_by_category = [];
                    $total_expenses = 0;
                    while ($row = $result->fetch_assoc()) {
                        $expenses_by_category[] = $row;
                        $total_expenses += $row['total_amount'];
                    }
                    $stmt->close();
                    $html = '';
                    $chart_labels = [];
                    $chart_data_amounts = [];
                    $raw_data_array = [['Metric' => 'Month', 'Value' => $selected_month_str], ['Metric' => 'Total Monthly Expenses', 'Value' => format_currency($total_expenses)]];
                    foreach ($expenses_by_category as $expense) {
                        $html .= '<tr><td>-</td><td>' . html($expense['category']) . '</td><td>' . format_currency($expense['total_amount']) . '</td></tr>';
                        $chart_labels[] = html($expense['category']);
                        $chart_data_amounts[] = $expense['total_amount'];
                        $raw_data_array[] = ['Metric' => $expense['category'], 'Value' => format_currency($expense['total_amount'])];
                    }
                    $html .= '<tr><td><strong>Total</strong></td><td></td><td><strong>' . format_currency($total_expenses) . '</strong></td></tr>';
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No expenses recorded for ' . html($selected_month_str) . '.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Amount', 'data' => $chart_data_amounts, 'backgroundColor' => [], 'borderColor' => [], 'borderWidth' => 1]
                        ],
                        'type' => 'pie',
                        'title' => 'Expenses Summary for ' . html($selected_month_str)
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
            }
            echo json_encode(['success' => true, 'report_data' => $report_data]);
            exit();
        case 'get_users_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('users')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT u.id, u.username, u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            exit();
        case 'get_roles_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('users')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT * FROM roles WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $stmt = $conn->prepare('SELECT rp.*, r.tenant_id FROM role_page_permissions rp JOIN roles r ON rp.role_id = r.id WHERE r.tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $perms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($roles as &$r) {
                $r['permissions'] = array_column(array_filter($perms, fn($p) => $p['role_id'] == $r['id']), 'page_key');
            }
            echo json_encode(['success' => true, 'roles' => $roles]);
            exit();
        case 'get_dashboard_stats_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('dashboard')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $today = date('Y-m-d');
            $start_of_day = $today . ' 00:00:00';
            $end_of_day = $today . ' 23:59:59';
            $stmt = $conn->prepare('SELECT COUNT(*) as cnt, SUM(total) as rev FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?');
            $stmt->bind_param('iss', TENANT_ID, $start_of_day, $end_of_day);
            $stmt->execute();
            $today_stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $month_start = date('Y-m-01 00:00:00');
            $stmt = $conn->prepare('SELECT SUM(total) as rev FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?');
            $stmt->bind_param('iss', TENANT_ID, $month_start, $end_of_day);
            $stmt->execute();
            $month_stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM online_orders WHERE tenant_id = ? AND status = "pending"');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $pending_orders = $stmt->get_result()->fetch_row()[0];
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM books WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $total_products = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND is_active = 1');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $total_customers = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM suppliers WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $total_suppliers = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM books WHERE tenant_id = ? AND stock < 5');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $low_stock_cnt = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT SUM(total) FROM sales WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $lifetime_rev = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT SUM(amount) FROM expenses WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $total_expenses = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM promotions WHERE tenant_id = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $active_promos = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $stmt = $conn->prepare('SELECT SUM(stock * COALESCE(purchase_price, price)) FROM books WHERE tenant_id = ?');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $stock_value = $stmt->get_result()->fetch_row()[0] ?? 0;
            $stmt->close();
            $weekly_labels = [];
            $weekly_data = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $weekly_labels[] = date('D, M d', strtotime($d));
                $ds = $d . ' 00:00:00';
                $de = $d . ' 23:59:59';
                $stmt = $conn->prepare('SELECT SUM(total) as rev FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?');
                $stmt->bind_param('iss', TENANT_ID, $ds, $de);
                $stmt->execute();
                $rev = $stmt->get_result()->fetch_assoc()['rev'] ?? 0;
                $weekly_data[] = (float) $rev;
                $stmt->close();
            }
            $stmt = $conn->prepare('SELECT b.name, SUM(si.quantity) as qty FROM sale_items si JOIN sales s ON si.sale_id = s.id JOIN books b ON si.book_id = b.id WHERE s.tenant_id = ? AND s.sale_date BETWEEN ? AND ? GROUP BY b.id ORDER BY qty DESC LIMIT 5');
            $stmt->bind_param('iss', TENANT_ID, $month_start, $end_of_day);
            $stmt->execute();
            $top_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $monthly_labels = [];
            $monthly_sales = [];
            $monthly_expenses = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-$i months"));
                $monthly_labels[] = date('M Y', strtotime($m . '-01'));
                $m_start = $m . '-01 00:00:00';
                $m_end = date('Y-m-t', strtotime($m_start)) . ' 23:59:59';
                $s_stmt = $conn->prepare('SELECT SUM(total) FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?');
                $s_stmt->bind_param('iss', TENANT_ID, $m_start, $m_end);
                $s_stmt->execute();
                $monthly_sales[] = (float) ($s_stmt->get_result()->fetch_row()[0] ?? 0);
                $s_stmt->close();
                $e_stmt = $conn->prepare('SELECT SUM(amount) FROM expenses WHERE tenant_id = ? AND expense_date BETWEEN ? AND ?');
                $e_stmt->bind_param('iss', TENANT_ID, $m_start, $m_end);
                $e_stmt->execute();
                $monthly_expenses[] = (float) ($e_stmt->get_result()->fetch_row()[0] ?? 0);
                $e_stmt->close();
            }
            $stmt = $conn->prepare('SELECT status, COUNT(*) as cnt FROM online_orders WHERE tenant_id = ? GROUP BY status');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $order_stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode([
                'success' => true,
                'today_rev' => (float) $today_stats['rev'],
                'today_cnt' => (int) $today_stats['cnt'],
                'month_rev' => (float) $month_stats['rev'],
                'pending_orders' => (int) $pending_orders,
                'total_products' => (int) $total_products,
                'total_customers' => (int) $total_customers,
                'total_suppliers' => (int) $total_suppliers,
                'low_stock_cnt' => (int) $low_stock_cnt,
                'lifetime_rev' => (float) $lifetime_rev,
                'total_expenses' => (float) $total_expenses,
                'active_promos' => (int) $active_promos,
                'stock_value' => (float) $stock_value,
                'chart_weekly' => ['labels' => $weekly_labels, 'data' => $weekly_data],
                'chart_top' => $top_products,
                'chart_monthly' => ['labels' => $monthly_labels, 'sales' => $monthly_sales, 'expenses' => $monthly_expenses],
                'chart_orders' => $order_stats
            ]);
            exit();
        case 'get_live_sales_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('live-sales')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $today = date('Y-m-d');
            $start_of_day = $today . ' 00:00:00';
            $end_of_day = $today . ' 23:59:59';
            $stmt = $conn->prepare('SELECT COUNT(*) as total_orders, SUM(total) as total_revenue, SUM(discount) as total_discount FROM sales WHERE tenant_id = ? AND sale_date BETWEEN ? AND ?');
            $stmt->bind_param('iss', TENANT_ID, $start_of_day, $end_of_day);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $conn->prepare("SELECT s.id, s.sale_date, c.name AS customer_name, s.total, s.discount, s.promotion_code,
                                    u.username AS sold_by_user, psl.link_name AS public_link_name,
                                    GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                                    FROM sales s 
                                    LEFT JOIN customers c ON s.customer_id = c.id
                                    LEFT JOIN users u ON s.user_id = u.id
                                    LEFT JOIN public_sale_links psl ON psl.id = SUBSTRING_INDEX(SUBSTRING_INDEX(s.promotion_code, '-', 3), '-', -1) AND s.promotion_code LIKE 'PUBLIC-LINK-%' AND psl.tenant_id = s.tenant_id
                                    JOIN sale_items si ON s.id = si.sale_id 
                                    JOIN books b ON si.book_id = b.id
                                    WHERE s.tenant_id = ? AND s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.sale_date DESC LIMIT 50");
            $stmt->bind_param('iss', TENANT_ID, $start_of_day, $end_of_day);
            $stmt->execute();
            $recent_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode([
                'success' => true,
                'summary' => [
                    'revenue' => (float) $summary['total_revenue'],
                    'orders' => (int) $summary['total_orders'],
                    'discount' => (float) $summary['total_discount']
                ],
                'recent_sales' => $recent_sales
            ]);
            exit();
        case 'global_search_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('dashboard')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            $query = $_GET['query'] ?? '';
            $results = [];
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'results' => []]);
                exit();
            }
            $search_term = '%' . $query . '%';
            $stmt = $conn->prepare('SELECT id, name FROM books WHERE tenant_id = ? AND name LIKE ? LIMIT 5');
            $stmt->bind_param('is', TENANT_ID, $search_term);
            $stmt->execute();
            $book_res = $stmt->get_result();
            while ($row = $book_res->fetch_assoc()) {
                $results[] = ['type' => 'Product', 'id' => $row['id'], 'name' => $row['name'], 'link' => get_redirect_url('books')];
            }
            $stmt->close();
            $stmt = $conn->prepare('SELECT id, name FROM customers WHERE tenant_id = ? AND name LIKE ? LIMIT 5');
            $stmt->bind_param('is', TENANT_ID, $search_term);
            $stmt->execute();
            $customer_res = $stmt->get_result();
            while ($row = $customer_res->fetch_assoc()) {
                $results[] = ['type' => 'Customer', 'id' => $row['id'], 'name' => $row['name'], 'link' => get_redirect_url('customers')];
            }
            $stmt->close();
            $stmt = $conn->prepare('SELECT id FROM sales WHERE tenant_id = ? AND id LIKE ? LIMIT 5');
            $stmt->bind_param('is', TENANT_ID, $search_term);
            $stmt->execute();
            $sale_res = $stmt->get_result();
            while ($row = $sale_res->fetch_assoc()) {
                $results[] = ['type' => 'Sale', 'id' => $row['id'], 'name' => 'Sale #' . $row['id'], 'link' => get_redirect_url('sales-history')];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'results' => $results]);
            exit();
        case 'get_online_order_status':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!isCustomerLoggedIn()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $order_id = $_GET['order_id'] ?? null;
            if (!$order_id) {
                echo json_encode(['success' => false, 'message' => 'Order ID is required.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT status FROM online_orders WHERE tenant_id = ? AND id = ? AND customer_id = ?');
            $stmt->bind_param('iii', TENANT_ID, $order_id, $_SESSION['customer_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $order = $result->fetch_assoc();
            $stmt->close();
            if ($order) {
                echo json_encode(['success' => true, 'status' => $order['status']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found or you do not have permission.']);
            }
            exit();
        case 'get_book_by_barcode_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            $barcode = trim($_GET['barcode'] ?? '');
            $token = trim($_GET['token'] ?? '');
            if ($barcode === '') {
                echo json_encode(['success' => false, 'message' => 'Barcode is required.']);
                exit();
            }
            if (!isLoggedIn() && !has_public_sale_access($token)) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $cleanBarcode = preg_replace('/\s+/', '', $barcode);
            $stmt = $conn->prepare("SELECT *, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price FROM books WHERE tenant_id = ? AND (barcode = ? OR isbn = ? OR REPLACE(IFNULL(isbn,''), '-', '') = ?) LIMIT 1");
            $stmt->bind_param('isss', TENANT_ID, $cleanBarcode, $cleanBarcode, $cleanBarcode);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$book) {
                echo json_encode(['success' => false, 'message' => 'No product found for this barcode.']);
                exit();
            }
            if ($token && has_public_sale_access($token)) {
                $link = current_public_sale_link($conn, $token);
                $priceMode = $link['price_mode'] ?? 'retail';
                $book['link_price_mode'] = $priceMode;
                $book['link_price'] = $priceMode === 'wholesale' ? ($book['wholesale_price'] ?: $book['retail_price']) : ($book['retail_price'] ?: $book['price']);
            }
            echo json_encode(['success' => true, 'book' => $book]);
            exit();
        case 'get_sidebar_products_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            $token = trim($_GET['token'] ?? '');
            if (!isLoggedIn() && !has_public_sale_access($token)) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $search = trim($_GET['search'] ?? '');
            $category = trim($_GET['category'] ?? 'all');
            $productType = trim($_GET['product_type'] ?? 'all');
            $where = ['tenant_id = ?', 'stock > 0'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = '(name LIKE ? OR author LIKE ? OR isbn LIKE ? OR barcode LIKE ? OR category LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like);
                $types .= 'sssss';
            }
            if ($category !== '' && $category !== 'all') {
                $where[] = 'category = ?';
                $params[] = $category;
                $types .= 's';
            }
            if ($productType !== '' && $productType !== 'all') {
                $where[] = 'product_type = ?';
                $params[] = $productType;
                $types .= 's';
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $stmt = $conn->prepare("SELECT id, name, author, category, product_type, barcode, stock, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price, cover_image, (SELECT IFNULL(SUM(quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.book_id = books.id AND s.tenant_id = books.tenant_id) AS total_sold FROM books {$whereSql} ORDER BY total_sold DESC, name ASC LIMIT 150");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            $priceMode = 'retail';
            if ($token && has_public_sale_access($token)) {
                $link = current_public_sale_link($conn, $token);
                if ($link) {
                    $priceMode = $link['price_mode'];
                }
            }
            while ($row = $result->fetch_assoc()) {
                $row['display_price'] = $priceMode === 'wholesale' ? ($row['wholesale_price'] ?: $row['retail_price']) : ($row['retail_price'] ?: $row['price']);
                $row['cover_image'] = $row['cover_image'] ? ROOT_URL . '/' . TENANT_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['cover_image']) : null;
                $rows[] = $row;
            }
            $stmt->close();
            $stmt = $conn->prepare("SELECT DISTINCT category FROM books WHERE tenant_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category ASC");
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $categoryRows = $stmt->get_result();
            $categories = [];
            if ($categoryRows) {
                while ($cat = $categoryRows->fetch_assoc()) {
                    $categories[] = $cat['category'];
                }
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $rows, 'categories' => $categories, 'price_mode' => $priceMode]);
            exit();
        case 'ajax_quick_sell':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!hasPlanAccess('cart')) {
                echo json_encode(['success' => false, 'message' => 'Your plan does not allow access to this feature.']);
                exit();
            }
            if ($app_in_read_only_mode) {
                echo json_encode(['success' => false, 'message' => 'Tenant account is in read-only mode. Renew subscription to regain full access.']);
                exit();
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $book_id = $data['book_id'] ?? null;
            $qty = (int) ($data['quantity'] ?? 1);
            if (empty($book_id) || $qty < 1) {
                echo json_encode(['success' => false, 'message' => 'Invalid product or quantity.']);
                exit();
            }
            $conn->begin_transaction();
            try {
                $stmt_book = $conn->prepare('SELECT name, price, stock FROM books WHERE tenant_id = ? AND id = ?');
                $stmt_book->bind_param('ii', TENANT_ID, $book_id);
                $stmt_book->execute();
                $book_data = $stmt_book->get_result()->fetch_assoc();
                $stmt_book->close();
                if (!$book_data)
                    throw new Exception('Product not found.');
                if ($book_data['stock'] < $qty)
                    throw new Exception('Not enough stock. Available: ' . $book_data['stock']);
                $user_id = $_SESSION['user_id'];
                $subtotal = $book_data['price'] * $qty;
                $stmt_sale = $conn->prepare('INSERT INTO sales (tenant_id, customer_id, user_id, subtotal, discount, total) VALUES (?, NULL, ?, ?, 0, ?)');
                $stmt_sale->bind_param('iidd', TENANT_ID, $user_id, $subtotal, $subtotal);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, 0)');
                $stmt_sale_item->bind_param('iiid', $sale_id, $book_id, $qty, $book_data['price']);
                $stmt_sale_item->execute();
                $stmt_sale_item->close();
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE tenant_id = ? AND id = ?');
                $stmt_update_stock->bind_param('iii', $qty, TENANT_ID, $book_id);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();
                log_action($conn, 'Quick Sell', "Quick sale of {$qty}x {$book_data['name']} (ID: $book_id). Sale ID: $sale_id.", 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Quick sale completed for ' . $qty . 'x ' . html($book_data['name']), 'sale_id' => $sale_id]);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Quick Sell Failed', 'Quick sale failed: ' . $e->getMessage(), 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                echo json_encode(['success' => false, 'message' => 'Quick sale failed: ' . $e->getMessage()]);
            }
            exit();
        case 'get_tenant_pwa_manifest':
            if (!TENANT_ID) {
                header('HTTP/1.1 404 Not Found');
                exit();
            }
            header('Content-Type: application/manifest+json');
            $pwa_settings = [];
            $stmt_pwa = $conn->prepare('SELECT app_name, short_name, theme_color, background_color, icon_path FROM pwa_settings WHERE tenant_id = ?');
            $stmt_pwa->bind_param('i', TENANT_ID);
            $stmt_pwa->execute();
            $res_pwa = $stmt_pwa->get_result();
            if ($res_pwa && $row_pwa = $res_pwa->fetch_assoc()) {
                $pwa_settings = $row_pwa;
            } else {
                $pwa_settings['app_name'] = TENANT_NAME . ' App';
                $pwa_settings['short_name'] = TENANT_NAME;
                $pwa_settings['theme_color'] = '#2a9d8f';
                $pwa_settings['background_color'] = '#ffffff';
                $pwa_settings['icon_path'] = null;
            }
            $stmt_pwa->close();
            $manifest = [
                'name' => $pwa_settings['app_name'],
                'short_name' => $pwa_settings['short_name'],
                'description' => 'A PWA for ' . TENANT_NAME,
                'start_url' => ROOT_URL . '/' . TENANT_SLUG . '/login',
                'display' => 'standalone',
                'background_color' => $pwa_settings['background_color'],
                'theme_color' => $pwa_settings['theme_color'],
                'icons' => [
                    [
                        'src' => ROOT_URL . '/' . TENANT_SLUG . '/pwa_icon_192.png',
                        'sizes' => '192x192',
                        'type' => 'image/png',
                    ],
                    [
                        'src' => ROOT_URL . '/' . TENANT_SLUG . '/pwa_icon_512.png',
                        'sizes' => '512x512',
                        'type' => 'image/png',
                    ],
                ],
            ];
            echo json_encode($manifest);
            exit();
        case 'get_tenant_pwa_logo_icon':
            if (!TENANT_ID) {
                header('HTTP/1.1 404 Not Found');
                exit();
            }
            $size = (int) ($_GET['size'] ?? 192);
            if (!in_array($size, [192, 512])) $size = 192;
            $pwa_settings = [];
            $stmt_pwa = $conn->prepare('SELECT icon_path FROM pwa_settings WHERE tenant_id = ?');
            $stmt_pwa->bind_param('i', TENANT_ID);
            $stmt_pwa->execute();
            $res_pwa = $stmt_pwa->get_result();
            if ($res_pwa && $row_pwa = $res_pwa->fetch_assoc()) {
                $pwa_settings = $row_pwa;
            }
            $stmt_pwa->close();
            $logo_path = $pwa_settings['icon_path'];
            $final_image = null;
            if ($logo_path && file_exists($logo_path)) {
                $image_info = getimagesize($logo_path);
                if ($image_info) {
                    $original_image = null;
                    if ($image_info['mime'] === 'image/jpeg' || $image_info['mime'] === 'image/jpg') {
                        $original_image = imagecreatefromjpeg($logo_path);
                    } elseif ($image_info['mime'] === 'image/png') {
                        $original_image = imagecreatefrompng($logo_path);
                    } elseif ($image_info['mime'] === 'image/gif') {
                        $original_image = imagecreatefromgif($logo_path);
                    } elseif ($image_info['mime'] === 'image/webp') {
                        $original_image = imagecreatefromwebp($logo_path);
                    }
                    if ($original_image) {
                        $final_image = imagecreatetruecolor($size, $size);
                        imagesavealpha($final_image, true);
                        $transparent = imagecolorallocatealpha($final_image, 0, 0, 0, 127);
                        imagefill($final_image, 0, 0, $transparent);
                        imagecopyresampled($final_image, $original_image, 0, 0, 0, 0, $size, $size, imagesx($original_image), imagesy($original_image));
                        imagedestroy($original_image);
                    }
                }
            }
            if (!$final_image) {
                $final_image = imagecreatetruecolor($size, $size);
                $bg = imagecolorallocate($final_image, 42, 157, 143);
                $text_color = imagecolorallocate($final_image, 255, 255, 255);
                imagefill($final_image, 0, 0, $bg);
                $initials = strtoupper(substr(TENANT_NAME, 0, 1) . substr(TENANT_NAME, strpos(TENANT_NAME, ' ') + 1, 1));
                if (strlen($initials) > 2) $initials = strtoupper(substr(TENANT_NAME, 0, 2));
                if (strlen($initials) === 0) $initials = 'BS';
                $font_size = ($size / 2) / (strlen($initials) > 1 ? 1.2 : 1);
                $font_path = __DIR__ . '/arial.ttf';
                if (!file_exists($font_path)) $font_path = null;
                $bbox = $font_path ? imagettfbbox($font_size, 0, $font_path, $initials) : imagefontwidth(5) * strlen($initials);
                $x = $font_path ? ($size - ($bbox[2] - $bbox[0])) / 2 : ($size - $bbox) / 2;
                $y = $font_path ? ($size - ($bbox[1] - $bbox[7])) / 2 : ($size - imagefontheight(5)) / 2;
                if ($font_path) {
                    imagettftext($final_image, $font_size, 0, (int)$x, (int)($y + $font_size * 0.75), $text_color, $font_path, $initials);
                } else {
                    imagestring($final_image, 5, (int)$x, (int)$y, $initials, $text_color);
                }
            }
            header('Content-Type: image/png');
            imagepng($final_image);
            imagedestroy($final_image);
            exit();
        case 'get_superadmin_settings_json':
            if (!IS_SUPERADMIN_MODE) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT setting_key, setting_value FROM superadmin_settings');
            $stmt->execute();
            $result = $stmt->get_result();
            $settings_data = [];
            while ($row = $result->fetch_assoc()) {
                $settings_data[$row['setting_key']] = $row['setting_value'];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'settings' => $settings_data]);
            exit();
        case 'get_tenants_json':
            if (!IS_SUPERADMIN_MODE) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.email LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            if ($status !== 'all') {
                $where_clauses[] = 't.status = ?';
                $params[] = $status;
                $types .= 's';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenants t $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT t.*, sp.name AS plan_name, sp.enable_file_uploads AS plan_enable_uploads, su.username AS superadmin_creator 
                    FROM tenants t 
                    LEFT JOIN subscription_plans sp ON t.plan_id = sp.id 
                    LEFT JOIN superadmin_users su ON t.created_by_superadmin = su.id
                    $where_sql ORDER BY t.registration_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $tenants = [];
            while ($row = $result->fetch_assoc()) {
                $row['logo_url'] = $row['logo_path'] ? ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['logo_path']) : null;
                $tenants[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'tenants' => $tenants, 'total_items' => $total_items]);
            exit();
        case 'get_plans_json':
            if (!IS_SUPERADMIN_MODE) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $plan_id = $_GET['plan_id'] ?? null;
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($plan_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $plan_id;
                $types .= 'i';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT * FROM subscription_plans $where_sql ORDER BY price_per_month ASC");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($plans as &$plan) {
                $stmt = $conn->prepare('SELECT page_key FROM plan_permissions WHERE plan_id = ?');
                $stmt->bind_param('i', $plan['id']);
                $stmt->execute();
                $plan['permissions'] = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'page_key');
                $stmt->close();
            }
            echo json_encode(['success' => true, 'plans' => $plans]);
            exit();
        case 'get_payments_json':
            if (!IS_SUPERADMIN_MODE) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $payment_id = $_GET['payment_id'] ?? null;
            $tenant_filter_id = $_GET['tenant_id'] ?? null;
            $status_filter = $_GET['status'] ?? 'all';
            $page = $_GET['page_num'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($payment_id) {
                $where_clauses[] = 'sp.id = ?';
                $params[] = $payment_id;
                $types .= 'i';
            }
            if ($tenant_filter_id) {
                $where_clauses[] = 'sp.tenant_id = ?';
                $params[] = $tenant_filter_id;
                $types .= 'i';
            }
            if ($status_filter !== 'all') {
                $where_clauses[] = 'sp.status = ?';
                $params[] = $status_filter;
                $types .= 's';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM subscription_payments sp JOIN tenants t ON sp.tenant_id = t.id JOIN subscription_plans spl ON sp.plan_id = spl.id $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT sp.*, t.name AS tenant_name, t.slug AS tenant_slug, spl.name AS plan_name, su.username AS superadmin_processor 
                    FROM subscription_payments sp 
                    JOIN tenants t ON sp.tenant_id = t.id 
                    JOIN subscription_plans spl ON sp.plan_id = spl.id
                    LEFT JOIN superadmin_users su ON sp.processed_by_superadmin = su.id
                    $where_sql ORDER BY sp.payment_date DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $payments = [];
            while ($row = $result->fetch_assoc()) {
                $row['payment_proof_url'] = $row['payment_proof_path'] ? ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['payment_proof_path']) : null;
                $payments[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'payments' => $payments, 'total_items' => $total_items]);
            exit();
        case 'get_superadmin_news_json':
            if (!IS_SUPERADMIN_MODE && !TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $news_id = $_GET['news_id'] ?? null;
            $visibility_filter = IS_SUPERADMIN_MODE ? ($_GET['visibility'] ?? 'all') : 'all_users';
            $is_active_filter = IS_SUPERADMIN_MODE ? ($_GET['is_active'] ?? 'all') : '1';
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($news_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $news_id;
                $types .= 'i';
            }
            if ($visibility_filter !== 'all') {
                $where_clauses[] = 'visibility = ?';
                $params[] = $visibility_filter;
                $types .= 's';
            }
            if ($is_active_filter !== 'all') {
                $where_clauses[] = 'is_active = ?';
                $params[] = (int)$is_active_filter;
                $types .= 'i';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            if (!IS_SUPERADMIN_MODE) {
                $where_sql .= ($where_sql ? ' AND ' : 'WHERE ') . "(visibility = 'all_users' OR (visibility = 'tenant_admins_only' AND ?)) AND is_active = 1";
                $params[] = (isTenantAdmin() ? 1 : 0);
                $types .= 'i';
            }
            $stmt = $conn->prepare("SELECT * FROM superadmin_news $where_sql ORDER BY created_at DESC");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $news_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($news_items as &$news) {
                if ($news['media_path'] && ($news['media_type'] === 'image' || $news['media_type'] === 'video_upload')) {
                    $news['media_url'] = ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $news['media_path']);
                }
            }
            echo json_encode(['success' => true, 'news' => $news_items]);
            exit();
        case 'get_tenant_news_json':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            $news_id = $_GET['news_id'] ?? null;
            $is_active_filter = $_GET['is_active'] ?? 'all';
            $where_clauses = ['tenant_id = ?'];
            $params = [TENANT_ID];
            $types = 'i';
            if ($news_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $news_id;
                $types .= 'i';
            }
            if ($is_active_filter !== 'all') {
                $where_clauses[] = 'is_active = ?';
                $params[] = (int)$is_active_filter;
                $types .= 'i';
            }
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            $stmt = $conn->prepare("SELECT * FROM public_news $where_sql ORDER BY created_at DESC");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $news_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['success' => true, 'news' => $news_items]);
            exit();
        case 'get_audit_logs_json':
            $log_type = $_GET['log_type'] ?? 'superadmin';
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            $table_name = 'audit_logs';
            if ($log_type === 'superadmin' && !isSuperAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized to view superadmin logs.']);
                exit();
            } elseif ($log_type === 'tenant' && !TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Not a tenant context.']);
                exit();
            } elseif ($log_type === 'tenant' && TENANT_ID) {
                $where_clauses[] = 'al.tenant_id = ?';
                $params[] = TENANT_ID;
                $types .= 'i';
            } elseif ($log_type === 'superadmin' && IS_SUPERADMIN_MODE) {
                $where_clauses[] = 'al.tenant_id IS NULL';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(al.action LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $join_sql = '';
            if (IS_SUPERADMIN_MODE) {
                $join_sql = 'LEFT JOIN tenants t ON al.tenant_id = t.id LEFT JOIN superadmin_users su ON al.user_id = su.id';
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM $table_name al $join_sql $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $select_cols = 'al.id, al.timestamp, al.action, al.description, al.ip_address, al.user_type, al.user_id, al.tenant_id';
            if (IS_SUPERADMIN_MODE) {
                $select_cols .= ', t.name AS tenant_name, su.username AS superadmin_username';
            }
            $sql = "SELECT $select_cols FROM $table_name al $join_sql $where_sql ORDER BY al.timestamp DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($sql);
            if ($types) {
                $all_params = array_merge($params, [$limit, $offset]);
                $stmt->bind_param($types . 'ii', ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($logs as &$log) {
                if (IS_SUPERADMIN_MODE && $log['user_type'] === 'superadmin' && $log['user_id']) {
                    $log['username_display'] = $log['superadmin_username'] ?? 'Unknown Superadmin';
                } elseif ($log['tenant_id'] && ($log['user_type'] === 'tenant_admin' || $log['user_type'] === 'staff') && $log['user_id']) {
                    $stmt_user = $conn->prepare('SELECT username FROM users WHERE id = ?');
                    $stmt_user->bind_param('i', $log['user_id']);
                    $stmt_user->execute();
                    $user_res = $stmt_user->get_result()->fetch_assoc();
                    $log['username_display'] = $user_res['username'] ?? 'Unknown User';
                    $stmt_user->close();
                } elseif ($log['tenant_id'] && $log['user_type'] === 'customer' && $log['user_id']) {
                    $stmt_cust = $conn->prepare('SELECT name FROM customers WHERE id = ?');
                    $stmt_cust->bind_param('i', $log['user_id']);
                    $stmt_cust->execute();
                    $cust_res = $stmt_cust->get_result()->fetch_assoc();
                    $log['username_display'] = $cust_res['name'] ?? 'Unknown Customer';
                    $stmt_cust->close();
                } else {
                    $log['username_display'] = 'N/A';
                }
            }
            echo json_encode(['success' => true, 'logs' => $logs, 'total_items' => $total_items]);
            exit();
        case 'get_tenant_current_subscription':
            if (!TENANT_ID) {
                echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
                exit();
            }
            if (!isTenantAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $current_date = new DateTime();
            $subscription_end_date_str = TENANT_SUB_END_DATE;
            $subscription_end_date = $subscription_end_date_str ? new DateTime($subscription_end_date_str) : null;
            $days_remaining = null;
            if ($subscription_end_date) {
                $interval = $current_date->diff($subscription_end_date);
                $days_remaining = (int)$interval->format('%r%a');
            }
            $plan_info = null;
            if (TENANT_PLAN_ID) {
                $stmt = $conn->prepare('SELECT name, price_per_month, enable_file_uploads FROM subscription_plans WHERE id = ?');
                $stmt->bind_param('i', TENANT_PLAN_ID);
                $stmt->execute();
                $plan_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            $payments = [];
            $stmt = $conn->prepare('SELECT sp.*, spl.name AS plan_name, spl.price_per_month 
                                    FROM subscription_payments sp 
                                    JOIN subscription_plans spl ON sp.plan_id = spl.id 
                                    WHERE sp.tenant_id = ? ORDER BY sp.payment_date DESC LIMIT 10');
            $stmt->bind_param('i', TENANT_ID);
            $stmt->execute();
            $payments_result = $stmt->get_result();
            while ($row = $payments_result->fetch_assoc()) {
                $row['payment_proof_url'] = $row['payment_proof_path'] ? ROOT_URL . '/' . TENANT_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $row['payment_proof_path']) : null;
                $payments[] = $row;
            }
            $stmt->close();
            echo json_encode([
                'success' => true,
                'tenant_status' => TENANT_STATUS,
                'subscription_end_date' => $subscription_end_date_str,
                'days_remaining' => $days_remaining,
                'plan_info' => $plan_info,
                'payments' => $payments,
                'app_status_info' => $tenant_status_info
            ]);
            exit();
        case 'get_superadmin_all_plans':
            if (!IS_SUPERADMIN_MODE && !isTenantAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT id, name, price_per_month, enable_file_uploads FROM subscription_plans WHERE is_active = 1 ORDER BY price_per_month ASC');
            $stmt->execute();
            $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['success' => true, 'plans' => $plans]);
            exit();
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            exit();
    }
}

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $message_type = 'error';
    $message = 'An unknown error occurred.';

    if ($action === 'register_tenant') {
    } elseif (IS_PUBLIC_MAIN_SITE && !in_array($action, ['login', 'register_tenant', 'contact_submit', 'customer_login', 'customer_register'])) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access on public site.'];
        redirect('home');
    } elseif (IS_SUPERADMIN_MODE && !isSuperAdmin() && !in_array($action, ['login', 'contact_submit'])) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access for superadmin actions.'];
        redirect('login');
    } elseif (TENANT_ID && !isLoggedIn() && !in_array($action, ['login', 'customer_login', 'customer_register', 'public_sale_login', 'submit_public_sale', 'contact_submit'])) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access for tenant actions.'];
        redirect('login');
    }
    if ($app_in_read_only_mode && !in_array($action, ['login', 'customer_login', 'customer_register', 'changepassword', 'submit_subscription_payment'])) {
        if (!IS_SUPERADMIN_MODE) {
            $_SESSION['toast'] = ['type' => 'error', 'message' => 'Tenant account is in read-only mode. Renew subscription to regain full access.'];
            redirect(CURRENT_PAGE, $_GET);
        }
    }

    switch ($action) {
        case 'login':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $captcha = $_POST['captcha'] ?? '';
            if (empty($_SESSION['captcha']) || strtolower($captcha) !== strtolower($_SESSION['captcha'])) {
                $message = 'Invalid CAPTCHA.';
                break;
            }
            if (IS_SUPERADMIN_MODE) {
                $stmt = $conn->prepare('SELECT id, username, password_hash FROM superadmin_users WHERE username = ?');
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
                if ($user && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['superadmin_id'] = $user['id'];
                    $_SESSION['superadmin_username'] = $user['username'];
                    $_SESSION['auth_started_at'] = time();
                    $_SESSION['auth_absolute_start'] = time();
                    log_action($conn, 'Superadmin Login', 'Superadmin logged in successfully.', 'superadmin', null, $user['id']);
                    $message_type = 'success';
                    $message = 'Welcome, ' . html($user['username']) . '!';
                    redirect('dashboard');
                } else {
                    log_action($conn, 'Superadmin Login Failed', 'Failed login attempt for username: ' . $username, 'superadmin');
                    $message = 'Invalid username or password.';
                }
            } else {
                if (!TENANT_ID) {
                    $message = 'Tenant not found.';
                    break;
                }
                $stmt = $conn->prepare('SELECT u.id, u.username, u.password_hash, u.role_id, r.name as role_name, r.is_tenant_admin FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ? AND u.username = ?');
                $stmt->bind_param('is', TENANT_ID, $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
                if ($user && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role_name'] = $user['role_name'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['is_tenant_admin'] = $user['is_tenant_admin'];
                    $perm_stmt = $conn->prepare('SELECT page_key FROM role_page_permissions WHERE role_id = ?');
                    $perm_stmt->bind_param('i', $user['role_id']);
                    $perm_stmt->execute();
                    $res = $perm_stmt->get_result();
                    $perms = [];
                    while ($p = $res->fetch_assoc()) {
                        $perms[] = $p['page_key'];
                    }
                    $perm_stmt->close();
                    $_SESSION['permissions'] = $perms;
                    $_SESSION['auth_started_at'] = time();
                    $_SESSION['auth_absolute_start'] = time();
                    log_action($conn, 'Tenant User Login', 'User logged in successfully.', 'tenant_admin', TENANT_ID, $user['id']);
                    $message_type = 'success';
                    $message = 'Welcome, ' . html($user['username']) . '!';
                    redirect('dashboard');
                } else {
                    log_action($conn, 'Tenant User Login Failed', 'Failed login attempt for username: ' . $username, 'tenant_admin', TENANT_ID);
                    $message = 'Invalid username or password.';
                }
            }
            break;
        case 'changepassword':
            $current = $_POST['currentpassword'] ?? '';
            $new = $_POST['newpassword'] ?? '';
            $confirm = $_POST['confirmpassword'] ?? '';
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            if (empty($current) || empty($new) || empty($confirm)) {
                $message = 'All fields are required.';
                $message_type = 'error';
            } elseif ($new !== $confirm) {
                $message = 'New passwords do not match.';
                $message_type = 'error';
            } elseif (strlen($new) < 8 || !preg_match($special_char_regex, $new)) {
                $message = 'Password must be at least 8 characters long and contain at least one special character.';
                $message_type = 'error';
            } else {
                if (isSuperAdmin()) {
                    $uid = $_SESSION['superadmin_id'];
                    $stmt = $conn->prepare('SELECT password_hash FROM superadmin_users WHERE id = ?');
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($res && password_verify($current, $res['password_hash'])) {
                        $hash = password_hash($new, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare('UPDATE superadmin_users SET password_hash = ? WHERE id = ?');
                        $stmt->bind_param('si', $hash, $uid);
                        if ($stmt->execute()) {
                            $message = 'Password updated successfully.';
                            $message_type = 'success';
                            log_action($conn, 'Change Password', 'Superadmin changed password.', 'superadmin', null, $uid);
                        } else {
                            $message = 'Failed to update password.';
                            $message_type = 'error';
                        }
                    } else {
                        $message = 'Incorrect current password.';
                        $message_type = 'error';
                    }
                } elseif (isCustomerLoggedIn()) {
                    $uid = $_SESSION['customer_id'];
                    $stmt = $conn->prepare('SELECT password_hash FROM customers WHERE tenant_id = ? AND id = ?');
                    $stmt->bind_param('ii', TENANT_ID, $uid);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($res && password_verify($current, $res['password_hash'])) {
                        $hash = password_hash($new, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare('UPDATE customers SET password_hash = ? WHERE tenant_id = ? AND id = ?');
                        $stmt->bind_param('sii', $hash, TENANT_ID, $uid);
                        if ($stmt->execute()) {
                            $message = 'Password updated successfully.';
                            $message_type = 'success';
                            log_action($conn, 'Change Password', 'Customer changed password.', 'customer', TENANT_ID, $uid);
                        } else {
                            $message = 'Failed to update password.';
                            $message_type = 'error';
                        }
                    } else {
                        $message = 'Incorrect current password.';
                        $message_type = 'error';
                    }
                } else {
                    $uid = $_SESSION['user_id'];
                    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE tenant_id = ? AND id = ?');
                    $stmt->bind_param('ii', TENANT_ID, $uid);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($res && password_verify($current, $res['password_hash'])) {
                        $hash = password_hash($new, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE tenant_id = ? AND id = ?');
                        $stmt->bind_param('sii', $hash, TENANT_ID, $uid);
                        if ($stmt->execute()) {
                            $message = 'Password updated successfully.';
                            $message_type = 'success';
                            log_action($conn, 'Change Password', 'Tenant user changed password.', 'tenant_admin_or_staff', TENANT_ID, $uid);
                        } else {
                            $message = 'Failed to update password.';
                            $message_type = 'error';
                        }
                    } else {
                        $message = 'Incorrect current password.';
                        $message_type = 'error';
                    }
                }
            }
            $_SESSION['toast'] = ['type' => $message_type, 'message' => $message];
            if (isCustomerLoggedIn()) {
                redirect('customer-dashboard');
            } elseif (isSuperAdmin()) {
                redirect('dashboard');
            } else {
                redirect('dashboard');
            }
            break;
        case 'customer_login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $captcha = $_POST['captcha'] ?? '';
            if (empty($_SESSION['captcha']) || strtolower($captcha) !== strtolower($_SESSION['captcha'])) {
                $message = 'Invalid CAPTCHA.';
                break;
            }
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM customers WHERE tenant_id = ? AND email = ? AND is_active = 1');
            $stmt->bind_param('is', TENANT_ID, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();
            if ($customer && password_verify($password, $customer['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_email'] = $customer['email'];
                $_SESSION['user_role'] = 'customer';
                $_SESSION['auth_started_at'] = time();
                $_SESSION['auth_absolute_start'] = time();
                log_action($conn, 'Customer Login', 'Customer logged in successfully.', 'customer', TENANT_ID, $customer['id']);
                $message_type = 'success';
                $message = 'Welcome, ' . html($customer['name']) . '!';
                redirect('customer-dashboard');
            } else {
                log_action($conn, 'Customer Login Failed', 'Failed login attempt for email: ' . $email, 'customer', TENANT_ID);
                $message = 'Invalid email or password.';
            }
            break;
        case 'customer_register':
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $address = $_POST['address'] ?? '';
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password)) {
                $message = 'All fields are required.';
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Invalid email format.';
                break;
            }
            if ($password !== $confirm_password) {
                $message = 'Passwords do not match.';
                break;
            }
            if (strlen($password) < 8 || !preg_match($special_char_regex, $password)) {
                $message = 'Password must be at least 8 characters long and contain at least one special character.';
                break;
            }
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            $stmt_check = $conn->prepare('SELECT id FROM customers WHERE tenant_id = ? AND email = ?');
            $stmt_check->bind_param('is', TENANT_ID, $email);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'An account with this email already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare('INSERT INTO customers (tenant_id, name, phone, email, password_hash, address, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
            $stmt->bind_param('isssss', TENANT_ID, $name, $phone, $email, $password_hash, $address);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Registration successful! You can now log in.';
                log_action($conn, 'Customer Registration', 'New customer registered: ' . $email, 'customer', TENANT_ID, $conn->insert_id);
                redirect('customer-login', ['toast_type' => 'success', 'toast_message' => $message]);
            } else {
                $message = 'Failed to register: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_book':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('books')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $book_id = $_POST['book_id'] ?? null;
            $name = $_POST['name'];
            $product_type = $_POST['product_type'];
            $author = $_POST['author'] ?? null;
            $category = $_POST['category'];
            $isbn = $_POST['isbn'] ?? null;
            $publisher = $_POST['publisher'] ?? null;
            $year = $_POST['year'] ?? null;
            $price = $_POST['price'];
            $purchase_price = $_POST['purchase_price'] ?? 0;
            $retail_price = $_POST['retail_price'] ?? $_POST['price'];
            $wholesale_price = $_POST['wholesale_price'] ?? $_POST['price'];
            $stock = $_POST['stock'];
            $barcode = trim($_POST['barcode'] ?? '');
            $description = $_POST['description'] ?? null;
            $cover_image_path = $_POST['existing_cover_image'] ?? null;
            if (empty($name) || empty($product_type) || empty($category) || empty($price) || !isset($stock)) {
                $message = 'All required product fields must be filled.';
                break;
            }
            if ($product_type === 'book' && (empty($author) || empty($isbn))) {
                $message = 'For "Book" type, Author and ISBN are required.';
                break;
            }
            if (!is_numeric($price) || $price < 0 || !is_numeric($retail_price) || $retail_price < 0 || !is_numeric($wholesale_price) || $wholesale_price < 0) {
                $message = 'Invalid pricing values.';
                break;
            }
            $retail_price = (float) $retail_price;
            $wholesale_price = (float) $wholesale_price;
            $price = $retail_price;
            if ($barcode === '' && !empty($isbn)) {
                $barcode = preg_replace('/[^0-9A-Za-z]/', '', $isbn);
            }
            if (!is_numeric($stock) || $stock < 0) {
                $message = 'Invalid stock quantity.';
                break;
            }
            $target_dir = UPLOAD_DIR . '/' . TENANT_SLUG . '/covers/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                if (!TENANT_ALLOW_UPLOADS) {
                    $message = 'File uploads are not enabled for your tenant plan. Please upgrade.';
                    break;
                }
                $file_tmp_name = $_FILES['cover_image']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($file_ext, $allowed_ext)) {
                    $new_file_name = uniqid('cover_') . '.' . $file_ext;
                    $destination = $target_dir . $new_file_name;
                    $source_image = null;
                    if ($file_ext === 'jpg' || $file_ext === 'jpeg') $source_image = imagecreatefromjpeg($file_tmp_name);
                    elseif ($file_ext === 'png') $source_image = imagecreatefrompng($file_tmp_name);
                    elseif ($file_ext === 'gif') $source_image = imagecreatefromgif($file_tmp_name);
                    elseif ($file_ext === 'webp') $source_image = imagecreatefromwebp($file_tmp_name);
                    if ($source_image) {
                        $width = imagesx($source_image);
                        $height = imagesy($source_image);
                        $quality = 80;
                        ob_start();
                        imagejpeg($source_image, null, $quality);
                        $compressed_image_data = ob_get_clean();
                        imagedestroy($source_image);
                        while (strlen($compressed_image_data) > 200 * 1024 && $quality > 10) {
                            $quality -= 10;
                            $source_image = imagecreatefromstring($compressed_image_data);
                            ob_start();
                            imagejpeg($source_image, null, $quality);
                            $compressed_image_data = ob_get_clean();
                            imagedestroy($source_image);
                        }
                        if (file_put_contents($destination, $compressed_image_data)) {
                            if ($cover_image_path && file_exists($cover_image_path)) {
                                unlink($cover_image_path);
                            }
                            $cover_image_path = $destination;
                        } else {
                            $message = 'Failed to save processed cover image.';
                            break;
                        }
                    } else {
                        $message = 'Invalid image file or format.';
                        break;
                    }
                } else {
                    $message = 'Only JPG, JPEG, PNG, GIF, WEBP files are allowed for cover image.';
                    break;
                }
            } else if (isset($_POST['remove_cover_image']) && $_POST['remove_cover_image'] === 'true') {
                if ($cover_image_path && file_exists($cover_image_path)) {
                    unlink($cover_image_path);
                }
                $cover_image_path = null;
            }
            if ($book_id) {
                $stmt = $conn->prepare('UPDATE books SET name=?, product_type=?, author=?, category=?, isbn=?, publisher=?, year=?, price=?, purchase_price=?, retail_price=?, wholesale_price=?, stock=?, barcode=?, description=?, cover_image=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ssssssiddddisssii', $name, $product_type, $author, $category, $isbn, $publisher, $year, $price, $purchase_price, $retail_price, $wholesale_price, $stock, $barcode, $description, $cover_image_path, TENANT_ID, $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product updated successfully!';
                    log_action($conn, 'Product Update', 'Updated product: ' . $name . ' (ID: ' . $book_id . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update product: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO books (tenant_id, name, product_type, author, category, isbn, publisher, year, price, purchase_price, retail_price, wholesale_price, stock, barcode, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssiddddisss', TENANT_ID, $name, $product_type, $author, $category, $isbn, $publisher, $year, $price, $purchase_price, $retail_price, $wholesale_price, $stock, $barcode, $description, $cover_image_path);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product added successfully!';
                    log_action($conn, 'Product Add', 'Added new product: ' . $name . ' (ID: ' . $conn->insert_id . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to add product: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_book':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('books')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $book_id = $_POST['book_id'] ?? null;
            if ($book_id) {
                $sales_check_stmt = $conn->prepare('SELECT COUNT(*) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.tenant_id = ? AND si.book_id = ?');
                $sales_check_stmt->bind_param('ii', TENANT_ID, $book_id);
                $sales_check_stmt->execute();
                $has_sales = $sales_check_stmt->get_result()->fetch_row()[0] > 0;
                $sales_check_stmt->close();
                $po_check_stmt = $conn->prepare('SELECT COUNT(*) FROM po_items poi JOIN purchase_orders po ON poi.po_id = po.id WHERE po.tenant_id = ? AND poi.book_id = ?');
                $po_check_stmt->bind_param('ii', TENANT_ID, $book_id);
                $po_check_stmt->execute();
                $has_pos = $po_check_stmt->get_result()->fetch_row()[0] > 0;
                $po_check_stmt->close();
                $online_order_check_stmt = $conn->prepare('SELECT COUNT(*) FROM online_order_items ooi JOIN online_orders oo ON ooi.order_id = oo.id WHERE oo.tenant_id = ? AND ooi.book_id = ?');
                $online_order_check_stmt->bind_param('ii', TENANT_ID, $book_id);
                $online_order_check_stmt->execute();
                $has_online_orders = $online_order_check_stmt->get_result()->fetch_row()[0] > 0;
                $online_order_check_stmt->close();
                if ($has_sales || $has_pos || $has_online_orders) {
                    $message = 'Cannot delete product with existing sales, purchase orders, or online orders.';
                    break;
                }
                $stmt = $conn->prepare('SELECT name, cover_image FROM books WHERE tenant_id = ? AND id = ?');
                $stmt->bind_param('ii', TENANT_ID, $book_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $book = $result->fetch_assoc();
                $stmt->close();
                if ($book && $book['cover_image'] && file_exists($book['cover_image'])) {
                    unlink($book['cover_image']);
                }
                $stmt = $conn->prepare('DELETE FROM books WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ii', TENANT_ID, $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product deleted successfully!';
                    log_action($conn, 'Product Delete', 'Deleted product: ' . $book['name'] . ' (ID: ' . $book_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to delete product: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Product ID not provided.';
            }
            break;
        case 'quick_sell':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('books')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $book_id = $_POST['book_id'] ?? null;
            if (empty($book_id)) {
                $message = 'Product ID not provided.';
                break;
            }
            $conn->begin_transaction();
            try {
                $stmt_book = $conn->prepare('SELECT name, price, stock FROM books WHERE tenant_id = ? AND id = ?');
                $stmt_book->bind_param('ii', TENANT_ID, $book_id);
                $stmt_book->execute();
                $book_data = $stmt_book->get_result()->fetch_assoc();
                $stmt_book->close();
                if (!$book_data) {
                    throw new Exception('Product not found.');
                }
                if ($book_data['stock'] < 1) {
                    throw new Exception('Not enough stock for ' . html($book_data['name']) . '.');
                }
                $user_id = $_SESSION['user_id'];
                $subtotal = $book_data['price'];
                $total = $book_data['price'];
                $stmt_sale = $conn->prepare('INSERT INTO sales (tenant_id, customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (?, NULL, ?, ?, 0, ?, NULL)');
                $stmt_sale->bind_param('iidd', TENANT_ID, $user_id, $subtotal, $total);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, 1, ?, 0)');
                $stmt_sale_item->bind_param('iid', $sale_id, $book_id, $book_data['price']);
                $stmt_sale_item->execute();
                $stmt_sale_item->close();
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - 1 WHERE tenant_id = ? AND id = ?');
                $stmt_update_stock->bind_param('ii', TENANT_ID, $book_id);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();
                log_action($conn, 'Quick Sell (1 unit)', "Quick sale of 1 unit of {$book_data['name']} (ID: $book_id). Sale ID: $sale_id.", 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                $conn->commit();
                $message_type = 'success';
                $message = 'Quick sale completed for ' . html($book_data['name']) . '!';
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Quick Sell Failed', 'Quick sale failed: ' . $e->getMessage(), 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                $message = 'Quick sale failed: ' . $e->getMessage();
            }
            break;
        case 'update_stock':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('books')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $book_id = $_POST['book_id'] ?? null;
            $quantity_to_add = $_POST['quantity_to_add'] ?? null;
            if (empty($book_id) || !is_numeric($quantity_to_add) || $quantity_to_add <= 0) {
                $message = 'Invalid input for restock.';
                break;
            }
            $stmt = $conn->prepare('UPDATE books SET stock = stock + ? WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('iii', $quantity_to_add, TENANT_ID, $book_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Product stock updated successfully!';
                log_action($conn, 'Stock Update', 'Restocked product ID ' . $book_id . ' by ' . $quantity_to_add . ' units.', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to update stock: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_user':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('users')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $u_id = $_POST['user_id'] ?? null;
            $u_name = $_POST['username'];
            $u_role_id = (int) $_POST['role_id'];
            $u_pass = $_POST['password'] ?? '';
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            $stmt_check = $conn->prepare('SELECT id FROM users WHERE tenant_id = ? AND username = ? AND id != ?');
            $check_id = $u_id ?: 0;
            $stmt_check->bind_param('isi', TENANT_ID, $u_name, $check_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'Username already exists.';
                break;
            }
            $stmt_check->close();
            if ($u_id) {
                if (!empty($u_pass)) {
                    if (strlen($u_pass) < 8 || !preg_match($special_char_regex, $u_pass)) {
                        $message = 'Password must be at least 8 characters long and contain at least one special character.';
                        break;
                    }
                    $hash = password_hash($u_pass, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare('UPDATE users SET username=?, role_id=?, password_hash=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('sisi', $u_name, $u_role_id, $hash, TENANT_ID, $u_id);
                } else {
                    $stmt = $conn->prepare('UPDATE users SET username=?, role_id=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('siii', $u_name, $u_role_id, TENANT_ID, $u_id);
                }
            } else {
                if (empty($u_pass)) {
                    $message = 'Password required for new user.';
                    break;
                }
                if (strlen($u_pass) < 8 || !preg_match($special_char_regex, $u_pass)) {
                    $message = 'Password must be at least 8 characters long and contain at least one special character.';
                    break;
                }
                $hash = password_hash($u_pass, PASSWORD_BCRYPT);
                $stmt = $conn->prepare('INSERT INTO users (tenant_id, username, role_id, password_hash) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('iiss', TENANT_ID, $u_name, $u_role_id, $hash);
            }
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'User saved successfully!';
                log_action($conn, 'User Save', 'Saved user: ' . $u_name . ' (ID: ' . ($u_id ?: $conn->insert_id) . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to save user: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'delete_user':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('users')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $u_id = $_POST['user_id'] ?? null;
            if ($u_id == $_SESSION['user_id']) {
                $message = 'You cannot delete your own user account.';
                break;
            }
            $stmt_check_sales = $conn->prepare('SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND user_id = ?');
            $stmt_check_sales->bind_param('ii', TENANT_ID, $u_id);
            $stmt_check_sales->execute();
            if ($stmt_check_sales->get_result()->fetch_row()[0] > 0) {
                $message = 'Cannot delete user with associated sales records.';
                $stmt_check_sales->close();
                break;
            }
            $stmt_check_sales->close();
            $stmt = $conn->prepare('SELECT username FROM users WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $u_id);
            $stmt->execute();
            $user_name = $stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown User';
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM users WHERE tenant_id = ? AND id=?');
            $stmt->bind_param('ii', TENANT_ID, $u_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'User deleted!';
                log_action($conn, 'User Delete', 'Deleted user: ' . $user_name . ' (ID: ' . $u_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to delete user: ' . $stmt->error;
            }
            break;
        case 'save_role':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('users')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $r_id = $_POST['role_id'] ?? null;
            $r_name = $_POST['role_name'];
            $pages = $_POST['pages'] ?? [];
            if ($r_id) {
                $stmt = $conn->prepare('UPDATE roles SET name=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('sii', $r_name, TENANT_ID, $r_id);
                $stmt->execute();
            } else {
                $stmt_check_name = $conn->prepare('SELECT id FROM roles WHERE tenant_id = ? AND name = ?');
                $stmt_check_name->bind_param('is', TENANT_ID, $r_name);
                $stmt_check_name->execute();
                if ($stmt_check_name->get_result()->num_rows > 0) {
                    $message = 'Role name already exists.';
                    $stmt_check_name->close();
                    break;
                }
                $stmt_check_name->close();
                $stmt = $conn->prepare('INSERT INTO roles (tenant_id, name) VALUES (?, ?)');
                $stmt->bind_param('is', TENANT_ID, $r_name);
                $stmt->execute();
                $r_id = $conn->insert_id;
            }
            $stmt = $conn->prepare('DELETE FROM role_page_permissions WHERE role_id=?');
            $stmt->bind_param('i', $r_id);
            $stmt->execute();
            if (!empty($pages)) {
                $stmt = $conn->prepare('INSERT INTO role_page_permissions (role_id, page_key) VALUES (?, ?)');
                foreach ($pages as $p) {
                    $stmt->bind_param('is', $r_id, $p);
                    $stmt->execute();
                }
            }
            $message_type = 'success';
            $message = 'Role saved successfully!';
            log_action($conn, 'Role Save', 'Saved role: ' . $r_name . ' (ID: ' . ($r_id) . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            break;
        case 'delete_role':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('users')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $r_id = $_POST['role_id'] ?? null;
            $stmt_check = $conn->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role_id=?');
            $stmt_check->bind_param('ii', TENANT_ID, $r_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                $message = 'Cannot delete role assigned to users. Please reassign users first.';
                break;
            }
            $stmt_check->close();
            $stmt = $conn->prepare('SELECT name FROM roles WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $r_id);
            $stmt->execute();
            $role_name = $stmt->get_result()->fetch_assoc()['name'] ?? 'Unknown Role';
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM roles WHERE tenant_id = ? AND id=?');
            $stmt->bind_param('ii', TENANT_ID, $r_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Role deleted!';
                log_action($conn, 'Role Delete', 'Deleted role: ' . $role_name . ' (ID: ' . $r_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to delete role: ' . $stmt->error;
            }
            break;
        case 'save_customer':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('customers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $customer_id = $_POST['customer_id'] ?? null;
            $name = $_POST['name'];
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $address = $_POST['address'] ?? null;
            $password = $_POST['password'] ?? null;
            $password_hash = null;
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            if (empty($name)) {
                $message = 'Customer name is required.';
                break;
            }
            if ($password) {
                if (strlen($password) < 8 || !preg_match($special_char_regex, $password)) {
                    $message = 'Password must be at least 8 characters long and contain at least one special character.';
                    break;
                }
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
            }
            if ($email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Invalid email format.';
                    break;
                }
                $stmt_check = $conn->prepare('SELECT id FROM customers WHERE tenant_id = ? AND email = ? AND id != ?');
                $stmt_check->bind_param('isi', TENANT_ID, $email, $customer_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $message = 'A customer with this email already exists.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
            }
            if ($customer_id) {
                if ($password_hash) {
                    $stmt = $conn->prepare('UPDATE customers SET name=?, phone=?, email=?, password_hash=?, address=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('sssssii', $name, $phone, $email, $password_hash, $address, TENANT_ID, $customer_id);
                } else {
                    $stmt = $conn->prepare('UPDATE customers SET name=?, phone=?, email=?, address=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('ssssii', $name, $phone, $email, $address, TENANT_ID, $customer_id);
                }
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Customer updated successfully!';
                    log_action($conn, 'Customer Update', 'Updated customer: ' . $name . ' (ID: ' . $customer_id . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update customer: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                if (!$password_hash) {
                    $message = 'Password is required for new customer.';
                    break;
                }
                if (!$email) {
                    $message = 'Email is required for new customer.';
                    break;
                }
                $stmt = $conn->prepare('INSERT INTO customers (tenant_id, name, phone, email, password_hash, address) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssss', TENANT_ID, $name, $phone, $email, $password_hash, $address);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Customer added successfully!';
                    log_action($conn, 'Customer Add', 'Added new customer: ' . $name . ' (ID: ' . $conn->insert_id . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to add customer: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'toggle_customer_status':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('customers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $customer_id = $_POST['customer_id'] ?? null;
            $current_status = filter_var($_POST['current_status'], FILTER_VALIDATE_BOOLEAN);
            if ($customer_id) {
                $new_status = !$current_status;
                $stmt = $conn->prepare('UPDATE customers SET is_active=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('iii', $new_status, TENANT_ID, $customer_id);
                if ($stmt->execute()) {
                    $message_type = 'info';
                    $status_text = ($new_status ? 'Active' : 'Inactive');
                    $message = 'Customer status updated to ' . $status_text . '.';
                    log_action($conn, 'Customer Status Change', 'Customer ID ' . $customer_id . ' status set to ' . $status_text, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update customer status: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Customer ID not provided.';
            }
            break;
        case 'save_supplier':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('suppliers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $supplier_id = $_POST['supplier_id'] ?? null;
            $name = $_POST['name'];
            $contact_person = $_POST['contact_person'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $address = $_POST['address'] ?? null;
            if (empty($name)) {
                $message = 'Supplier name is required.';
                break;
            }
            if ($email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Invalid email format.';
                    break;
                }
                $stmt_check = $conn->prepare('SELECT id FROM suppliers WHERE tenant_id = ? AND email = ? AND id != ?');
                $stmt_check->bind_param('isi', TENANT_ID, $email, $supplier_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $message = 'A supplier with this email already exists.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
            }
            if ($supplier_id) {
                $stmt = $conn->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('sssssii', $name, $contact_person, $phone, $email, $address, TENANT_ID, $supplier_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier updated successfully!';
                    log_action($conn, 'Supplier Update', 'Updated supplier: ' . $name . ' (ID: ' . $supplier_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update supplier: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO suppliers (tenant_id, name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssss', TENANT_ID, $name, $contact_person, $phone, $email, $address);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier added successfully!';
                    log_action($conn, 'Supplier Add', 'Added new supplier: ' . $name . ' (ID: ' . $conn->insert_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to add supplier: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_supplier':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('suppliers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $supplier_id = $_POST['supplier_id'] ?? null;
            if ($supplier_id) {
                $stmt_check = $conn->prepare('SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = ? AND supplier_id = ?');
                $stmt_check->bind_param('ii', TENANT_ID, $supplier_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                    $message = 'Cannot delete supplier with existing purchase orders.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
                $stmt = $conn->prepare('SELECT name FROM suppliers WHERE tenant_id = ? AND id = ?');
                $stmt->bind_param('ii', TENANT_ID, $supplier_id);
                $stmt->execute();
                $supplier_name = $stmt->get_result()->fetch_assoc()['name'] ?? 'Unknown Supplier';
                $stmt->close();
                $stmt = $conn->prepare('DELETE FROM suppliers WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ii', TENANT_ID, $supplier_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier deleted successfully!';
                    log_action($conn, 'Supplier Delete', 'Deleted supplier: ' . $supplier_name . ' (ID: ' . $supplier_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to delete supplier: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Supplier ID not provided.';
            }
            break;
        case 'save_po':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('purchase-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $po_id = $_POST['po_id'] ?? null;
            $supplier_id = $_POST['supplier_id'];
            $order_date = $_POST['order_date'];
            $expected_date = $_POST['expected_date'] ?? null;
            $status = $_POST['status'];
            $po_items_json = $_POST['po_items'] ?? '[]';
            $po_items = json_decode($po_items_json, true);
            if (empty($supplier_id) || empty($order_date) || empty($po_items)) {
                $message = 'All required PO fields and items must be provided.';
                break;
            }
            $total_cost = 0;
            foreach ($po_items as $item) {
                $total_cost += $item['quantity'] * $item['cost_per_unit'];
            }
            if ($po_id) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('UPDATE purchase_orders SET supplier_id=?, user_id=?, status=?, order_date=?, expected_date=?, total_cost=? WHERE tenant_id = ? AND id=?');
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param('iisssisii', $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost, TENANT_ID, $po_id);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare('DELETE FROM po_items WHERE po_id=?');
                    $stmt->bind_param('i', $po_id);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare('INSERT INTO po_items (po_id, book_id, quantity, cost_per_unit) VALUES (?, ?, ?, ?)');
                    foreach ($po_items as $item) {
                        $stmt->bind_param('iiid', $po_id, $item['bookId'], $item['quantity'], $item['cost_per_unit']);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $conn->commit();
                    $message_type = 'success';
                    $message = 'Purchase Order updated successfully!';
                    log_action($conn, 'PO Update', 'Updated PO: ' . $po_id, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } catch (Exception $e) {
                    $conn->rollback();
                    log_action($conn, 'PO Update Failed', 'Failed to update PO ' . $po_id . ': ' . $e->getMessage(), 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                    $message = 'Failed to update PO: ' . $e->getMessage();
                }
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('INSERT INTO purchase_orders (tenant_id, supplier_id, user_id, status, order_date, expected_date, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param('iiisssd', TENANT_ID, $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost);
                    $stmt->execute();
                    $po_id = $conn->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare('INSERT INTO po_items (po_id, book_id, quantity, cost_per_unit) VALUES (?, ?, ?, ?)');
                    foreach ($po_items as $item) {
                        $stmt->bind_param('iiid', $po_id, $item['bookId'], $item['quantity'], $item['cost_per_unit']);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $conn->commit();
                    $message_type = 'success';
                    $message = 'Purchase Order created successfully!';
                    log_action($conn, 'PO Create', 'Created new PO: ' . $po_id, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } catch (Exception $e) {
                    $conn->rollback();
                    log_action($conn, 'PO Create Failed', 'Failed to create PO: ' . $e->getMessage(), 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                    $message = 'Failed to create PO: ' . $e->getMessage();
                }
            }
            break;
        case 'delete_po':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('purchase-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $po_id = $_POST['po_id'] ?? null;
            if ($po_id) {
                $stmt = $conn->prepare('DELETE FROM purchase_orders WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ii', TENANT_ID, $po_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Purchase Order deleted successfully!';
                    log_action($conn, 'PO Delete', 'Deleted PO: ' . $po_id, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to delete PO: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'PO ID not provided.';
            }
            break;
        case 'receive_po':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('purchase-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $po_id = $_POST['po_id'] ?? null;
            if ($po_id) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('SELECT status FROM purchase_orders WHERE tenant_id = ? AND id = ?');
                    $stmt->bind_param('ii', TENANT_ID, $po_id);
                    $stmt->execute();
                    $current_status = $stmt->get_result()->fetch_assoc()['status'] ?? null;
                    $stmt->close();
                    if ($current_status !== 'received') {
                        $stmt_items = $conn->prepare('SELECT book_id, quantity FROM po_items WHERE po_id = ?');
                        $stmt_items->bind_param('i', $po_id);
                        $stmt_items->execute();
                        $items = $stmt_items->get_result();
                        while ($item = $items->fetch_assoc()) {
                            $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock + ? WHERE tenant_id = ? AND id = ?');
                            $stmt_update_stock->bind_param('iii', $item['quantity'], TENANT_ID, $item['book_id']);
                            $stmt_update_stock->execute();
                            $stmt_update_stock->close();
                        }
                        $stmt_items->close();
                        $stmt_update_po = $conn->prepare("UPDATE purchase_orders SET status = 'received' WHERE tenant_id = ? AND id = ?");
                        $stmt_update_po->bind_param('ii', TENANT_ID, $po_id);
                        $stmt_update_po->execute();
                        $stmt_update_po->close();
                        $conn->commit();
                        $message_type = 'success';
                        $message = 'Purchase Order received and product stock updated!';
                        log_action($conn, 'PO Received', 'Received PO: ' . $po_id . ' and updated stock.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                    } else {
                        $message = 'Purchase Order already marked as received.';
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    log_action($conn, 'PO Receive Failed', 'Failed to receive PO ' . $po_id . ': ' . $e->getMessage(), 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                    $message = 'Failed to receive PO: ' . $e->getMessage();
                }
            } else {
                $message = 'PO ID not provided.';
            }
            break;
        case 'complete_sale':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('cart')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $customer_id = $_POST['customer_id'] ?? null;
            if (empty($customer_id)) {
                $customer_id = null;
            }
            $promotion_code = $_POST['promotion_code'] ?? null;
            if (empty($promotion_code)) {
                $promotion_code = null;
            }
            $cart_items_json = $_POST['cart_items'] ?? '[]';
            $cart_items = json_decode($cart_items_json, true);
            if (empty($cart_items)) {
                $message = 'Cart is empty, cannot complete sale.';
                break;
            }
            $conn->begin_transaction();
            try {
                $subtotal = 0;
                $total_discount = 0;
                foreach ($cart_items as &$cart_item) {
                    $stmt_book = $conn->prepare('SELECT stock, price, category FROM books WHERE tenant_id = ? AND id = ?');
                    $stmt_book->bind_param('ii', TENANT_ID, $cart_item['bookId']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $cart_item['quantity']) {
                        throw new Exception('Not enough stock for ' . html($cart_item['name']) . '. Available: ' . ($book_data['stock'] ?? 0) . ', Needed: ' . $cart_item['quantity'] . '.');
                    }
                    $subtotal += $book_data['price'] * $cart_item['quantity'];
                    $cart_item['price_per_unit'] = $book_data['price'];
                    $manual_disc = isset($cart_item['custom_discount']) ? (float) $cart_item['custom_discount'] : 0;
                    $cart_item['discount_per_unit'] = min($manual_disc, $book_data['price']);
                    $cart_item['category'] = $book_data['category'];
                    $total_discount += ($cart_item['discount_per_unit'] * $cart_item['quantity']);
                }
                unset($cart_item);
                if ($promotion_code) {
                    $stmt_promo = $conn->prepare('SELECT * FROM promotions WHERE tenant_id = ? AND code = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())');
                    $stmt_promo->bind_param('is', TENANT_ID, $promotion_code);
                    $stmt_promo->execute();
                    $promotion = $stmt_promo->get_result()->fetch_assoc();
                    $stmt_promo->close();
                    if ($promotion) {
                        if ($promotion['applies_to'] === 'all') {
                            $discount_amount = ($promotion['type'] === 'percentage') ? ($subtotal * ($promotion['value'] / 100)) : $promotion['value'];
                            $promo_discount = min($discount_amount, $subtotal - $total_discount);
                            $total_discount += $promo_discount;
                            if ($subtotal > 0) {
                                foreach ($cart_items as &$cart_item) {
                                    $item_subtotal_proportion = ($cart_item['price_per_unit'] * $cart_item['quantity']) / $subtotal;
                                    $cart_item['discount_per_unit'] += ($promo_discount * $item_subtotal_proportion) / $cart_item['quantity'];
                                }
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-book') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['bookId'] == $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $added_discount = min($discount_amount / $cart_item['quantity'], $cart_item['price_per_unit'] - $cart_item['discount_per_unit']);
                                    $cart_item['discount_per_unit'] += $added_discount;
                                    $total_discount += ($added_discount * $cart_item['quantity']);
                                }
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-category') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['category'] === $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $added_discount = min($discount_amount / $cart_item['quantity'], $cart_item['price_per_unit'] - $cart_item['discount_per_unit']);
                                    $cart_item['discount_per_unit'] += $added_discount;
                                    $total_discount += ($added_discount * $cart_item['quantity']);
                                }
                            }
                            unset($cart_item);
                        }
                    } else {
                        $promotion_code = null;
                    }
                }
                $final_total = $subtotal - $total_discount;
                $final_total = max(0, $final_total);
                $stmt_sale = $conn->prepare('INSERT INTO sales (tenant_id, customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $user_id = $_SESSION['user_id'] ?? null;
                $stmt_sale->bind_param('iiddds', TENANT_ID, $customer_id, $user_id, $subtotal, $total_discount, $final_total, $promotion_code);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)');
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE tenant_id = ? AND id = ?');
                foreach ($cart_items as $item) {
                    $discount_value_per_unit = $item['discount_per_unit'];
                    $stmt_sale_item->bind_param('iiidd', $sale_id, $item['bookId'], $item['quantity'], $item['price_per_unit'], $discount_value_per_unit);
                    $stmt_sale_item->execute();
                    $stmt_update_stock->bind_param('iii', $item['quantity'], TENANT_ID, $item['bookId']);
                    $stmt_update_stock->execute();
                }
                $stmt_sale_item->close();
                $stmt_update_stock->close();
                $conn->commit();
                $_SESSION['cart'] = [];
                $_SESSION['applied_promotion'] = null;
                $message_type = 'success';
                $message = 'Sale completed successfully!';
                $_SESSION['last_sale_id'] = $sale_id;
                log_action($conn, 'Sale Complete', 'Completed sale (ID: ' . $sale_id . ') for total: ' . $final_total, 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Sale Failed', 'Failed to complete sale: ' . $e->getMessage(), 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                $message = 'Sale failed: ' . $e->getMessage();
            }
            break;
        case 'place_online_order':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isCustomerLoggedIn()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('online-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $customer_id = $_SESSION['customer_id'];
            $promotion_code = $_POST['promotion_code'] ?? null;
            if (empty($promotion_code)) {
                $promotion_code = null;
            }
            $cart_items_json = $_POST['cart_items'] ?? '[]';
            $cart_items = json_decode($cart_items_json, true);
            if (empty($cart_items)) {
                $message = 'Cart is empty, cannot place order.';
                break;
            }
            $conn->begin_transaction();
            try {
                $subtotal = 0;
                $total_discount = 0;
                foreach ($cart_items as &$cart_item) {
                    $stmt_book = $conn->prepare('SELECT stock, price, category FROM books WHERE tenant_id = ? AND id = ?');
                    $stmt_book->bind_param('ii', TENANT_ID, $cart_item['bookId']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $cart_item['quantity']) {
                        throw new Exception('Not enough stock for ' . html($cart_item['name']) . '. Available: ' . ($book_data['stock'] ?? 0) . ', Needed: ' . $cart_item['quantity'] . '.');
                    }
                    $subtotal += $book_data['price'] * $cart_item['quantity'];
                    $cart_item['price_per_unit'] = $book_data['price'];
                    $cart_item['discount_per_unit'] = 0;
                    $cart_item['category'] = $book_data['category'];
                }
                unset($cart_item);
                if ($promotion_code) {
                    $stmt_promo = $conn->prepare('SELECT * FROM promotions WHERE tenant_id = ? AND code = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())');
                    $stmt_promo->bind_param('is', TENANT_ID, $promotion_code);
                    $stmt_promo->execute();
                    $promotion = $stmt_promo->get_result()->fetch_assoc();
                    $stmt_promo->close();
                    if ($promotion) {
                        if ($promotion['applies_to'] === 'all') {
                            $discount_amount = ($promotion['type'] === 'percentage') ? ($subtotal * ($promotion['value'] / 100)) : $promotion['value'];
                            $total_discount = min($discount_amount, $subtotal);
                            if ($subtotal > 0) {
                                foreach ($cart_items as &$cart_item) {
                                    $item_subtotal_proportion = ($cart_item['price_per_unit'] * $cart_item['quantity']) / $subtotal;
                                    $cart_item['discount_per_unit'] = ($total_discount * $item_subtotal_proportion) / $cart_item['quantity'];
                                }
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-book') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['bookId'] == $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $cart_item['discount_per_unit'] = min($discount_amount / $cart_item['quantity'], $cart_item['price_per_unit']);
                                    $total_discount += ($cart_item['discount_per_unit'] * $cart_item['quantity']);
                                }
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-category') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['category'] === $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $cart_item['discount_per_unit'] = min($discount_amount / $cart_item['quantity'], $cart_item['price_per_unit']);
                                    $total_discount += ($cart_item['discount_per_unit'] * $cart_item['quantity']);
                                }
                            }
                            unset($cart_item);
                        }
                    } else {
                        $promotion_code = null;
                    }
                }
                $final_total = $subtotal - $total_discount;
                $final_total = max(0, $final_total);
                $stmt_order = $conn->prepare("INSERT INTO online_orders (tenant_id, customer_id, subtotal, discount, total, promotion_code, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt_order->bind_param('iiddds', TENANT_ID, $customer_id, $subtotal, $total_discount, $final_total, $promotion_code);
                $stmt_order->execute();
                $order_id = $conn->insert_id;
                $stmt_order->close();
                $stmt_order_item = $conn->prepare('INSERT INTO online_order_items (order_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)');
                foreach ($cart_items as $item) {
                    $discount_value_per_unit = $item['discount_per_unit'];
                    $stmt_order_item->bind_param('iiidd', $order_id, $item['bookId'], $item['quantity'], $item['price_per_unit'], $discount_value_per_unit);
                    $stmt_order_item->execute();
                }
                $stmt_order_item->close();
                $conn->commit();
                $_SESSION['cart'] = [];
                $_SESSION['applied_promotion'] = null;
                $message_type = 'success';
                $message = 'Online order placed successfully! Order ID: ' . $order_id . '.';
                log_action($conn, 'Online Order Place', 'New online order (ID: ' . $order_id . ') placed by customer ' . $customer_id, 'customer', TENANT_ID, $customer_id);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Online Order Failed', 'Failed to place online order: ' . $e->getMessage(), 'customer', TENANT_ID, $customer_id);
                $message = 'Online order failed: ' . $e->getMessage();
            }
            break;
        case 'approve_online_order':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('online-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $order_id = $_POST['order_id'] ?? null;
            if (!$order_id) {
                $message = 'Order ID not provided.';
                break;
            }
            $conn->begin_transaction();
            try {
                $stmt_order = $conn->prepare("SELECT * FROM online_orders WHERE tenant_id = ? AND id = ? AND status = 'pending'");
                $stmt_order->bind_param('ii', TENANT_ID, $order_id);
                $stmt_order->execute();
                $order = $stmt_order->get_result()->fetch_assoc();
                $stmt_order->close();
                if (!$order) {
                    throw new Exception('Online order not found or already processed.');
                }
                $stmt_items = $conn->prepare('SELECT * FROM online_order_items WHERE order_id = ?');
                $stmt_items->bind_param('i', $order_id);
                $stmt_items->execute();
                $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();
                foreach ($items as $item) {
                    $stmt_book = $conn->prepare('SELECT stock, name FROM books WHERE tenant_id = ? AND id = ?');
                    $stmt_book->bind_param('ii', TENANT_ID, $item['book_id']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $item['quantity']) {
                        throw new Exception('Not enough stock for ' . html($book_data['name'] ?? 'product ID ' . $item['book_id']) . ' for order ' . $order_id . '.');
                    }
                }
                $user_id = $_SESSION['user_id'];
                $stmt_sale = $conn->prepare('INSERT INTO sales (tenant_id, customer_id, user_id, sale_date, subtotal, discount, total, promotion_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt_sale->bind_param('iisddds', TENANT_ID, $order['customer_id'], $user_id, $order['order_date'], $order['subtotal'], $order['discount'], $order['total'], $order['promotion_code']);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)');
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE tenant_id = ? AND id = ?');
                foreach ($items as $item) {
                    $stmt_sale_item->bind_param('iiidd', $sale_id, $item['book_id'], $item['quantity'], $item['price_per_unit'], $item['discount_per_unit']);
                    $stmt_sale_item->execute();
                    $stmt_update_stock->bind_param('iii', $item['quantity'], TENANT_ID, $item['book_id']);
                    $stmt_update_stock->execute();
                }
                $stmt_sale_item->close();
                $stmt_update_stock->close();
                $stmt_update_order = $conn->prepare("UPDATE online_orders SET status = 'approved', sale_id = ? WHERE tenant_id = ? AND id = ?");
                $stmt_update_order->bind_param('iii', $sale_id, TENANT_ID, $order_id);
                $stmt_update_order->execute();
                $stmt_update_order->close();
                $conn->commit();
                $message_type = 'success';
                $message = 'Online order ' . $order_id . ' approved and converted to sale ' . $sale_id . '!';
                log_action($conn, 'Online Order Approve', 'Approved online order ' . $order_id . ', converted to sale ' . $sale_id, 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Online Order Approve Failed', 'Failed to approve online order ' . $order_id . ': ' . $e->getMessage(), 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                $message = 'Failed to approve online order: ' . $e->getMessage();
            }
            break;
        case 'reject_online_order':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!hasPlanAccess('online-orders')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $order_id = $_POST['order_id'] ?? null;
            if (!$order_id) {
                $message = 'Order ID not provided.';
                break;
            }
            $stmt = $conn->prepare("UPDATE online_orders SET status = 'rejected' WHERE tenant_id = ? AND id = ? AND status = 'pending'");
            $stmt->bind_param('ii', TENANT_ID, $order_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message_type = 'info';
                $message = 'Online order ' . $order_id . ' rejected.';
                log_action($conn, 'Online Order Reject', 'Rejected online order ' . $order_id, 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to reject online order ' . $order_id . ' (may already be processed or not found).';
            }
            $stmt->close();
            break;
        case 'save_promotion':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('promotions')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $promotion_id = $_POST['promotion_id'] ?? null;
            $code = $_POST['code'];
            $type = $_POST['type'];
            $value = $_POST['value'];
            $applies_to = $_POST['applies_to'];
            $applies_to_value = $_POST['applies_to_value'] ?? null;
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'] ?? null;
            if (empty($code) || empty($type) || empty($value) || empty($applies_to) || empty($start_date)) {
                $message = 'All required promotion fields must be filled.';
                break;
            }
            if (!is_numeric($value) || $value <= 0) {
                $message = 'Discount value must be a positive number.';
                break;
            }
            if ($type === 'percentage' && $value > 100) {
                $message = 'Percentage discount cannot exceed 100%.';
                break;
            }
            if ($applies_to === 'specific-book' && empty($_POST['promotion_book_id'])) {
                $message = 'Please select a product for this promotion.';
                break;
            }
            if ($applies_to === 'specific-category' && empty($_POST['promotion_category'])) {
                $message = 'Please enter a category for this promotion.';
                break;
            }
            if ($applies_to === 'specific-book') {
                $applies_to_value = $_POST['promotion_book_id'];
            } elseif ($applies_to === 'specific-category') {
                $applies_to_value = $_POST['promotion_category'];
            } else {
                $applies_to_value = null;
            }
            $stmt_check = $conn->prepare('SELECT id FROM promotions WHERE tenant_id = ? AND code = ? AND id != ?');
            $stmt_check->bind_param('isi', TENANT_ID, $code, $promotion_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'A promotion with this code already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            if ($promotion_id) {
                $stmt = $conn->prepare('UPDATE promotions SET code=?, type=?, value=?, applies_to=?, applies_to_value=?, start_date=?, end_date=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ssdsisiii', $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date, TENANT_ID, $promotion_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion updated successfully!';
                    log_action($conn, 'Promotion Update', 'Updated promotion: ' . $code . ' (ID: ' . $promotion_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update promotion: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO promotions (tenant_id, code, type, value, applies_to, applies_to_value, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issdsiss', TENANT_ID, $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion added successfully!';
                    log_action($conn, 'Promotion Add', 'Added new promotion: ' . $code . ' (ID: ' . $conn->insert_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to add promotion: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_promotion':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('promotions')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $promotion_id = $_POST['promotion_id'] ?? null;
            if ($promotion_id) {
                $stmt_check = $conn->prepare('SELECT code FROM promotions WHERE tenant_id = ? AND id = ?');
                $stmt_check->bind_param('ii', TENANT_ID, $promotion_id);
                $stmt_check->execute();
                $promo_code = $stmt_check->get_result()->fetch_assoc()['code'] ?? null;
                $stmt_check->close();
                if ($promo_code) {
                    $stmt_sales_check = $conn->prepare('SELECT COUNT(*) FROM sales WHERE tenant_id = ? AND promotion_code = ?');
                    $stmt_sales_check->bind_param('is', TENANT_ID, $promo_code);
                    $stmt_sales_check->execute();
                    if ($stmt_sales_check->get_result()->fetch_row()[0] > 0) {
                        $message = 'Cannot delete promotion that has been used in sales.';
                        $stmt_sales_check->close();
                        break;
                    }
                    $stmt_sales_check->close();
                }
                $stmt = $conn->prepare('DELETE FROM promotions WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ii', TENANT_ID, $promotion_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion deleted successfully!';
                    log_action($conn, 'Promotion Delete', 'Deleted promotion code: ' . ($promo_code ?? 'N/A') . ' (ID: ' . $promotion_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to delete promotion: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Promotion ID not provided.';
            }
            break;
        case 'save_expense':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('expenses')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $expense_id = $_POST['expense_id'] ?? null;
            $date = $_POST['date'];
            $category = $_POST['category'];
            $description = $_POST['description'] ?? null;
            $amount = $_POST['amount'];
            if (empty($date) || empty($category) || empty($amount)) {
                $message = 'All required expense fields must be filled.';
                break;
            }
            if (!is_numeric($amount) || $amount < 0) {
                $message = 'Invalid amount.';
                break;
            }
            if ($expense_id) {
                $stmt = $conn->prepare('UPDATE expenses SET user_id=?, category=?, description=?, amount=?, expense_date=? WHERE tenant_id = ? AND id=?');
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param('isdsdii', $user_id, $category, $description, $amount, $date, TENANT_ID, $expense_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense updated successfully!';
                    log_action($conn, 'Expense Update', 'Updated expense ID: ' . $expense_id . ' (Category: ' . $category . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update expense: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO expenses (tenant_id, user_id, expense_date, category, description, amount) VALUES (?, ?, ?, ?, ?, ?)');
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param('iisssd', TENANT_ID, $user_id, $date, $category, $description, $amount);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense added successfully!';
                    log_action($conn, 'Expense Add', 'Added new expense (ID: ' . $conn->insert_id . ') (Category: ' . $category . ')', 'tenant_admin_or_staff', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to add expense: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_expense':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('expenses')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $expense_id = $_POST['expense_id'] ?? null;
            if ($expense_id) {
                $stmt = $conn->prepare('DELETE FROM expenses WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ii', TENANT_ID, $expense_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense deleted successfully!';
                    log_action($conn, 'Expense Delete', 'Deleted expense ID: ' . $expense_id, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to delete expense: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Expense ID not provided.';
            }
            break;
        case 'save_news':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('news')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $news_id = $_POST['news_id'] ?? null;
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if ($title === '' || $content === '') {
                $message = 'Title and content are required.';
                break;
            }
            if ($news_id) {
                $stmt = $conn->prepare('UPDATE public_news SET title=?, content=?, is_active=? WHERE tenant_id = ? AND id=?');
                $stmt->bind_param('ssiii', $title, $content, $is_active, TENANT_ID, $news_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'News updated successfully.';
                    log_action($conn, 'Tenant News Update', 'Updated news ID: ' . $news_id . ' (Title: ' . $title . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update news: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO public_news (tenant_id, title, content, is_active) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('issi', TENANT_ID, $title, $content, $is_active);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'News created successfully.';
                    log_action($conn, 'Tenant News Create', 'Created new news (ID: ' . $conn->insert_id . ') (Title: ' . $title . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to create news: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_news':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('news')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $news_id = (int) ($_POST['news_id'] ?? 0);
            if ($news_id <= 0) {
                $message = 'News ID not provided.';
                break;
            }
            $stmt = $conn->prepare('SELECT title FROM public_news WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $news_id);
            $stmt->execute();
            $news_title = $stmt->get_result()->fetch_assoc()['title'] ?? 'Unknown News';
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM public_news WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $news_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'News deleted.';
                log_action($conn, 'Tenant News Delete', 'Deleted news: ' . $news_title . ' (ID: ' . $news_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to delete news: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_settings':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('settings')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $new_settings = [
                'system_name' => $_POST['system_name'] ?? '',
                'about_story' => $_POST['about_story'] ?? '',
                'mission' => $_POST['mission'] ?? '',
                'vision' => $_POST['vision'] ?? '',
                'address' => $_POST['address'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'whatsapp_number' => $_POST['whatsapp_number'] ?? '',
                'email' => $_POST['email'] ?? '',
                'google_map_embed_url' => $_POST['google_map_embed_url'] ?? '',
                'facebook_url' => $_POST['facebook_url'] ?? '',
                'instagram_url' => $_POST['instagram_url'] ?? '',
                'currency_symbol' => $_POST['currency_symbol'] ?? 'PKR ',
                'public_site_enabled' => isset($_POST['public_site_enabled']) ? '1' : '0',
            ];

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
                foreach ($new_settings as $key => $value) {
                    $stmt->bind_param('isss', TENANT_ID, $key, $value, $value);
                    $stmt->execute();
                }
                $stmt->close();

                // Update PWA settings
                $pwa_app_name = $new_settings['system_name'] . ' App';
                $pwa_short_name = $new_settings['system_name'];
                $stmt_pwa = $conn->prepare('INSERT INTO pwa_settings (tenant_id, app_name, short_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE app_name = ?, short_name = ?');
                $stmt_pwa->bind_param('issss', TENANT_ID, $pwa_app_name, $pwa_short_name, $pwa_app_name, $pwa_short_name);
                $stmt_pwa->execute();
                $stmt_pwa->close();

                $conn->commit();
                $message_type = 'success';
                $message = 'Settings updated successfully!';
                log_action($conn, 'Tenant Settings Update', 'Updated tenant settings.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Tenant Settings Update Failed', 'Failed to update tenant settings: ' . $e->getMessage(), 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                $message = 'Failed to update settings: ' . $e->getMessage();
            }
            break;
        case 'import_books_action':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('books')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $conflict_resolution = $_POST['import_conflict_books'] ?? 'skip';
            if (!isset($_FILES['import_books_file']) || $_FILES['import_books_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'No file uploaded or an error occurred during upload.';
                break;
            }
            $file_content = file_get_contents($_FILES['import_books_file']['tmp_name']);
            $books_data = json_decode($file_content, true);
            if (!is_array($books_data)) {
                $message = 'Invalid JSON file. Expected an array of products.';
                break;
            }
            $new_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $conn->begin_transaction();
            try {
                $stmt_insert = $conn->prepare('INSERT INTO books (tenant_id, name, product_type, author, category, isbn, publisher, year, price, purchase_price, retail_price, wholesale_price, stock, barcode, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt_update_isbn = $conn->prepare('UPDATE books SET name=?, product_type=?, author=?, category=?, publisher=?, year=?, price=?, purchase_price=?, retail_price=?, wholesale_price=?, stock=?, barcode=?, description=?, cover_image=? WHERE tenant_id = ? AND isbn=?');
                $stmt_update_name_type = $conn->prepare('UPDATE books SET author=?, category=?, publisher=?, year=?, price=?, purchase_price=?, retail_price=?, wholesale_price=?, stock=?, barcode=?, description=?, cover_image=? WHERE tenant_id = ? AND name=? AND product_type=?');
                
                foreach ($books_data as $book) {
                    if (!isset($book['name']) || !isset($book['price']) || !isset($book['stock'])) {
                        $skipped_count++;
                        continue;
                    }

                    $book['product_type'] = $book['product_type'] ?? 'general';
                    $book['category'] = $book['category'] ?? 'Uncategorized';
                    $book['purchase_price'] = $book['purchase_price'] ?? 0;
                    $book['retail_price'] = $book['retail_price'] ?? $book['price'];
                    $book['wholesale_price'] = $book['wholesale_price'] ?? $book['price'];
                    $book['barcode'] = $book['barcode'] ?? (isset($book['isbn']) ? preg_replace('/[^0-9A-Za-z]/', '', $book['isbn']) : null);

                    $existing_book_id = null;
                    if ($book['product_type'] == 'book' && isset($book['isbn']) && !empty($book['isbn'])) {
                        $stmt_check = $conn->prepare('SELECT id FROM books WHERE tenant_id = ? AND isbn = ?');
                        $stmt_check->bind_param('is', TENANT_ID, $book['isbn']);
                        $stmt_check->execute();
                        $existing_book_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                        $stmt_check->close();
                    }
                    if (!$existing_book_id && isset($book['name']) && !empty($book['name'])) {
                        $stmt_check = $conn->prepare('SELECT id FROM books WHERE tenant_id = ? AND name = ? AND product_type = ?');
                        $stmt_check->bind_param('iss', TENANT_ID, $book['name'], $book['product_type']);
                        $stmt_check->execute();
                        $existing_book_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                        $stmt_check->close();
                    }

                    if ($existing_book_id) {
                        if ($conflict_resolution === 'update') {
                            if ($book['product_type'] == 'book' && isset($book['isbn']) && !empty($book['isbn'])) {
                                $stmt_update_isbn->bind_param(
                                    'sssssiddissis',
                                    $book['name'],
                                    $book['product_type'],
                                    $book['author'] ?? null,
                                    $book['category'],
                                    $book['publisher'] ?? null,
                                    $book['year'] ?? null,
                                    $book['price'],
                                    $book['purchase_price'],
                                    $book['retail_price'],
                                    $book['wholesale_price'],
                                    $book['stock'],
                                    $book['barcode'],
                                    $book['description'] ?? null,
                                    $book['cover_image'] ?? null,
                                    TENANT_ID,
                                    $book['isbn']
                                );
                                $stmt_update_isbn->execute();
                            } else {
                                $stmt_update_name_type->bind_param(
                                    'ssiddississs',
                                    $book['author'] ?? null,
                                    $book['category'],
                                    $book['publisher'] ?? null,
                                    $book['year'] ?? null,
                                    $book['price'],
                                    $book['purchase_price'],
                                    $book['retail_price'],
                                    $book['wholesale_price'],
                                    $book['stock'],
                                    $book['barcode'],
                                    $book['description'] ?? null,
                                    $book['cover_image'] ?? null,
                                    TENANT_ID,
                                    $book['name'],
                                    $book['product_type']
                                );
                                $stmt_update_name_type->execute();
                            }
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'isssssiddddisss',
                            TENANT_ID,
                            $book['name'],
                            $book['product_type'],
                            $book['author'] ?? null,
                            $book['category'],
                            $book['isbn'] ?? null,
                            $book['publisher'] ?? null,
                            $book['year'] ?? null,
                            $book['price'],
                            $book['purchase_price'],
                            $book['retail_price'],
                            $book['wholesale_price'],
                            $book['stock'],
                            $book['barcode'],
                            $book['description'] ?? null,
                            $book['cover_image'] ?? null
                        );
                        $stmt_insert->execute();
                        $new_count++;
                    }
                }
                $stmt_insert->close();
                $stmt_update_isbn->close();
                $stmt_update_name_type->close();
                $conn->commit();
                $message_type = 'success';
                $message = "Products imported: $new_count new, $updated_count updated, $skipped_count skipped.";
                log_action($conn, 'Products Import', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during product import: ' . $e->getMessage();
                log_action($conn, 'Products Import Failed', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            }
            break;
        case 'import_customers_action':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('customers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $conflict_resolution = $_POST['import_conflict_customers'] ?? 'skip';
            if (!isset($_FILES['import_customers_file']) || $_FILES['import_customers_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'No file uploaded or an error occurred during upload.';
                break;
            }
            $file_content = file_get_contents($_FILES['import_customers_file']['tmp_name']);
            $customers_data = json_decode($file_content, true);
            if (!is_array($customers_data)) {
                $message = 'Invalid JSON file. Expected an array of customers.';
                break;
            }
            $new_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $conn->begin_transaction();
            try {
                $stmt_insert = $conn->prepare('INSERT INTO customers (tenant_id, name, phone, email, password_hash, address, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt_update = $conn->prepare('UPDATE customers SET name=?, phone=?, password_hash=?, address=?, is_active=? WHERE tenant_id = ? AND email=?');
                foreach ($customers_data as $customer) {
                    if (!isset($customer['name']) || !isset($customer['email']) || !isset($customer['password'])) {
                        $skipped_count++;
                        continue;
                    }
                    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                        $skipped_count++;
                        continue;
                    }

                    $stmt_check = $conn->prepare('SELECT id FROM customers WHERE tenant_id = ? AND email = ?');
                    $stmt_check->bind_param('is', TENANT_ID, $customer['email']);
                    $stmt_check->execute();
                    $existing_customer_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    
                    // Enforce password complexity for new or updated passwords
                    $password_hash = null;
                    $temp_password = $customer['password'] ?? '';
                    if (!empty($temp_password) && (strlen($temp_password) < 8 || !preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $temp_password))) {
                        $skipped_count++; // Skip if provided password is too weak
                        continue;
                    }
                    $password_hash = password_hash($temp_password, PASSWORD_BCRYPT);

                    if ($existing_customer_id) {
                        if ($conflict_resolution === 'update') {
                            $phone = $customer['phone'] ?? null;
                            $address = $customer['address'] ?? null;
                            $is_active = (int) ($customer['is_active'] ?? 1);
                            $stmt_update->bind_param(
                                'ssssiis',
                                $customer['name'],
                                $phone,
                                $password_hash,
                                $address,
                                $is_active,
                                TENANT_ID,
                                $customer['email']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'isssssi',
                            TENANT_ID,
                            $customer['name'],
                            $customer['phone'] ?? null,
                            $customer['email'],
                            $password_hash,
                            $customer['address'] ?? null,
                            $customer['is_active'] ?? 1
                        );
                        $stmt_insert->execute();
                        $new_count++;
                    }
                }
                $stmt_insert->close();
                $stmt_update->close();
                $conn->commit();
                $message_type = 'success';
                $message = "Customers imported: $new_count new, $updated_count updated, $skipped_count skipped.";
                log_action($conn, 'Customers Import', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during customer import: ' . $e->getMessage();
                log_action($conn, 'Customers Import Failed', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            }
            break;
        case 'import_suppliers_action':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('suppliers')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $conflict_resolution = $_POST['import_conflict_suppliers'] ?? 'skip';
            if (!isset($_FILES['import_suppliers_file']) || $_FILES['import_suppliers_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'No file uploaded or an error occurred during upload.';
                break;
            }
            $file_content = file_get_contents($_FILES['import_suppliers_file']['tmp_name']);
            $suppliers_data = json_decode($file_content, true);
            if (!is_array($suppliers_data)) {
                $message = 'Invalid JSON file. Expected an array of suppliers.';
                break;
            }
            $new_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $conn->begin_transaction();
            try {
                $stmt_insert = $conn->prepare('INSERT INTO suppliers (tenant_id, name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt_update = $conn->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, address=? WHERE tenant_id = ? AND email=?');
                foreach ($suppliers_data as $supplier) {
                    if (!isset($supplier['name']) || !isset($supplier['email'])) {
                        $skipped_count++;
                        continue;
                    }
                    if (!filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
                        $skipped_count++;
                        continue;
                    }

                    $stmt_check = $conn->prepare('SELECT id FROM suppliers WHERE tenant_id = ? AND email = ?');
                    $stmt_check->bind_param('is', TENANT_ID, $supplier['email']);
                    $stmt_check->execute();
                    $existing_supplier_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    if ($existing_supplier_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                'ssssis',
                                $supplier['name'],
                                $supplier['contact_person'] ?? null,
                                $supplier['phone'] ?? null,
                                $supplier['address'] ?? null,
                                TENANT_ID,
                                $supplier['email']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'isssss',
                            TENANT_ID,
                            $supplier['name'],
                            $supplier['contact_person'] ?? null,
                            $supplier['phone'] ?? null,
                            $supplier['email'],
                            $supplier['address'] ?? null
                        );
                        $stmt_insert->execute();
                        $new_count++;
                    }
                }
                $stmt_insert->close();
                $stmt_update->close();
                $conn->commit();
                $message_type = 'success';
                $message = "Suppliers imported: $new_count new, $updated_count updated, $skipped_count skipped.";
                log_action($conn, 'Suppliers Import', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during supplier import: ' . $e->getMessage();
                log_action($conn, 'Suppliers Import Failed', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            }
            break;
        case 'export_all_data':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('backup-restore')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }

            $all_data = [];
            $tables = ['books', 'customers', 'suppliers', 'sales', 'sale_items', 'purchase_orders', 'po_items', 'expenses', 'promotions', 'online_orders', 'online_order_items', 'public_news', 'public_sale_links', 'users', 'roles', 'role_page_permissions', 'tenant_settings'];

            foreach ($tables as $table) {
                $tenant_specific_tables = ['books', 'customers', 'suppliers', 'sales', 'sale_items', 'purchase_orders', 'po_items', 'expenses', 'promotions', 'online_orders', 'online_order_items', 'public_news', 'public_sale_links', 'users', 'roles', 'role_page_permissions', 'tenant_settings'];
                
                if (in_array($table, $tenant_specific_tables)) {
                    // For tenant-specific tables, fetch only current tenant's data
                    if ($table === 'role_page_permissions') {
                        $stmt = $conn->prepare("SELECT rpp.* FROM role_page_permissions rpp JOIN roles r ON rpp.role_id = r.id WHERE r.tenant_id = ?");
                        $stmt->bind_param('i', TENANT_ID);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                            $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                        } else {
                            error_log('Failed to fetch data for table: ' . $table . ' - ' . $conn->error);
                        }
                        $stmt->close();
                    } elseif ($table === 'tenant_settings') {
                         $stmt = $conn->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?');
                         $stmt->bind_param('i', TENANT_ID);
                         $stmt->execute();
                         $result = $stmt->get_result();
                         if ($result) {
                            $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                         } else {
                            error_log('Failed to fetch data for table: ' . $table . ' - ' . $conn->error);
                         }
                         $stmt->close();
                    } else {
                        $stmt = $conn->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id = ?');
                        $stmt->bind_param('i', TENANT_ID);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                            $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                        } else {
                            error_log('Failed to fetch data for table: ' . $table . ' - ' . $conn->error);
                        }
                        $stmt->close();
                    }
                }
            }

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . TENANT_SLUG . '_data_backup_' . date('Y-m-d_H-i-s') . '.json"');
            echo json_encode($all_data, JSON_PRETTY_PRINT);
            log_action($conn, 'Tenant Data Export', 'Exported all tenant data.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            exit();

        case 'import_all_data':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('backup-restore')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'No file uploaded or an error occurred during upload.';
                break;
            }
            $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
            $imported_data = json_decode($file_content, true);
            if (!is_array($imported_data)) {
                $message = 'Invalid JSON file. Expected an object with table data.';
                break;
            }

            // Perform basic hash validation and integrity check
            $received_hash = $_POST['data_hash'] ?? ''; // Assuming hash is sent from client-side for validation
            if (!empty($received_hash)) {
                $calculated_hash = md5($file_content);
                if ($received_hash !== $calculated_hash) {
                    $message = 'Data integrity check failed: file hash mismatch.';
                    log_action($conn, 'Tenant Data Import Failed (Integrity)', 'Hash mismatch for data import. Expected: ' . $received_hash . ', Calculated: ' . $calculated_hash, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                    break;
                }
            }
            
            // Define a strict order for deletion and insertion to respect foreign key constraints
            $tables_in_order = ['roles', 'users', 'customers', 'suppliers', 'books', 'promotions', 'expenses', 'purchase_orders', 'po_items', 'sales', 'sale_items', 'online_orders', 'online_order_items', 'public_news', 'public_sale_links', 'tenant_settings'];
            $tables_delete_order = array_reverse($tables_in_order);

            $conn->begin_transaction();
            try {
                // Temporarily disable foreign key checks for deletion
                $conn->query('SET FOREIGN_KEY_CHECKS = 0');

                // Delete only current tenant's data
                foreach ($tables_delete_order as $table) {
                    $has_tenant_id_column = column_exists($conn, $table, 'tenant_id');
                    if ($table === 'role_page_permissions') {
                        $conn->query("DELETE FROM role_page_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = " . TENANT_ID . ")");
                    } elseif ($has_tenant_id_column) {
                        $conn->query('DELETE FROM ' . $table . ' WHERE tenant_id = ' . TENANT_ID);
                    }
                }

                // Re-enable foreign key checks for insertion
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($tables_in_order as $table) {
                    if (isset($imported_data[$table]) && is_array($imported_data[$table])) {
                        if (empty($imported_data[$table]))
                            continue;

                        $insert_data_for_tenant = [];
                        foreach($imported_data[$table] as $row) {
                            if (isset($row['tenant_id']) && $row['tenant_id'] == TENANT_ID) { // Ensure tenant_id matches the current tenant
                                $insert_data_for_tenant[] = $row;
                            } elseif (!isset($row['tenant_id']) && in_array($table, ['roles', 'users', 'customers', 'suppliers', 'books', 'promotions', 'expenses', 'purchase_orders', 'online_orders', 'public_news', 'public_sale_links'])) {
                                // For tables that should have tenant_id but it's missing in backup, assume it's for current tenant if no tenant_id is explicitly specified.
                                // Or, more safely, skip if tenant_id is missing where expected.
                                // For now, we'll assume the backup is only for this tenant and add tenant_id if not present for consistency.
                                $row['tenant_id'] = TENANT_ID;
                                $insert_data_for_tenant[] = $row;
                            } elseif ($table === 'tenant_settings') {
                                // tenant_settings has unique tenant_id, extract and update
                                $insert_data_for_tenant[] = $row;
                            } elseif (in_array($table, ['po_items', 'sale_items', 'online_order_items', 'role_page_permissions'])) {
                                // For items tables, rely on parent IDs already being isolated by tenant
                                $insert_data_for_tenant[] = $row;
                            }
                        }

                        if (empty($insert_data_for_tenant)) continue;

                        $first_row = $insert_data_for_tenant[0];
                        $columns = array_keys($first_row);
                        
                        // Dynamically add tenant_id if it's a tenant-specific table and not already in columns
                        if (in_array($table, ['books', 'customers', 'suppliers', 'sales', 'purchase_orders', 'expenses', 'promotions', 'online_orders', 'public_news', 'public_sale_links', 'users', 'roles']) && !in_array('tenant_id', $columns)) {
                            array_unshift($columns, 'tenant_id'); // Add tenant_id to the beginning
                            foreach ($insert_data_for_tenant as &$row) {
                                $row = ['tenant_id' => TENANT_ID] + $row; // Prepend tenant_id to each row
                            }
                            $first_row = $insert_data_for_tenant[0];
                            $columns = array_keys($first_row);
                        }

                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $column_names = implode(', ', $columns);
                        
                        $stmt = $conn->prepare('INSERT INTO ' . $table . ' (' . $column_names . ') VALUES (' . $placeholders . ')');
                        
                        $types = '';
                        foreach ($columns as $col) {
                            $sample_value = $first_row[$col];
                            if (is_int($sample_value)) {
                                $types .= 'i';
                            } elseif (is_float($sample_value)) {
                                $types .= 'd';
                            } elseif (is_bool($sample_value)) {
                                $types .= 'i';
                            } else {
                                $types .= 's';
                            }
                        }

                        foreach ($insert_data_for_tenant as $row) {
                            $values = [];
                            foreach ($columns as $col) {
                                $value = $row[$col];
                                if (is_bool($value)) {
                                    $values[] = (int) $value;
                                } else {
                                    $values[] = $value;
                                }
                            }
                            $stmt->bind_param($types, ...$values);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }
                }
                
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                $conn->commit();
                $message_type = 'success';
                $message = 'All data imported successfully!';
                log_action($conn, 'Tenant Data Import', 'Successfully imported tenant data.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                $message = 'Error during data import: ' . $e->getMessage();
                log_action($conn, 'Tenant Data Import Failed', $message, 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            }
            break;
        case 'save_public_sale_link':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('public-sale-links')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $link_id = $_POST['link_id'] ?? null;
            $link_name = trim($_POST['link_name'] ?? '');
            $access_password = $_POST['access_password'] ?? '';
            $price_mode = ($_POST['price_mode'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
            $notes = trim($_POST['notes'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            if ($link_name === '') {
                $message = 'Link name is required.';
                break;
            }
            if (!$link_id && $access_password === '') {
                $message = 'Password is required for a new secure sale link.';
                break;
            }
            if (!empty($access_password) && (strlen($access_password) < 8 || !preg_match($special_char_regex, $access_password))) {
                $message = 'Password must be at least 8 characters long and contain at least one special character.';
                break;
            }

            if ($link_id) {
                if ($access_password !== '') {
                    $password_hash = password_hash($access_password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare('UPDATE public_sale_links SET link_name=?, password_hash=?, price_mode=?, notes=?, is_active=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('ssssiii', $link_name, $password_hash, $price_mode, $notes, $is_active, TENANT_ID, $link_id);
                } else {
                    $stmt = $conn->prepare('UPDATE public_sale_links SET link_name=?, price_mode=?, notes=?, is_active=? WHERE tenant_id = ? AND id=?');
                    $stmt->bind_param('sssiii', $link_name, $price_mode, $notes, $is_active, TENANT_ID, $link_id);
                }
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Secure sale link updated successfully.';
                    log_action($conn, 'Secure Link Update', 'Updated secure sale link: ' . $link_name . ' (ID: ' . $link_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to update secure sale link: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $token = str_replace('-', '', generate_uuid()) . substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
                $password_hash = password_hash($access_password, PASSWORD_BCRYPT);
                $created_by = $_SESSION['user_id'];
                $stmt = $conn->prepare('INSERT INTO public_sale_links (tenant_id, token, link_name, password_hash, price_mode, created_by, notes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssii', TENANT_ID, $token, $link_name, $password_hash, $price_mode, $created_by, $notes, $is_active);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Secure sale link created successfully.';
                    log_action($conn, 'Secure Link Create', 'Created new secure sale link: ' . $link_name . ' (ID: ' . $conn->insert_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                } else {
                    $message = 'Failed to create secure sale link: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_public_sale_link':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('public-sale-links')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $link_id = (int) ($_POST['link_id'] ?? 0);
            if ($link_id <= 0) {
                $message = 'Link ID not provided.';
                break;
            }
            $stmt = $conn->prepare('SELECT link_name FROM public_sale_links WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $link_id);
            $stmt->execute();
            $link_name = $stmt->get_result()->fetch_assoc()['link_name'] ?? 'Unknown Link';
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM public_sale_links WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('ii', TENANT_ID, $link_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Secure sale link deleted.';
                log_action($conn, 'Secure Link Delete', 'Deleted secure sale link: ' . $link_name . ' (ID: ' . $link_id . ')', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to delete secure sale link: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'toggle_public_sale_link':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            if (!hasPlanAccess('public-sale-links')) {
                $message = 'Your plan does not allow access to this feature.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $link_id = (int) ($_POST['link_id'] ?? 0);
            $current_status = (int) ($_POST['current_status'] ?? 0);
            $new_status = $current_status ? 0 : 1;
            $stmt = $conn->prepare('UPDATE public_sale_links SET is_active = ? WHERE tenant_id = ? AND id = ?');
            $stmt->bind_param('iii', $new_status, TENANT_ID, $link_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Secure sale link status updated.';
                log_action($conn, 'Secure Link Status Toggle', 'Toggled status for secure sale link ID: ' . $link_id . ' to ' . ($new_status ? 'Active' : 'Inactive'), 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to update secure sale link status: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'public_sale_login':
            $token = trim($_POST['token'] ?? '');
            $access_password = $_POST['access_password'] ?? '';
            $link = current_public_sale_link($conn, $token);
            if (!$link || !password_verify($access_password, $link['password_hash'])) {
                $message = 'Invalid password or inactive sale link.';
                log_action($conn, 'Public Sale Login Failed', 'Failed login attempt for public sale link token: ' . $token, 'public', TENANT_ID);
                break;
            }
            if (!isset($_SESSION['public_sale_access'])) {
                $_SESSION['public_sale_access'] = [];
            }
            if (!isset($_SESSION['public_sale_access'][TENANT_ID])) {
                $_SESSION['public_sale_access'][TENANT_ID] = [];
            }
            $_SESSION['public_sale_access'][TENANT_ID][$token] = [
                'granted_at' => time(),
                'link_id' => $link['id'],
                'price_mode' => $link['price_mode']
            ];
            $message_type = 'success';
            $message = 'Secure sale link unlocked.';
            log_action($conn, 'Public Sale Login', 'Public sale link unlocked. Token: ' . $token, 'public', TENANT_ID);
            redirect('public-sale', ['token' => $token]);
            break;
        case 'submit_public_sale':
            $token = trim($_POST['token'] ?? '');
            $cart_items_json = $_POST['cart_items'] ?? '[]';
            $cart_items = json_decode($cart_items_json, true);
            $link = current_public_sale_link($conn, $token);
            if (!$link || !has_public_sale_access($token)) {
                $message = 'This sale link is locked or expired.';
                log_action($conn, 'Public Sale Failed (Expired)', 'Public sale submission failed due to expired/locked link. Token: ' . $token, 'public', TENANT_ID);
                break;
            }
            if (empty($cart_items) || !is_array($cart_items)) {
                $message = 'No products in the secure sale cart.';
                break;
            }
            if ($app_in_read_only_mode) {
                $message = 'Tenant account is in read-only mode. Renew subscription to regain full access.';
                break;
            }
            $conn->begin_transaction();
            try {
                $subtotal = 0;
                $price_mode = $link['price_mode'];
                foreach ($cart_items as &$cart_item) {
                    $book_id = (int) ($cart_item['bookId'] ?? 0);
                    $stmt_book = $conn->prepare('SELECT id, name, stock, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price FROM books WHERE tenant_id = ? AND id = ? LIMIT 1');
                    $stmt_book->bind_param('ii', TENANT_ID, $book_id);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < (int) $cart_item['quantity']) {
                        throw new Exception('Not enough stock for ' . ($book_data['name'] ?? 'selected product') . '.');
                    }
                    $unit_price = $price_mode === 'wholesale' ? ($book_data['wholesale_price'] ?: $book_data['retail_price']) : ($book_data['retail_price'] ?: $book_data['price']);
                    $cart_item['price_per_unit'] = $unit_price;
                    $cart_item['quantity'] = (int) $cart_item['quantity'];
                    $subtotal += ($unit_price * $cart_item['quantity']);
                }
                unset($cart_item);
                $creator_user_id = !empty($link['created_by']) ? (int) $link['created_by'] : null;
                $promotion_code = 'PUBLIC-LINK-' . $link['id'] . '-' . strtoupper($price_mode);
                $stmt_sale = $conn->prepare('INSERT INTO sales (tenant_id, customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (?, NULL, ?, ?, 0, ?, ?)');
                $stmt_sale->bind_param('iidds', TENANT_ID, $creator_user_id, $subtotal, $subtotal, $promotion_code);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, 0)');
                $stmt_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE tenant_id = ? AND id = ?');
                foreach ($cart_items as $item) {
                    $book_id = (int) $item['bookId'];
                    $quantity = (int) $item['quantity'];
                    $price_per_unit = (float) $item['price_per_unit'];
                    $stmt_sale_item->bind_param('iiid', $sale_id, $book_id, $quantity, $price_per_unit);
                    $stmt_sale_item->execute();
                    $stmt_stock->bind_param('iii', $quantity, TENANT_ID, $book_id);
                    $stmt_stock->execute();
                }
                $stmt_sale_item->close();
                $stmt_stock->close();
                $conn->commit();
                $_SESSION['public_sale_last_receipt'][TENANT_ID][$token] = $sale_id;
                $message_type = 'success';
                $message = 'Sale completed successfully at ' . strtoupper($price_mode) . ' rate.';
                log_action($conn, 'Public Sale Complete', 'Completed public sale (ID: ' . $sale_id . ') via secure link: ' . $token, 'public', TENANT_ID);
                redirect('public-sale', ['token' => $token]);
            } catch (Exception $e) {
                $conn->rollback();
                log_action($conn, 'Public Sale Failed', 'Public sale submission failed: ' . $e->getMessage() . '. Token: ' . $token, 'public', TENANT_ID);
                $message = 'Secure sale failed: ' . $e->getMessage();
            }
            break;
        case 'register_tenant':
            $name = $_POST['name'] ?? '';
            $slug = $_POST['slug'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $captcha = $_POST['captcha'] ?? '';
            $invitation_code = $_POST['invitation_code'] ?? null;
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';

            if (empty($_SESSION['captcha']) || strtolower($captcha) !== strtolower($_SESSION['captcha'])) {
                $message = 'Invalid CAPTCHA.';
                break;
            }
            if (empty($name) || empty($slug) || empty($email) || empty($password) || empty($confirm_password)) {
                $message = 'All required fields must be filled.';
                break;
            }
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                $message = 'Slug can only contain lowercase letters, numbers, and hyphens.';
                break;
            }
            if ($slug === SUPERADMIN_SLUG) {
                $message = 'This slug is reserved.';
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Invalid email format.';
                break;
            }
            if ($password !== $confirm_password) {
                $message = 'Passwords do not match.';
                break;
            }
            if (strlen($password) < 8 || !preg_match($special_char_regex, $password)) {
                $message = 'Password must be at least 8 characters long and contain at least one special character.';
                break;
            }

            $stmt_check = $conn->prepare('SELECT id FROM tenants WHERE slug = ?');
            $stmt_check->bind_param('s', $slug);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'Tenant slug already exists. Please choose another one.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();

            $stmt_check = $conn->prepare('SELECT id FROM tenants WHERE email = ?');
            $stmt_check->bind_param('s', $email);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'An account with this email already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();

            $conn->begin_transaction();
            try {
                // Insert new tenant with default trial (6 days)
                $sub_end_date = date('Y-m-d', strtotime('+6 days')); // 6-day trial
                $default_plan_id = null; // No plan initially, plan assigned after payment
                $stmt = $conn->prepare('INSERT INTO tenants (name, slug, email, contact_phone, subscription_end_date, plan_id, status, invitation_code, is_active) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, 0)');
                $stmt->bind_param('sssssis', $name, $slug, $email, $phone, $sub_end_date, $default_plan_id, $invitation_code);
                $stmt->execute();
                $new_tenant_id = $conn->insert_id;
                $stmt->close();

                // Create default admin role
                $stmt_role = $conn->prepare('INSERT INTO roles (tenant_id, name, is_tenant_admin) VALUES (?, ?, 1)');
                $admin_role_name = 'Admin';
                $stmt_role->bind_param('is', $new_tenant_id, $admin_role_name);
                $stmt_role->execute();
                $admin_role_id = $conn->insert_id;
                $stmt_role->close();

                // Assign all app pages to admin role initially
                $tenant_app_pages_full = ['dashboard', 'books', 'users', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'online-orders', 'promotions', 'expenses', 'reports', 'live-sales', 'news', 'settings', 'public-sale-links', 'print-barcodes', 'backup-restore', 'customer-dashboard', 'online-shop-cart', 'my-orders', 'profile', 'subscription'];
                $stmt_perm = $conn->prepare('INSERT INTO role_page_permissions (role_id, page_key) VALUES (?, ?)');
                foreach ($tenant_app_pages_full as $page_key) {
                    $stmt_perm->bind_param('is', $admin_role_id, $page_key);
                    $stmt_perm->execute();
                }
                $stmt_perm->close();

                // Create initial admin user for the tenant
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt_user = $conn->prepare('INSERT INTO users (tenant_id, username, password_hash, role_id) VALUES (?, ?, ?, ?)');
                $username_clean = str_replace(['@', '.', '-'], '', explode('@', $email)[0]);
                $stmt_user->bind_param('issi', $new_tenant_id, $username_clean, $password_hash, $admin_role_id);
                $stmt_user->execute();
                $admin_user_id = $conn->insert_id;
                $stmt_user->close();

                // Set default tenant settings
                $default_tenant_settings = [
                    'system_name' => $name . ' Bookshop',
                    'about_story' => 'Welcome to ' . $name . ' Bookshop. We are dedicated to providing the best selection of books and products.',
                    'mission' => 'To be the leading bookshop and general store for our community.',
                    'vision' => 'To enrich lives through knowledge and quality products.',
                    'address' => 'Your Store Address, City, Country',
                    'phone' => $phone,
                    'whatsapp_number' => $phone,
                    'email' => $email,
                    'google_map_embed_url' => '',
                    'facebook_url' => '',
                    'instagram_url' => '',
                    'currency_symbol' => 'PKR ',
                    'public_site_enabled' => '1',
                ];
                $stmt_setting = $conn->prepare('INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)');
                foreach ($default_tenant_settings as $key => $value) {
                    $stmt_setting->bind_param('iss', $new_tenant_id, $key, $value);
                    $stmt_setting->execute();
                }
                $stmt_setting->close();

                // Setup default PWA settings for the new tenant
                $pwa_app_name = $name . ' App';
                $pwa_short_name = $name;
                $stmt_pwa = $conn->prepare('INSERT INTO pwa_settings (tenant_id, app_name, short_name, theme_color, background_color) VALUES (?, ?, ?, ?, ?)');
                $default_theme_color = '#2a9d8f';
                $default_bg_color = '#ffffff';
                $stmt_pwa->bind_param('issss', $new_tenant_id, $pwa_app_name, $pwa_short_name, $default_theme_color, $default_bg_color);
                $stmt_pwa->execute();
                $stmt_pwa->close();
                
                $conn->commit();
                $message_type = 'success';
                $message = 'Tenant registration successful! Your trial account for ' . html($name) . ' is now active. You will receive an email shortly with your login details. Please proceed to your tenant login.';
                log_action($conn, 'Tenant Registration', 'New tenant registered: ' . $name . ' (Slug: ' . $slug . ') with a 6-day trial.', 'public', $new_tenant_id);
                redirect($slug . '/login', ['toast_type' => 'success', 'toast_message' => $message]);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Tenant registration failed: ' . $e->getMessage();
                log_action($conn, 'Tenant Registration Failed', 'Tenant registration failed for slug: ' . $slug . '. Error: ' . $e->getMessage(), 'public');
            }
            break;
        case 'submit_subscription_payment':
            if (!TENANT_ID) {
                $message = 'Tenant not found.';
                break;
            }
            if (!isTenantAdmin()) {
                $message = 'Unauthorized access.';
                break;
            }
            $plan_id = $_POST['plan_id'] ?? null;
            $months = (int)($_POST['months'] ?? 5);
            $total_amount = (float)($_POST['total_amount'] ?? 0);

            if (empty($plan_id) || $months <= 0 || $total_amount <= 0) {
                $message = 'Invalid subscription details provided.';
                break;
            }

            $stmt_plan = $conn->prepare('SELECT price_per_month, enable_file_uploads FROM subscription_plans WHERE id = ?');
            $stmt_plan->bind_param('i', $plan_id);
            $stmt_plan->execute();
            $plan_details = $stmt_plan->get_result()->fetch_assoc();
            $stmt_plan->close();

            if (!$plan_details) {
                $message = 'Selected plan not found.';
                break;
            }

            $calculated_amount = $plan_details['price_per_month'] * $months;
            if (abs($calculated_amount - $total_amount) > 0.01) { // Allow for floating point discrepancies
                $message = "Payment amount mismatch. Expected: " . format_currency($calculated_amount) . ", Received: " . format_currency($total_amount) . ".";
                $message_type = 'warning';
                log_action($conn, 'Subscription Payment Failed (Underpayment)', 'Attempted payment for ' . format_currency($total_amount) . ' for plan ' . $plan_id . ', expected ' . format_currency($calculated_amount) . '.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
                $_SESSION['toast'] = ['type' => $message_type, 'message' => $message];
                redirect('subscription');
            }

            $payment_proof_path = null;
            $target_dir = UPLOAD_DIR . '/' . TENANT_SLUG . '/payment_proofs/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['payment_proof']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf']; // Allow PDF for payment proofs
                if (in_array($file_ext, $allowed_ext)) {
                    $new_file_name = uniqid('payment_') . '.' . $file_ext;
                    $destination = $target_dir . $new_file_name;
                    if (move_uploaded_file($file_tmp_name, $destination)) {
                        $payment_proof_path = $destination;
                    } else {
                        $message = 'Failed to upload payment proof.';
                        break;
                    }
                } else {
                    $message = 'Only JPG, JPEG, PNG, PDF files are allowed for payment proof.';
                    break;
                }
            } else {
                $message = 'Payment proof is required.';
                break;
            }

            $stmt = $conn->prepare('INSERT INTO subscription_payments (tenant_id, plan_id, amount, months_subscribed, payment_proof_path, status) VALUES (?, ?, ?, ?, ?, "pending")');
            $stmt->bind_param('iidss', TENANT_ID, $plan_id, $total_amount, $months, $payment_proof_path);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Payment submitted successfully! It will be reviewed by the Superadmin shortly.';
                log_action($conn, 'Subscription Payment Submit', 'Submitted payment for ' . format_currency($total_amount) . ' for plan ' . $plan_details['name'] . '.', 'tenant_admin', TENANT_ID, $_SESSION['user_id']);
            } else {
                $message = 'Failed to submit payment: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'update_tenant_status': // Superadmin only
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $tenant_id_to_update = $_POST['tenant_id'] ?? null;
            $new_status = $_POST['status'] ?? null;
            $new_plan_id = $_POST['plan_id'] ?? null;
            $allow_uploads = isset($_POST['allow_uploads']) ? 1 : 0;
            $extend_months = (int)($_POST['extend_months'] ?? 0);
            $rejection_reason = $_POST['rejection_reason'] ?? null;
            $payment_id = $_POST['payment_id'] ?? null;
            $delete_proof_file = isset($_POST['delete_proof_file']) ? true : false;
            
            if (empty($tenant_id_to_update) || empty($new_status)) {
                $message = 'Invalid tenant or status provided.';
                break;
            }

            $conn->begin_transaction();
            try {
                $current_tenant_data_stmt = $conn->prepare('SELECT subscription_end_date, status, plan_id FROM tenants WHERE id = ?');
                $current_tenant_data_stmt->bind_param('i', $tenant_id_to_update);
                $current_tenant_data_stmt->execute();
                $current_tenant_data = $current_tenant_data_stmt->get_result()->fetch_assoc();
                $current_tenant_data_stmt->close();

                if (!$current_tenant_data) {
                    throw new Exception('Tenant not found.');
                }

                $update_tenant_sql = 'UPDATE tenants SET status=?, allow_uploads=?';
                $update_tenant_types = 'sii';
                $update_tenant_params = [$new_status, $allow_uploads];

                if ($new_plan_id) {
                    $update_tenant_sql .= ', plan_id = ?';
                    $update_tenant_types .= 'i';
                    $update_tenant_params[] = $new_plan_id;
                    
                    // Update tenant's allow_uploads based on the selected plan
                    $stmt_plan_uploads = $conn->prepare('SELECT enable_file_uploads FROM subscription_plans WHERE id = ?');
                    $stmt_plan_uploads->bind_param('i', $new_plan_id);
                    $stmt_plan_uploads->execute();
                    $plan_upload_status = $stmt_plan_uploads->get_result()->fetch_assoc()['enable_file_uploads'] ?? 0;
                    $stmt_plan_uploads->close();

                    // Allow_uploads can be overridden by superadmin, but default to plan setting if not explicitly set
                    if (!isset($_POST['allow_uploads'])) { // If checkbox was not present, use plan default
                         $update_tenant_params[array_search($allow_uploads, $update_tenant_params)] = $plan_upload_status; // Update the allow_uploads parameter in the array
                    }
                }
                
                if ($extend_months > 0 && $new_status === 'active') {
                    $current_end_date = new DateTime($current_tenant_data['subscription_end_date'] ?? date('Y-m-d'));
                    if ($current_end_date < new DateTime(date('Y-m-d'))) { // If already expired, start from today
                        $current_end_date = new DateTime(date('Y-m-d'));
                    }
                    $current_end_date->modify("+$extend_months months");
                    $update_tenant_sql .= ', subscription_end_date = ?';
                    $update_tenant_types .= 's';
                    $update_tenant_params[] = $current_end_date->format('Y-m-d');
                }
                $update_tenant_sql .= ' WHERE id = ?';
                $update_tenant_types .= 'i';
                $update_tenant_params[] = $tenant_id_to_update;

                $stmt_update_tenant = $conn->prepare($update_tenant_sql);
                $stmt_update_tenant->bind_param($update_tenant_types, ...$update_tenant_params);
                $stmt_update_tenant->execute();
                $stmt_update_tenant->close();

                if ($payment_id) {
                    $payment_sql = 'UPDATE subscription_payments SET status = ?, rejection_reason = ?, processed_by_superadmin = ?';
                    $payment_types = 'ssi';
                    $payment_params = [$new_status === 'active' ? 'approved' : 'rejected', $rejection_reason, $_SESSION['superadmin_id']];

                    $stmt_proof = $conn->prepare('SELECT payment_proof_path FROM subscription_payments WHERE id = ?');
                    $stmt_proof->bind_param('i', $payment_id);
                    $stmt_proof->execute();
                    $proof_path = $stmt_proof->get_result()->fetch_assoc()['payment_proof_path'] ?? null;
                    $stmt_proof->close();

                    if ($delete_proof_file && $proof_path && file_exists($proof_path)) {
                        unlink($proof_path);
                        $payment_sql .= ', payment_proof_path = NULL';
                    }
                    $payment_sql .= ' WHERE id = ?';
                    $payment_types .= 'i';
                    $payment_params[] = $payment_id;

                    $stmt_update_payment = $conn->prepare($payment_sql);
                    $stmt_update_payment->bind_param($payment_types, ...$payment_params);
                    $stmt_update_payment->execute();
                    $stmt_update_payment->close();
                }
                
                // Reward inviter if applicable
                $stmt_inviter = $conn->prepare('SELECT t.invitation_code, inviter.id AS inviter_id, inviter.subscription_end_date FROM tenants t JOIN tenants inviter ON t.invitation_code = inviter.invitation_code WHERE t.id = ? AND t.status = "active" AND inviter.id IS NOT NULL');
                $stmt_inviter->bind_param('i', $tenant_id_to_update);
                $stmt_inviter->execute();
                $inviter_info = $stmt_inviter->get_result()->fetch_assoc();
                $stmt_inviter->close();

                if ($inviter_info && $extend_months > 0) { // Only reward if the payment adds new months
                    $inviter_id = $inviter_info['inviter_id'];
                    $inviter_sub_end = new DateTime($inviter_info['subscription_end_date'] ?? date('Y-m-d'));
                    if ($inviter_sub_end < new DateTime(date('Y-m-d'))) { // If inviter's subscription is expired, start bonus from today
                         $inviter_sub_end = new DateTime(date('Y-m-d'));
                    }
                    $inviter_sub_end->modify('+1 month'); // Add 1 month bonus
                    $stmt_update_inviter = $conn->prepare('UPDATE tenants SET subscription_end_date = ? WHERE id = ?');
                    $stmt_update_inviter->bind_param('si', $inviter_sub_end->format('Y-m-d'), $inviter_id);
                    $stmt_update_inviter->execute();
                    $stmt_update_inviter->close();
                    log_action($conn, 'Inviter Reward', 'Tenant ID ' . $inviter_id . ' received 1 month bonus for inviting Tenant ID ' . $tenant_id_to_update, 'superadmin', null, $_SESSION['superadmin_id']);
                }

                $conn->commit();
                $message_type = 'success';
                $message = 'Tenant status and subscription updated successfully!';
                log_action($conn, 'Tenant Status Update', 'Tenant ID ' . $tenant_id_to_update . ' status set to ' . $new_status . '.', 'superadmin', null, $_SESSION['superadmin_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to update tenant status: ' . $e->getMessage();
                log_action($conn, 'Tenant Status Update Failed', 'Failed to update tenant ID ' . $tenant_id_to_update . ': ' . $e->getMessage(), 'superadmin', null, $_SESSION['superadmin_id']);
            }
            break;
        case 'create_superadmin':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $special_char_regex = '/[!@#$%^&*()\-_=+{};:,<.>]/';
            if (empty($username) || empty($password) || empty($confirm_password)) {
                $message = 'All fields are required.';
                break;
            }
            if ($password !== $confirm_password) {
                $message = 'Passwords do not match.';
                break;
            }
            if (strlen($password) < 8 || !preg_match($special_char_regex, $password)) {
                $message = 'Password must be at least 8 characters long and contain at least one special character.';
                break;
            }
            $stmt_check = $conn->prepare('SELECT id FROM superadmin_users WHERE username = ?');
            $stmt_check->bind_param('s', $username);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'Username already exists. Please choose a different one.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare('INSERT INTO superadmin_users (username, password_hash) VALUES (?, ?)');
            $stmt->bind_param('ss', $username, $password_hash);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Superadmin account created successfully!';
                log_action($conn, 'Superadmin Create', 'New superadmin account created: ' . $username, 'superadmin');
            } else {
                $message = 'Failed to create Superadmin account: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_superadmin_settings':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $new_settings = [
                'system_name' => $_POST['system_name'] ?? 'Bookshop SaaS',
                'slogan' => $_POST['slogan'] ?? 'Your ultimate bookshop management solution.',
                'default_subscription_price_per_month' => (float) ($_POST['default_subscription_price_per_month'] ?? 499.00),
                'contact_email' => $_POST['contact_email'] ?? '',
                'contact_phone' => $_POST['contact_phone'] ?? '',
                'facebook_url' => $_POST['facebook_url'] ?? '',
                'twitter_url' => $_POST['twitter_url'] ?? '',
                'linkedin_url' => $_POST['linkedin_url'] ?? '',
                'default_currency_symbol' => $_POST['default_currency_symbol'] ?? 'PKR ',
            ];

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO superadmin_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
                foreach ($new_settings as $key => $value) {
                    $stmt->bind_param('sss', $key, $value, $value);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
                $message_type = 'success';
                $message = 'Superadmin settings updated successfully!';
                log_action($conn, 'Superadmin Settings Update', 'Updated global system settings.', 'superadmin', null, $_SESSION['superadmin_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to update Superadmin settings: ' . $e->getMessage();
                log_action($conn, 'Superadmin Settings Update Failed', $message, 'superadmin', null, $_SESSION['superadmin_id']);
            }
            break;
        case 'save_plan':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $plan_id = $_POST['plan_id'] ?? null;
            $name = $_POST['name'] ?? '';
            $price_per_month = (float) ($_POST['price_per_month'] ?? 0);
            $enable_file_uploads = isset($_POST['enable_file_uploads']) ? 1 : 0;
            $description = $_POST['description'] ?? '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $permissions = $_POST['permissions'] ?? [];

            if (empty($name) || $price_per_month <= 0) {
                $message = 'Plan name and price per month are required and must be positive.';
                break;
            }

            $conn->begin_transaction();
            try {
                if ($plan_id) {
                    $stmt = $conn->prepare('UPDATE subscription_plans SET name=?, price_per_month=?, enable_file_uploads=?, description=?, is_active=? WHERE id=?');
                    $stmt->bind_param('sdsii', $name, $price_per_month, $enable_file_uploads, $description, $is_active, $plan_id);
                } else {
                    $stmt = $conn->prepare('INSERT INTO subscription_plans (name, price_per_month, enable_file_uploads, description, is_active) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param('sdsii', $name, $price_per_month, $enable_file_uploads, $description, $is_active);
                }
                $stmt->execute();
                if (!$plan_id) {
                    $plan_id = $conn->insert_id;
                }
                $stmt->close();

                $stmt_delete_perms = $conn->prepare('DELETE FROM plan_permissions WHERE plan_id = ?');
                $stmt_delete_perms->bind_param('i', $plan_id);
                $stmt_delete_perms->execute();
                $stmt_delete_perms->close();

                if (!empty($permissions)) {
                    $stmt_insert_perm = $conn->prepare('INSERT INTO plan_permissions (plan_id, page_key) VALUES (?, ?)');
                    foreach ($permissions as $page_key) {
                        $stmt_insert_perm->bind_param('is', $plan_id, $page_key);
                        $stmt_insert_perm->execute();
                    }
                    $stmt_insert_perm->close();
                }
                $conn->commit();
                $message_type = 'success';
                $message = 'Subscription plan saved successfully!';
                log_action($conn, 'Plan Save', 'Saved subscription plan: ' . $name . ' (ID: ' . $plan_id . ')', 'superadmin', null, $_SESSION['superadmin_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to save plan: ' . $e->getMessage();
                log_action($conn, 'Plan Save Failed', $message, 'superadmin', null, $_SESSION['superadmin_id']);
            }
            break;
        case 'delete_plan':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $plan_id = $_POST['plan_id'] ?? null;
            if (!$plan_id) {
                $message = 'Plan ID not provided.';
                break;
            }
            $stmt_check_tenants = $conn->prepare('SELECT COUNT(*) FROM tenants WHERE plan_id = ?');
            $stmt_check_tenants->bind_param('i', $plan_id);
            $stmt_check_tenants->execute();
            if ($stmt_check_tenants->get_result()->fetch_row()[0] > 0) {
                $message = 'Cannot delete plan assigned to active tenants.';
                $stmt_check_tenants->close();
                break;
            }
            $stmt_check_tenants->close();
            $stmt = $conn->prepare('SELECT name FROM subscription_plans WHERE id = ?');
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $plan_name = $stmt->get_result()->fetch_assoc()['name'] ?? 'Unknown Plan';
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM subscription_plans WHERE id = ?');
            $stmt->bind_param('i', $plan_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Plan deleted successfully!';
                log_action($conn, 'Plan Delete', 'Deleted plan: ' . $plan_name . ' (ID: ' . $plan_id . ')', 'superadmin', null, $_SESSION['superadmin_id']);
            } else {
                $message = 'Failed to delete plan: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_superadmin_news':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $news_id = $_POST['news_id'] ?? null;
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $visibility = $_POST['visibility'] ?? 'all_users';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $media_path = $_POST['existing_media_path'] ?? null;
            $media_type = $_POST['media_type'] ?? null;
            $media_link = $_POST['media_link'] ?? null; // For YouTube/Facebook embeds

            if (empty($title) || empty($content)) {
                $message = 'Title and content are required.';
                break;
            }
            
            // Handle media upload/link
            if ($media_type === 'youtube_embed' || $media_type === 'facebook_embed') {
                $media_path = $media_link; // Store the URL directly
                if (empty($media_link)) {
                    $message = 'Media link is required for embedded videos.';
                    break;
                }
            } elseif (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                $target_dir = UPLOAD_DIR . '/' . SUPERADMIN_SLUG . '/news_media/';
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $file_tmp_name = $_FILES['media_file']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION));
                $allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $allowed_video_ext = ['mp4', 'webm', 'ogg'];
                
                if (in_array($file_ext, $allowed_image_ext)) {
                    $media_type = 'image';
                    $new_file_name = uniqid('news_img_') . '.' . $file_ext;
                } elseif (in_array($file_ext, $allowed_video_ext)) {
                    $media_type = 'video_upload';
                    $new_file_name = uniqid('news_vid_') . '.' . $file_ext;
                } else {
                    $message = 'Only image (JPG, PNG, GIF, WEBP) or video (MP4, WEBM, OGG) files are allowed.';
                    break;
                }
                $destination = $target_dir . $new_file_name;
                if (move_uploaded_file($file_tmp_name, $destination)) {
                    if ($media_path && file_exists($media_path)) {
                        unlink($media_path); // Delete old media file if new one is uploaded
                    }
                    $media_path = $destination;
                } else {
                    $message = 'Failed to upload media file.';
                    break;
                }
            } elseif (isset($_POST['remove_media']) && $_POST['remove_media'] === 'true') {
                if ($media_path && file_exists($media_path)) {
                    unlink($media_path);
                }
                $media_path = null;
                $media_type = null;
            } else {
                // No new upload, no removal, keep existing media_path and media_type from form
                $media_path = $_POST['existing_media_path'] ?? null;
                $media_type = $_POST['existing_media_type'] ?? null;
            }

            if ($news_id) {
                $stmt = $conn->prepare('UPDATE superadmin_news SET title=?, content=?, media_path=?, media_type=?, visibility=?, is_active=? WHERE id=?');
                $stmt->bind_param('sssssii', $title, $content, $media_path, $media_type, $visibility, $is_active, $news_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'News updated successfully.';
                    log_action($conn, 'Superadmin News Update', 'Updated news: ' . $title . ' (ID: ' . $news_id . ')', 'superadmin', null, $_SESSION['superadmin_id']);
                } else {
                    $message = 'Failed to update news: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO superadmin_news (title, content, media_path, media_type, visibility, is_active) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssssi', $title, $content, $media_path, $media_type, $visibility, $is_active);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'News created successfully.';
                    log_action($conn, 'Superadmin News Create', 'Created new news: ' . $title . ' (ID: ' . $conn->insert_id . ')', 'superadmin', null, $_SESSION['superadmin_id']);
                } else {
                    $message = 'Failed to create news: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_superadmin_news':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $news_id = (int) ($_POST['news_id'] ?? 0);
            if ($news_id <= 0) {
                $message = 'News ID not provided.';
                break;
            }
            $stmt = $conn->prepare('SELECT title, media_path FROM superadmin_news WHERE id = ?');
            $stmt->bind_param('i', $news_id);
            $stmt->execute();
            $news_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($news_data && $news_data['media_path'] && file_exists($news_data['media_path'])) {
                unlink($news_data['media_path']);
            }
            $stmt = $conn->prepare('DELETE FROM superadmin_news WHERE id = ?');
            $stmt->bind_param('i', $news_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'News deleted.';
                log_action($conn, 'Superadmin News Delete', 'Deleted news: ' . ($news_data['title'] ?? 'N/A') . ' (ID: ' . $news_id . ')', 'superadmin', null, $_SESSION['superadmin_id']);
            } else {
                $message = 'Failed to delete news: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'delete_old_audit_logs':
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $threshold_date = date('Y-m-d H:i:s', strtotime('-1 month'));

            // Prepare for email attachment (if enabled, assuming an email service integration)
            $send_email_archive = isset($_POST['send_email_archive']) ? true : false;
            $archive_filepath = null;

            if ($send_email_archive) {
                $archive_data = [];
                $res_logs = $conn->query("SELECT * FROM audit_logs WHERE timestamp < '{$threshold_date}'");
                if ($res_logs) {
                    while ($row = $res_logs->fetch_assoc()) {
                        $archive_data[] = $row;
                    }
                    $archive_filename = 'audit_logs_archive_' . date('Y-m-d_H-i-s') . '.json';
                    $archive_filepath = sys_get_temp_dir() . '/' . $archive_filename;
                    file_put_contents($archive_filepath, json_encode($archive_data, JSON_PRETTY_PRINT));
                    // TODO: Implement actual email sending with attachment
                    // For now, we'll just log that an email *would* have been sent.
                    error_log('Audit log archive saved to: ' . $archive_filepath . '. Email sending not implemented.');
                }
            }

            $stmt = $conn->prepare('DELETE FROM audit_logs WHERE timestamp < ?');
            $stmt->bind_param('s', $threshold_date);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = $stmt->affected_rows . ' audit logs older than one month deleted successfully.';
                log_action($conn, 'Audit Logs Cleanup', $message, 'superadmin', null, $_SESSION['superadmin_id']);
                if ($send_email_archive && $archive_filepath && file_exists($archive_filepath)) {
                    unlink($archive_filepath); // Clean up temp file
                }
            } else {
                $message = 'Failed to delete old audit logs: ' . $stmt->error;
                log_action($conn, 'Audit Logs Cleanup Failed', $message, 'superadmin', null, $_SESSION['superadmin_id']);
            }
            $stmt->close();
            break;
        case 'sa_export_all_data': // Superadmin full system backup
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            $all_data = [];
            $tables = ['superadmin_users', 'tenants', 'subscription_plans', 'plan_permissions', 'subscription_payments', 'superadmin_settings', 'superadmin_news', 'audit_logs', 'users', 'roles', 'role_page_permissions', 'books', 'customers', 'suppliers', 'purchase_orders', 'po_items', 'sales', 'sale_items', 'online_orders', 'online_order_items', 'promotions', 'expenses', 'tenant_settings', 'public_news', 'public_sale_links', 'pwa_settings'];

            foreach ($tables as $table) {
                $result = $conn->query('SELECT * FROM ' . $table);
                if ($result) {
                    $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                } else {
                    error_log('Failed to fetch data for table: ' . $table . ' - ' . $conn->error);
                }
            }

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="saas_full_system_backup_' . date('Y-m-d_H-i-s') . '.json"');
            echo json_encode($all_data, JSON_PRETTY_PRINT);
            log_action($conn, 'Superadmin Full System Export', 'Exported full system data backup.', 'superadmin', null, $_SESSION['superadmin_id']);
            exit();

        case 'sa_import_all_data': // Superadmin full system restore
            if (!IS_SUPERADMIN_MODE) {
                $message = 'Unauthorized.';
                break;
            }
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'No file uploaded or an error occurred during upload.';
                break;
            }
            $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
            $imported_data = json_decode($file_content, true);
            if (!is_array($imported_data) && !is_object($imported_data)) {
                $message = 'Invalid JSON file. Expected an object with table data.';
                break;
            }

            $tables_ordered = [ // Carefully ordered to respect foreign keys
                'superadmin_users', 'superadmin_settings', 'superadmin_news',
                'subscription_plans',
                'tenants', // Must be before tenant-specific tables
                'pwa_settings', // Depends on tenants
                'roles', 'users', 'customers', 'suppliers', 'books', 'promotions', 'expenses', 'tenant_settings', 'public_news', 'public_sale_links',
                'plan_permissions', // Depends on subscription_plans
                'purchase_orders', 'po_items', // Depends on suppliers, books
                'sales', 'sale_items', // Depends on customers, users, books
                'online_orders', 'online_order_items', // Depends on customers, books, sales
                'audit_logs', // Can depend on various user/tenant IDs, best loaded last
            ];

            $conn->begin_transaction();
            try {
                $conn->query('SET FOREIGN_KEY_CHECKS = 0');

                // Truncate all tables
                foreach (array_reverse($tables_ordered) as $table) {
                    $conn->query('TRUNCATE TABLE ' . $table);
                }

                // Insert data
                foreach ($tables_ordered as $table) {
                    if (isset($imported_data[$table]) && is_array($imported_data[$table])) {
                        if (empty($imported_data[$table]))
                            continue;
                        
                        $columns = array_keys($imported_data[$table][0]);
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $column_names = implode(', ', $columns);
                        $stmt = $conn->prepare('INSERT INTO ' . $table . ' (' . $column_names . ') VALUES (' . $placeholders . ')');
                        $types = '';
                        foreach ($columns as $col) {
                            $sample_value = $imported_data[$table][0][$col];
                            if (is_int($sample_value)) {
                                $types .= 'i';
                            } elseif (is_float($sample_value)) {
                                $types .= 'd';
                            } elseif (is_bool($sample_value)) {
                                $types .= 'i';
                            } else {
                                $types .= 's';
                            }
                        }
                        foreach ($imported_data[$table] as $row) {
                            $values = [];
                            foreach ($columns as $col) {
                                $value = $row[$col];
                                if (is_bool($value)) {
                                    $values[] = (int) $value;
                                } else {
                                    $values[] = $value;
                                }
                            }
                            $stmt->bind_param($types, ...$values);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }
                }
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                $conn->commit();
                $message_type = 'success';
                $message = 'Full system data imported successfully!';
                log_action($conn, 'Superadmin Full System Import', 'Restored full system data backup.', 'superadmin', null, $_SESSION['superadmin_id']);
            } catch (Exception $e) {
                $conn->rollback();
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                $message = 'Error during full system data import: ' . $e->getMessage();
                log_action($conn, 'Superadmin Full System Import Failed', $message, 'superadmin', null, $_SESSION['superadmin_id']);
            }
            break;
        case 'contact_submit':
            // This is a public form, no auth checks needed
            // Basic validation and CAPTCHA already handled by JS (client-side)
            $name = html($_POST['name'] ?? 'Anonymous');
            $email = html($_POST['email'] ?? 'N/A');
            $subject = html($_POST['subject'] ?? 'No Subject');
            $message_content = html($_POST['message_content'] ?? 'No Message');

            // Log the contact message (Superadmin can view all tenant logs)
            // Use tenant_id if in tenant context, else null
            $log_tenant_id = TENANT_ID;
            $log_description = "New contact message from {$name} ({$email}). Subject: {$subject}. Message: {$message_content}";
            log_action($conn, 'Contact Form Submission', $log_description, 'public', $log_tenant_id);

            $message_type = 'success';
            $message = 'Thank you for your message! We will get back to you soon.';
            break;
        default:
            $message = 'Invalid action.';
            break;
    }
    $_SESSION['toast'] = ['type' => $message_type, 'message' => $message];
    
    $redirect_params = [];
    if (isset($_GET['token'])) {
        $redirect_params['token'] = $_GET['token'];
    }
    redirect(CURRENT_PAGE, $redirect_params);
}

// Initial setup for Superadmin
$stmt = $conn->prepare('SELECT COUNT(*) FROM superadmin_users');
$stmt->execute();
$superadmin_count = $stmt->get_result()->fetch_row()[0];
$stmt->close();

if ($superadmin_count == 0 && CURRENT_PAGE !== 'create-superadmin') {
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'No Superadmin account found. Please create one.'];
    redirect('create-superadmin');
} elseif ($superadmin_count > 0 && CURRENT_PAGE === 'create-superadmin' && !isSuperAdmin()) {
    $_SESSION['toast'] = ['type' => 'warning', 'message' => 'Superadmin account already exists. Please log in.'];
    redirect('login');
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Access Control Logic
if (IS_PUBLIC_MAIN_SITE) {
    if (!in_array(CURRENT_PAGE, $APP_PAGES)) {
        redirect('home');
    }
} elseif (IS_SUPERADMIN_MODE) {
    if (!isSuperAdmin() && CURRENT_PAGE !== 'login' && CURRENT_PAGE !== 'create-superadmin') {
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'Please log in to access this page.'];
        redirect('login');
    }
    if (!in_array(CURRENT_PAGE, $APP_PAGES) && CURRENT_PAGE !== 'create-superadmin') {
        redirect('dashboard');
    }
} else { // Tenant Mode
    if ($tenant_status_info['status'] === 'read_only' || $tenant_status_info['status'] === 'banned') {
        $allowed_pages = ['customer-login', 'customer-register', 'profile', 'subscription', 'home', 'about', 'contact', 'books-public', 'pwa-install', 'public-sale'];
        if (!in_array(CURRENT_PAGE, $allowed_pages)) {
            $_SESSION['toast'] = ['type' => 'warning', 'message' => $tenant_status_info['message']];
            if (isCustomerLoggedIn()) {
                redirect('customer-dashboard'); // Or home page?
            } else {
                redirect('login');
            }
        }
        if ($settings['public_site_enabled'] === '0' && in_array(CURRENT_PAGE, ['home', 'about', 'contact', 'books-public'])) {
            redirect('login');
        }
    } elseif (!isLoggedIn() && !in_array(CURRENT_PAGE, ['customer-login', 'customer-register', 'home', 'about', 'contact', 'books-public', 'public-sale', 'login', 'register'])) {
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'Please log in to access this page.'];
        $redirect_page = (strpos(CURRENT_PAGE, 'customer') !== false || strpos(CURRENT_PAGE, 'online') !== false || strpos(CURRENT_PAGE, 'my-orders') !== false || strpos(CURRENT_PAGE, 'profile') !== false) ? 'customer-login' : 'login';
        redirect($redirect_page);
    }
    if (!isLoggedIn() && !IS_PUBLIC_MAIN_SITE && TENANT_ID && $settings['public_site_enabled'] === '0' && in_array(CURRENT_PAGE, ['home', 'about', 'contact', 'books-public'])) {
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'This tenant\'s public website is currently disabled.'];
        redirect('login');
    }
    // Tenant specific plan permission check
    if (isTenantAdmin() || isStaff()) {
        if (!hasPlanAccess(CURRENT_PAGE) && !in_array(CURRENT_PAGE, ['login', 'dashboard', 'profile', 'subscription'])) {
             $_SESSION['toast'] = ['type' => 'error', 'message' => 'Your current subscription plan does not allow access to this feature. Please upgrade.'];
             redirect('dashboard');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="A complete General Store & Bookshop Management Web App and Public Website with PHP and MySQL. Manage products, customers, sales, suppliers, purchase orders, reports, and expenses with role-based access control. Browse available products, find out about us, and contact us.">
    <meta name="keywords" content="General Store, Bookshop, Management, Web App, PHP, MySQL, Products, Books, Stationery, Customers, Sales, Reports, Inventory, Suppliers, Purchase Orders, Expenses, Promotions, Analytics, Admin, Staff, Online Shop, Pakistan Shop, New Products">
    <meta name="author" content="Yasin Ullah, Pakistan">
    <title>
        <?php
        if (IS_SUPERADMIN_MODE) {
            echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS') . ' - Superadmin';
        } elseif (TENANT_ID) {
            echo html($settings['system_name'] ?? 'General Store & Bookshop') . ' - ' . html(TENANT_NAME);
        } else {
            echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS');
        }
        ?>
    </title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%232a9d8f' d='M18 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2m-1 15H7V5h10v12M9 7h6v2H9V7m0 4h6v2H9v-2m0 4h6v2H9v-2z'/%3C/svg%3E" type="image/svg+xml">
    <?php if (TENANT_ID && CURRENT_PAGE !== 'pwa-install'): ?>
    <link rel="manifest" href="<?php echo ROOT_URL . '/' . TENANT_SLUG; ?>/pwa-manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="<?php echo html($settings['system_name'] ?? TENANT_NAME); ?>">
    <link rel="apple-touch-icon" href="<?php echo ROOT_URL . '/' . TENANT_SLUG; ?>/pwa_icon_192.png">
    <?php endif; ?>
    <style>
        @import url("https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css");
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        :root {
            --primary-color: #2a9d8f;
            --primary-dark-color: #218579;
            --accent-color: #f4a261;
            --secondary-accent-color: #e9c46a;
            --background-color: #f8f9fa;
            --surface-color: #ffffff;
            --text-color: #343a40;
            --light-text-color: #6c757d;
            --border-color: #e2e6ea;
            --shadow-color: rgba(0, 0, 0, 0.08);
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --disabled-color: #cccccc;
        }
        [data-theme='dark'] {
            --primary-color: #55b7a8;
            --primary-dark-color: #4a9d91;
            --accent-color: #f4a261;
            --secondary-accent-color: #e9c46a;
            --background-color: #2c3e50;
            --surface-color: #34495e;
            --text-color: #ecf0f1;
            --light-text-color: #bdc3c7;
            --border-color: #4a657e;
            --shadow-color: rgba(0, 0, 0, 0.3);
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --disabled-color: #555555;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            transition: background-color 0.3s, color 0.3s;
        }
        #app-container,
        #public-site-container,
        #login-container {
            display: flex;
            flex: 1;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
            background-color: var(--background-color);
            box-shadow: var(--shadow-color) 0 0 15px;
            border-radius: 8px;
            overflow: hidden;
        }
        #public-site-container {
            flex-direction: column;
            box-shadow: none;
            border-radius: 0;
            max-width: none;
            background-color: var(--background-color);
        }
        #login-container {
            box-shadow: none;
            border-radius: 0;
            max-width: none;
            background-color: var(--background-color);
            justify-content: center;
            align-items: center;
        }
        .global-search-bar {
            background-color: var(--surface-color);
            padding: 10px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .global-search-bar input {
            flex-grow: 1;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            background-color: var(--background-color);
            color: var(--text-color);
            font-size: 0.95em;
        }
        .global-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--surface-color);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 5px 5px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0 5px 15px var(--shadow-color);
            z-index: 100;
            display: none;
        }
        .global-search-results.active {
            display: block;
        }
        .global-search-results div {
            padding: 10px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--border-color);
        }
        .global-search-results div:last-child {
            border-bottom: none;
        }
        .global-search-results div:hover {
            background-color: var(--background-color);
        }
        .global-search-results .type-label {
            font-size: 0.8em;
            color: white;
            margin-right: 8px;
            padding: 3px 6px;
            background-color: var(--primary-color);
            border-radius: 3px;
        }
        .public-header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px var(--shadow-color);
            flex-wrap: wrap;
        }
        .public-header .logo {
            font-size: 2.2em;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }
        .public-header nav ul {
            list-style: none;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .public-header nav ul li a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            font-size: 1.1em;
            padding: 5px 0;
            position: relative;
        }
        .public-header nav ul li a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--accent-color);
            transition: width 0.3s ease;
        }
        .public-header nav ul li a:hover::after,
        .public-header nav ul li a.active::after {
            width: 100%;
        }
        .public-header .login-btn {
            background-color: var(--accent-color);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }
        .public-header .login-btn:hover {
            background-color: #e09255;
        }
        .public-content {
            flex-grow: 1;
            padding: 40px 20px;
            margin: 20px auto;
            background-color: var(--surface-color);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow-color);
            max-width: 1200px;
            width: 100%;
        }
        .hero-section {
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark-color) 100%);
            color: white;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }
        .hero-section h1 {
            font-size: 4em;
            margin-bottom: 20px;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }
        .hero-section p {
            font-size: 1.4em;
            margin-bottom: 40px;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }
        .hero-section .btn-primary {
            padding: 18px 35px;
            font-size: 1.3em;
            border-radius: 50px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }
        .book-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .book-card {
            background-color: var(--surface-color);
            border-radius: 10px;
            box-shadow: 0 4px 15px var(--shadow-color);
            padding: 15px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .book-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .book-card img {
            max-width: 100%;
            height: 200px;
            object-fit: contain;
            border-radius: 8px;
            margin-bottom: 15px;
            background-color: var(--background-color);
            padding: 5px;
        }
        .book-card h3 {
            font-size: 1.3em;
            margin-bottom: 5px;
            color: var(--primary-color);
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
            overflow: hidden;
        }
        .book-card p {
            font-size: 0.95em;
            color: var(--light-text-color);
            margin-bottom: 10px;
        }
        .book-card .price {
            font-size: 1.2em;
            font-weight: bold;
            color: var(--accent-color);
            margin-top: auto;
            margin-bottom: 10px;
        }
        .book-card .stock-info {
            font-size: 0.85em;
            color: var(--light-text-color);
            padding-bottom: 10px;
        }
        .book-card .stock-info.low {
            color: var(--warning-color);
            font-weight: bold;
        }
        .book-card .stock-info.out {
            color: var(--danger-color);
            font-weight: bold;
            text-decoration: line-through;
        }
        .public-product-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .public-product-actions .btn {
            flex-grow: 1;
            padding: 8px 12px;
            font-size: 0.9em;
        }
        .public-footer {
            background-color: var(--surface-color);
            color: var(--light-text-color);
            padding: 30px 40px;
            text-align: center;
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -2px 5px var(--shadow-color);
            margin-top: 40px;
        }
        .public-footer p {
            margin: 0;
            font-size: 0.9em;
        }
        aside.sidebar {
            width: 280px;
            background-color: var(--surface-color);
            padding: 20px;
            box-shadow: 2px 0 5px var(--shadow-color);
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border-color);
            transition: background-color 0.3s, border-color 0.3s;
        }
        aside.sidebar h2 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.8em;
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 10px;
        }
        aside.sidebar nav ul {
            list-style: none;
            flex-grow: 1;
        }
        aside.sidebar nav ul li {
            margin-bottom: 10px;
        }
        aside.sidebar nav ul li a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: var(--light-text-color);
            text-decoration: none;
            border-radius: 6px;
            transition: background-color 0.3s, color 0.3s;
            font-weight: 500;
        }
        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        aside.sidebar nav ul li a.active {
            font-weight: 600;
        }
        aside.sidebar nav ul li a i {
            margin-right: 10px;
            font-size: 1.2em;
        }
        .user-info {
            padding: 10px 15px;
            margin-top: auto;
            border-top: 1px solid var(--border-color);
            font-size: 0.9em;
            color: var(--light-text-color);
            text-align: center;
        }
        .user-info span {
            font-weight: bold;
            color: var(--text-color);
        }
        .user-info a {
            color: var(--danger-color);
            text-decoration: none;
            font-weight: bold;
            margin-left: 5px;
        }
        .user-info a:hover {
            text-decoration: underline;
        }
        .dark-mode-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 20px;
            padding: 10px 15px;
            background-color: var(--background-color);
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        .dark-mode-toggle label {
            font-weight: 500;
            color: var(--text-color);
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked+.slider {
            background-color: var(--primary-color);
        }
        input:focus+.slider {
            box-shadow: 0 0 1px var(--primary-color);
        }
        input:checked+.slider:before {
            transform: translateX(16px);
        }
        main.content {
            flex-grow: 1;
            padding: 20px;
            background-color: var(--background-color);
            overflow-y: auto;
        }
        .page-content:not(.active) {
            display: none;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        .page-header h1 {
            font-size: 2em;
            color: var(--primary-color);
        }
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn:disabled {
            background-color: var(--disabled-color);
            cursor: not-allowed;
            opacity: 0.7;
            transform: none;
            box-shadow: none;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover:not(:disabled) {
            background-color: var(--primary-dark-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .btn-secondary {
            background-color: var(--surface-color);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover:not(:disabled) {
            background-color: var(--background-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        .btn-danger:hover:not(:disabled) {
            background-color: #d15034;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        .btn-success:hover:not(:disabled) {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        .btn-info:hover:not(:disabled) {
            background-color: #117a8b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .card {
            background-color: var(--surface-color);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow-color);
            padding: 20px;
            margin-bottom: 20px;
            transition: background-color 0.3s, border-color 0.3s;
        }
        .card-header {
            font-size: 1.3em;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .dashboard-card {
            text-align: center;
            padding: 25px;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background-color: var(--surface-color);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow-color);
            transition: transform 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        .dashboard-card h3 {
            font-size: 1.2em;
            color: var(--light-text-color);
            margin-bottom: 10px;
        }
        .dashboard-card p {
            font-size: 2.5em;
            font-weight: bold;
            color: var(--primary-color);
        }
        .dashboard-card p.danger {
            color: var(--danger-color);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: var(--surface-color);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px var(--shadow-color);
        }
        .data-table thead {
            background-color: var(--primary-color);
            color: white;
        }
        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .data-table tbody tr:hover {
            background-color: var(--background-color);
        }
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        .data-table .actions {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .data-table .actions .btn {
            padding: 6px 10px;
            font-size: 0.85em;
        }
        .low-stock {
            background-color: #fff3cd !important;
            color: var(--warning-color) !important;
        }
        [data-theme='dark'] .low-stock {
            background-color: #6a4000 !important;
            color: #ffd180 !important;
        }
        .inactive-customer {
            background-color: #fce8e6 !important;
            color: var(--danger-color) !important;
        }
        [data-theme='dark'] .inactive-customer {
            background-color: #5c3029 !important;
            color: #ff998c !important;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-color);
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="url"],
        .form-group textarea,
        .form-group select,
        .form-group input[type="date"],
        .form-group input[type="month"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            background-color: var(--background-color);
            color: var(--text-color);
            font-size: 1em;
            transition: border-color 0.3s;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background-color: var(--surface-color);
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 15px var(--shadow-color);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.5em;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: var(--light-text-color);
            transition: color 0.3s;
        }
        .modal-close:hover {
            color: var(--text-color);
        }
        #toast-container {
            position: fixed;
            top: 20px;
            <?php echo !isLoggedIn() ? 'left: 50%; transform: translateX(-50%);' : 'right: 20px;'; ?>z-index: 1001;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            background-color: var(--surface-color);
            color: var(--text-color);
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px var(--shadow-color);
            opacity: 0;
            <?php echo !isLoggedIn() ? 'transform: translateY(-100%);' : 'transform: translateX(100%);'; ?>transition: opacity 0.3s ease, transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 250px;
        }
        .toast.show {
            opacity: 1;
            <?php echo !isLoggedIn() ? 'transform: translateY(0);' : 'transform: translateX(0);'; ?>
        }
        .toast.success {
            border-left: 5px solid var(--success-color);
        }
        .toast.error {
            border-left: 5px solid var(--danger-color);
        }
        .toast.info {
            border-left: 5px solid var(--info-color);
        }
        .toast.warning {
            border-left: 5px solid var(--warning-color);
        }
        .toast i {
            font-size: 1.2em;
        }
        .search-sort-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-sort-controls .form-group {
            flex: 1;
            min-width: 180px;
            margin-bottom: 0;
        }
        .search-sort-controls .form-group label {
            display: none;
        }
        .search-sort-controls .form-group input,
        .search-sort-controls .form-group select {
            width: 100%;
        }
        #cart-items-table .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        #cart-items-table .quantity-controls input {
            width: 60px;
            text-align: center;
            padding: 5px;
        }
        #cart-summary {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.2em;
            font-weight: 600;
        }
        #cart-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .report-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .report-filters .form-group {
            flex: 1;
            min-width: 150px;
        }
        .report-controls {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .flex-group {
            display: flex;
            gap: 15px;
        }
        .flex-group .form-group {
            flex: 1;
        }
        .img-preview {
            width: 100px;
            height: 100px;
            border: 1px dashed var(--border-color);
            border-radius: 5px;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            background-color: var(--background-color);
            margin-top: 10px;
        }
        .img-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            gap: 10px;
        }
        .pagination button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .pagination button:hover:not(:disabled) {
            background-color: var(--primary-dark-color);
        }
        .pagination button:disabled {
            background-color: var(--disabled-color);
            cursor: not-allowed;
        }
        .pagination span {
            font-weight: 500;
        }
        .chart-container {
            width: 100%;
            height: 400px;
            margin-top: 20px;
            position: relative;
        }
        .promotion-type-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        .promotion-type-toggle button {
            flex: 1;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            background-color: var(--background-color);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .promotion-type-toggle button.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .item-picker {
            position: relative;
        }
        .item-picker-results {
            position: absolute;
            background-color: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            max-height: 200px;
            overflow-y: auto;
            width: 100%;
            z-index: 10;
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        .item-picker-results div {
            padding: 8px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .item-picker-results div:hover {
            background-color: var(--background-color);
        }
        #login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: var(--background-color);
            width: 100%;
        }
        .login-card {
            background-color: var(--surface-color);
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 20px var(--shadow-color);
            width: 90%;
            max-width: 400px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        .login-card h2 {
            color: var(--primary-color);
            margin-bottom: 30px;
            font-size: 2em;
        }
        .login-card .form-group {
            text-align: left;
        }
        .login-card .form-group input {
            background-color: var(--background-color);
        }
        .login-card .btn-primary {
            width: 100%;
            margin-top: 20px;
            padding: 12px;
        }
        .table-responsive {
            display: block;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        @keyframes pulse-live {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }
        #mobile-nav-toggle {
            display: none;
            margin-right: 15px;
        }
        @media (max-width: 900px) {
            #mobile-nav-toggle {
                display: inline-flex !important;
            }
        }
        @media (max-width: 992px) {
            aside.sidebar {
                width: 200px;
            }
        }
        @media (max-width: 768px) {
            #app-container {
                flex-direction: column;
                margin: 0;
                border-radius: 0;
                box-shadow: none;
            }
            aside.sidebar {
                width: 100%;
                height: auto;
                padding: 15px 20px;
                box-shadow: 0 2px 5px var(--shadow-color);
                border-bottom: 1px solid var(--border-color);
                border-right: none;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
            aside.sidebar h2 {
                margin-bottom: 0;
                border-bottom: none;
                padding-bottom: 0;
                font-size: 1.5em;
            }
            aside.sidebar nav {
                display: none;
            }
            aside.sidebar .dark-mode-toggle {
                margin-top: 0;
            }
            .hamburger-menu {
                display: block !important;
                background: none;
                border: none;
                color: var(--primary-color);
                font-size: 1.8em;
                cursor: pointer;
            }
            aside.sidebar.active {
                flex-direction: column;
                align-items: flex-start;
            }
            aside.sidebar.active nav {
                display: block;
                width: 100%;
                margin-top: 20px;
            }
            aside.sidebar.active nav ul {
                flex-direction: column;
            }
            aside.sidebar.active nav ul li {
                margin: 0;
            }
            aside.sidebar.active nav ul li a {
                padding: 15px 20px;
                border-radius: 0;
            }
            main.content {
                padding: 15px;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .page-header h1 {
                font-size: 1.8em;
            }
            .search-sort-controls,
            .report-filters,
            .flex-group {
                flex-direction: column;
                gap: 10px;
            }
            .form-actions {
                flex-direction: column;
            }
            .form-actions .btn {
                width: 100%;
            }
            .data-table th,
            .data-table td {
                padding: 8px 10px;
            }
            .data-table .actions {
                flex-direction: column;
                gap: 2px;
            }
            .data-table .actions .btn {
                width: 100%;
                text-align: center;
            }
            .hamburger-menu {
                order: -1;
            }
            .public-header {
                flex-direction: column;
                padding: 15px 20px;
            }
            .public-header .logo {
                margin-bottom: 15px;
            }
            .public-header nav ul {
                flex-direction: column;
                gap: 10px;
                margin-bottom: 15px;
            }
            .public-content {
                padding: 20px;
                margin: 10px auto;
            }
            .hero-section {
                padding: 40px 15px;
            }
            .hero-section h1 {
                font-size: 2.5em;
            }
            .hero-section p {
                font-size: 1.1em;
            }
            .book-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
            .global-search-bar {
                position: static;
                flex-wrap: wrap;
            }
            .global-search-results {
                left: 0;
                right: 0;
                width: 100%;
            }
        }
        @media (max-width: 480px) {
            .modal-content {
                padding: 20px;
            }
        }
        .hamburger-menu {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            margin-right: 10px;
        }
        .whatsapp-btn {
            background-color: #25d366;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background-color 0.3s;
        }
        .whatsapp-btn:hover {
            background-color: #128c7e;
        }
        .about-header,
        .contact-header {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark-color));
            color: white;
            border-radius: 8px;
            margin-bottom: 40px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .about-header h1,
        .contact-header h1 {
            font-size: 3em;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .about-header p,
        .contact-header p {
            font-size: 1.2em;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto;
        }
        .mission-vision-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }
        .mv-card {
            background: var(--surface-color);
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 5px 20px var(--shadow-color);
            text-align: center;
            border-top: 5px solid var(--accent-color);
            transition: transform 0.3s;
            border-left: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }
        .mv-card:hover {
            transform: translateY(-8px);
        }
        .mv-card i {
            font-size: 3em;
            color: var(--accent-color);
            margin-bottom: 20px;
        }
        .mv-card h3 {
            font-size: 1.5em;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        .mv-card p {
            font-size: 0.9em;
            color: var(--light-text-color);
            margin: 0;
            line-height: 1.5;
        }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 25px;
            background: var(--background-color);
            border-radius: 10px;
            transition: background 0.3s;
        }
        .feature-item:hover {
            background: var(--surface-color);
            box-shadow: 0 2px 10px var(--shadow-color);
        }
        .feature-item i {
            font-size: 2em;
            color: var(--primary-color);
        }
        .feature-item h4 {
            margin-bottom: 5px;
            font-size: 1.1em;
            color: var(--text-color);
        }
        .feature-item p {
            font-size: 0.9em;
            color: var(--light-text-color);
            margin: 0;
            line-height: 1.5;
        }
        .contact-wrapper {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        @media(max-width: 850px) {
            .contact-wrapper {
                grid-template-columns: 1fr;
            }
        }
        .contact-info-box {
            background: var(--primary-color);
            color: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(42, 157, 143, 0.3);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .contact-info-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }
        .contact-info-item:last-child {
            margin-bottom: 0;
        }
        .contact-info-item i {
            font-size: 1.4em;
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
        }
        .contact-info-item div h4 {
            font-size: 0.9em;
            opacity: 0.8;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .contact-info-item div p {
            font-size: 1.1em;
            font-weight: 600;
            margin: 0;
        }
        .contact-info-item a {
            color: white;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .contact-info-item a:hover {
            opacity: 0.8;
        }
        .contact-form-box {
            background: var(--surface-color);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 5px 20px var(--shadow-color);
            border: 1px solid var(--border-color);
        }
        .map-container {
            height: 450px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px var(--shadow-color);
            border: 5px solid var(--surface-color);
        }
        .mobile-header-breadcrumb {
            display: none;
        }
        @media (max-width: 768px) {
            aside.sidebar h2 {
                display: none;
            }
            .mobile-header-breadcrumb {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 1.1em;
                font-weight: 600;
                color: var(--primary-color);
                margin-left: 10px;
                flex-grow: 1;
            }
            .mobile-header-breadcrumb a {
                color: inherit;
                text-decoration: none;
            }
        }
        .mobile-breadcrumb {
            display: none;
        }
        @media (max-width: 768px) {
            #app-container {
                flex-direction: column;
                margin: 0;
            }
            aside.sidebar {
                width: 100%;
                height: auto;
                padding: 10px 15px;
                flex-direction: row !important;
                justify-content: flex-start !important;
                align-items: center !important;
                position: sticky;
                top: 0;
                z-index: 1000;
            }
            .mobile-breadcrumb,
            .mobile-header-breadcrumb {
                display: none !important;
            }
            aside.sidebar h2 {
                display: block !important;
                margin: 0 0 0 15px !important;
                font-size: 1.1em !important;
                border-bottom: none !important;
                padding-bottom: 0 !important;
                white-space: nowrap;
            }
            .hamburger-menu {
                display: block !important;
                order: -1;
            }
            aside.sidebar .user-info,
            aside.sidebar .dark-mode-toggle {
                display: none;
            }
            aside.sidebar.active .user-info,
            aside.sidebar.active .dark-mode-toggle {
                display: block;
            }
        }
        .table-responsive {
            display: block;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        main.content {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }
        body.sidebar-collapsed aside.sidebar {
            width: 88px !important;
            padding-inline: 12px !important;
        }
        body.sidebar-collapsed aside.sidebar h2,
        body.sidebar-collapsed aside.sidebar nav ul li a span.sidebar-label,
        body.sidebar-collapsed aside.sidebar .user-info,
        body.sidebar-collapsed aside.sidebar .dark-mode-toggle,
        body.sidebar-collapsed aside.sidebar .sidebar-product-navigator {
            display: none !important;
        }
        body.sidebar-collapsed aside.sidebar nav ul li a {
            justify-content: center;
            padding-inline: 0 !important;
        }
        body.sidebar-collapsed aside.sidebar nav ul li a i {
            margin-right: 0 !important;
        }
        .sidebar-header-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .sidebar-product-navigator {
            margin-top: 16px;
            border-top: 1px solid var(--border-color);
            padding-top: 14px;
        }
        .sidebar-product-navigator .mini-title {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--light-text-color);
            margin-bottom: 10px;
        }
        .sidebar-product-list {
            max-height: 240px;
            overflow: auto;
            display: grid;
            gap: 8px;
        }
        .sidebar-product-chip {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 8px 10px;
            background: var(--background-color);
            cursor: pointer;
            transition: 0.2s ease;
        }
        .sidebar-product-chip:hover {
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }
        .barcode-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(42, 157, 143, 0.08);
            color: var(--primary-color);
            font-size: 11px;
            font-weight: 600;
        }
        .barcode-print-card {
            padding: 18px;
            text-align: center;
        }
        .inline-input-group {
            display: flex;
            gap: 10px;
        }
        .inline-input-group > input,
        .inline-input-group > select {
            flex: 1;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .status-pill.success { background: rgba(40,167,69,0.12); color: #198754; }
        .status-pill.muted { background: rgba(108,117,125,0.12); color: var(--light-text-color); }
        .secure-link-copy-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .secure-link-input {
            width: 100%;
            min-width: 220px;
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: var(--background-color);
            color: var(--text-color);
        }
        .public-sale-shell {
            display: grid;
            grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        .public-sale-products-panel {
            position: sticky;
            top: 20px;
        }
        .public-sale-product-list {
            display: grid;
            gap: 10px;
            max-height: calc(100vh - 280px);
            overflow: auto;
        }
        .public-sale-product-item {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 12px;
            background: var(--background-color);
            cursor: pointer;
        }
        .public-sale-product-item strong {
            display: block;
            margin-bottom: 4px;
        }
        .public-sale-main { display: grid; gap: 20px; }
        .public-sale-top-card { overflow: hidden; }
        .public-sale-header-row {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .public-sale-rate-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(42,157,143,0.12);
            color: var(--primary-color);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .public-sale-scanner-grid {
            display: grid;
            grid-template-columns: minmax(320px, 1fr) minmax(220px, 320px);
            gap: 16px;
            align-items: start;
        }
        .public-sale-scanner-box {
            min-height: 280px;
            border-radius: 18px;
            overflow: hidden;
            background: #0f172a;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .public-sale-status-chip {
            display: inline-flex;
            margin-top: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--background-color);
            border: 1px solid var(--border-color);
            font-size: 12px;
            font-weight: 600;
        }
        .public-sale-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 14px;
            margin-top: 12px;
            border-top: 1px solid var(--border-color);
            font-size: 18px;
        }
        .barcode-scan-btn { white-space: nowrap; }
        @media (max-width: 1100px) {
            .public-sale-shell,
            .public-sale-scanner-grid {
                grid-template-columns: 1fr;
            }
            .public-sale-products-panel {
                position: static;
            }
        }
        @media (max-width: 900px) {
            aside.sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                width: min(86vw, 320px);
                transform: translateX(-105%);
                transition: transform 0.25s ease;
                z-index: 1300;
                height: 100vh;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                align-items: stretch;
            }
            body.sidebar-open aside.sidebar,
            aside.sidebar.active {
                transform: translateX(0);
            }
            .hamburger-menu {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 42px;
                height: 42px;
                border-radius: 12px;
                background: var(--background-color);
                border: 1px solid var(--border-color);
            }
            aside.sidebar nav,
            aside.sidebar .user-info,
            aside.sidebar .dark-mode-toggle,
            aside.sidebar .sidebar-product-navigator,
            aside.sidebar h2 {
                display: block;
            }
            .public-sale-login-card,
            .public-sale-shell {
                margin-top: 10px;
            }
        }
        .public-header {
            height: auto !important;
            flex-wrap: wrap !important;
            overflow: visible !important;
        }
        .public-header nav,
        .public-header nav ul {
            overflow: visible !important;
            white-space: normal !important;
        }
        .tenant-status-banner {
            background-color: var(--warning-color);
            color: white;
            padding: 10px 20px;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 999;
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .tenant-status-banner.error {
            background-color: var(--danger-color);
        }
    </style>
    <style id="minimalist-compact-overrides">
        :root {
            --primary-color: #111827;
            --primary-dark-color: #0f172a;
            --accent-color: #2563eb;
            --secondary-accent-color: #dbeafe;
            --background-color: #f5f7fb;
            --surface-color: #ffffff;
            --text-color: #111827;
            --light-text-color: #6b7280;
            --border-color: #e5e7eb;
            --shadow-color: rgba(15, 23, 42, 0.06);
            --danger-color: #dc2626;
            --success-color: #059669;
            --warning-color: #d97706;
            --info-color: #2563eb;
            --disabled-color: #cbd5e1;
            --page-max-width: 1440px;
        }
        [data-theme='dark'] {
            --primary-color: #f8fafc;
            --primary-dark-color: #e2e8f0;
            --accent-color: #60a5fa;
            --secondary-accent-color: rgba(96, 165, 250, 0.14);
            --background-color: #0b1220;
            --surface-color: #121a2b;
            --text-color: #e5edf8;
            --light-text-color: #94a3b8;
            --border-color: #243047;
            --shadow-color: rgba(2, 8, 23, 0.32);
            --danger-color: #f87171;
            --success-color: #34d399;
            --warning-color: #fbbf24;
            --info-color: #60a5fa;
            --disabled-color: #334155;
        }
        html {
            font-size: 15px;
            scroll-behavior: smooth;
        }
        body {
            background: var(--background-color);
            color: var(--text-color);
            line-height: 1.45;
            font-size: 0.95rem;
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(107, 114, 128, 0.4) transparent;
        }
        *::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        *::-webkit-scrollbar-thumb {
            background: rgba(107, 114, 128, 0.32);
            border-radius: 999px;
        }
        #app-container,
        #public-site-container,
        #login-container {
            width: 100%;
            max-width: var(--page-max-width);
            margin: 0 auto;
            background: transparent;
            box-shadow: none;
            border-radius: 0;
            overflow: visible;
        }
        #login-container {
            padding: 24px;
        }
        .login-card,
        .card,
        .dashboard-card,
        .book-card,
        .modal-content,
        .mv-card,
        .contact-form-box,
        .contact-info-box,
        .public-content {
            border: 1px solid var(--border-color);
            box-shadow: 0 12px 28px var(--shadow-color);
        }
        .card,
        .public-content,
        .login-card,
        .modal-content,
        .contact-form-box {
            border-radius: 16px;
        }
        .card,
        .public-content,
        .login-card,
        .modal-content,
        .contact-form-box {
            padding: 16px;
        }
        .dashboard-card {
            border-radius: 18px;
            padding: 18px;
            min-height: 118px;
        }
        .dashboard-card h3 {
            font-size: 0.9rem;
            margin-bottom: 8px;
            color: var(--light-text-color);
            font-weight: 600;
        }
        .dashboard-card p {
            font-size: 1.85rem;
            line-height: 1.1;
            letter-spacing: -0.03em;
        }
        aside.sidebar {
            width: 248px;
            padding: 16px;
            background: var(--surface-color);
            border-right: 1px solid var(--border-color);
            box-shadow: none;
            gap: 8px;
        }
        aside.sidebar h2 {
            margin-bottom: 14px;
            padding-bottom: 12px;
            font-size: 1.05rem;
            color: var(--text-color);
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        aside.sidebar nav ul li {
            margin-bottom: 4px;
        }
        aside.sidebar nav ul li a {
            min-height: 40px;
            padding: 9px 12px;
            border-radius: 12px;
            font-size: 0.92rem;
            color: var(--light-text-color);
        }
        aside.sidebar nav ul li a i {
            width: 18px;
            margin-right: 10px;
            font-size: 0.95rem;
        }
        aside.sidebar nav ul li a:hover,
        aside.sidebar nav ul li a.active {
            background: var(--secondary-accent-color);
            color: var(--text-color);
            box-shadow: none;
        }
        .user-info,
        .dark-mode-toggle {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: rgba(148, 163, 184, 0.05);
        }
        .user-info {
            margin-top: 12px;
            padding: 12px;
            font-size: 0.82rem;
        }
        .dark-mode-toggle {
            margin-top: 10px;
            padding: 10px 12px;
        }
        main.content {
            padding: 18px;
            background: transparent;
        }
        .page-header {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
            gap: 12px;
        }
        .page-header h1,
        .card-header {
            color: var(--text-color);
            letter-spacing: -0.02em;
        }
        .page-header h1 {
            font-size: 1.45rem;
            font-weight: 700;
        }
        .card-header {
            font-size: 1rem;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .dashboard-grid {
            gap: 14px;
            margin-bottom: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .btn {
            min-height: 38px;
            padding: 8px 14px;
            font-size: 0.88rem;
            font-weight: 600;
            border-radius: 10px;
            gap: 7px;
            box-shadow: none;
        }
        .btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px var(--shadow-color);
        }
        .btn-primary {
            background: var(--accent-color);
            color: #fff;
        }
        .btn-primary:hover:not(:disabled) {
            background: #1d4ed8;
        }
        .btn-secondary {
            background: var(--surface-color);
            color: var(--text-color);
        }
        .btn-danger {
            background: var(--danger-color);
        }
        .btn-success {
            background: var(--success-color);
        }
        .btn-info {
            background: var(--info-color);
        }
        .form-group {
            margin-bottom: 12px;
        }
        .form-group label {
            margin-bottom: 6px;
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--light-text-color);
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="url"],
        .form-group textarea,
        .form-group select,
        .form-group input[type="date"],
        .form-group input[type="month"],
        .form-group input[type="password"] {
            min-height: 40px;
            padding: 9px 12px;
            font-size: 0.9rem;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--surface-color);
            color: var(--text-color);
            box-shadow: none;
        }
        .form-group textarea {
            min-height: 110px;
            resize: vertical;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus,
        .global-search-bar input:focus {
            border-color: rgba(37, 99, 235, 0.45);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }
        .search-sort-controls,
        .report-filters,
        .flex-group {
            gap: 12px;
        }
        .global-search-bar {
            position: sticky;
            top: 0;
            z-index: 30;
            margin-bottom: 16px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(8px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 12px 28px var(--shadow-color);
        }
        [data-theme='dark'] .global-search-bar {
            background: rgba(18, 26, 43, 0.9);
        }
        .global-search-results {
            position: absolute;
            top: calc(100% + 6px);
            left: 12px;
            right: 12px;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 40px var(--shadow-color);
            overflow: hidden;
        }
        .global-search-results div {
            padding: 10px 12px;
            font-size: 0.88rem;
        }
        .data-table {
            margin-top: 14px;
            border-radius: 14px;
            box-shadow: none;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        .data-table thead {
            background: #f8fafc;
            color: var(--light-text-color);
        }
        [data-theme='dark'] .data-table thead {
            background: #162033;
        }
        .data-table th,
        .data-table td {
            padding: 10px 12px;
            font-size: 0.87rem;
            vertical-align: middle;
        }
        .data-table th {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            font-size: 0.72rem;
        }
        .data-table td {
            color: var(--text-color);
        }
        .data-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.03);
        }
        .data-table .actions {
            gap: 6px;
            flex-wrap: wrap;
        }
        .data-table .actions .btn {
            min-height: 32px;
            padding: 6px 10px;
            font-size: 0.78rem;
            border-radius: 8px;
        }
        .table-responsive {
            border-radius: 14px;
        }
        .pagination {
            margin-top: 14px;
            gap: 8px;
            flex-wrap: wrap;
        }
        .pagination button {
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 0.85rem;
        }
        .modal-content {
            width: min(560px, calc(100vw - 24px));
            max-height: calc(100vh - 36px);
            padding: 18px;
        }
        .modal-header {
            margin-bottom: 14px;
            padding-bottom: 10px;
        }
        .modal-header h3 {
            font-size: 1.05rem;
            color: var(--text-color);
        }
        .modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
        }
        .toast {
            min-width: 220px;
            max-width: 340px;
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 0.88rem;
            box-shadow: 0 18px 32px rgba(15, 23, 42, 0.16);
        }
        .hero-section,
        .about-header,
        .contact-header {
            border-radius: 24px;
            box-shadow: none;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }
        .hero-section {
            padding: 40px 28px;
            margin-bottom: 20px;
        }
        .hero-section h1 {
            font-size: clamp(1.8rem, 3vw, 2.8rem);
            line-height: 1.08;
            margin-bottom: 14px;
            text-shadow: none;
        }
        .hero-section p {
            max-width: 760px;
            margin-bottom: 22px;
            font-size: 0.98rem;
            color: rgba(255,255,255,0.8);
        }
        .hero-section .btn-primary {
            min-height: 42px;
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 0.9rem;
            background: #fff;
            color: #111827;
        }
        .public-header {
            position: sticky;
            top: 0;
            z-index: 40;
            gap: 12px;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            box-shadow: none;
        }
        [data-theme='dark'] .public-header {
            background: rgba(11, 18, 32, 0.92);
        }
        .public-header .logo {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-color);
        }
        .public-header nav {
            flex: 1;
            min-width: 0;
        }
        .public-header nav ul {
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .public-header nav ul li {
            flex: 0 0 auto;
        }
        .public-header nav ul li a {
            padding: 8px 10px;
            font-size: 0.88rem;
            color: var(--light-text-color);
            border-radius: 999px;
            white-space: nowrap;
        }
        .public-header nav ul li a::after {
            display: none;
        }
        .public-header nav ul li a:hover,
        .public-header nav ul li a.active {
            background: var(--secondary-accent-color);
            color: var(--text-color);
        }
        .public-header .login-btn {
            padding: 9px 14px;
            border-radius: 999px;
            font-size: 0.84rem;
            background: var(--accent-color);
            color: #fff;
        }
        .public-content {
            width: calc(100% - 24px);
            max-width: 1220px;
            margin: 16px auto 0;
            padding: 18px;
            background: transparent;
            box-shadow: none;
            border: none;
        }
        .book-grid {
            gap: 14px;
            margin-top: 16px;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        }
        .book-card {
            padding: 12px;
            border-radius: 16px;
            text-align: left;
            box-shadow: none;
            background: var(--surface-color);
        }
        .book-card img {
            height: 160px;
            margin-bottom: 12px;
            border-radius: 12px;
            padding: 0;
            background: #f8fafc;
        }
        .book-card h3 {
            margin-bottom: 4px;
            font-size: 0.96rem;
            line-height: 1.35;
            white-space: normal;
        }
        .book-card p,
        .book-card .stock-info,
        .feature-item p,
        .public-footer p,
        .contact-info-item div h4,
        .contact-info-item div p,
        .about-header p,
        .contact-header p {
            font-size: 0.84rem;
        }
        .book-card .price {
            margin-bottom: 8px;
            font-size: 1rem;
            color: var(--text-color);
        }
        .public-product-actions {
            gap: 8px;
            justify-content: flex-start;
        }
        .public-product-actions .btn,
        .whatsapp-btn {
            min-height: 34px;
            padding: 7px 11px;
            font-size: 0.8rem;
            border-radius: 9px;
        }
        .whatsapp-btn {
            background: #16a34a;
        }
        .about-header,
        .contact-header {
            margin-bottom: 20px;
            padding: 32px 20px;
        }
        .about-header h1,
        .contact-header h1 {
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            margin-bottom: 10px;
        }
        .mission-vision-container,
        .features-grid,
        .contact-wrapper {
            gap: 16px;
            margin-bottom: 20px;
        }
        .mv-card,
        .contact-info-box,
        .contact-form-box {
            padding: 20px;
            border-radius: 18px;
        }
        .mv-card i,
        .feature-item i {
            font-size: 1.5rem;
        }
        .mv-card h3,
        .contact-form-box h3 {
            font-size: 1.05rem;
            margin-bottom: 10px;
        }
        .feature-item {
            gap: 12px;
            padding: 16px;
            border-radius: 14px;
        }
        .contact-info-item {
            gap: 12px;
            margin-bottom: 18px;
        }
        .contact-info-item i {
            width: 40px;
            height: 40px;
            font-size: 1rem;
            padding: 0;
        }
        .map-container {
            height: 300px;
            border-radius: 18px;
            border-width: 1px;
        }
        .public-footer {
            margin-top: 18px;
            padding: 16px 18px;
            font-size: 0.82rem;
            background: transparent;
            box-shadow: none;
        }
        .login-card {
            padding: 24px;
            border-radius: 18px;
        }
        .login-card h2 {
            font-size: 1.4rem;
            margin-bottom: 18px;
        }
        .report-controls,
        .form-actions,
        #cart-actions,
        #online-cart-actions {
            gap: 8px;
            flex-wrap: wrap;
        }
        #cart-summary,
        #online-cart-summary {
            margin-top: 16px;
            padding-top: 14px;
            font-size: 1rem;
        }
        .img-preview {
            width: 88px;
            height: 88px;
            border-radius: 12px;
        }
        .low-stock {
            background: rgba(217, 119, 6, 0.08) !important;
            color: var(--warning-color) !important;
        }
        .inactive-customer {
            background: rgba(220, 38, 38, 0.08) !important;
            color: var(--danger-color) !important;
        }
        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.42);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 998;
        }
        body.sidebar-open .sidebar-backdrop {
            opacity: 1;
            visibility: visible;
        }
        .pos-wrapper { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; height: calc(100vh - 140px); }
        .pos-main-panel { display: flex; flex-direction: column; height: 100%; overflow: hidden; background: var(--surface-color); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 12px 28px var(--shadow-color); padding: 16px; }
        .pos-header-controls { display: flex; gap: 10px; margin-bottom: 16px; }
        .pos-header-controls input, .pos-header-controls select { flex: 1; min-height: 42px; border-radius: 10px; border: 1px solid var(--border-color); padding: 0 12px; background: var(--background-color); color: var(--text-color); }
        .pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; overflow-y: auto; padding-right: 5px; flex-grow: 1; align-content: flex-start; padding-top: 10px; }
        .pos-card { border: 1px solid var(--border-color); border-radius: 12px; padding: 10px; text-align: center; cursor: pointer; transition: 0.2s ease; background: var(--background-color); position: relative; user-select: none; }
        .pos-card:hover { border-color: var(--primary-color); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .pos-card img { width: 100%; height: 90px; object-fit: contain; margin-bottom: 8px; border-radius: 8px; }
        .pos-card .title { font-size: 0.85rem; font-weight: 600; line-height: 1.2; margin-bottom: 4px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .pos-card .price { font-weight: 700; color: var(--primary-color); font-size: 0.95rem; }
        .pos-card .stock-badge { position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 6px; font-weight: 600; }
        .pos-cart-panel { background: var(--surface-color); border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 12px 28px var(--shadow-color); display: flex; flex-direction: column; height: 100%; overflow: hidden; }
        .pos-cart-header { padding: 16px; border-bottom: 1px solid var(--border-color); font-weight: 700; font-size: 1.1rem; display: flex; justify-content: space-between; align-items: center; }
        .pos-cart-items-wrap { flex-grow: 1; overflow-y: auto; padding: 10px; }
        .pos-cart-item { background: var(--background-color); border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; margin-bottom: 10px; }
        .pos-cart-item-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 8px; display: flex; justify-content: space-between; }
        .pos-cart-item-controls { display: flex; gap: 10px; align-items: center; justify-content: space-between; }
        .pos-qty-group { display: flex; align-items: center; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: var(--surface-color); }
        .pos-qty-group button { background: transparent; border: none; padding: 6px 10px; cursor: pointer; color: var(--text-color); font-weight: bold; }
        .pos-qty-group button:hover { background: rgba(0,0,0,0.05); }
        .pos-qty-group input { width: 40px; border: none; text-align: center; font-weight: 600; background: transparent; color: var(--text-color); -moz-appearance: textfield; }
        .pos-qty-group input::-webkit-outer-spin-button, .pos-qty-group input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .pos-disc-group { display: flex; align-items: center; gap: 5px; }
        .pos-disc-group label { font-size: 0.75rem; color: var(--light-text-color); font-weight: 600; text-transform: uppercase; }
        .pos-disc-group input { width: 60px; padding: 5px; border: 1px solid var(--border-color); border-radius: 6px; text-align: right; font-size: 0.85rem; background: var(--surface-color); color: var(--text-color); }
        .pos-totals-panel { background: var(--background-color); padding: 16px; border-top: 1px solid var(--border-color); }
        .pos-summary-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 0.9rem; color: var(--light-text-color); }
        .pos-summary-row.grand { font-size: 1.3rem; font-weight: 700; color: var(--primary-color); border-top: 1px dashed var(--border-color); padding-top: 10px; margin-top: 5px; margin-bottom: 12px; }
        .pos-action-btns { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; }
        .pos-action-btns .btn { padding: 14px; font-size: 1rem; border-radius: 12px; }
        @media (max-width: 1024px) {
            .pos-wrapper { grid-template-columns: 1fr; height: auto; }
            .pos-cart-panel { height: 500px; }
        }
        @media (max-width: 1100px) {
            aside.sidebar {
                width: 224px;
            }
            .dashboard-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        @media (max-width: 900px) {
            html {
                font-size: 14px;
            }
            #app-container {
                flex-direction: column;
            }
            body aside.sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: min(280px, 86vw);
                height: 100vh;
                padding: 14px;
                transform: translateX(-105%);
                transition: transform 0.22s ease;
                z-index: 999;
                overflow-y: auto;
                box-shadow: 0 24px 48px rgba(15, 23, 42, 0.16);
                border-right: 1px solid var(--border-color);
                background: var(--surface-color);
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
            }
            body aside.sidebar.active {
                transform: translateX(0);
            }
            body aside.sidebar h2 {
                display: block !important;
                margin: 0 0 12px 0 !important;
                font-size: 1rem !important;
            }
            body aside.sidebar nav {
                display: block !important;
                width: 100%;
                margin-top: 2px;
            }
            body aside.sidebar .user-info,
            body aside.sidebar .dark-mode-toggle {
                display: block !important;
            }
            .hamburger-menu {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                width: 38px;
                height: 38px;
                border-radius: 10px;
                background: var(--surface-color);
                border: 1px solid var(--border-color);
                color: var(--text-color);
                box-shadow: none;
            }
            main.content {
                width: 100%;
                padding: 14px;
            }
            .global-search-bar {
                margin-bottom: 12px;
                padding: 10px;
            }
            .page-header {
                align-items: flex-start;
            }
            .dashboard-grid,
            .book-grid,
            .mission-vision-container,
            .features-grid,
            .contact-wrapper {
                grid-template-columns: 1fr;
            }
            .public-header {
                padding: 12px 14px;
                align-items: flex-start;
            }
            .public-header > div:last-child {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            .public-content {
                width: calc(100% - 16px);
                margin-top: 10px;
                padding: 14px;
            }
            .hero-section,
            .about-header,
            .contact-header {
                padding: 24px 16px;
                border-radius: 18px;
            }
            .hero-section h1 {
                font-size: 1.7rem;
            }
            .book-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .book-card {
                padding: 10px;
            }
            .book-card img {
                height: 112px;
            }
            .dashboard-card p {
                font-size: 1.6rem;
            }
            .data-table td {
                padding-left: 92px;
            }
            .data-table td::before {
                width: 80px;
            }
            .login-card {
                padding: 18px;
            }
        }
        @media (max-width: 560px) {
            .public-header .logo {
                font-size: 1rem;
            }
            .book-grid {
                grid-template-columns: 1fr 1fr;
            }
            .book-card img {
                height: 112px;
            }
            .dashboard-card p {
                font-size: 1.6rem;
            }
            .data-table td {
                padding-left: 92px;
            }
            .data-table td::before {
                width: 80px;
            }
            .login-card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <?php if (TENANT_ID && $tenant_status_info['status'] !== 'active' && CURRENT_PAGE !== 'subscription'): ?>
        <div class="tenant-status-banner <?php echo $tenant_status_info['status'] === 'banned' || $tenant_status_info['status'] === 'read_only' ? 'error' : ''; ?>">
            <i class="fas fa-exclamation-triangle"></i> <?php echo html($tenant_status_info['message']); ?> 
            <a href="<?php echo get_redirect_url('subscription'); ?>" style="color: white; text-decoration: underline; margin-left: 10px;">Go to Subscription Page</a>
        </div>
    <?php endif; ?>

    <?php if (IS_PUBLIC_MAIN_SITE || CURRENT_PAGE === 'login' || CURRENT_PAGE === 'register' || CURRENT_PAGE === 'create-superadmin'): ?>
        <?php if (isset($_SESSION['toast'])) {
            echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
            unset($_SESSION['toast']);
        } elseif (isset($_GET['toast_type']) && isset($_GET['toast_message'])) {
            echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_GET['toast_type']) . "' data-message='" . html($_GET['toast_message']) . "'></div>";
        } ?>

        <?php if (CURRENT_PAGE === 'home' || CURRENT_PAGE === 'about' || CURRENT_PAGE === 'features' || CURRENT_PAGE === 'pricing' || CURRENT_PAGE === 'contact' || CURRENT_PAGE === 'policy'): ?>
        <div id="public-site-container">
            <header class="public-header">
                <a href="<?php echo ROOT_URL; ?>/" class="logo"><?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?></a>
                <nav>
                    <ul>
                        <li><a href="<?php echo ROOT_URL; ?>/" class="nav-link <?php echo CURRENT_PAGE === 'home' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="<?php echo ROOT_URL; ?>/features" class="nav-link <?php echo CURRENT_PAGE === 'features' ? 'active' : ''; ?>">Features</a></li>
                        <li><a href="<?php echo ROOT_URL; ?>/pricing" class="nav-link <?php echo CURRENT_PAGE === 'pricing' ? 'active' : ''; ?>">Pricing</a></li>
                        <li><a href="<?php echo ROOT_URL; ?>/about" class="nav-link <?php echo CURRENT_PAGE === 'about' ? 'active' : ''; ?>">About Us</a></li>
                        <li><a href="<?php echo ROOT_URL; ?>/contact" class="nav-link <?php echo CURRENT_PAGE === 'contact' ? 'active' : ''; ?>">Contact Us</a></li>
                        <li><a href="<?php echo ROOT_URL; ?>/policy" class="nav-link <?php echo CURRENT_PAGE === 'policy' ? 'active' : ''; ?>">Privacy Policy</a></li>
                    </ul>
                </nav>
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <a href="<?php echo ROOT_URL; ?>/register" class="login-btn" style="background-color: var(--primary-dark-color);">Register Tenant</a>
                    <a href="<?php echo ROOT_URL; ?>/login" class="login-btn">Tenant Login</a>
                    <a href="<?php echo ROOT_URL; ?>/<?php echo SUPERADMIN_SLUG; ?>/login" class="login-btn" style="background-color: var(--danger-color);">Superadmin Login</a>
                </div>
            </header>
            <main class="public-content">
                <?php
                switch (CURRENT_PAGE) {
                    case 'home':
                        $news_query = $conn->query("SELECT * FROM superadmin_news WHERE is_active = 1 AND visibility = 'all_users' ORDER BY created_at DESC LIMIT 3");
                        $news_items = [];
                        if ($news_query) {
                            while($row = $news_query->fetch_assoc()) {
                                $news_items[] = $row;
                            }
                        }
                        ?>
                        <section id="main-home" class="page-content active">
                            <div class="hero-section">
                                <h1><?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?></h1>
                                <p><?php echo html($superadmin_settings['slogan'] ?? 'Your ultimate multi-tenant bookshop and general store management solution.'); ?></p>
                                <a href="<?php echo ROOT_URL; ?>/register" class="btn btn-primary">Get Started Now <i class="fas fa-arrow-right"></i></a>
                            </div>

                            <?php if (!empty($news_items)): ?>
                            <div class="card" style="margin-bottom: 30px;">
                                <div class="card-header"><i class="fas fa-bullhorn"></i> Latest System Updates</div>
                                <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;">
                                    <?php foreach ($news_items as $news): ?>
                                        <div style="padding: 15px; border: 1px solid var(--border-color); border-radius: 8px; background-color: var(--background-color);">
                                            <h3 style="color: var(--primary-color); margin-bottom: 5px;"><?php echo html($news['title']); ?></h3>
                                            <small style="color: var(--light-text-color); display: block; margin-bottom: 10px;"><?php echo format_date($news['created_at']); ?></small>
                                            <p style="margin: 0; line-height: 1.6;"><?php echo nl2br(html($news['content'])); ?></p>
                                            <?php if ($news['media_path']): ?>
                                                <?php if ($news['media_type'] === 'image'): ?>
                                                    <img src="<?php echo ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $news['media_path']); ?>" alt="News Image" style="max-width: 100%; height: auto; border-radius: 8px; margin-top: 15px;">
                                                <?php elseif ($news['media_type'] === 'video_upload'): ?>
                                                    <video controls src="<?php echo ROOT_URL . '/' . SUPERADMIN_SLUG . '/' . str_replace(UPLOAD_DIR . '/', 'uploads/', $news['media_path']); ?>" style="max-width: 100%; height: auto; border-radius: 8px; margin-top: 15px;"></video>
                                                <?php elseif ($news['media_type'] === 'youtube_embed' || $news['media_type'] === 'facebook_embed'): ?>
                                                    <iframe src="<?php echo html($news['media_path']); ?>" frameborder="0" allowfullscreen style="width: 100%; height: 315px; border-radius: 8px; margin-top: 15px;"></iframe>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="card">
                                <div class="card-header">Our Key Features</div>
                                <div class="features-grid">
                                    <div class="feature-item">
                                        <i class="fas fa-user-shield"></i>
                                        <div>
                                            <h4>Multi-Tenant Architecture</h4>
                                            <p>Dedicated instances for each tenant with full data isolation.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-chart-line"></i>
                                        <div>
                                            <h4>Comprehensive Management</h4>
                                            <p>Manage products, sales, customers, suppliers, and more from one place.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-shopping-cart"></i>
                                        <div>
                                            <h4>Point of Sale (POS)</h4>
                                            <p>Fast and efficient checkout experience with barcode scanning.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-globe"></i>
                                        <div>
                                            <h4>Optional Public Website</h4>
                                            <p>Each tenant can have their own public product display website.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-tags"></i>
                                        <div>
                                            <h4>Promotions & Discounts</h4>
                                            <p>Create and manage flexible promotional campaigns.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <div>
                                            <h4>Subscription & Billing</h4>
                                            <p>Flexible subscription plans with easy payment and renewal process.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'features':
                        ?>
                        <section id="main-features" class="page-content active">
                            <div class="about-header">
                                <h1>Robust Features for Your Business</h1>
                                <p>Explore the powerful tools <?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?> offers to streamline your operations.</p>
                            </div>
                            <div class="card">
                                <h2 class="card-header">Core Management Modules</h2>
                                <div class="features-grid">
                                    <div class="feature-item">
                                        <i class="fas fa-box-open"></i>
                                        <div>
                                            <h4>Product & Inventory</h4>
                                            <p>Add/edit products (books, general items) with stock tracking, pricing tiers (retail, wholesale, purchase), images, and barcodes.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-users"></i>
                                        <div>
                                            <h4>Customers & CRM</h4>
                                            <p>Manage customer profiles, view purchase history, and toggle account activity. Secure customer login portal.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-truck-moving"></i>
                                        <div>
                                            <h4>Suppliers & POs</h4>
                                            <p>Maintain supplier details and create/manage purchase orders (POs) with itemized costs and status tracking.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-receipt"></i>
                                        <div>
                                            <h4>Sales & POS</h4>
                                            <p>Intuitive Point-of-Sale (POS) for quick sales, detailed sales history, and online order processing. Supports manual discounts and promotions.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <div>
                                            <h4>Expense Tracking</h4>
                                            <p>Record and categorize business expenses to monitor financial outflow with monthly summaries.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-chart-line"></i>
                                        <div>
                                            <h4>Reports & Analytics</h4>
                                            <p>Generate daily, weekly, monthly sales reports, best-selling products/authors, low stock alerts, and expense summaries.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card" style="margin-top: 20px;">
                                <h2 class="card-header">Advanced SaaS Capabilities</h2>
                                <div class="features-grid">
                                    <div class="feature-item">
                                        <i class="fas fa-cloud"></i>
                                        <div>
                                            <h4>Multi-Tenant Isolation</h4>
                                            <p>Each tenant gets a completely isolated environment, ensuring data security and privacy.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-money-check-alt"></i>
                                        <div>
                                            <h4>Flexible Subscriptions</h4>
                                            <p>Choose from various plans, manage renewals, and enjoy grace periods for seamless service.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-share-alt"></i>
                                        <div>
                                            <h4>Referral & Rewards</h4>
                                            <p>Invite new tenants and earn bonus subscription months for successful referrals.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-file-upload"></i>
                                        <div>
                                            <h4>Configurable File Uploads</h4>
                                            <p>Control file upload capabilities for products and other media, based on your subscription plan.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-history"></i>
                                        <div>
                                            <h4>Audit Logging</h4>
                                            <p>Comprehensive logging of all key actions for transparency and accountability.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-database"></i>
                                        <div>
                                            <h4>Backup & Restore</h4>
                                            <p>Securely backup and restore your tenant's data with integrity checks.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-mobile-alt"></i>
                                        <div>
                                            <h4>PWA Support</h4>
                                            <p>Install your tenant's app as a Progressive Web App (PWA) for native-like experience.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-link"></i>
                                        <div>
                                            <h4>Secure Sale Links</h4>
                                            <p>Generate password-protected links for public selling with specific price modes (retail/wholesale).</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-lock"></i>
                                        <div>
                                            <h4>Robust Security</h4>
                                            <p>Strong password policies, CAPTCHA, session management, and anti-brute-force measures.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'pricing':
                        $all_plans = $conn->query('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_per_month ASC')->fetch_all(MYSQLI_ASSOC);
                        $default_price_per_month = DEFAULT_SUBSCRIPTION_PRICE_PER_MONTH;
                        ?>
                        <section id="main-pricing" class="page-content active">
                            <div class="about-header">
                                <h1>Flexible Pricing for Every Business</h1>
                                <p>Choose the plan that best fits your needs, with transparent and competitive pricing.</p>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-bottom: 50px;">
                                <div class="mv-card">
                                    <i class="fas fa-hourglass-half"></i>
                                    <h3>Free Trial</h3>
                                    <p>Start with a <strong>6-day free trial</strong> upon registration. Experience all features before you commit!</p>
                                    <p style="font-size: 1.5em; font-weight: bold; color: var(--success-color); margin-top: 15px;">FREE</p>
                                    <a href="<?php echo ROOT_URL; ?>/register" class="btn btn-primary" style="margin-top: 20px;">Start Free Trial</a>
                                </div>
                                <?php if (!empty($all_plans)): ?>
                                    <?php foreach ($all_plans as $plan): ?>
                                        <div class="mv-card">
                                            <i class="fas <?php echo $plan['enable_file_uploads'] ? 'fa-cloud-upload-alt' : 'fa-hand-holding-usd'; ?>"></i>
                                            <h3><?php echo html($plan['name']); ?></h3>
                                            <p><?php echo html($plan['description'] ?: 'A robust plan for growing businesses.'); ?></p>
                                            <p style="font-size: 1.5em; font-weight: bold; color: var(--primary-color); margin-top: 15px;">
                                                <?php echo format_currency($plan['price_per_month']); ?> / month
                                            </p>
                                            <?php
                                            $plan_perms_query = $conn->prepare('SELECT page_key FROM plan_permissions WHERE plan_id = ?');
                                            $plan_perms_query->bind_param('i', $plan['id']);
                                            $plan_perms_query->execute();
                                            $plan_perms_result = $plan_perms_query->get_result();
                                            $plan_permissions = [];
                                            while($perm_row = $plan_perms_result->fetch_assoc()) {
                                                $plan_permissions[] = $perm_row['page_key'];
                                            }
                                            $plan_perms_query->close();

                                            $features_display = [
                                                'dashboard' => 'Dashboard Overview',
                                                'books' => 'Products & Inventory',
                                                'customers' => 'Customer Management',
                                                'users' => 'Users & Roles',
                                                'suppliers' => 'Suppliers',
                                                'purchase-orders' => 'Purchase Orders',
                                                'cart' => 'Point of Sale (POS)',
                                                'sales-history' => 'Sales History',
                                                'online-orders' => 'Online Orders',
                                                'promotions' => 'Promotions',
                                                'expenses' => 'Expenses',
                                                'reports' => 'Reports & Analytics',
                                                'live-sales' => 'Live Sales Monitor',
                                                'news' => 'Tenant News',
                                                'settings' => 'Tenant Settings',
                                                'public-sale-links' => 'Secure Sale Links',
                                                'print-barcodes' => 'Print Barcodes',
                                                'backup-restore' => 'Backup & Restore',
                                            ];
                                            echo '<ul style="list-style: none; padding: 0; text-align: left; margin-top: 20px; font-size: 0.9em; max-width: 250px; margin-left: auto; margin-right: auto;">';
                                            foreach ($features_display as $key => $label) {
                                                if (in_array($key, $plan_permissions)) {
                                                    echo '<li style="margin-bottom: 5px;"><i class="fas fa-check-circle" style="color: var(--success-color); margin-right: 8px;"></i> ' . html($label) . '</li>';
                                                } else {
                                                    echo '<li style="margin-bottom: 5px; opacity: 0.7;"><i class="fas fa-times-circle" style="color: var(--danger-color); margin-right: 8px;"></i> ' . html($label) . '</li>';
                                                }
                                            }
                                            if ($plan['enable_file_uploads']) {
                                                echo '<li style="margin-bottom: 5px;"><i class="fas fa-check-circle" style="color: var(--success-color); margin-right: 8px;"></i> File Uploads Enabled</li>';
                                            } else {
                                                echo '<li style="margin-bottom: 5px; opacity: 0.7;"><i class="fas fa-times-circle" style="color: var(--danger-color); margin-right: 8px;"></i> File Uploads Disabled</li>';
                                            }
                                            echo '</ul>';
                                            ?>
                                            <a href="<?php echo ROOT_URL; ?>/register" class="btn btn-primary" style="margin-top: 20px;">Choose Plan</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No subscription plans available currently. Please check back later.</p>
                                <?php endif; ?>
                            </div>
                            <div class="card">
                                <h2 class="card-header">Referral Program</h2>
                                <p>Invite new tenants to <?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?>! For every new tenant who signs up using your unique invitation code and completes their first paid subscription, you'll receive <strong>+1 month FREE</strong> added to your current subscription.</p>
                                <p style="font-size: 0.9em; color: var(--light-text-color); margin-top: 15px;">Terms & Conditions apply. Reward is issued only after the referred tenant's first successful paid subscription and Superadmin approval.</p>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'about':
                        ?>
                        <section id="main-about" class="page-content active">
                            <div class="about-header">
                                <h1>About <?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?></h1>
                                <p>Learn more about our mission, vision, and the values that drive our multi-tenant platform.</p>
                            </div>
                            <div class="mission-vision-container">
                                <div class="mv-card">
                                    <i class="fas fa-bullseye"></i>
                                    <h3>Our Mission</h3>
                                    <p>To empower small and medium businesses with a powerful, accessible, and affordable management system for their bookshops and general stores.</p>
                                </div>
                                <div class="mv-card">
                                    <i class="fas fa-eye"></i>
                                    <h3>Our Vision</h3>
                                    <p>To be the global leader in SaaS solutions for retail, enabling businesses worldwide to thrive through efficient and intelligent operations.</p>
                                </div>
                                <div class="mv-card">
                                    <i class="fas fa-award"></i>
                                    <h3>Our Values</h3>
                                    <p>Innovation, Customer Success, Integrity, and Simplicity. We believe in building solutions that truly make a difference.</p>
                                </div>
                            </div>
                            <div class="card" style="padding: 40px;">
                                <h2 style="text-align: center; margin-bottom: 10px; color: var(--primary-color);">Why Choose Our SaaS?</h2>
                                <p style="text-align: center; color: var(--light-text-color); margin-bottom: 30px;">We deliver excellence with every feature and every tenant.</p>
                                <div class="features-grid">
                                    <div class="feature-item">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <div>
                                            <h4>Scalable & Secure</h4>
                                            <p>Designed for growth, our cloud infrastructure ensures your data is safe and available 24/7.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-handshake"></i>
                                        <div>
                                            <h4>Dedicated Support</h4>
                                            <p>Our team is ready to assist you with any questions or issues, ensuring a smooth experience.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-bolt"></i>
                                        <div>
                                            <h4>Performance Optimized</h4>
                                            <p>Enjoy blazing-fast performance, even as your business expands.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-dollar-sign"></i>
                                        <div>
                                            <h4>Cost-Effective</h4>
                                            <p>Affordable monthly plans eliminate high upfront costs for software and infrastructure.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'contact':
                        ?>
                        <section id="main-contact" class="page-content active">
                            <div class="contact-header">
                                <h1>Get in Touch with Us</h1>
                                <p>Have questions about our SaaS platform? Want to discuss a custom solution? Reach out today!</p>
                            </div>
                            <div class="contact-wrapper">
                                <div class="contact-info-box">
                                    <h3 style="color: white; margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.3); padding-bottom: 15px;">Our Contact Information</h3>
                                    <div class="contact-info-item">
                                        <i class="fas fa-phone-alt"></i>
                                        <div>
                                            <h4>Phone</h4>
                                            <p><a href="tel:<?php echo html($superadmin_settings['contact_phone'] ?? ''); ?>"><?php echo html($superadmin_settings['contact_phone'] ?? 'N/A'); ?></a></p>
                                        </div>
                                    </div>
                                    <div class="contact-info-item">
                                        <i class="fas fa-envelope"></i>
                                        <div>
                                            <h4>Email</h4>
                                            <p><a href="mailto:<?php echo html($superadmin_settings['contact_email'] ?? ''); ?>"><?php echo html($superadmin_settings['contact_email'] ?? 'N/A'); ?></a></p>
                                        </div>
                                    </div>
                                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.3);">
                                        <h4 style="margin-bottom: 15px; opacity: 0.9;">Connect With Us</h4>
                                        <div style="display: flex; gap: 15px;">
                                            <?php if (!empty($superadmin_settings['facebook_url'])): ?>
                                                <a href="<?php echo html($superadmin_settings['facebook_url']); ?>" target="_blank" style="background: white; color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s;"><i class="fab fa-facebook-f"></i></a>
                                            <?php endif; ?>
                                            <?php if (!empty($superadmin_settings['twitter_url'])): ?>
                                                <a href="<?php echo html($superadmin_settings['twitter_url']); ?>" target="_blank" style="background: white; color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s;"><i class="fab fa-twitter"></i></a>
                                            <?php endif; ?>
                                            <?php if (!empty($superadmin_settings['linkedin_url'])): ?>
                                                <a href="<?php echo html($superadmin_settings['linkedin_url']); ?>" target="_blank" style="background: white; color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s;"><i class="fab fa-linkedin-in"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="contact-form-box">
                                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Send Us a Message</h3>
                                    <form id="contact-message-form" action="<?php echo ROOT_URL; ?>/contact" method="POST">
                                        <input type="hidden" name="action" value="contact_submit">
                                        <div class="form-group">
                                            <label>Your Name</label>
                                            <input type="text" name="name" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="John Doe">
                                        </div>
                                        <div class="form-group">
                                            <label>Your Email</label>
                                            <input type="email" name="email" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="john@example.com">
                                        </div>
                                        <div class="form-group">
                                            <label>Subject</label>
                                            <input type="text" name="subject" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="Inquiry about...">
                                        </div>
                                        <div class="form-group">
                                            <label>Message</label>
                                            <textarea name="message_content" class="form-control" rows="5" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="How can we help you?"></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>CAPTCHA</label>
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <img src="<?php echo ROOT_URL; ?>/index.php?action=captcha" id="captcha-img-contact" onclick="this.src='<?php echo ROOT_URL; ?>/index.php?action=captcha&'+Math.random()" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 5px; height: 44px;" title="Click to refresh">
                                                <input type="text" class="form-control" id="contact-captcha" required placeholder="Enter code" style="flex: 1; padding:12px; border:1px solid var(--border-color); border-radius:5px;">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1.1em;">Send Message</button>
                                    </form>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'policy':
                        ?>
                        <section id="main-policy" class="page-content active">
                            <div class="about-header">
                                <h1>Privacy Policy</h1>
                                <p>Your privacy is important to us. Read how we collect, use, and protect your information.</p>
                            </div>
                            <div class="card">
                                <h2 class="card-header">1. Information We Collect</h2>
                                <p>We collect personal information such as your name, email address, phone number, and business details when you register for an account or use our services. We also collect usage data, device information, and IP addresses for service improvement and security.</p>
                                <h2 class="card-header" style="margin-top: 30px;">2. How We Use Your Information</h2>
                                <p>Your information is used to provide, maintain, and improve our SaaS platform, process subscriptions and payments, communicate with you, and personalize your experience. We may also use data for analytical purposes to understand trends and enhance our offerings.</p>
                                <h2 class="card-header" style="margin-top: 30px;">3. Data Security</h2>
                                <p>We implement robust security measures, including encryption, access controls, and regular security audits, to protect your data from unauthorized access, alteration, disclosure, or destruction. We strive to maintain the highest standards of data security.</p>
                                <h2 class="card-header" style="margin-top: 30px;">4. Data Isolation (Multi-Tenant Architecture)</h2>
                                <p>As a multi-tenant SaaS, we ensure strict data isolation between tenants. Your business data is logically separated and secured, meaning one tenant's data is inaccessible to another. This architecture is fundamental to our service and your privacy.</p>
                                <h2 class="card-header" style="margin-top: 30px;">5. Third-Party Services</h2>
                                <p>We may use third-party services (e.g., payment processors, analytics providers) that may have access to your information. We ensure that these third parties adhere to strict data protection standards and are only provided with necessary data to perform their services.</p>
                                <h2 class="card-header" style="margin-top: 30px;">6. Your Choices & Rights</h2>
                                <p>You have the right to access, update, or delete your personal information. You can manage your preferences within your account settings or contact our support team for assistance. We respect your control over your data.</p>
                                <h2 class="card-header" style="margin-top: 30px;">7. Changes to This Policy</h2>
                                <p>We may update our Privacy Policy from time to time. We will notify you of any changes by posting the new policy on this page and updating the "Last Updated" date. We encourage you to review this policy periodically.</p>
                                <p style="margin-top: 20px; font-size: 0.9em; color: var(--light-text-color);">Last Updated: <?php echo date('F d, Y'); ?></p>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'register':
                        ?>
                        <div id="login-container">
                            <div class="login-card">
                                <h2>Register Your Tenant Account</h2>
                                <p style="margin-bottom: 15px; color: var(--light-text-color);">Start your <strong>6-day free trial</strong> today!</p>
                                <form action="<?php echo ROOT_URL; ?>/register" method="POST" id="tenant-registration-form">
                                    <input type="hidden" name="action" value="register_tenant">
                                    <div class="form-group">
                                        <label for="tenant-name">Business Name</label>
                                        <input type="text" id="tenant-name" name="name" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="tenant-slug">Tenant URL Slug (e.g., <?php echo ROOT_URL; ?>/<strong>yourslug</strong>)</label>
                                        <input type="text" id="tenant-slug" name="slug" pattern="^[a-z0-9-]+$" title="Lowercase letters, numbers, and hyphens only" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="tenant-email">Admin Email</label>
                                        <input type="email" id="tenant-email" name="email" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="tenant-phone">Contact Phone (Optional)</label>
                                        <input type="tel" id="tenant-phone" name="phone">
                                    </div>
                                    <div class="form-group">
                                        <label for="tenant-password">Admin Password</label>
                                        <input type="password" id="tenant-password" name="password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="tenant-confirm-password">Confirm Password</label>
                                        <input type="password" id="tenant-confirm-password" name="confirm_password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="invitation-code">Invitation Code (Optional)</label>
                                        <input type="text" id="invitation-code" name="invitation_code" placeholder="Enter if you have one">
                                    </div>
                                    <div class="form-group">
                                        <label for="captcha-register">CAPTCHA</label>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <img src="<?php echo ROOT_URL; ?>/index.php?action=captcha" onclick="this.src='<?php echo ROOT_URL; ?>/index.php?action=captcha&'+Math.random()" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 5px; height: 40px;" title="Click to refresh">
                                            <input type="text" id="captcha-register" name="captcha" required placeholder="Enter code" style="flex: 1;">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Register Tenant</button>
                                </form>
                                <p style="margin-top: 20px;">Already have an account? <a href="<?php echo ROOT_URL; ?>/login">Login here</a></p>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'login':
                        ?>
                        <div id="login-container">
                            <div class="login-card">
                                <h2>Tenant Login</h2>
                                <p style="margin-bottom: 15px; color: var(--light-text-color);">Enter your tenant's username and password.</p>
                                <form action="<?php echo ROOT_URL; ?>/login" method="POST" id="tenant-login-form">
                                    <input type="hidden" name="action" value="login">
                                    <div class="form-group">
                                        <label for="tenant-login-slug">Tenant Slug</label>
                                        <input type="text" id="tenant-login-slug" name="tenant_slug_redirect" required placeholder="Your tenant slug">
                                    </div>
                                    <div class="form-group">
                                        <label for="username">Username</label>
                                        <input type="text" id="username" name="username" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="password">Password</label>
                                        <input type="password" id="password" name="password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="captcha">CAPTCHA</label>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <img src="<?php echo ROOT_URL; ?>/index.php?action=captcha" onclick="this.src='<?php echo ROOT_URL; ?>/index.php?action=captcha&'+Math.random()" style="cursor: pointer; border: 1px solid var(--border-color); border-radius: 5px; height: 40px;" title="Click to refresh">
                                            <input type="text" id="captcha" name="captcha" required placeholder="Enter code" style="flex: 1;">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Login</button>
                                </form>
                                <p style="margin-top: 20px;">Don't have a tenant? <a href="<?php echo ROOT_URL; ?>/register">Register now</a></p>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'create-superadmin':
                        ?>
                        <div id="login-container">
                            <div class="login-card">
                                <h2>Create Superadmin Account</h2>
                                <p style="margin-bottom: 15px; color: var(--light-text-color);">This is a one-time setup for the system owner.</p>
                                <form action="<?php echo ROOT_URL; ?>/<?php echo SUPERADMIN_SLUG; ?>/login" method="POST" id="create-superadmin-form">
                                    <input type="hidden" name="action" value="create_superadmin">
                                    <div class="form-group">
                                        <label for="superadmin-username">Username</label>
                                        <input type="text" id="superadmin-username" name="username" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="superadmin-password">Password</label>
                                        <input type="password" id="superadmin-password" name="password" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="superadmin-confirm-password">Confirm Password</label>
                                        <input type="password" id="superadmin-confirm-password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Create Superadmin</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        break;
                    default:
                        // Fallback, should be handled by redirects
                        redirect('home');
                        break;
                }
                ?>
            </main>
            <footer class="public-footer">
                <p>&copy; <?php echo date('Y'); ?> <a href="<?php echo ROOT_URL; ?>/" style="color:var(--primary-color); text-decoration:none;"><?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?></a>. All rights reserved.</p>
                <p>Created by: Yasin Ullah – Bannu Software Solutions</p>
                <p>Website: <a href="https://www.yasinbss.com" target="_blank" style="color:var(--primary-color); text-decoration:none;">https://www.yasinbss.com</a></p>
                <p>WhatsApp: 03361593533</p>
            </footer>
        </div>
    <?php elseif (IS_SUPERADMIN_MODE): ?>
        <div id="app-container">
            <aside class="sidebar">
                <div class="sidebar-header-row">
                    <button class="hamburger-menu" id="hamburger-menu"><i class="fas fa-bars"></i></button>
                    <h2>Superadmin</h2>
                </div>
                <nav>
                    <ul>
                        <li><a href="<?php echo get_redirect_url('dashboard'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> <span class="sidebar-label">Dashboard</span></a></li>
                        <li><a href="<?php echo get_redirect_url('tenants'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'tenants' ? 'active' : ''; ?>"><i class="fas fa-city"></i> <span class="sidebar-label">Tenants</span></a></li>
                        <li><a href="<?php echo get_redirect_url('plans'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'plans' ? 'active' : ''; ?>"><i class="fas fa-cubes"></i> <span class="sidebar-label">Subscription Plans</span></a></li>
                        <li><a href="<?php echo get_redirect_url('news'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'news' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> <span class="sidebar-label">News & Updates</span></a></li>
                        <li><a href="<?php echo get_redirect_url('settings'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'settings' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> <span class="sidebar-label">Settings</span></a></li>
                        <li><a href="<?php echo get_redirect_url('logs'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'logs' ? 'active' : ''; ?>"><i class="fas fa-history"></i> <span class="sidebar-label">Audit Logs</span></a></li>
                        <li><a href="<?php echo get_redirect_url('backup-restore'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'backup-restore' ? 'active' : ''; ?>"><i class="fas fa-database"></i> <span class="sidebar-label">Backup/Restore</span></a></li>
                    </ul>
                </nav>
                <div class="user-info">
                    Logged in as <span><?php echo html($_SESSION['superadmin_username']); ?> (Superadmin)</span><br>
                    <a href="#" onclick="document.getElementById('change-password-modal').classList.add('active'); return false;">Change Password</a> | 
                    <a href="<?php echo get_redirect_url('logout'); ?>">Logout</a>
                </div>
                <div class="dark-mode-toggle">
                    <label for="dark-mode-switch">Dark Mode</label>
                    <label class="switch">
                        <input type="checkbox" id="dark-mode-switch">
                        <span class="slider"></span>
                    </label>
                </div>
            </aside>
            <main class="content">
                <div class="global-search-bar">
                    <button id="mobile-nav-toggle" class="hamburger-menu" title="Menu" style="display:none;"><i class="fas fa-bars"></i></button>
                    <input type="text" id="global-search-input" placeholder="Global Search (Tenants, Plans, Logs)..." disabled>
                    <div id="global-search-results" class="global-search-results"></div>
                </div>
                <?php
                if (isset($_SESSION['toast'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
                    unset($_SESSION['toast']);
                }
                switch (CURRENT_PAGE) {
                    case 'dashboard':
                        $total_tenants = $conn->query('SELECT COUNT(*) FROM tenants')->fetch_row()[0];
                        $active_tenants = $conn->query('SELECT COUNT(*) FROM tenants WHERE status = "active"')->fetch_row()[0];
                        $pending_tenants = $conn->query('SELECT COUNT(*) FROM tenants WHERE status = "pending"')->fetch_row()[0];
                        $expired_tenants = $conn->query('SELECT COUNT(*) FROM tenants WHERE subscription_end_date < CURDATE() AND status = "active"')->fetch_row()[0];
                        $total_revenue_lifetime = $conn->query('SELECT SUM(amount) FROM subscription_payments WHERE status = "approved"')->fetch_row()[0] ?? 0;
                        $total_plans = $conn->query('SELECT COUNT(*) FROM subscription_plans')->fetch_row()[0];

                        $pending_payments_query = "SELECT sp.*, t.name AS tenant_name FROM subscription_payments sp JOIN tenants t ON sp.tenant_id = t.id WHERE sp.status = 'pending' ORDER BY payment_date ASC LIMIT 5";
                        $pending_payments_result = $conn->query($pending_payments_query);
                        $pending_payments = [];
                        if ($pending_payments_result) {
                            while($row = $pending_payments_result->fetch_assoc()) {
                                $pending_payments[] = $row;
                            }
                        }
                        ?>
                        <section id="superadmin-dashboard" class="page-content active">
                            <div class="page-header">
                                <h1>Superadmin Dashboard</h1>
                            </div>
                            <div class="dashboard-grid">
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Total Tenants</h3>
                                    <p><?php echo html($total_tenants); ?></p>
                                </div>
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Active Tenants</h3>
                                    <p><?php echo html($active_tenants); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Pending Approvals</h3>
                                    <p class="danger"><?php echo html($pending_tenants); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Expired Subscriptions</h3>
                                    <p class="danger"><?php echo html($expired_tenants); ?></p>
                                </div>
                            </div>
                            <div class="dashboard-grid">
                                <div class="dashboard-card">
                                    <h3>Total Lifetime Revenue</h3>
                                    <p><?php echo format_currency($total_revenue_lifetime); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Active Plans</h3>
                                    <p><?php echo html($total_plans); ?></p>
                                </div>
                            </div>

                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header">Pending Subscription Payments</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Payment ID</th>
                                                <th>Tenant Name</th>
                                                <th>Amount</th>
                                                <th>Months</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pending-payments-list">
                                            <?php if (!empty($pending_payments)): ?>
                                                <?php foreach ($pending_payments as $payment): ?>
                                                    <tr>
                                                        <td><?php echo html($payment['id']); ?></td>
                                                        <td><a href="<?php echo ROOT_URL . '/' . SUPERADMIN_SLUG . '/tenants#edit-' . html($payment['tenant_id']); ?>" style="color: var(--primary-color); text-decoration: none;"><?php echo html($payment['tenant_name']); ?></a></td>
                                                        <td><?php echo format_currency($payment['amount']); ?></td>
                                                        <td><?php echo html($payment['months_subscribed']); ?></td>
                                                        <td><?php echo format_date($payment['payment_date']); ?></td>
                                                        <td class="actions">
                                                            <button class="btn btn-info btn-sm view-payment-proof-btn" data-payment-id="<?php echo html($payment['id']); ?>"><i class="fas fa-eye"></i> View</button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6">No pending payments.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'tenants':
                        $all_plans_query = $conn->query('SELECT id, name FROM subscription_plans ORDER BY name ASC');
                        $all_plans_options = '';
                        if ($all_plans_query) {
                            while($row = $all_plans_query->fetch_assoc()) {
                                $all_plans_options .= '<option value="' . html($row['id']) . '">' . html($row['name']) . '</option>';
                            }
                        }
                        ?>
                        <section id="tenants" class="page-content active">
                            <div class="page-header">
                                <h1>Manage Tenants</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-primary" id="add-tenant-btn"><i class="fas fa-plus"></i> Add Tenant</button>
                                    <button class="btn btn-secondary" id="export-tenants-btn"><i class="fas fa-download"></i> Export Tenants</button>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="tenant-search">Search Tenants</label>
                                    <input type="text" id="tenant-search" placeholder="Search by name, slug, email...">
                                </div>
                                <div class="form-group">
                                    <label for="tenant-status-filter">Status</label>
                                    <select id="tenant-status-filter">
                                        <option value="all">All Statuses</option>
                                        <option value="pending">Pending</option>
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="banned">Banned</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Slug</th>
                                            <th>Email</th>
                                            <th>Status</th>
                                            <th>Plan</th>
                                            <th>Expiry Date</th>
                                            <th>Uploads</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tenants-list">
                                        <tr><td colspan="9">Loading tenants...</td></tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="tenants-pagination">
                                    <button id="tenants-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="tenants-page-info">Page 1 of 1</span>
                                    <button id="tenants-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'plans':
                        $all_tenant_pages = ['dashboard', 'books', 'users', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'online-orders', 'promotions', 'expenses', 'reports', 'live-sales', 'news', 'settings', 'public-sale-links', 'print-barcodes', 'backup-restore', 'customer-dashboard', 'online-shop-cart', 'my-orders', 'profile', 'subscription'];
                        ?>
                        <section id="plans" class="page-content active">
                            <div class="page-header">
                                <h1>Subscription Plans</h1>
                                <button class="btn btn-primary" id="add-plan-btn"><i class="fas fa-plus"></i> Add New Plan</button>
                            </div>
                            <div class="card">
                                <div class="card-header">Current Plans</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Price / Month</th>
                                                <th>File Uploads</th>
                                                <th>Active</th>
                                                <th>Description</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="plans-list">
                                            <tr><td colspan="6">Loading plans...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'settings':
                        ?>
                        <section id="superadmin-settings" class="page-content active">
                            <div class="page-header">
                                <h1>Superadmin Settings</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">General System Information</div>
                                <form action="<?php echo get_redirect_url('settings'); ?>" method="POST" id="superadmin-settings-form">
                                    <input type="hidden" name="action" value="save_superadmin_settings">
                                    <div class="form-group">
                                        <label for="sa-system-name">System Name</label>
                                        <input type="text" id="sa-system-name" name="system_name" value="<?php echo html($superadmin_settings['system_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-slogan">Slogan</label>
                                        <input type="text" id="sa-slogan" name="slogan" value="<?php echo html($superadmin_settings['slogan'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-default-price">Default Subscription Price per Month</label>
                                        <input type="number" id="sa-default-price" name="default_subscription_price_per_month" value="<?php echo html($superadmin_settings['default_subscription_price_per_month'] ?? '499.00'); ?>" step="0.01" min="0" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-contact-email">Contact Email</label>
                                        <input type="email" id="sa-contact-email" name="contact_email" value="<?php echo html($superadmin_settings['contact_email'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-contact-phone">Contact Phone</label>
                                        <input type="tel" id="sa-contact-phone" name="contact_phone" value="<?php echo html($superadmin_settings['contact_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-facebook-url">Facebook URL</label>
                                        <input type="url" id="sa-facebook-url" name="facebook_url" value="<?php echo html($superadmin_settings['facebook_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-twitter-url">Twitter URL</label>
                                        <input type="url" id="sa-twitter-url" name="twitter_url" value="<?php echo html($superadmin_settings['twitter_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-linkedin-url">LinkedIn URL</label>
                                        <input type="url" id="sa-linkedin-url" name="linkedin_url" value="<?php echo html($superadmin_settings['linkedin_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="sa-default-currency-symbol">Default Currency Symbol</label>
                                        <input type="text" id="sa-default-currency-symbol" name="default_currency_symbol" value="<?php echo html($superadmin_settings['default_currency_symbol'] ?? 'PKR '); ?>" required>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                                    </div>
                                </form>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'news':
                        ?>
                        <section id="superadmin-news" class="page-content active">
                            <div class="page-header">
                                <h1>Superadmin News & Updates</h1>
                                <button class="btn btn-primary" id="add-superadmin-news-btn"><i class="fas fa-plus"></i> Add News</button>
                            </div>
                            <div class="card">
                                <div class="card-header">Manage Global Announcements</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Title</th>
                                                <th>Visibility</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="superadmin-news-list">
                                            <tr><td colspan="5">Loading news items...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'logs':
                        ?>
                        <section id="superadmin-logs" class="page-content active">
                            <div class="page-header">
                                <h1>Audit Logs</h1>
                                <button class="btn btn-danger" id="clean-logs-btn"><i class="fas fa-eraser"></i> Clean Old Logs</button>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="log-search">Search Logs</label>
                                    <input type="text" id="log-search" placeholder="Search by action, description, IP...">
                                </div>
                                <div class="form-group">
                                    <label for="log-type-filter">Log Type</label>
                                    <select id="log-type-filter">
                                        <option value="superadmin">Superadmin Logs</option>
                                        <option value="tenant">Tenant Logs</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>User Type</th>
                                            <th>User Name</th>
                                            <th>Tenant</th>
                                            <th>Action</th>
                                            <th>Description</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody id="audit-logs-list">
                                        <tr><td colspan="7">Loading logs...</td></tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="logs-pagination">
                                    <button id="logs-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="logs-page-info">Page 1 of 1</span>
                                    <button id="logs-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                        <?php
                        break;
                    case 'backup-restore':
                        ?>
                        <section id="superadmin-backup-restore" class="page-content active">
                            <div class="page-header">
                                <h1>Backup & Restore (Full System)</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">Export All System Data</div>
                                <p>Export all SaaS data (Superadmin settings, Tenants, Plans, Payments, Users, Books, etc.) as a JSON file. This file can be used to restore the entire system.</p>
                                <form action="<?php echo get_redirect_url('backup-restore'); ?>" method="POST">
                                    <input type="hidden" name="action" value="sa_export_all_data">
                                    <button type="submit" class="btn btn-primary" id="sa-export-all-data-btn"><i class="fas fa-download"></i> Export All Data</button>
                                </form>
                            </div>
                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header">Import All System Data</div>
                                <p>Import a previously exported JSON file to restore the entire SaaS system. <strong>Warning: This will overwrite ALL existing data for ALL tenants and Superadmin!</strong></p>
                                <form action="<?php echo get_redirect_url('backup-restore'); ?>" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="sa_import_all_data">
                                    <div class="form-group">
                                        <label for="sa-import-file">Select JSON File to Import</label>
                                        <input type="file" id="sa-import-file" name="import_file" accept=".json">
                                    </div>
                                    <button type="submit" class="btn btn-danger" id="sa-import-all-data-btn" disabled><i class="fas fa-upload"></i> Import All Data</button>
                                </form>
                            </div>
                        </section>
                        <?php
                        break;
                    default:
                        redirect('dashboard');
                        break;
                }
                ?>
                <footer class="public-footer">
                    <p>&copy; <?php echo date('Y'); ?> <a href="<?php echo ROOT_URL; ?>/" style="color:var(--primary-color); text-decoration:none;"><?php echo html($superadmin_settings['system_name'] ?? 'Bookshop SaaS'); ?></a>. All rights reserved.</p>
                    <p>Created by: Yasin Ullah – Bannu Software Solutions</p>
                    <p>Website: <a href="https://www.yasinbss.com" target="_blank" style="color:var(--primary-color); text-decoration:none;">https://www.yasinbss.com</a></p>
                    <p>WhatsApp: 03361593533</p>
                </footer>
            </main>
        </div>
    <?php else: // Tenant App Mode ?>
        <div id="app-container">
            <aside class="sidebar">
                <div class="sidebar-header-row">
                    <button class="hamburger-menu" id="hamburger-menu"><i class="fas fa-bars"></i></button>
                    <h2><?php echo html($settings['system_name'] ?? TENANT_NAME); ?></h2>
                </div>
                <nav>
                    <ul>
                        <?php if (hasPlanAccess('dashboard')): ?><li><a href="<?php echo get_redirect_url('dashboard'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> <span class="sidebar-label">Dashboard</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('books')): ?><li><a href="<?php echo get_redirect_url('books'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'books' ? 'active' : ''; ?>"><i class="fas fa-box-open"></i> <span class="sidebar-label">Products</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('customers')): ?><li><a href="<?php echo get_redirect_url('customers'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'customers' ? 'active' : ''; ?>"><i class="fas fa-users"></i> <span class="sidebar-label">Customers</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('users')): ?><li><a href="<?php echo get_redirect_url('users'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'users' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i> <span class="sidebar-label">Users & Roles</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('suppliers')): ?><li><a href="<?php echo get_redirect_url('suppliers'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'suppliers' ? 'active' : ''; ?>"><i class="fas fa-truck-moving"></i> <span class="sidebar-label">Suppliers</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('purchase-orders')): ?><li><a href="<?php echo get_redirect_url('purchase-orders'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'purchase-orders' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> <span class="sidebar-label">Purchase Orders</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('cart')): ?><li><a href="<?php echo get_redirect_url('cart'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'cart' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> <span class="sidebar-label">POS (Cart)</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('sales-history')): ?><li><a href="<?php echo get_redirect_url('sales-history'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'sales-history' ? 'active' : ''; ?>"><i class="fas fa-receipt"></i> <span class="sidebar-label">Sales History</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('online-orders')): ?><li><a href="<?php echo get_redirect_url('online-orders'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'online-orders' ? 'active' : ''; ?>"><i class="fas fa-globe"></i> <span class="sidebar-label">Online Orders</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('promotions')): ?><li><a href="<?php echo get_redirect_url('promotions'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'promotions' ? 'active' : ''; ?>"><i class="fas fa-tag"></i> <span class="sidebar-label">Promotions</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('expenses')): ?><li><a href="<?php echo get_redirect_url('expenses'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'expenses' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> <span class="sidebar-label">Expenses</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('reports')): ?><li><a href="<?php echo get_redirect_url('reports'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> <span class="sidebar-label">Reports</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('live-sales')): ?><li><a href="<?php echo get_redirect_url('live-sales'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'live-sales' ? 'active' : ''; ?>"><i class="fas fa-satellite-dish"></i> <span class="sidebar-label">Live Sales</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('news')): ?><li><a href="<?php echo get_redirect_url('news'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'news' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> <span class="sidebar-label">News & Updates</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('settings')): ?><li><a href="<?php echo get_redirect_url('settings'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'settings' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> <span class="sidebar-label">Settings</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('public-sale-links')): ?><li><a href="<?php echo get_redirect_url('public-sale-links'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'public-sale-links' ? 'active' : ''; ?>"><i class="fas fa-link"></i> <span class="sidebar-label">Secure Sale Links</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('print-barcodes')): ?><li><a href="<?php echo get_redirect_url('print-barcodes'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'print-barcodes' ? 'active' : ''; ?>"><i class="fas fa-print"></i> <span class="sidebar-label">Print Barcodes</span></a></li><?php endif; ?>
                        <?php if (hasPlanAccess('backup-restore')): ?><li><a href="<?php echo get_redirect_url('backup-restore'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'backup-restore' ? 'active' : ''; ?>"><i class="fas fa-database"></i> <span class="sidebar-label">Backup/Restore</span></a></li><?php endif; ?>
                        <li><a href="<?php echo get_redirect_url('subscription'); ?>" class="nav-link <?php echo CURRENT_PAGE === 'subscription' ? 'active' : ''; ?>"><i class="fas fa-crown"></i> <span class="sidebar-label">Subscription</span></a></li>
                    </ul>
                </nav>
                <div class="sidebar-product-navigator"></div>
                <div class="user-info">
                    Logged in as <span><?php echo html($_SESSION['username']); ?> (<?php echo html($_SESSION['user_role_name']); ?>)</span><br>
                    <a href="#" onclick="document.getElementById('change-password-modal').classList.add('active'); return false;">Change Password</a> | 
                    <a href="<?php echo get_redirect_url('logout'); ?>">Logout</a>
                </div>
                <div class="dark-mode-toggle">
                    <label for="dark-mode-switch">Dark Mode</label>
                    <label class="switch">
                        <input type="checkbox" id="