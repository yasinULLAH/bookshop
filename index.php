<?php
ob_start();
session_start();
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'root');
define('DB_NAME', 'bookshop_management');
define('UPLOAD_DIR', 'uploads/covers/');
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

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

function ensure_runtime_schema($conn)
{
    if (table_exists($conn, 'books')) {
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
        $conn->query('UPDATE books SET retail_price = price WHERE retail_price IS NULL OR retail_price = 0');
        $conn->query('UPDATE books SET wholesale_price = price WHERE wholesale_price IS NULL OR wholesale_price = 0');
        $conn->query("UPDATE books SET barcode = REPLACE(isbn, '-', '') WHERE (barcode IS NULL OR barcode = '') AND isbn IS NOT NULL AND isbn <> ''");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS public_sale_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(120) NOT NULL UNIQUE,
        link_name VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        price_mode ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
        created_by INT NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensure_runtime_schema($conn);
$currency_symbol = 'PKR ';
$settings = [];
$settings_result = $conn->query('SELECT setting_key, setting_value FROM settings');
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $currency_symbol = html($settings['currency_symbol'] ?? 'PKR ');
}

function html($text)
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($page, $params = [])
{
    $queryString = http_build_query($params);
    header("Location: index.php?page=$page" . ($queryString ? "&$queryString" : ''));
    exit();
}

function isAdmin()
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function hasAccess($page)
{
    if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        return in_array($page, $_SESSION['permissions']);
    }
    if (isAdmin())
        return true;
    if (isStaff() && in_array($page, ['dashboard', 'books', 'cart', 'sales-history', 'online-orders', 'customers']))
        return true;
    return false;
}

function isCustomer()
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer';
}

function isStaff()
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'staff';
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']) || isset($_SESSION['customer_id']);
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
    if (empty($token) || empty($_SESSION['public_sale_access'][$token]['granted_at'])) {
        return false;
    }
    $grantedAt = (int) $_SESSION['public_sale_access'][$token]['granted_at'];
    if ((time() - $grantedAt) > (8 * 60 * 60)) {
        unset($_SESSION['public_sale_access'][$token]);
        return false;
    }
    return true;
}

function current_public_sale_link($conn, $token)
{
    if (empty($token)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM public_sale_links WHERE token = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

if (isset($_SESSION['auth_started_at']) && (time() - (int) $_SESSION['auth_started_at']) > (8 * 60 * 60)) {
    session_destroy();
    session_start();
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'Your session expired after 8 hours. Please log in again.'];
}
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        session_destroy();
        session_start();
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'You have been logged out.'];
        redirect('login');
    }
    if (in_array($_GET['action'], ['get_public_books_json', 'get_online_order_status', 'get_book_by_barcode_json', 'get_sidebar_products_json', 'get_sale_details_json'])) {
    } elseif (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    header('Content-Type: application/json');
    $action = $_GET['action'];
    switch ($action) {
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
            $book_id = $_GET['book_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $sort = $_GET['sort'] ?? 'name-asc';
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
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
            $sql = "SELECT * FROM books $where_sql ORDER BY $order_by LIMIT ? OFFSET ?";
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
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_public_books_json':
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? 'all';
            $product_type = $_GET['product_type'] ?? 'all';
            $sort = $_GET['sort'] ?? 'name-asc';
            $limit = $_GET['limit'] ?? 12;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['stock > 0'];
            $params = [];
            $types = '';
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
            $sql = "SELECT id, name, author, category, isbn, barcode, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price, stock, description, cover_image, product_type FROM books $where_sql ORDER BY $order_by LIMIT ? OFFSET ?";
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
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_customers_json':
            $customer_id = $_GET['customer_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($customer_id) {
                $where_clauses[] = 'id = ?';
                $params[] = $customer_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
                $types .= 'ssss';
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
            $supplier_id = $_GET['supplier_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
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
            $po_id = $_GET['po_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
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
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = ['stock > 0'];
            $params = [];
            $types = '';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = '(name LIKE ? OR author LIKE ? OR isbn LIKE ? OR barcode LIKE ?)';
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM books $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT id, name, author, barcode, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price, stock, category, product_type FROM books $where_sql ORDER BY name ASC LIMIT ? OFFSET ?";
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
                $books[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'books' => $books, 'total_items' => $total_items]);
            exit();
        case 'get_sales_json':
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
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
            if (!isCustomer()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $customer_id = $_SESSION['customer_id'];
            $stmt = $conn->prepare('SELECT name, phone, email, address FROM customers WHERE id = ?');
            $stmt->bind_param('i', $customer_id);
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
            $public_token = trim($_GET['token'] ?? '');
            if (!isAdmin() && !isStaff() && !has_public_sale_access($public_token)) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $sale_id = $_GET['sale_id'] ?? '';
            $stmt = $conn->prepare('SELECT s.*, c.name AS customer_name 
                                    FROM sales s 
                                    LEFT JOIN customers c ON s.customer_id = c.id 
                                    WHERE s.id = ?');
            $stmt->bind_param('i', $sale_id);
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
            if (!isAdmin() && !isStaff() && !isCustomer()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $customer_id = isCustomer() ? $_SESSION['customer_id'] : ($_GET['customer_id'] ?? '');
            if (empty($customer_id)) {
                echo json_encode(['success' => false, 'message' => 'Customer ID not provided.']);
                exit();
            }
            $stmt = $conn->prepare("SELECT s.*, 
                                    GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                                    FROM sales s
                                    JOIN sale_items si ON s.id = si.sale_id
                                    JOIN books b ON si.book_id = b.id
                                    WHERE s.customer_id = ?
                                    GROUP BY s.id
                                    ORDER BY s.sale_date DESC");
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['success' => true, 'sales' => $sales]);
            exit();
        case 'get_online_orders_json':
            if (!isAdmin() && !isStaff() && !isCustomer()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $order_id = $_GET['order_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'all';
            if (isAdmin() || isStaff()) {
                $status = $_GET['status'] ?? 'pending';
            }
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            if (isCustomer()) {
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
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $promotion_id = $_GET['promotion_id'] ?? null;
            $where_clauses = [];
            $params = [];
            $types = '';
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
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $expense_id = $_GET['expense_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $month = $_GET['month'] ?? '';
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
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
                $stmt_total = $conn->prepare("SELECT SUM(amount) AS total_amount FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
                $stmt_total->bind_param('s', $month);
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
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $stmt = $conn->prepare('SELECT * FROM settings');
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
            if (!isAdmin() && !isStaff()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $report_type = $_GET['report_type'] ?? '';
            $report_data = ['table_html' => '<tr><td colspan="3">No data generated.</td></tr>', 'chart_data' => null, 'raw_data' => []];
            switch ($report_type) {
                case 'sales-daily':
                    $selected_date = $_GET['date'] ?? date('Y-m-d');
                    $start_of_day = $selected_date . ' 00:00:00';
                    $end_of_day = $selected_date . ' 23:59:59';
                    $stmt = $conn->prepare('SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('ss', $start_of_day, $end_of_day);
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
                    $stmt = $conn->prepare('SELECT s.total, si.quantity, s.sale_date FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('ss', $first_day_of_month->format('Y-m-d 00:00:00'), $last_day_of_month->format('Y-m-d 23:59:59'));
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
                    $stmt = $conn->prepare('SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?');
                    $stmt->bind_param('ss', $start_of_month, $end_of_month);
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
                                            JOIN books b ON si.book_id = b.id 
                                            GROUP BY b.id 
                                            ORDER BY total_quantity_sold DESC 
                                            LIMIT 10');
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
                                            JOIN books b ON si.book_id = b.id 
                                            WHERE b.product_type = 'book' AND b.author IS NOT NULL AND b.author != ''
                                            GROUP BY b.author 
                                            ORDER BY total_quantity_sold DESC 
                                            LIMIT 10");
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
                    $stmt = $conn->prepare('SELECT name, author, stock, isbn, product_type FROM books WHERE stock < 5 ORDER BY stock ASC');
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
                    $stmt = $conn->prepare('SELECT category, SUM(amount) AS total_amount FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total_amount DESC');
                    $stmt->bind_param('ss', $start_of_month, $end_of_month);
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
            if (!hasAccess('users')) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $stmt = $conn->query('SELECT u.id, u.username, u.role_id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id');
            $users = $stmt->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            exit();
        case 'get_roles_json':
            if (!hasAccess('users')) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $roles = $conn->query('SELECT * FROM roles')->fetch_all(MYSQLI_ASSOC);
            $perms = $conn->query('SELECT * FROM role_page_permissions')->fetch_all(MYSQLI_ASSOC);
            foreach ($roles as &$r) {
                $r['permissions'] = array_column(array_filter($perms, fn($p) => $p['role_id'] == $r['id']), 'page_key');
            }
            echo json_encode(['success' => true, 'roles' => $roles]);
            exit();
        case 'get_dashboard_stats_json':
            if (!isAdmin() && !isStaff()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $today = date('Y-m-d');
            $start_of_day = $today . ' 00:00:00';
            $end_of_day = $today . ' 23:59:59';
            $stmt = $conn->prepare('SELECT COUNT(*) as cnt, SUM(total) as rev FROM sales WHERE sale_date BETWEEN ? AND ?');
            $stmt->bind_param('ss', $start_of_day, $end_of_day);
            $stmt->execute();
            $today_stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $month_start = date('Y-m-01 00:00:00');
            $stmt = $conn->prepare('SELECT SUM(total) as rev FROM sales WHERE sale_date BETWEEN ? AND ?');
            $stmt->bind_param('ss', $month_start, $end_of_day);
            $stmt->execute();
            $month_stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM online_orders WHERE status = "pending"');
            $stmt->execute();
            $pending_orders = $stmt->get_result()->fetch_row()[0];
            $stmt->close();

            $total_products = $conn->query('SELECT COUNT(*) FROM books')->fetch_row()[0] ?? 0;
            $total_customers = $conn->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetch_row()[0] ?? 0;
            $total_suppliers = $conn->query('SELECT COUNT(*) FROM suppliers')->fetch_row()[0] ?? 0;
            $low_stock_cnt = $conn->query('SELECT COUNT(*) FROM books WHERE stock < 5')->fetch_row()[0] ?? 0;
            $lifetime_rev = $conn->query('SELECT SUM(total) FROM sales')->fetch_row()[0] ?? 0;
            $total_expenses = $conn->query('SELECT SUM(amount) FROM expenses')->fetch_row()[0] ?? 0;
            $active_promos = $conn->query('SELECT COUNT(*) FROM promotions WHERE start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())')->fetch_row()[0] ?? 0;
            $stock_value = $conn->query('SELECT SUM(stock * COALESCE(purchase_price, price)) FROM books')->fetch_row()[0] ?? 0;

            $weekly_labels = [];
            $weekly_data = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $weekly_labels[] = date('D, M d', strtotime($d));
                $ds = $d . ' 00:00:00';
                $de = $d . ' 23:59:59';
                $stmt = $conn->prepare('SELECT SUM(total) as rev FROM sales WHERE sale_date BETWEEN ? AND ?');
                $stmt->bind_param('ss', $ds, $de);
                $stmt->execute();
                $rev = $stmt->get_result()->fetch_assoc()['rev'] ?? 0;
                $weekly_data[] = (float) $rev;
                $stmt->close();
            }
            $stmt = $conn->prepare('SELECT b.name, SUM(si.quantity) as qty FROM sale_items si JOIN sales s ON si.sale_id = s.id JOIN books b ON si.book_id = b.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY b.id ORDER BY qty DESC LIMIT 5');
            $stmt->bind_param('ss', $month_start, $end_of_day);
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
                $s_stmt = $conn->prepare('SELECT SUM(total) FROM sales WHERE sale_date BETWEEN ? AND ?');
                $s_stmt->bind_param('ss', $m_start, $m_end);
                $s_stmt->execute();
                $monthly_sales[] = (float) ($s_stmt->get_result()->fetch_row()[0] ?? 0);
                $s_stmt->close();
                $e_stmt = $conn->prepare('SELECT SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ?');
                $e_stmt->bind_param('ss', $m_start, $m_end);
                $e_stmt->execute();
                $monthly_expenses[] = (float) ($e_stmt->get_result()->fetch_row()[0] ?? 0);
                $e_stmt->close();
            }

            $order_stats = $conn->query('SELECT status, COUNT(*) as cnt FROM online_orders GROUP BY status')->fetch_all(MYSQLI_ASSOC);

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
            if (!isAdmin()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $today = date('Y-m-d');
            $start_of_day = $today . ' 00:00:00';
            $end_of_day = $today . ' 23:59:59';
            $stmt = $conn->prepare('SELECT COUNT(*) as total_orders, SUM(total) as total_revenue, SUM(discount) as total_discount FROM sales WHERE sale_date BETWEEN ? AND ?');
            $stmt->bind_param('ss', $start_of_day, $end_of_day);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = $conn->prepare("SELECT s.id, s.sale_date, c.name AS customer_name, s.total, s.discount, s.promotion_code,
                                    u.username AS sold_by_user, psl.link_name AS public_link_name,
                                    GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                                    FROM sales s 
                                    LEFT JOIN customers c ON s.customer_id = c.id
                                    LEFT JOIN users u ON s.user_id = u.id
                                    LEFT JOIN public_sale_links psl ON psl.id = SUBSTRING_INDEX(SUBSTRING_INDEX(s.promotion_code, '-', 3), '-', -1) AND s.promotion_code LIKE 'PUBLIC-LINK-%'
                                    JOIN sale_items si ON s.id = si.sale_id 
                                    JOIN books b ON si.book_id = b.id
                                    WHERE s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.sale_date DESC LIMIT 50");
            $stmt->bind_param('ss', $start_of_day, $end_of_day);
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
            if (!isAdmin() && !isStaff()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $query = $_GET['query'] ?? '';
            $results = [];
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'results' => []]);
                exit();
            }
            $search_term = '%' . $query . '%';
            $stmt = $conn->prepare('SELECT id, name FROM books WHERE name LIKE ? LIMIT 5');
            $stmt->bind_param('s', $search_term);
            $stmt->execute();
            $book_res = $stmt->get_result();
            while ($row = $book_res->fetch_assoc()) {
                $results[] = ['type' => 'Product', 'id' => $row['id'], 'name' => $row['name'], 'link' => 'index.php?page=books'];
            }
            $stmt->close();
            $stmt = $conn->prepare('SELECT id, name FROM customers WHERE name LIKE ? LIMIT 5');
            $stmt->bind_param('s', $search_term);
            $stmt->execute();
            $customer_res = $stmt->get_result();
            while ($row = $customer_res->fetch_assoc()) {
                $results[] = ['type' => 'Customer', 'id' => $row['id'], 'name' => $row['name'], 'link' => 'index.php?page=customers'];
            }
            $stmt->close();
            $stmt = $conn->prepare('SELECT id FROM sales WHERE id LIKE ? LIMIT 5');
            $stmt->bind_param('s', $search_term);
            $stmt->execute();
            $sale_res = $stmt->get_result();
            while ($row = $sale_res->fetch_assoc()) {
                $results[] = ['type' => 'Sale', 'id' => $row['id'], 'name' => 'Sale #' . $row['id'], 'link' => 'index.php?page=sales-history'];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'results' => $results]);
            exit();
        case 'get_online_order_status':
            if (!isCustomer()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $order_id = $_GET['order_id'] ?? null;
            if (!$order_id) {
                echo json_encode(['success' => false, 'message' => 'Order ID is required.']);
                exit();
            }
            $stmt = $conn->prepare('SELECT status FROM online_orders WHERE id = ? AND customer_id = ?');
            $stmt->bind_param('ii', $order_id, $_SESSION['customer_id']);
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
            $stmt = $conn->prepare("SELECT *, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price FROM books WHERE barcode = ? OR isbn = ? OR REPLACE(IFNULL(isbn,''), '-', '') = ? LIMIT 1");
            $stmt->bind_param('sss', $cleanBarcode, $cleanBarcode, $cleanBarcode);
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
            $token = trim($_GET['token'] ?? '');
            if (!isLoggedIn() && !has_public_sale_access($token)) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $search = trim($_GET['search'] ?? '');
            $category = trim($_GET['category'] ?? 'all');
            $productType = trim($_GET['product_type'] ?? 'all');
            $where = ['stock > 0'];
            $params = [];
            $types = '';
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
            $stmt = $conn->prepare("SELECT id, name, author, category, product_type, barcode, stock, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price FROM books {$whereSql} ORDER BY name ASC LIMIT 150");
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
                $rows[] = $row;
            }
            $stmt->close();
            $categoryRows = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
            $categories = [];
            if ($categoryRows) {
                while ($cat = $categoryRows->fetch_assoc()) {
                    $categories[] = $cat['category'];
                }
            }
            echo json_encode(['success' => true, 'books' => $rows, 'categories' => $categories, 'price_mode' => $priceMode]);
            exit();
        case 'ajax_quick_sell':
            if (!isAdmin() && !isStaff()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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
                $stmt_book = $conn->prepare('SELECT name, price, stock FROM books WHERE id = ?');
                $stmt_book->bind_param('i', $book_id);
                $stmt_book->execute();
                $book_data = $stmt_book->get_result()->fetch_assoc();
                $stmt_book->close();
                if (!$book_data)
                    throw new Exception('Product not found.');
                if ($book_data['stock'] < $qty)
                    throw new Exception('Not enough stock. Available: ' . $book_data['stock']);

                $user_id = $_SESSION['user_id'];
                $subtotal = $book_data['price'] * $qty;
                $stmt_sale = $conn->prepare('INSERT INTO sales (customer_id, user_id, subtotal, discount, total) VALUES (NULL, ?, ?, 0, ?)');
                $stmt_sale->bind_param('idd', $user_id, $subtotal, $subtotal);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();

                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, 0)');
                $stmt_sale_item->bind_param('iiid', $sale_id, $book_id, $qty, $book_data['price']);
                $stmt_sale_item->execute();
                $stmt_sale_item->close();

                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE id = ?');
                $stmt_update_stock->bind_param('ii', $qty, $book_id);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();

                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Quick sale completed for ' . $qty . 'x ' . html($book_data['name']), 'sale_id' => $sale_id]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Quick sale failed: ' . $e->getMessage()]);
            }
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
    if (in_array($action, ['login', 'customer_register', 'customer_login', 'public_sale_login', 'submit_public_sale'])) {
    } elseif (!isLoggedIn()) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
        redirect('login');
    }
    switch ($action) {
        case 'login':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $stmt = $conn->prepare('SELECT u.id, u.username, u.password_hash, u.role_id, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['role_id'] = $user['role_id'];

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
                $_SESSION['auth_started_at'] = time();
                $_SESSION['toast'] = ['type' => 'success', 'message' => 'Welcome, ' . html($user['username']) . '!'];
                redirect('dashboard');
            } else {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid username or password.'];
                redirect('login');
            }
            break;
        case 'customer_login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM customers WHERE email = ? AND is_active = 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();
            if ($customer && password_verify($password, $customer['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['user_role'] = 'customer';
                $_SESSION['auth_started_at'] = time();
                $_SESSION['auth_started_at'] = time();
                $_SESSION['toast'] = ['type' => 'success', 'message' => 'Welcome, ' . html($customer['name']) . '!'];
                redirect('customer-dashboard');
            } else {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid email or password.'];
                redirect('customer-login');
            }
            break;
        case 'customer_register':
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $address = $_POST['address'] ?? '';
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
            if (strlen($password) < 6) {
                $message = 'Password must be at least 6 characters long.';
                break;
            }
            $stmt_check = $conn->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt_check->bind_param('s', $email);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'An account with this email already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare('INSERT INTO customers (name, phone, email, password_hash, address, is_active) VALUES (?, ?, ?, ?, ?, 1)');
            $stmt->bind_param('sssss', $name, $phone, $email, $password_hash, $address);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Registration successful! You can now log in.';
                redirect('customer-login', ['toast_type' => 'success', 'toast_message' => $message]);
            } else {
                $message = 'Failed to register: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_book':
            if (!isAdmin() && !isStaff()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
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
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['cover_image']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array($file_ext, $allowed_ext)) {
                    $new_file_name = uniqid('cover_') . '.' . $file_ext;
                    $destination = UPLOAD_DIR . $new_file_name;
                    if (move_uploaded_file($file_tmp_name, $destination)) {
                        if ($cover_image_path && file_exists($cover_image_path)) {
                            unlink($cover_image_path);
                        }
                        $cover_image_path = $destination;
                    } else {
                        $message = 'Failed to upload cover image.';
                        break;
                    }
                } else {
                    $message = 'Only JPG, JPEG, PNG, GIF files are allowed for cover image.';
                    break;
                }
            } else if (isset($_POST['remove_cover_image']) && $_POST['remove_cover_image'] === 'true') {
                if ($cover_image_path && file_exists($cover_image_path)) {
                    unlink($cover_image_path);
                }
                $cover_image_path = null;
            }
            if ($book_id) {
                $stmt = $conn->prepare('UPDATE books SET name=?, product_type=?, author=?, category=?, isbn=?, publisher=?, year=?, price=?, purchase_price=?, retail_price=?, wholesale_price=?, stock=?, barcode=?, description=?, cover_image=? WHERE id=?');
                $stmt->bind_param('ssssssiddddisssi', $name, $product_type, $author, $category, $isbn, $publisher, $year, $price, $purchase_price, $retail_price, $wholesale_price, $stock, $barcode, $description, $cover_image_path, $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product updated successfully!';
                } else {
                    $message = 'Failed to update product: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO books (name, product_type, author, category, isbn, publisher, year, price, purchase_price, retail_price, wholesale_price, stock, barcode, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssssiddddisss', $name, $product_type, $author, $category, $isbn, $publisher, $year, $price, $purchase_price, $retail_price, $wholesale_price, $stock, $barcode, $description, $cover_image_path);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product added successfully!';
                } else {
                    $message = 'Failed to add product: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_book':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
            }
            $book_id = $_POST['book_id'] ?? null;
            if ($book_id) {
                $sales_check_stmt = $conn->prepare('SELECT COUNT(*) FROM sale_items WHERE book_id = ?');
                $sales_check_stmt->bind_param('i', $book_id);
                $sales_check_stmt->execute();
                $has_sales = $sales_check_stmt->get_result()->fetch_row()[0] > 0;
                $sales_check_stmt->close();
                $po_check_stmt = $conn->prepare('SELECT COUNT(*) FROM po_items WHERE book_id = ?');
                $po_check_stmt->bind_param('i', $book_id);
                $po_check_stmt->execute();
                $has_pos = $po_check_stmt->get_result()->fetch_row()[0] > 0;
                $po_check_stmt->close();
                $online_order_check_stmt = $conn->prepare('SELECT COUNT(*) FROM online_order_items WHERE book_id = ?');
                $online_order_check_stmt->bind_param('i', $book_id);
                $online_order_check_stmt->execute();
                $has_online_orders = $online_order_check_stmt->get_result()->fetch_row()[0] > 0;
                $online_order_check_stmt->close();
                if ($has_sales || $has_pos || $has_online_orders) {
                    $message = 'Cannot delete product with existing sales, purchase orders, or online orders.';
                    break;
                }
                $stmt = $conn->prepare('SELECT cover_image FROM books WHERE id = ?');
                $stmt->bind_param('i', $book_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $book = $result->fetch_assoc();
                $stmt->close();
                if ($book && $book['cover_image'] && file_exists($book['cover_image'])) {
                    unlink($book['cover_image']);
                }
                $stmt = $conn->prepare('DELETE FROM books WHERE id=?');
                $stmt->bind_param('i', $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Product deleted successfully!';
                } else {
                    $message = 'Failed to delete product: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Product ID not provided.';
            }
            break;
        case 'quick_sell':
            if (!isAdmin() && !isStaff()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
            }
            $book_id = $_POST['book_id'] ?? null;
            if (empty($book_id)) {
                $message = 'Product ID not provided.';
                break;
            }
            $conn->begin_transaction();
            try {
                $stmt_book = $conn->prepare('SELECT name, price, stock FROM books WHERE id = ?');
                $stmt_book->bind_param('i', $book_id);
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
                $stmt_sale = $conn->prepare('INSERT INTO sales (customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (NULL, ?, ?, 0, ?, NULL)');
                $stmt_sale->bind_param('idd', $user_id, $subtotal, $total);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, 1, ?, 0)');
                $stmt_sale_item->bind_param('iid', $sale_id, $book_id, $book_data['price']);
                $stmt_sale_item->execute();
                $stmt_sale_item->close();
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - 1 WHERE id = ?');
                $stmt_update_stock->bind_param('i', $book_id);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();
                $conn->commit();
                $message_type = 'success';
                $message = 'Quick sale completed for ' . html($book_data['name']) . '!';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Quick sale failed: ' . $e->getMessage();
            }
            break;
        case 'update_stock':
            if (!isAdmin() && !isStaff()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
            }
            $book_id = $_POST['book_id'] ?? null;
            $quantity_to_add = $_POST['quantity_to_add'] ?? null;
            if (empty($book_id) || !is_numeric($quantity_to_add) || $quantity_to_add <= 0) {
                $message = 'Invalid input for restock.';
                break;
            }
            $stmt = $conn->prepare('UPDATE books SET stock = stock + ? WHERE id = ?');
            $stmt->bind_param('ii', $quantity_to_add, $book_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Product stock updated successfully!';
            } else {
                $message = 'Failed to update stock: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_user':
            if (!hasAccess('users')) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized'];
                redirect('users');
            }
            $u_id = $_POST['user_id'] ?? null;
            $u_name = $_POST['username'];
            $u_role = (int) $_POST['role_id'];
            $u_pass = $_POST['password'] ?? '';
            $stmt_check = $conn->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check_id = $u_id ?: 0;
            $stmt_check->bind_param('si', $u_name, $check_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'Username exists.';
                break;
            }
            $stmt_check->close();
            if ($u_id) {
                if (!empty($u_pass)) {
                    $hash = password_hash($u_pass, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare('UPDATE users SET username=?, role_id=?, password_hash=? WHERE id=?');
                    $stmt->bind_param('sisi', $u_name, $u_role, $hash, $u_id);
                } else {
                    $stmt = $conn->prepare('UPDATE users SET username=?, role_id=? WHERE id=?');
                    $stmt->bind_param('sii', $u_name, $u_role, $u_id);
                }
            } else {
                if (empty($u_pass)) {
                    $message = 'Password required for new user.';
                    break;
                }
                $hash = password_hash($u_pass, PASSWORD_BCRYPT);
                $stmt = $conn->prepare('INSERT INTO users (username, role_id, password_hash) VALUES (?, ?, ?)');
                $stmt->bind_param('sis', $u_name, $u_role, $hash);
            }
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'User saved successfully!';
            } else {
                $message = 'Failed to save user.';
            }
            break;
        case 'delete_user':
            if (!hasAccess('users')) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized'];
                redirect('users');
            }
            $u_id = $_POST['user_id'] ?? null;
            if ($u_id == $_SESSION['user_id']) {
                $message = 'Cannot delete yourself.';
                break;
            }
            $stmt = $conn->prepare('DELETE FROM users WHERE id=?');
            $stmt->bind_param('i', $u_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'User deleted!';
            } else {
                $message = 'Failed to delete user.';
            }
            break;
        case 'save_role':
            if (!hasAccess('users')) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized'];
                redirect('users');
            }
            $r_id = $_POST['role_id'] ?? null;
            $r_name = $_POST['role_name'];
            $pages = $_POST['pages'] ?? [];
            if ($r_id) {
                $stmt = $conn->prepare('UPDATE roles SET name=? WHERE id=?');
                $stmt->bind_param('si', $r_name, $r_id);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare('INSERT INTO roles (name) VALUES (?)');
                $stmt->bind_param('s', $r_name);
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
            break;
        case 'delete_role':
            if (!hasAccess('users')) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized'];
                redirect('users');
            }
            $r_id = $_POST['role_id'] ?? null;
            $stmt_check = $conn->prepare('SELECT COUNT(*) FROM users WHERE role_id=?');
            $stmt_check->bind_param('i', $r_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                $message = 'Cannot delete role assigned to users.';
                break;
            }
            $stmt = $conn->prepare('DELETE FROM roles WHERE id=?');
            $stmt->bind_param('i', $r_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Role deleted!';
            } else {
                $message = 'Failed to delete role.';
            }
            break;
        case 'save_customer':
            $customer_id = $_POST['customer_id'] ?? null;
            $name = $_POST['name'];
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $address = $_POST['address'] ?? null;
            $password = $_POST['password'] ?? null;
            $password_hash = null;
            if (empty($name)) {
                $message = 'Customer name is required.';
                break;
            }
            if ($password) {
                if (strlen($password) < 6) {
                    $message = 'Password must be at least 6 characters long.';
                    break;
                }
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
            }
            if ($email) {
                $stmt_check = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id != ?');
                $stmt_check->bind_param('si', $email, $customer_id);
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
                    $stmt = $conn->prepare('UPDATE customers SET name=?, phone=?, email=?, password_hash=?, address=? WHERE id=?');
                    $stmt->bind_param('sssssi', $name, $phone, $email, $password_hash, $address, $customer_id);
                } else {
                    $stmt = $conn->prepare('UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=?');
                    $stmt->bind_param('ssssi', $name, $phone, $email, $address, $customer_id);
                }
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Customer updated successfully!';
                } else {
                    $message = 'Failed to update customer: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                if (!$password_hash) {
                    $message = 'Password is required for new customer.';
                    break;
                }
                $stmt = $conn->prepare('INSERT INTO customers (name, phone, email, password_hash, address) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('sssss', $name, $phone, $email, $password_hash, $address);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Customer added successfully!';
                } else {
                    $message = 'Failed to add customer: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'toggle_customer_status':
            $customer_id = $_POST['customer_id'] ?? null;
            $current_status = filter_var($_POST['current_status'], FILTER_VALIDATE_BOOLEAN);
            if ($customer_id) {
                $new_status = !$current_status;
                $stmt = $conn->prepare('UPDATE customers SET is_active=? WHERE id=?');
                $stmt->bind_param('ii', $new_status, $customer_id);
                if ($stmt->execute()) {
                    $message_type = 'info';
                    $message = 'Customer status updated to ' . ($new_status ? 'Active' : 'Inactive') . '.';
                } else {
                    $message = 'Failed to update customer status: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Customer ID not provided.';
            }
            break;
        case 'save_supplier':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('suppliers');
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
                $stmt_check = $conn->prepare('SELECT id FROM suppliers WHERE email = ? AND id != ?');
                $stmt_check->bind_param('si', $email, $supplier_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $message = 'A supplier with this email already exists.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
            }
            if ($supplier_id) {
                $stmt = $conn->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?');
                $stmt->bind_param('sssssi', $name, $contact_person, $phone, $email, $address, $supplier_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier updated successfully!';
                } else {
                    $message = 'Failed to update supplier: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('sssss', $name, $contact_person, $phone, $email, $address);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier added successfully!';
                } else {
                    $message = 'Failed to add supplier: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_supplier':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('suppliers');
            }
            $supplier_id = $_POST['supplier_id'] ?? null;
            if ($supplier_id) {
                $stmt_check = $conn->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?');
                $stmt_check->bind_param('i', $supplier_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                    $message = 'Cannot delete supplier with existing purchase orders.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
                $stmt = $conn->prepare('DELETE FROM suppliers WHERE id=?');
                $stmt->bind_param('i', $supplier_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier deleted successfully!';
                } else {
                    $message = 'Failed to delete supplier: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Supplier ID not provided.';
            }
            break;
        case 'save_po':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('purchase-orders');
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
                    $stmt = $conn->prepare('UPDATE purchase_orders SET supplier_id=?, user_id=?, status=?, order_date=?, expected_date=?, total_cost=? WHERE id=?');
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param('iisssdi', $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost, $po_id);
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
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to update PO: ' . $e->getMessage();
                }
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('INSERT INTO purchase_orders (supplier_id, user_id, status, order_date, expected_date, total_cost) VALUES (?, ?, ?, ?, ?, ?)');
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param('iisssd', $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost);
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
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to create PO: ' . $e->getMessage();
                }
            }
            break;
        case 'delete_po':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('purchase-orders');
            }
            $po_id = $_POST['po_id'] ?? null;
            if ($po_id) {
                $stmt = $conn->prepare('DELETE FROM purchase_orders WHERE id=?');
                $stmt->bind_param('i', $po_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Purchase Order deleted successfully!';
                } else {
                    $message = 'Failed to delete PO: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'PO ID not provided.';
            }
            break;
        case 'receive_po':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('purchase-orders');
            }
            $po_id = $_POST['po_id'] ?? null;
            if ($po_id) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('SELECT status FROM purchase_orders WHERE id = ?');
                    $stmt->bind_param('i', $po_id);
                    $stmt->execute();
                    $current_status = $stmt->get_result()->fetch_assoc()['status'] ?? null;
                    $stmt->close();
                    if ($current_status !== 'received') {
                        $stmt_items = $conn->prepare('SELECT book_id, quantity FROM po_items WHERE po_id = ?');
                        $stmt_items->bind_param('i', $po_id);
                        $stmt_items->execute();
                        $items = $stmt_items->get_result();
                        while ($item = $items->fetch_assoc()) {
                            $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock + ? WHERE id = ?');
                            $stmt_update_stock->bind_param('ii', $item['quantity'], $item['book_id']);
                            $stmt_update_stock->execute();
                            $stmt_update_stock->close();
                        }
                        $stmt_items->close();
                        $stmt_update_po = $conn->prepare("UPDATE purchase_orders SET status = 'received' WHERE id = ?");
                        $stmt_update_po->bind_param('i', $po_id);
                        $stmt_update_po->execute();
                        $stmt_update_po->close();
                        $conn->commit();
                        $message_type = 'success';
                        $message = 'Purchase Order received and product stock updated!';
                    } else {
                        $message = 'Purchase Order already marked as received.';
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to receive PO: ' . $e->getMessage();
                }
            } else {
                $message = 'PO ID not provided.';
            }
            break;
        case 'complete_sale':
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
                    $stmt_book = $conn->prepare('SELECT stock, price, category FROM books WHERE id = ?');
                    $stmt_book->bind_param('i', $cart_item['bookId']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $cart_item['quantity']) {
                        throw new Exception('Not enough stock for ' . html($cart_item['name']) . '. Available: ' . ($book_data['stock'] ?? 0) . ', Needed: ' . $cart_item['quantity'] . '.');
                    }
                    $subtotal += $book_data['price'] * $cart_item['quantity'];
                    $cart_item['price_per_unit'] = $book_data['price'];

                    // Apply manual POS discount if set
                    $manual_disc = isset($cart_item['custom_discount']) ? (float) $cart_item['custom_discount'] : 0;
                    $cart_item['discount_per_unit'] = min($manual_disc, $book_data['price']);
                    $cart_item['category'] = $book_data['category'];

                    $total_discount += ($cart_item['discount_per_unit'] * $cart_item['quantity']);
                }
                unset($cart_item);
                if ($promotion_code) {
                    $stmt_promo = $conn->prepare('SELECT * FROM promotions WHERE code = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())');
                    $stmt_promo->bind_param('s', $promotion_code);
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
                $stmt_sale = $conn->prepare('INSERT INTO sales (customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (?, ?, ?, ?, ?, ?)');
                $user_id = $_SESSION['user_id'] ?? null;
                $stmt_sale->bind_param('iiddds', $customer_id, $user_id, $subtotal, $total_discount, $final_total, $promotion_code);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)');
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE id = ?');
                foreach ($cart_items as $item) {
                    $discount_value_per_unit = $item['discount_per_unit'];
                    $stmt_sale_item->bind_param('iiidd', $sale_id, $item['bookId'], $item['quantity'], $item['price_per_unit'], $discount_value_per_unit);
                    $stmt_sale_item->execute();
                    $stmt_update_stock->bind_param('ii', $item['quantity'], $item['bookId']);
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
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Sale failed: ' . $e->getMessage();
            }
            break;
        case 'place_online_order':
            if (!isCustomer()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('customer-dashboard');
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
                    $stmt_book = $conn->prepare('SELECT stock, price, category FROM books WHERE id = ?');
                    $stmt_book->bind_param('i', $cart_item['bookId']);
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
                    $stmt_promo = $conn->prepare('SELECT * FROM promotions WHERE code = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())');
                    $stmt_promo->bind_param('s', $promotion_code);
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
                $stmt_order = $conn->prepare("INSERT INTO online_orders (customer_id, subtotal, discount, total, promotion_code, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                $stmt_order->bind_param('iddds', $customer_id, $subtotal, $total_discount, $final_total, $promotion_code);
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
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Online order failed: ' . $e->getMessage();
            }
            break;
        case 'approve_online_order':
            if (!isAdmin() && !isStaff()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('online-orders');
            }
            $order_id = $_POST['order_id'] ?? null;
            if (!$order_id) {
                $message = 'Order ID not provided.';
                break;
            }
            $conn->begin_transaction();
            try {
                $stmt_order = $conn->prepare("SELECT * FROM online_orders WHERE id = ? AND status = 'pending'");
                $stmt_order->bind_param('i', $order_id);
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
                    $stmt_book = $conn->prepare('SELECT stock, name FROM books WHERE id = ?');
                    $stmt_book->bind_param('i', $item['book_id']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $item['quantity']) {
                        throw new Exception('Not enough stock for ' . html($book_data['name']) . ' for order ' . $order_id . '.');
                    }
                }
                $user_id = $_SESSION['user_id'];
                $stmt_sale = $conn->prepare('INSERT INTO sales (customer_id, user_id, sale_date, subtotal, discount, total, promotion_code) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt_sale->bind_param('iisddds', $order['customer_id'], $user_id, $order['order_date'], $order['subtotal'], $order['discount'], $order['total'], $order['promotion_code']);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)');
                $stmt_update_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE id = ?');
                foreach ($items as $item) {
                    $stmt_sale_item->bind_param('iiidd', $sale_id, $item['book_id'], $item['quantity'], $item['price_per_unit'], $item['discount_per_unit']);
                    $stmt_sale_item->execute();
                    $stmt_update_stock->bind_param('ii', $item['quantity'], $item['book_id']);
                    $stmt_update_stock->execute();
                }
                $stmt_sale_item->close();
                $stmt_update_stock->close();
                $stmt_update_order = $conn->prepare("UPDATE online_orders SET status = 'approved', sale_id = ? WHERE id = ?");
                $stmt_update_order->bind_param('ii', $sale_id, $order_id);
                $stmt_update_order->execute();
                $stmt_update_order->close();
                $conn->commit();
                $message_type = 'success';
                $message = 'Online order ' . $order_id . ' approved and converted to sale ' . $sale_id . '!';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to approve online order: ' . $e->getMessage();
            }
            break;
        case 'reject_online_order':
            if (!isAdmin() && !isStaff()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('online-orders');
            }
            $order_id = $_POST['order_id'] ?? null;
            if (!$order_id) {
                $message = 'Order ID not provided.';
                break;
            }
            $stmt = $conn->prepare("UPDATE online_orders SET status = 'rejected' WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $order_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message_type = 'info';
                $message = 'Online order ' . $order_id . ' rejected.';
            } else {
                $message = 'Failed to reject online order ' . $order_id . ' (may already be processed or not found).';
            }
            $stmt->close();
            break;
        case 'save_promotion':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('promotions');
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
            $stmt_check = $conn->prepare('SELECT id FROM promotions WHERE code = ? AND id != ?');
            $stmt_check->bind_param('si', $code, $promotion_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'A promotion with this code already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            if ($promotion_id) {
                $stmt = $conn->prepare('UPDATE promotions SET code=?, type=?, value=?, applies_to=?, applies_to_value=?, start_date=?, end_date=? WHERE id=?');
                $stmt->bind_param('ssdsissi', $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date, $promotion_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion updated successfully!';
                } else {
                    $message = 'Failed to update promotion: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO promotions (code, type, value, applies_to, applies_to_value, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssdsiss', $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion added successfully!';
                } else {
                    $message = 'Failed to add promotion: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_promotion':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('promotions');
            }
            $promotion_id = $_POST['promotion_id'] ?? null;
            if ($promotion_id) {
                $stmt_check = $conn->prepare('SELECT COUNT(*) FROM sales WHERE promotion_code IN (SELECT code FROM promotions WHERE id = ?)');
                $stmt_check->bind_param('i', $promotion_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                    $message = 'Cannot delete promotion that has been used in sales.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
                $stmt = $conn->prepare('DELETE FROM promotions WHERE id=?');
                $stmt->bind_param('i', $promotion_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion deleted successfully!';
                } else {
                    $message = 'Failed to delete promotion: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Promotion ID not provided.';
            }
            break;
        case 'save_expense':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('expenses');
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
                $stmt = $conn->prepare('UPDATE expenses SET user_id=?, category=?, description=?, amount=?, expense_date=? WHERE id=?');
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param('isdsdi', $user_id, $category, $description, $amount, $date, $expense_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense updated successfully!';
                } else {
                    $message = 'Failed to update expense: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO expenses (user_id, category, description, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param('isds', $user_id, $category, $description, $amount, $date);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense added successfully!';
                } else {
                    $message = 'Failed to add expense: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_expense':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('expenses');
            }
            $expense_id = $_POST['expense_id'] ?? null;
            if ($expense_id) {
                $stmt = $conn->prepare('DELETE FROM expenses WHERE id=?');
                $stmt->bind_param('i', $expense_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense deleted successfully!';
                } else {
                    $message = 'Failed to delete expense: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Expense ID not provided.';
            }
            break;
        case 'save_settings':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('settings');
            }
            $new_settings = [
                'system_name' => $_POST['system_name'] ?? '',
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
            ];
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
                foreach ($new_settings as $key => $value) {
                    $stmt->bind_param('sss', $key, $value, $value);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
                $message_type = 'success';
                $message = 'Settings updated successfully!';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Failed to update settings: ' . $e->getMessage();
            }
            break;
        case 'import_books_action':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
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
                $stmt_insert = $conn->prepare('INSERT INTO books (name, product_type, author, category, isbn, publisher, year, price, stock, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt_update = $conn->prepare('UPDATE books SET name=?, product_type=?, author=?, category=?, publisher=?, year=?, price=?, stock=?, description=?, cover_image=? WHERE isbn=?');
                foreach ($books_data as $book) {
                    if (!isset($book['name']) || !isset($book['price']) || !isset($book['stock'])) {
                        $skipped_count++;
                        continue;
                    }
                    $book['product_type'] = $book['product_type'] ?? 'general';
                    $book['category'] = $book['category'] ?? 'Uncategorized';
                    $stmt_check = null;
                    $existing_book_id = null;
                    if ($book['product_type'] == 'book' && isset($book['isbn']) && !empty($book['isbn'])) {
                        $stmt_check = $conn->prepare('SELECT id FROM books WHERE isbn = ?');
                        $stmt_check->bind_param('s', $book['isbn']);
                    } else if (isset($book['name']) && !empty($book['name'])) {
                        $stmt_check = $conn->prepare('SELECT id FROM books WHERE name = ? AND product_type = ?');
                        $stmt_check->bind_param('ss', $book['name'], $book['product_type']);
                    }
                    if ($stmt_check) {
                        $stmt_check->execute();
                        $existing_book_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                        $stmt_check->close();
                    }
                    if ($existing_book_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                'ssssidddsss',
                                $book['name'],
                                $book['product_type'],
                                $book['author'] ?? null,
                                $book['category'],
                                $book['publisher'] ?? null,
                                $book['year'] ?? null,
                                $book['price'],
                                $book['stock'],
                                $book['description'] ?? null,
                                $book['cover_image'] ?? null,
                                $book['isbn']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'sssssiddds',
                            $book['name'],
                            $book['product_type'],
                            $book['author'] ?? null,
                            $book['category'],
                            $book['isbn'] ?? null,
                            $book['publisher'] ?? null,
                            $book['year'] ?? null,
                            $book['price'],
                            $book['stock'],
                            $book['description'] ?? null,
                            $book['cover_image'] ?? null
                        );
                        $stmt_insert->execute();
                        $new_count++;
                    }
                }
                $stmt_insert->close();
                $stmt_update->close();
                $conn->commit();
                $message_type = 'success';
                $message = "Products imported: $new_count new, $updated_count updated, $skipped_count skipped.";
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during product import: ' . $e->getMessage();
            }
            break;
        case 'import_customers_action':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('customers');
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
                $stmt_insert = $conn->prepare('INSERT INTO customers (name, phone, email, password_hash, address, is_active) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt_update = $conn->prepare('UPDATE customers SET name=?, phone=?, password_hash=?, address=?, is_active=? WHERE email=?');
                foreach ($customers_data as $customer) {
                    if (!isset($customer['name']) || !isset($customer['email']) || !isset($customer['password'])) {
                        $skipped_count++;
                        continue;
                    }
                    $stmt_check = $conn->prepare('SELECT id FROM customers WHERE email = ?');
                    $stmt_check->bind_param('s', $customer['email']);
                    $stmt_check->execute();
                    $existing_customer_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    $password_hash = password_hash($customer['password'], PASSWORD_BCRYPT);
                    if ($existing_customer_id) {
                        if ($conflict_resolution === 'update') {
                            $phone = $customer['phone'] ?? null;
                            $address = $customer['address'] ?? null;
                            $is_active = (int) ($customer['is_active'] ?? 1);
                            $stmt_update->bind_param(
                                'ssssis',
                                $customer['name'],
                                $phone,
                                $password_hash,
                                $address,
                                $is_active,
                                $customer['email']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'sssssi',
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
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during customer import: ' . $e->getMessage();
            }
            break;
        case 'import_suppliers_action':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('suppliers');
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
                $stmt_insert = $conn->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)');
                $stmt_update = $conn->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, address=? WHERE email=?');
                foreach ($suppliers_data as $supplier) {
                    if (!isset($supplier['name']) || !isset($supplier['email'])) {
                        $skipped_count++;
                        continue;
                    }
                    $stmt_check = $conn->prepare('SELECT id FROM suppliers WHERE email = ?');
                    $stmt_check->bind_param('s', $supplier['email']);
                    $stmt_check->execute();
                    $existing_supplier_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    if ($existing_supplier_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                'sssss',
                                $supplier['name'],
                                $supplier['contact_person'] ?? null,
                                $supplier['phone'] ?? null,
                                $supplier['address'] ?? null,
                                $supplier['email']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            'sssss',
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
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during supplier import: ' . $e->getMessage();
            }
            break;
        case 'export_all_data':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('backup-restore');
            }
            $all_data = [];
            $tables = ['users', 'books', 'customers', 'suppliers', 'sales', 'sale_items', 'purchase_orders', 'po_items', 'expenses', 'promotions', 'settings', 'online_orders', 'online_order_items'];
            foreach ($tables as $table) {
                $result = $conn->query('SELECT * FROM ' . $table);
                if ($result) {
                    $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                } else {
                    error_log('Failed to fetch data for table: ' . $table . ' - ' . $conn->error);
                }
            }
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="general_bookshop_data_backup_' . date('Y-m-d') . '.json"');
            echo json_encode($all_data, JSON_PRETTY_PRINT);
            exit();
        case 'import_all_data':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('backup-restore');
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
            $tables_in_order = ['users', 'books', 'customers', 'suppliers', 'promotions', 'expenses', 'purchase_orders', 'po_items', 'sales', 'sale_items', 'settings', 'online_orders', 'online_order_items'];
            $tables_delete_order = array_reverse($tables_in_order);
            $conn->begin_transaction();
            try {
                $conn->query('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables_delete_order as $table) {
                    $conn->query('TRUNCATE TABLE ' . $table);
                }
                foreach ($tables_in_order as $table) {
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
                $message = 'All data imported successfully!';
            } catch (Exception $e) {
                $conn->rollback();
                $conn->query('SET FOREIGN_KEY_CHECKS = 1');
                $message = 'Error during data import: ' . $e->getMessage();
            }
            break;
        case 'save_public_sale_link':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('dashboard');
            }
            $link_id = $_POST['link_id'] ?? null;
            $link_name = trim($_POST['link_name'] ?? '');
            $access_password = $_POST['access_password'] ?? '';
            $price_mode = ($_POST['price_mode'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
            $notes = trim($_POST['notes'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if ($link_name === '') {
                $message = 'Link name is required.';
                break;
            }
            if (!$link_id && $access_password === '') {
                $message = 'Password is required for a new public sale link.';
                break;
            }
            if ($link_id) {
                if ($access_password !== '') {
                    $password_hash = password_hash($access_password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare('UPDATE public_sale_links SET link_name=?, password_hash=?, price_mode=?, notes=?, is_active=? WHERE id=?');
                    $stmt->bind_param('ssssii', $link_name, $password_hash, $price_mode, $notes, $is_active, $link_id);
                } else {
                    $stmt = $conn->prepare('UPDATE public_sale_links SET link_name=?, price_mode=?, notes=?, is_active=? WHERE id=?');
                    $stmt->bind_param('sssii', $link_name, $price_mode, $notes, $is_active, $link_id);
                }
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Public sale link updated successfully.';
                } else {
                    $message = 'Failed to update public sale link: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $token = str_replace('-', '', generate_uuid()) . substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
                $password_hash = password_hash($access_password, PASSWORD_BCRYPT);
                $created_by = $_SESSION['user_id'];
                $stmt = $conn->prepare('INSERT INTO public_sale_links (token, link_name, password_hash, price_mode, created_by, notes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssisi', $token, $link_name, $password_hash, $price_mode, $created_by, $notes, $is_active);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Public sale link created successfully.';
                } else {
                    $message = 'Failed to create public sale link: ' . $stmt->error;
                }
                $stmt->close();
            }
            break;
        case 'delete_public_sale_link':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('dashboard');
            }
            $link_id = (int) ($_POST['link_id'] ?? 0);
            if ($link_id <= 0) {
                $message = 'Link ID not provided.';
                break;
            }
            $stmt = $conn->prepare('DELETE FROM public_sale_links WHERE id = ?');
            $stmt->bind_param('i', $link_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Public sale link deleted.';
            } else {
                $message = 'Failed to delete public sale link: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'toggle_public_sale_link':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('dashboard');
            }
            $link_id = (int) ($_POST['link_id'] ?? 0);
            $current_status = (int) ($_POST['current_status'] ?? 0);
            $new_status = $current_status ? 0 : 1;
            $stmt = $conn->prepare('UPDATE public_sale_links SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $new_status, $link_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Public sale link status updated.';
            } else {
                $message = 'Failed to update public sale link status: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'public_sale_login':
            $token = trim($_POST['token'] ?? '');
            $access_password = $_POST['access_password'] ?? '';
            $link = current_public_sale_link($conn, $token);
            if (!$link || !password_verify($access_password, $link['password_hash'])) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid password or inactive sale link.'];
                redirect('public-sale', ['token' => $token]);
            }
            if (!isset($_SESSION['public_sale_access'])) {
                $_SESSION['public_sale_access'] = [];
            }
            $_SESSION['public_sale_access'][$token] = [
                'granted_at' => time(),
                'link_id' => $link['id'],
                'price_mode' => $link['price_mode']
            ];
            $_SESSION['toast'] = ['type' => 'success', 'message' => 'Secure sale link unlocked.'];
            redirect('public-sale', ['token' => $token]);
            break;
        case 'submit_public_sale':
            $token = trim($_POST['token'] ?? '');
            $cart_items_json = $_POST['cart_items'] ?? '[]';
            $cart_items = json_decode($cart_items_json, true);
            $link = current_public_sale_link($conn, $token);
            if (!$link || !has_public_sale_access($token)) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'This sale link is locked or expired.'];
                redirect('public-sale', ['token' => $token]);
            }
            if (empty($cart_items) || !is_array($cart_items)) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'No products in the secure sale cart.'];
                redirect('public-sale', ['token' => $token]);
            }
            $conn->begin_transaction();
            try {
                $subtotal = 0;
                $price_mode = $link['price_mode'];
                foreach ($cart_items as &$cart_item) {
                    $book_id = (int) ($cart_item['bookId'] ?? 0);
                    $stmt_book = $conn->prepare('SELECT id, name, stock, price, COALESCE(retail_price, price) AS retail_price, COALESCE(wholesale_price, price) AS wholesale_price FROM books WHERE id = ? LIMIT 1');
                    $stmt_book->bind_param('i', $book_id);
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
                $stmt_sale = $conn->prepare('INSERT INTO sales (customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (NULL, ?, ?, 0, ?, ?)');
                $stmt_sale->bind_param('idds', $creator_user_id, $subtotal, $subtotal, $promotion_code);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare('INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, 0)');
                $stmt_stock = $conn->prepare('UPDATE books SET stock = stock - ? WHERE id = ?');
                foreach ($cart_items as $item) {
                    $book_id = (int) $item['bookId'];
                    $quantity = (int) $item['quantity'];
                    $price_per_unit = (float) $item['price_per_unit'];
                    $stmt_sale_item->bind_param('iiid', $sale_id, $book_id, $quantity, $price_per_unit);
                    $stmt_sale_item->execute();
                    $stmt_stock->bind_param('ii', $quantity, $book_id);
                    $stmt_stock->execute();
                }
                $stmt_sale_item->close();
                $stmt_stock->close();
                $conn->commit();
                $_SESSION['public_sale_last_receipt'][$token] = $sale_id;
                $_SESSION['toast'] = ['type' => 'success', 'message' => 'Sale completed successfully at ' . strtoupper($price_mode) . ' rate.'];
                redirect('public-sale', ['token' => $token]);
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Secure sale failed: ' . $e->getMessage()];
                redirect('public-sale', ['token' => $token]);
            }
            break;
        default:
            $message = 'Invalid action.';
            break;
    }
    $_SESSION['toast'] = ['type' => $message_type, 'message' => $message];
    redirect($_GET['page'] ?? 'dashboard', isset($_GET['token']) ? ['token' => $_GET['token']] : []);
}
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['toast'] = ['type' => 'success', 'message' => 'Welcome, ' . html($user['username']) . '!'];
        redirect('dashboard');
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid username or password.'];
        redirect('login');
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'customer_login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare('SELECT id, name, email, password_hash FROM customers WHERE email = ? AND is_active = 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();
    if ($customer && password_verify($password, $customer['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['customer_id'] = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        $_SESSION['user_role'] = 'customer';
        $_SESSION['toast'] = ['type' => 'success', 'message' => 'Welcome, ' . html($customer['name']) . '!'];
        redirect('customer-dashboard');
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid email or password.'];
        redirect('customer-login');
    }
}
$stmt = $conn->prepare('SELECT COUNT(*) FROM users');
$stmt->execute();
$user_count = $stmt->get_result()->fetch_row()[0];
$stmt->close();
if ($user_count == 0) {
    $admin_password = password_hash('admin123', PASSWORD_BCRYPT);
    $staff_password = password_hash('staff123', PASSWORD_BCRYPT);
    $customer_password = password_hash('customer123', PASSWORD_BCRYPT);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $username = 'admin';
        $stmt->bind_param('ss', $username, $admin_password);
        $stmt->execute();
        $admin_user_id = $conn->insert_id;
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'staff')");
        $username = 'staff';
        $stmt->bind_param('ss', $username, $staff_password);
        $stmt->execute();
        $staff_user_id = $conn->insert_id;
        $stmt->close();
        $current_system_user_id = $admin_user_id;
        $sampleBooks = [
            ['name' => 'The Alchemist', 'product_type' => 'book', 'author' => 'Paulo Coelho', 'category' => 'Fiction', 'isbn' => '978-0061122415', 'publisher' => 'HarperOne', 'year' => 1988, 'price' => 850.0, 'stock' => 12, 'description' => 'A philosophical novel about a young shepherd boy named Santiago who journeys to find a treasure.', 'cover_image' => ''],
            ['name' => 'Sapiens: A Brief History of Humankind', 'product_type' => 'book', 'author' => 'Yuval Noah Harari', 'category' => 'History', 'isbn' => '978-0062316097', 'publisher' => 'Harper Perennial', 'year' => 2014, 'price' => 1200.0, 'stock' => 7, 'description' => 'Explores the history of humanity from the Stone Age to the twenty-first century.', 'cover_image' => ''],
            ['name' => 'Blue Ballpoint Pen (Pack of 5)', 'product_type' => 'general', 'author' => NULL, 'category' => 'Stationery', 'isbn' => NULL, 'publisher' => NULL, 'year' => NULL, 'price' => 150.0, 'stock' => 50, 'description' => 'Smooth writing blue ballpoint pens, ideal for office and school.', 'cover_image' => ''],
            ['name' => 'A4 Notebook (100 Pages)', 'product_type' => 'general', 'author' => NULL, 'category' => 'Stationery', 'isbn' => NULL, 'publisher' => NULL, 'year' => NULL, 'price' => 250.0, 'stock' => 30, 'description' => 'High-quality A4 size notebook with 100 ruled pages.', 'cover_image' => ''],
            ['name' => '1984', 'product_type' => 'book', 'author' => 'George Orwell', 'category' => 'Dystopian', 'isbn' => '978-0451524935', 'publisher' => 'Signet Classic', 'year' => 1949, 'price' => 600.0, 'stock' => 20, 'description' => 'A dystopian social science fiction novel and cautionary tale.', 'cover_image' => ''],
            ['name' => 'Sticky Notes (Assorted Colors)', 'product_type' => 'general', 'author' => NULL, 'category' => 'Stationery', 'isbn' => NULL, 'publisher' => NULL, 'year' => NULL, 'price' => 100.0, 'stock' => 45, 'description' => 'Colorful sticky notes for reminders and bookmarks.', 'cover_image' => ''],
        ];
        $book_ids = [];
        $stmt = $conn->prepare('INSERT INTO books (name, product_type, author, category, isbn, publisher, year, price, stock, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($sampleBooks as $book) {
            $stmt->bind_param('sssssiddds', $book['name'], $book['product_type'], $book['author'], $book['category'], $book['isbn'], $book['publisher'], $book['year'], $book['price'], $book['stock'], $book['description'], $book['cover_image']);
            $stmt->execute();
            $book_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $sampleCustomers = [
            ['name' => 'Ali Khan', 'phone' => '03001234567', 'email' => 'ali.khan@example.com', 'password_hash' => $customer_password, 'address' => 'Street 5, Sector G-8, Islamabad', 'is_active' => 1],
            ['name' => 'Sara Ahmed', 'phone' => '03337654321', 'email' => 'sara.ahmed@example.com', 'password_hash' => $customer_password, 'address' => 'House 12, Gulberg III, Lahore', 'is_active' => 1],
            ['name' => 'Usman Tariq', 'phone' => '03219876543', 'email' => 'usman.tariq@example.com', 'password_hash' => $customer_password, 'address' => 'Block A, DHA Phase V, Karachi', 'is_active' => 1],
            ['name' => 'Fatima Zohra', 'phone' => '03451122334', 'email' => 'fatima.z@example.com', 'password_hash' => $customer_password, 'address' => 'Apartment 7, F-10 Markaz, Islamabad', 'is_active' => 0],
        ];
        $customer_ids = [];
        $stmt = $conn->prepare('INSERT INTO customers (name, phone, email, password_hash, address, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($sampleCustomers as $customer) {
            $stmt->bind_param('sssssi', $customer['name'], $customer['phone'], $customer['email'], $customer['password_hash'], $customer['address'], $customer['is_active']);
            $stmt->execute();
            $customer_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $sampleSuppliers = [
            ['name' => 'ABC Publishers', 'contact_person' => 'Zain Ali', 'phone' => '021-34567890', 'email' => 'info@abcpubs.com', 'address' => 'D-34, Main Boulevard, Karachi'],
            ['name' => 'Global Products Distributors', 'contact_person' => 'Maria Khan', 'phone' => '042-12345678', 'email' => 'sales@globalproducts.pk', 'address' => 'Model Town, Lahore'],
            ['name' => 'Local Importers', 'contact_person' => 'Ahmed Raza', 'phone' => '051-98765432', 'email' => 'contact@localimporters.com', 'address' => 'I-8 Markaz, Islamabad'],
            ['name' => 'Stationery Hub Pvt Ltd', 'contact_person' => 'Hassan Iqbal', 'phone' => '051-5432109', 'email' => 'hassan@stationeryhub.pk', 'address' => 'Blue Area, Islamabad'],
        ];
        $supplier_ids = [];
        $stmt = $conn->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)');
        foreach ($sampleSuppliers as $supplier) {
            $stmt->bind_param('sssss', $supplier['name'], $supplier['contact_person'], $supplier['phone'], $supplier['email'], $supplier['address']);
            $stmt->execute();
            $supplier_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $expense_date1 = date('Y-m-d', time() - (3 * 24 * 60 * 60));
        $expense_date2 = date('Y-m-d', time() - (15 * 24 * 60 * 60));
        $expense_date3 = date('Y-m-d', time() - (20 * 24 * 60 * 60));
        $expense_date4 = date('Y-m-d');
        $sampleExpenses = [
            ['user_id' => $current_system_user_id, 'category' => 'Utilities', 'description' => 'Electricity bill for July', 'amount' => 8500.0, 'expense_date' => $expense_date1],
            ['user_id' => $current_system_user_id, 'category' => 'Rent', 'description' => 'Monthly shop rent', 'amount' => 50000.0, 'expense_date' => $expense_date2],
            ['user_id' => $current_system_user_id, 'category' => 'Supplies', 'description' => 'Office stationery and packing material', 'amount' => 3200.0, 'expense_date' => $expense_date3],
            ['user_id' => $current_system_user_id, 'category' => 'Marketing', 'description' => 'Social media ad campaign for new releases', 'amount' => 15000.0, 'expense_date' => $expense_date4],
        ];
        $stmt = $conn->prepare('INSERT INTO expenses (user_id, category, description, amount, expense_date) VALUES (?, ?, ?, ?, ?)');
        foreach ($sampleExpenses as $expense) {
            $stmt->bind_param('isdss', $expense['user_id'], $expense['category'], $expense['description'], $expense['amount'], $expense['expense_date']);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'Initial data (users, products, customers, etc.) added to the database.'];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Failed to insert initial data: ' . $e->getMessage());
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Failed to set up initial data: ' . $e->getMessage()];
    }
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$page = $_GET['page'] ?? 'home';
if (isLoggedIn()) {
    if (($page === 'login' || $page === 'customer-login' || $page === 'customer-register' || $page === 'home')) {
        redirect(isCustomer() ? 'customer-dashboard' : 'dashboard');
    }
}
$customer_only_pages = ['customer-dashboard', 'online-shop-cart', 'my-orders'];
if (isCustomer() && !in_array($page, array_merge($customer_only_pages, ['home', 'books-public', 'about', 'contact', 'public-sale']))) {
    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
    redirect('customer-dashboard');
}
$app_pages = ['dashboard', 'books', 'users', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'online-orders', 'promotions', 'expenses', 'reports', 'live-sales', 'settings', 'public-sale-links', 'print-barcodes', 'backup-restore'];
$authenticated_pages = array_merge($app_pages, $customer_only_pages);
if (isLoggedIn() && !isCustomer() && in_array($page, $app_pages)) {
    if (!hasAccess($page)) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access to ' . $page];
        redirect('dashboard');
    }
}
if (!isLoggedIn() && in_array($page, $authenticated_pages)) {
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'Please log in to access this page.'];
    $redirect_page = ($page === 'customer-dashboard' || $page === 'online-shop-cart' || $page === 'my-orders') ? 'customer-login' : 'login';
    redirect($redirect_page);
}
$public_settings = [];
$settings_result = $conn->query('SELECT setting_key, setting_value FROM settings');
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $public_settings[$row['setting_key']] = $row['setting_value'];
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
    <title><?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%232a9d8f' d='M18 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2m-1 15H7V5h10v12M9 7h6v2H9V7m0 4h6v2H9v-2m0 4h6v2H9v-2z'/%3E%3C/svg%3E" type="image/svg+xml">
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
            color: var(--light-text-color);
            margin-right: 8px;
            padding: 3px 6px;
            background-color: var(--primary-color);
            color: white;
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
                position: fixed !important;
                inset: 0 auto 0 0;
                width: min(86vw, 320px) !important;
                transform: translateX(-105%);
                transition: transform 0.25s ease;
                z-index: 1300;
                height: 100vh;
                overflow-y: auto;
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
            }
            body.sidebar-open aside.sidebar,
            aside.sidebar.active {
                transform: translateX(0);
            }
            .hamburger-menu {
                display: inline-flex !important;
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
                display: block !important;
            }
            .public-sale-login-card,
            .public-sale-shell {
                margin-top: 10px;
            }
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
        .dashboard-card,
        .public-content,
        .modal-content,
        .login-card,
        .mv-card,
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
        .form-group input[type="password"],
        .global-search-bar input {
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
            width: min(380px, 100%);
            padding: 24px;
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
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;
                width: min(280px, 86vw) !important;
                height: 100vh !important;
                padding: 14px !important;
                transform: translateX(-105%);
                transition: transform 0.22s ease;
                z-index: 999;
                overflow-y: auto;
                box-shadow: 0 24px 48px rgba(15, 23, 42, 0.16);
                border-right: 1px solid var(--border-color) !important;
                background: var(--surface-color) !important;
                flex-direction: column !important;
                align-items: stretch !important;
                justify-content: flex-start !important;
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
                height: 126px;
            }

            .search-sort-controls,
            .report-filters,
            .flex-group,
            .form-actions,
            #cart-actions,
            #online-cart-actions,
            .report-controls {
                flex-direction: column;
                gap: 8px;
            }

            .search-sort-controls .form-group,
            .report-filters .form-group,
            .flex-group .form-group,
            .form-actions .btn,
            #cart-actions .btn,
            #online-cart-actions .btn,
            .report-controls .btn {
                width: 100%;
            }

            .modal-content {
                width: calc(100vw - 16px);
                max-height: calc(100vh - 16px);
                padding: 16px;
            }

            .table-responsive {
                overflow: visible;
            }

            .data-table,
            .data-table thead,
            .data-table tbody,
            .data-table tr,
            .data-table td {
                display: block;
                width: 100%;
            }

            .data-table thead {
                display: none;
            }

            .data-table {
                border: none;
                background: transparent;
                box-shadow: none;
            }

            .data-table tbody {
                display: grid;
                gap: 10px;
            }

            .data-table tbody tr {
                background: var(--surface-color);
                border: 1px solid var(--border-color);
                border-radius: 14px;
                box-shadow: 0 10px 22px var(--shadow-color);
                padding: 10px;
            }

            .data-table td {
                border: none;
                padding: 7px 0 7px 108px;
                position: relative;
                min-height: 28px;
                text-align: left !important;
                font-size: 0.84rem;
            }

            .data-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                top: 7px;
                width: 96px;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                color: var(--light-text-color);
            }

            .data-table td.actions,
            .data-table td:last-child {
                padding-left: 0;
            }

            .data-table td.actions::before,
            .data-table td:last-child::before {
                position: static;
                display: block;
                width: auto;
                margin-bottom: 6px;
            }

            .data-table .actions {
                justify-content: flex-start;
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
    <?php if ($page === 'login' || $page === 'customer-login' || $page === 'customer-register'): ?>
        <div id="login-container">
            <div class="login-card">
                <?php if ($page === 'login'): ?>
                    <h2><?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?> Login</h2>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Login</button>
                    </form>
                <?php elseif ($page === 'customer-login'): ?>
                    <h2>Customer Login</h2>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="customer_login">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Login</button>
                    </form>
                    <p style="margin-top: 20px;">Don't have an account? <a href="index.php?page=customer-register">Register here</a></p>
                <?php else: ?>
                    <h2>Customer Registration</h2>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="customer_register">
                        <div class="form-group">
                            <label for="reg-name">Name</label>
                            <input type="text" id="reg-name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-email">Email</label>
                            <input type="email" id="reg-email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-phone">Phone</label>
                            <input type="tel" id="reg-phone" name="phone" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-password">Password</label>
                            <input type="password" id="reg-password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-confirm-password">Confirm Password</label>
                            <input type="password" id="reg-confirm-password" name="confirm_password" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-address">Address</label>
                            <textarea id="reg-address" name="address" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Register</button>
                    </form>
                    <p style="margin-top: 20px;">Already have an account? <a href="index.php?page=customer-login">Login here</a></p>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (in_array($page, ['home', 'books-public', 'about', 'contact', 'customer-dashboard', 'online-shop-cart', 'my-orders', 'public-sale'])): ?>
        <div id="public-site-container">
            <header class="public-header">
                <a href="index.php?page=home" class="logo"><?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?></a>
                <nav>
                    <ul>
                        <li><a href="index.php?page=home" class="nav-link <?php echo $page === 'home' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="index.php?page=books-public" class="nav-link <?php echo $page === 'books-public' ? 'active' : ''; ?>">Products</a></li>
                        <?php if (isCustomer()): ?>
                            <li><a href="index.php?page=online-shop-cart" class="nav-link <?php echo $page === 'online-shop-cart' ? 'active' : ''; ?>"><i class="fas fa-shopping-basket"></i> My Cart</a></li>
                            <li><a href="index.php?page=my-orders" class="nav-link <?php echo $page === 'my-orders' ? 'active' : ''; ?>"><i class="fas fa-receipt"></i> My Orders</a></li>
                        <?php endif; ?>
                        <li><a href="index.php?page=about" class="nav-link <?php echo $page === 'about' ? 'active' : ''; ?>">About Us</a></li>
                        <li><a href="index.php?page=contact" class="nav-link <?php echo $page === 'contact' ? 'active' : ''; ?>">Contact Us</a></li>
                    </ul>
                </nav>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <?php if (isCustomer()): ?>
                        <a href="index.php?page=customer-dashboard" class="login-btn">My Dashboard</a>
                        <a href="index.php?action=logout" style="color: white; font-weight: 500;">Logout</a>
                    <?php else: ?>
                        <a href="index.php?page=customer-login" style="color: white; font-weight: 500;">Customer Login</a>
                        <a href="index.php?page=login" class="login-btn">Admin/Staff Login</a>
                    <?php endif; ?>
                </div>
            </header>
            <main class="public-content">
                <?php
                if (isset($_SESSION['toast'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
                    unset($_SESSION['toast']);
                }
                if (isset($_GET['toast_type']) && isset($_GET['toast_message'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_GET['toast_type']) . "' data-message='" . html($_GET['toast_message']) . "'></div>";
                }
                switch ($page) {
                    case 'home':
                        $latest_books_query = 'SELECT id, name, author, price, cover_image, stock, product_type FROM books WHERE stock > 0 ORDER BY created_at DESC LIMIT 4';
                        $latest_books_result = $conn->query($latest_books_query);
                        $latest_books = [];
                        if ($latest_books_result) {
                            while ($row = $latest_books_result->fetch_assoc()) {
                                $latest_books[] = $row;
                            }
                        }
                        ?>
                        <section id="public-home" class="page-content active">
                            <div class="hero-section">
                                <h1>Welcome to <?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?></h1>
                                <p><?php echo html($public_settings['mission'] ?? 'Your one-stop destination for the latest and greatest products. Explore our vast collection and find what you need!'); ?></p>
                                <a href="index.php?page=books-public" class="btn btn-primary">Browse Products <i class="fas fa-arrow-right"></i></a>
                            </div>
                            <div class="card">
                                <div class="card-header">New Arrivals</div>
                                <div class="book-grid" id="latest-books-list">
                                    <?php if (!empty($latest_books)): ?>
                                        <?php foreach ($latest_books as $product): ?>
                                            <div class="book-card">
                                                <img src="<?php echo $product['cover_image'] ?: 'https://via.placeholder.com/150x200?text=No+Cover'; ?>" alt="<?php echo html($product['name']); ?>">
                                                <h3><?php echo html($product['name']); ?></h3>
                                                <p><?php echo ($product['author'] ? 'by ' . html($product['author']) : html($product['product_type'])); ?></p>
                                                <div class="price"><?php echo format_currency(html($product['price'])); ?></div>
                                                <div class="stock-info <?php echo $product['stock'] <= 5 ? 'low' : ''; ?> <?php echo $product['stock'] === 0 ? 'out' : ''; ?>">
                                                    <?php
                                                    if ($product['stock'] > 0) {
                                                        echo html($product['stock']) . ' In Stock';
                                                    } else {
                                                        echo 'Out of Stock';
                                                    }
                                                    ?>
                                                </div>
                                                <div class="public-product-actions">
                                                    <a href="https://wa.me/<?php echo html($public_settings['whatsapp_number'] ?? ''); ?>?text=Hello,%20I%20would%20like%20to%20order%20<?php echo urlencode(html($product['name'])); ?>%20-%20Price:%20<?php echo urlencode(format_currency(html($product['price']))); ?>." target="_blank" class="whatsapp-btn"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No new arrivals at the moment.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'books-public':
                    $all_categories_public = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetch_all(MYSQLI_ASSOC);
                    ?>
                        <section id="public-books" class="page-content active">
                            <div class="page-header">
                                <h1>Our Products Collection</h1>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="public-book-search">Search Products</label>
                                    <input type="text" id="public-book-search" placeholder="Search by name, author, ISBN...">
                                </div>
                                <div class="form-group">
                                    <label for="public-product-type-filter">Product Type</label>
                                    <select id="public-product-type-filter">
                                        <option value="all">All Types</option>
                                        <option value="book">Book</option>
                                        <option value="general">General Item</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="public-book-category-filter">Category</label>
                                    <select id="public-book-category-filter">
                                        <option value="all">All Categories</option>
                                        <?php foreach ($all_categories_public as $cat): ?>
                                            <option value="<?php echo html($cat['category']); ?>"><?php echo html($cat['category']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="public-book-sort">Sort By</label>
                                    <select id="public-book-sort">
                                        <option value="name-asc">Name (A-Z)</option>
                                        <option value="name-desc">Name (Z-A)</option>
                                        <option value="price-asc">Price (Low to High)</option>
                                        <option value="price-desc">Price (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="book-grid" id="public-books-list">
                                <p>Loading products...</p>
                            </div>
                            <div class="pagination" id="public-books-pagination">
                                <button id="public-books-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                <span id="public-books-page-info">Page 1 of 1</span>
                                <button id="public-books-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                            </div>
                        </section>
                    <?php
                    break;
                case 'about':
                    ?>
                        <section id="public-about" class="page-content active">
                            <div class="about-header">
                                <h1>About Us</h1>
                                <p>Discover the story behind <?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?> and our commitment to serving you.</p>
                            </div>
                            <div class="mission-vision-container">
                                <div class="mv-card">
                                    <i class="fas fa-bullseye"></i>
                                    <h3>Our Mission</h3>
                                    <p><?php echo html($public_settings['mission'] ?? 'To provide a diverse range of products and books to our community, fostering knowledge and meeting everyday needs with excellence.'); ?></p>
                                </div>
                                <div class="mv-card">
                                    <i class="fas fa-eye"></i>
                                    <h3>Our Vision</h3>
                                    <p><?php echo html($public_settings['vision'] ?? 'To be the leading general store and bookshop, known for quality, variety, and exceptional customer service.'); ?></p>
                                </div>
                                <div class="mv-card">
                                    <i class="fas fa-history"></i>
                                    <h3>Our Story</h3>
                                    <p>Founded with a passion for quality and community, we strive to be more than just a store. We are a hub for knowledge, daily essentials, and connection.</p>
                                </div>
                            </div>
                            <div class="card" style="padding: 40px;">
                                <h2 style="text-align: center; margin-bottom: 10px; color: var(--primary-color);">Why Choose Us?</h2>
                                <p style="text-align: center; color: var(--light-text-color); margin-bottom: 30px;">We go the extra mile to ensure your satisfaction.</p>
                                <div class="features-grid">
                                    <div class="feature-item">
                                        <i class="fas fa-check-circle"></i>
                                        <div>
                                            <h4>Quality Products</h4>
                                            <p>Carefully curated items ensuring the best value for your money.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-tags"></i>
                                        <div>
                                            <h4>Best Prices</h4>
                                            <p>Competitive pricing to make essentials and books accessible to all.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-smile"></i>
                                        <div>
                                            <h4>Customer First</h4>
                                            <p>Dedicated support and a seamless shopping experience.</p>
                                        </div>
                                    </div>
                                    <div class="feature-item">
                                        <i class="fas fa-shipping-fast"></i>
                                        <div>
                                            <h4>Fast Delivery</h4>
                                            <p>Reliable shipping to get your orders to you on time.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'customer-dashboard':
                    ?>
                        <section id="customer-dashboard" class="page-content active">
                            <div class="page-header" style="justify-content: space-between; width:100%;">
                                <h1>Welcome, <?php echo html($_SESSION['customer_name']); ?>!</h1>
                                <a href="index.php?action=logout" class="btn btn-danger">Logout</a>
                            </div>
                            <div class="card">
                                <div class="card-header">My Profile</div>
                                <div id="customer-profile-details">
                                    <p>Loading your details...</p>
                                </div>
                            </div>
                            <div class="card" style="margin-top: 20px;">
                                <div class="card-header">My Purchase History</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Sale ID</th>
                                                <th>Date</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="customer-dashboard-history-list">
                                            <tr>
                                                <td colspan="4">Loading purchase history...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card" style="margin-top: 20px;">
                                <div class="card-header">My Online Orders</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Date</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="customer-online-orders-list">
                                            <tr>
                                                <td colspan="6">Loading online orders...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'online-shop-cart':
                    $customers_for_checkout = [];
                    $customers_result = $conn->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC');
                    if ($customers_result) {
                        while ($row = $customers_result->fetch_assoc()) {
                            $customers_for_checkout[] = $row;
                        }
                    }
                    ?>
                        <section id="online-shop-cart" class="page-content active">
                            <div class="page-header">
                                <h1>My Online Shopping Cart</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">My Cart (<span id="online-cart-total-items">0</span> items)</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Price</th>
                                                <th>Quantity</th>
                                                <th>Discount</th>
                                                <th>Subtotal</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="online-cart-items-table">
                                            <tr>
                                                <td colspan="6">Cart is empty.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div id="online-cart-summary">
                                    <span>Total:</span>
                                    <span id="online-cart-grand-total"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span>
                                </div>
                                <div id="online-cart-promo-section" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                                    <div class="form-group">
                                        <label for="online-checkout-promotion-code">Promotion Code (Optional)</label>
                                        <input type="text" id="online-checkout-promotion-code" placeholder="Enter promo code">
                                        <button type="button" class="btn btn-sm btn-info" id="online-apply-promo-btn" style="margin-top: 5px;">Apply</button>
                                    </div>
                                    <p id="online-promo-message" style="color: var(--danger-color); font-size: 0.9em;"></p>
                                </div>
                                <div id="online-cart-actions">
                                    <button class="btn btn-danger" id="online-clear-cart-btn" disabled><i class="fas fa-trash"></i> Clear Cart</button>
                                    <button class="btn btn-success" id="place-online-order-btn" disabled><i class="fas fa-shopping-basket"></i> Place Order</button>
                                </div>
                            </div>
                        </section>
                        <div id="online-order-modal" class="modal-overlay">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h3>Confirm Online Order</h3>
                                    <button class="modal-close"><i class="fas fa-times"></i></button>
                                </div>
                                <form id="online-order-form" method="POST" action="index.php?page=online-shop-cart">
                                    <p>Please confirm your order details below:</p>
                                    <div class="form-group">
                                        <label>Customer Name:</label>
                                        <input type="text" value="<?php echo html($_SESSION['customer_name'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>Customer Email:</label>
                                        <input type="text" value="<?php echo html($_SESSION['customer_email'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="online-order-subtotal">Subtotal</label>
                                        <input type="text" id="online-order-subtotal" readonly>
                                    </div>
                                    <div class="form-group" id="online-order-discount-display" style="display: none;">
                                        <label for="online-order-discount">Discount Applied</label>
                                        <input type="text" id="online-order-discount" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="online-order-total">Total Amount</label>
                                        <input type="text" id="online-order-total" readonly>
                                    </div>
                                    <input type="hidden" name="action" value="place_online_order">
                                    <input type="hidden" name="promotion_code" id="online-order-promotion-code-input">
                                    <input type="hidden" name="cart_items" id="online-order-cart-items-input">
                                    <div class="form-actions">
                                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                                        <button type="submit" class="btn btn-success">Confirm & Place Order</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php
                    break;
                case 'my-orders':
                    ?>
                        <section id="my-orders" class="page-content active">
                            <div class="page-header">
                                <h1>My Online Orders</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">My Orders List</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Date</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="customer-my-orders-list">
                                            <tr>
                                                <td colspan="6">Loading your orders...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'public-sale':
                    $public_sale_token = trim($_GET['token'] ?? '');
                    $public_sale_link = current_public_sale_link($conn, $public_sale_token);
                    $public_sale_access = $public_sale_link ? has_public_sale_access($public_sale_token) : false;
                    $public_sale_last_receipt = ($public_sale_access && isset($_SESSION['public_sale_last_receipt'][$public_sale_token])) ? (int) $_SESSION['public_sale_last_receipt'][$public_sale_token] : 0;
                    if ($public_sale_last_receipt) {
                        unset($_SESSION['public_sale_last_receipt'][$public_sale_token]);
                    }
                    ?>
                        <section id="public-sale-page" class="page-content active public-sale-page" data-token="<?php echo html($public_sale_token); ?>" data-access="<?php echo $public_sale_access ? '1' : '0'; ?>" data-price-mode="<?php echo html($public_sale_link['price_mode'] ?? 'retail'); ?>" data-last-sale-id="<?php echo html($public_sale_last_receipt); ?>">
                            <?php if (!$public_sale_link): ?>
                                <div class="card" style="max-width: 640px; margin: 0 auto; text-align: center; padding: 36px;">
                                    <div class="card-header">Secure Sale Link</div>
                                    <p>This secure sale link is invalid or inactive.</p>
                                </div>
                            <?php elseif (!$public_sale_access): ?>
                                <div class="card public-sale-login-card" style="max-width: 520px; margin: 0 auto; padding: 32px;">
                                    <div class="card-header"><?php echo html($public_sale_link['link_name']); ?></div>
                                    <p style="margin-bottom: 18px; color: var(--light-text-color);">This secure sale page runs in <strong><?php echo html(strtoupper($public_sale_link['price_mode'])); ?></strong> mode. Enter the password to unlock live barcode selling.</p>
                                    <form method="POST" action="index.php?page=public-sale&token=<?php echo urlencode($public_sale_token); ?>">
                                        <input type="hidden" name="action" value="public_sale_login">
                                        <input type="hidden" name="token" value="<?php echo html($public_sale_token); ?>">
                                        <div class="form-group">
                                            <label for="public-sale-password">Password</label>
                                            <input type="password" id="public-sale-password" name="access_password" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary" style="width: 100%;">Unlock Secure Sale</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="public-sale-shell">
                                    <aside class="public-sale-products-panel card">
                                        <div class="card-header">Products</div>
                                        <div class="public-sale-rate-badge"><?php echo html(strtoupper($public_sale_link['price_mode'])); ?> RATE</div>
                                        <div class="form-group">
                                            <input type="text" id="public-sale-sidebar-search" placeholder="Search products or barcode...">
                                        </div>
                                        <div class="form-group">
                                            <select id="public-sale-sidebar-category">
                                                <option value="all">All Categories</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <select id="public-sale-sidebar-type">
                                                <option value="all">All Types</option>
                                                <option value="book">Book</option>
                                                <option value="general">General Item</option>
                                            </select>
                                        </div>
                                        <div id="public-sale-sidebar-products" class="public-sale-product-list"></div>
                                    </aside>
                                    <div class="public-sale-main">
                                        <div class="card public-sale-top-card">
                                            <div class="public-sale-header-row">
                                                <div>
                                                    <h1 style="margin-bottom: 4px;"><?php echo html($public_sale_link['link_name']); ?></h1>
                                                    <p style="color: var(--light-text-color); margin: 0;">Camera stays active for barcode selling. Session auto-locks after 8 hours.</p>
                                                </div>
                                                <div class="public-sale-rate-badge"><?php echo html(strtoupper($public_sale_link['price_mode'])); ?> RATE</div>
                                            </div>
                                            <div class="public-sale-scanner-grid">
                                                <div>
                                                    <label style="display:block; margin-bottom:8px; font-weight:600;">Live Barcode Scanner</label>
                                                    <div id="public-sale-scanner" class="public-sale-scanner-box"></div>
                                                </div>
                                                <div>
                                                    <div class="form-group">
                                                        <label for="public-sale-manual-barcode">Manual Barcode Entry</label>
                                                        <div class="inline-input-group">
                                                            <input type="text" id="public-sale-manual-barcode" placeholder="Scan or type barcode">
                                                            <button type="button" class="btn btn-primary" id="public-sale-barcode-submit">Add</button>
                                                        </div>
                                                    </div>
                                                    <div id="public-sale-scanner-status" class="public-sale-status-chip">Scanner ready</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card">
                                            <div class="card-header">Current Secure Cart</div>
                                            <div class="table-responsive">
                                                <table class="data-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th>Barcode</th>
                                                            <th>Rate</th>
                                                            <th>Qty</th>
                                                            <th>Subtotal</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="public-sale-cart-items">
                                                        <tr><td colspan="6">No products scanned yet.</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div id="public-sale-cart-summary" class="public-sale-summary-row">
                                                <span>Total</span>
                                                <strong id="public-sale-grand-total"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</strong>
                                            </div>
                                            <form id="public-sale-submit-form" method="POST" action="index.php?page=public-sale&token=<?php echo urlencode($public_sale_token); ?>">
                                                <input type="hidden" name="action" value="submit_public_sale">
                                                <input type="hidden" name="token" value="<?php echo html($public_sale_token); ?>">
                                                <input type="hidden" name="cart_items" id="public-sale-cart-input">
                                                <div class="form-actions" style="justify-content: flex-end;">
                                                    <button type="button" class="btn btn-secondary" id="public-sale-clear-cart">Clear</button>
                                                    <button type="submit" class="btn btn-success" id="public-sale-submit-btn">Complete Sale</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php
                    break;
                case 'contact':
                    ?>
                        <section id="public-contact" class="page-content active">
                            <div class="contact-header">
                                <h1>Get in Touch</h1>
                                <p>We'd love to hear from you. Whether you have a question about products, orders, or just want to say hello!</p>
                            </div>
                            <div class="contact-wrapper">
                                <div class="contact-info-box">
                                    <h3 style="color: white; margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.3); padding-bottom: 15px;">Contact Information</h3>
                                    <div class="contact-info-item">
                                        <i class="fas fa-phone-alt"></i>
                                        <div>
                                            <h4>Phone</h4>
                                            <p><a href="tel:<?php echo html($public_settings['phone'] ?? ''); ?>"><?php echo html($public_settings['phone'] ?? 'N/A'); ?></a></p>
                                        </div>
                                    </div>
                                    <div class="contact-info-item">
                                        <i class="fab fa-whatsapp"></i>
                                        <div>
                                            <h4>WhatsApp</h4>
                                            <p><a href="https://wa.me/<?php echo html($public_settings['whatsapp_number'] ?? ''); ?>" target="_blank"><?php echo html($public_settings['whatsapp_number'] ?? 'N/A'); ?></a></p>
                                        </div>
                                    </div>
                                    <div class="contact-info-item">
                                        <i class="fas fa-envelope"></i>
                                        <div>
                                            <h4>Email</h4>
                                            <p><a href="mailto:<?php echo html($public_settings['email'] ?? ''); ?>"><?php echo html($public_settings['email'] ?? 'N/A'); ?></a></p>
                                        </div>
                                    </div>
                                    <div class="contact-info-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <div>
                                            <h4>Address</h4>
                                            <p><?php echo nl2br(html($public_settings['address'] ?? 'N/A')); ?></p>
                                        </div>
                                    </div>
                                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.3);">
                                        <h4 style="margin-bottom: 15px; opacity: 0.9;">Follow Us</h4>
                                        <div style="display: flex; gap: 15px;">
                                            <?php if (!empty($public_settings['facebook_url'])): ?>
                                                <a href="<?php echo html($public_settings['facebook_url']); ?>" target="_blank" style="background: white; color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s;"><i class="fab fa-facebook-f"></i></a>
                                            <?php endif; ?>
                                            <?php if (!empty($public_settings['instagram_url'])): ?>
                                                <a href="<?php echo html($public_settings['instagram_url']); ?>" target="_blank" style="background: white; color: var(--primary-color); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: transform 0.3s;"><i class="fab fa-instagram"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="contact-form-box">
                                    <h3 style="color: var(--primary-color); margin-bottom: 20px;">Send us a Message</h3>
                                    <form id="contact-message-form">
                                        <div class="form-group">
                                            <label>Your Name</label>
                                            <input type="text" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="John Doe">
                                        </div>
                                        <div class="form-group">
                                            <label>Your Email</label>
                                            <input type="email" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="john@example.com">
                                        </div>
                                        <div class="form-group">
                                            <label>Subject</label>
                                            <input type="text" class="form-control" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="Inquiry about...">
                                        </div>
                                        <div class="form-group">
                                            <label>Message</label>
                                            <textarea class="form-control" rows="5" style="width:100%; padding:12px; border:1px solid var(--border-color); border-radius:5px;" required placeholder="How can we help you?"></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1.1em;">Send Message</button>
                                    </form>
                                </div>
                            </div>
                            <?php if (!empty($public_settings['google_map_embed_url'])): ?>
                                <div class="map-container">
                                    <iframe src="<?php echo html($public_settings['google_map_embed_url']); ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
                                </div>
                            <?php endif; ?>
                        </section>
                <?php
                        break;
                    default:
                        redirect('home');
                        break;
                }
                ?>
            </main>
            <footer class="public-footer">
                <p>&copy; <?php echo date('Y'); ?> <?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?>. All rights reserved. Designed by Yasin Ullah, Pakistan.</p>
            </footer>
        </div>
    <?php else: ?>
        <div id="app-container">
            <aside class="sidebar">
                <div class="sidebar-header-row">
                    <button class="hamburger-menu" id="hamburger-menu"><i class="fas fa-bars"></i></button>
                    <div class="mobile-breadcrumb">
                    <a href="index.php?page=dashboard"><i class="fas fa-home"></i></a>
                    <span class="separator">/</span>
                    <span><?php echo html(ucwords(str_replace('-', ' ', $page))); ?></span>
                </div>
                    <h2><?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?></h2>
                </div>
                <nav>
                    <ul>
                        <li><a href="index.php?page=dashboard" class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> <span class="sidebar-label">Dashboard</span></a></li>
                        <li><a href="index.php?page=books" class="nav-link <?php echo $page === 'books' ? 'active' : ''; ?>"><i class="fas fa-box-open"></i> <span class="sidebar-label">Products</span></a></li>
                        <?php if (hasAccess('customers')): ?><li><a href="index.php?page=customers" class="nav-link <?php echo $page === 'customers' ? 'active' : ''; ?>"><i class="fas fa-users"></i> <span class="sidebar-label">Customers</span></a></li><?php endif; ?>
                        <?php if (hasAccess('users')): ?><li><a href="index.php?page=users" class="nav-link <?php echo $page === 'users' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i> <span class="sidebar-label">Users & Roles</span></a></li><?php endif; ?>
                        <?php if (hasAccess('suppliers')): ?><li><a href="index.php?page=suppliers" class="nav-link <?php echo $page === 'suppliers' ? 'active' : ''; ?>"><i class="fas fa-truck-moving"></i> <span class="sidebar-label">Suppliers</span></a></li><?php endif; ?>
                        <?php if (hasAccess('purchase-orders')): ?><li><a href="index.php?page=purchase-orders" class="nav-link <?php echo $page === 'purchase-orders' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> <span class="sidebar-label">Purchase Orders</span></a></li><?php endif; ?>
                        <?php if (hasAccess('cart')): ?><li><a href="index.php?page=cart" class="nav-link <?php echo $page === 'cart' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> <span class="sidebar-label">POS (Cart)</span></a></li><?php endif; ?>
                        <?php if (hasAccess('sales-history')): ?><li><a href="index.php?page=sales-history" class="nav-link <?php echo $page === 'sales-history' ? 'active' : ''; ?>"><i class="fas fa-receipt"></i> <span class="sidebar-label">Sales History</span></a></li><?php endif; ?>
                        <?php if (hasAccess('online-orders')): ?><li><a href="index.php?page=online-orders" class="nav-link <?php echo $page === 'online-orders' ? 'active' : ''; ?>"><i class="fas fa-globe"></i> <span class="sidebar-label">Online Orders</span></a></li><?php endif; ?>
                        <?php if (hasAccess('promotions')): ?><li><a href="index.php?page=promotions" class="nav-link <?php echo $page === 'promotions' ? 'active' : ''; ?>"><i class="fas fa-tag"></i> <span class="sidebar-label">Promotions</span></a></li><?php endif; ?>
                        <?php if (hasAccess('expenses')): ?><li><a href="index.php?page=expenses" class="nav-link <?php echo $page === 'expenses' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> <span class="sidebar-label">Expenses</span></a></li><?php endif; ?>
                        <?php if (hasAccess('reports')): ?><li><a href="index.php?page=reports" class="nav-link <?php echo $page === 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> <span class="sidebar-label">Reports</span></a></li><?php endif; ?>
                        <?php if (hasAccess('live-sales')): ?><li><a href="index.php?page=live-sales" class="nav-link <?php echo $page === 'live-sales' ? 'active' : ''; ?>"><i class="fas fa-satellite-dish"></i> <span class="sidebar-label">Live Sales</span></a></li><?php endif; ?>
                        <?php if (hasAccess('settings')): ?><li><a href="index.php?page=settings" class="nav-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> <span class="sidebar-label">Settings</span></a></li><?php endif; ?>
                        <?php if (hasAccess('public-sale-links')): ?><li><a href="index.php?page=public-sale-links" class="nav-link <?php echo $page === 'public-sale-links' ? 'active' : ''; ?>"><i class="fas fa-link"></i> <span class="sidebar-label">Secure Sale Links</span></a></li><?php endif; ?>
                        <?php if (hasAccess('print-barcodes')): ?><li><a href="index.php?page=print-barcodes" class="nav-link <?php echo $page === 'print-barcodes' ? 'active' : ''; ?>"><i class="fas fa-print"></i> <span class="sidebar-label">Print Barcodes</span></a></li><?php endif; ?>
                        <?php if (hasAccess('backup-restore')): ?><li><a href="index.php?page=backup-restore" class="nav-link <?php echo $page === 'backup-restore' ? 'active' : ''; ?>"><i class="fas fa-database"></i> <span class="sidebar-label">Backup/Restore</span></a></li><?php endif; ?>
                    </ul>
                </nav>
                <div class="user-info">
                    Logged in as <span><?php echo html($_SESSION['username']); ?> (<?php echo html($_SESSION['user_role']); ?>)</span><br>
                    <a href="index.php?action=logout">Logout</a>
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
                    <input type="text" id="global-search-input" placeholder="Global Search (Products, Customers, Sales ID)...">
                    <div id="global-search-results" class="global-search-results"></div>
                </div>
                <?php
                if (isset($_SESSION['toast'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
                    unset($_SESSION['toast']);
                }
                switch ($page) {
                    case 'dashboard':
                        $total_books_count = $conn->query('SELECT COUNT(*) FROM books')->fetch_row()[0];
                        $total_customers_count = $conn->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetch_row()[0];
                        $low_stock_count = $conn->query('SELECT COUNT(*) FROM books WHERE stock < 5')->fetch_row()[0];
                        $today_start = date('Y-m-d 00:00:00');
                        $today_end = date('Y-m-d 23:59:59');
                        $stmt_today_sales = $conn->prepare('SELECT SUM(total) FROM sales WHERE sale_date BETWEEN ? AND ?');
                        $stmt_today_sales->bind_param('ss', $today_start, $today_end);
                        $stmt_today_sales->execute();
                        $today_sales_total = $stmt_today_sales->get_result()->fetch_row()[0] ?? 0;
                        $stmt_today_sales->close();
                        $recent_sales_query = "SELECT s.id, s.sale_date, c.name AS customer_name, s.total, 
                                            GROUP_CONCAT(CONCAT(b.name, ' (', si.quantity, ')') SEPARATOR ', ') AS item_names
                                            FROM sales s
                                            LEFT JOIN customers c ON s.customer_id = c.id
                                            JOIN sale_items si ON s.id = si.sale_id
                                            JOIN books b ON si.book_id = b.id
                                            GROUP BY s.id
                                            ORDER BY s.sale_date DESC LIMIT 5";
                        $recent_sales_result = $conn->query($recent_sales_query);
                        $recent_sales = [];
                        if ($recent_sales_result) {
                            while ($row = $recent_sales_result->fetch_assoc()) {
                                $recent_sales[] = $row;
                            }
                        }
                        $low_stock_books_query = 'SELECT id, name, author, stock, product_type FROM books WHERE stock < 5 ORDER BY stock ASC';
                        $low_stock_books_result = $conn->query($low_stock_books_query);
                        $low_stock_books = [];
                        if ($low_stock_books_result) {
                            while ($row = $low_stock_books_result->fetch_assoc()) {
                                $low_stock_books[] = $row;
                            }
                        }
                        ?>
                        <section id="dashboard" class="page-content active">
                            <div class="page-header">
                                <h1>Dashboard Overview</h1>
                            </div>
                            <div class="dashboard-grid">
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Today's Revenue</h3>
                                    <p id="dash-today-rev" style="color: white;"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Today's Orders</h3>
                                    <p id="dash-today-orders" style="color: white;">0</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>This Month's Revenue</h3>
                                    <p id="dash-month-rev"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Pending Online Orders</h3>
                                    <p id="dash-pending-orders" class="danger">0</p>
                                </div>
                            </div>
                            
                            <div class="dashboard-grid">
                                <div class="dashboard-card">
                                    <h3>Total Products</h3>
                                    <p id="dash-total-products">0</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Total Customers</h3>
                                    <p id="dash-total-customers">0</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Total Suppliers</h3>
                                    <p id="dash-total-suppliers">0</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Low Stock Items</h3>
                                    <p id="dash-low-stock" class="danger">0</p>
                                </div>
                            </div>

                            <div class="dashboard-grid">
                                <div class="dashboard-card">
                                    <h3>Total Stock Value</h3>
                                    <p id="dash-stock-value"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Lifetime Revenue</h3>
                                    <p id="dash-lifetime-rev"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Lifetime Expenses</h3>
                                    <p id="dash-total-expenses" class="danger"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Active Promotions</h3>
                                    <p id="dash-active-promos">0</p>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 20px;">
                                <div class="card">
                                    <div class="card-header">Last 7 Days Revenue</div>
                                    <div style="height:300px;"><canvas id="dash-weekly-chart"></canvas></div>
                                </div>
                                <div class="card">
                                    <div class="card-header">Top Selling Items (This Month)</div>
                                    <div style="height:300px;"><canvas id="dash-top-chart"></canvas></div>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 20px;">
                                <div class="card">
                                    <div class="card-header">Sales vs Expenses (Last 6 Months)</div>
                                    <div style="height:300px;"><canvas id="dash-monthly-chart"></canvas></div>
                                </div>
                                <div class="card">
                                    <div class="card-header">Online Orders Status</div>
                                    <div style="height:300px;"><canvas id="dash-orders-chart"></canvas></div>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                                <div class="card">
                                    <div class="card-header">Recent Sales</div>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Customer</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody id="dashboard-recent-sales">
                                                <tr><td colspan="3">Loading...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="card">
                                    <div class="card-header">Low Stock Alerts</div>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Stock</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="dashboard-low-stock-books">
                                                <tr><td colspan="3">Loading...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'live-sales':
                    ?>
                        <section id="live-sales" class="page-content <?php echo $page === 'live-sales' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Live Sales Monitor <span style="font-size:14px; color:var(--success-color); margin-left: 10px;"><i class="fas fa-circle" style="animation: pulse-live 1.5s infinite;"></i> Live</span></h1>
                            </div>
                            <div class="dashboard-grid">
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Today's Earnings</h3>
                                    <p id="live-today-rev" style="color: white;"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Today's Orders</h3>
                                    <p id="live-today-orders" style="color: white;">0</p>
                                </div>
                                <div class="dashboard-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
                                    <h3 style="color: rgba(255,255,255,0.8);">Total Discount Given</h3>
                                    <p id="live-today-disc" style="color: white;"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</p>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Today's Transactions (Auto-updates every 5s)</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Sale ID</th>
                                                <th>Customer</th>
                                                <th>Sold By</th>
                                                <th>Items</th>
                                                <th>Discount</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="live-sales-list">
                                            <tr><td colspan="7">Waiting for data...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'books':
                    ?>
                        <section id="books" class="page-content <?php echo $page === 'books' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Products Management</h1>
                                <div style="display: flex; gap: 10px;">
                                    <?php if (isAdmin()): ?>
                                        <button class="btn btn-primary" id="add-book-btn"><i class="fas fa-plus"></i> Add New
                                            Product</button>
                                        <button class="btn btn-secondary" id="export-books-btn"><i class="fas fa-download"></i> Export
                                            Products</button>
                                        <button class="btn btn-secondary" id="import-books-btn"><i class="fas fa-upload"></i> Import
                                            Products</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="book-search">Search Products</label>
                                    <input type="text" id="book-search" placeholder="Search by name, author, ISBN, category...">
                                </div>
                                <div class="form-group">
                                    <label for="book-sort">Sort By</label>
                                    <select id="book-sort">
                                        <option value="name-asc">Name (A-Z)</option>
                                        <option value="name-desc">Name (Z-A)</option>
                                        <option value="price-asc">Price (Low to High)</option>
                                        <option value="price-desc">Price (High to Low)</option>
                                        <option value="stock-asc">Stock (Low to High)</option>
                                        <option value="stock-desc">Stock (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Cover</th>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Author</th>
                                            <th>Category</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="books-list">
                                        <tr>
                                            <td colspan="8">Loading products...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="books-pagination">
                                    <button id="books-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="books-page-info">Page 1 of 1</span>
                                    <button id="books-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'users':
                    if (!hasAccess('users')) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized'];
                        redirect('dashboard');
                    }
                    ?>
                    <section id="users-page" class="page-content active">
                        <div class="page-header">
                            <h1>Users & Roles Management</h1>
                            <div style="display: flex; gap: 10px;">
                                <button class="btn btn-primary" onclick="openUserModal()"><i class="fas fa-user-plus"></i> Add User</button>
                                <button class="btn btn-secondary" onclick="openRoleModal()"><i class="fas fa-shield-alt"></i> Add Role</button>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                            <div class="card">
                                <div class="card-header">System Users</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead><tr><th>Username</th><th>Role</th><th>Actions</th></tr></thead>
                                        <tbody id="users-list"><tr><td colspan="3">Loading...</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Roles & Permissions</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead><tr><th>Role Name</th><th>Actions</th></tr></thead>
                                        <tbody id="roles-list"><tr><td colspan="2">Loading...</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                    <?php
                    break;
                case 'customers':
                    $customers_permission_level = 'limited';
                    if (isAdmin()) {
                        $customers_permission_level = 'full_access';
                    }
                    ?>
                        <section id="customers" class="page-content <?php echo $page === 'customers' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Customers Management</h1>
                                <div style="display: flex; gap: 10px;">
                                    <?php if ($customers_permission_level === 'full_access'): ?>
                                        <button class="btn btn-primary" id="add-customer-btn"><i class="fas fa-plus"></i> Add New
                                            Customer</button>
                                        <button class="btn btn-secondary" id="export-customers-btn"><i class="fas fa-download"></i>
                                            Export Customers</button>
                                        <button class="btn btn-secondary" id="import-customers-btn"><i class="fas fa-upload"></i>
                                            Import Customers</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="customer-search">Search Customers</label>
                                    <input type="text" id="customer-search" placeholder="Search by name, email, phone...">
                                </div>
                                <div class="form-group">
                                    <label for="customer-filter-status">Status</label>
                                    <select id="customer-filter-status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="all">All</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Email</th>
                                            <th>Address</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="customers-list">
                                        <tr>
                                            <td colspan="6">Loading customers...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="customers-pagination">
                                    <button id="customers-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="customers-page-info">Page 1 of 1</span>
                                    <button id="customers-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'suppliers':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                        <section id="suppliers" class="page-content <?php echo $page === 'suppliers' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Suppliers Management</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-primary" id="add-supplier-btn"><i class="fas fa-plus"></i> Add New
                                        Supplier</button>
                                    <button class="btn btn-secondary" id="export-suppliers-btn"><i class="fas fa-download"></i>
                                        Export Suppliers</button>
                                    <button class="btn btn-secondary" id="import-suppliers-btn"><i class="fas fa-upload"></i> Import
                                        Suppliers</button>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="supplier-search" placeholder="Search by name, contact, email...">
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Contact Person</th>
                                            <th>Phone</th>
                                            <th>Email</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="suppliers-list">
                                        <tr>
                                            <td colspan="5">Loading suppliers...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="suppliers-pagination">
                                    <button id="suppliers-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="suppliers-page-info">Page 1 of 1</span>
                                    <button id="suppliers-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'purchase-orders':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                        <?php
                        $all_books_for_po = $conn->query('SELECT id, name, author, price FROM books ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
                        ?>
                        <section id="purchase-orders" class="page-content <?php echo $page === 'purchase-orders' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Purchase Orders</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-primary" id="create-po-btn"><i class="fas fa-file-invoice"></i> Create
                                        New
                                        PO</button>
                                    <button class="btn btn-secondary" id="export-pos-btn"><i class="fas fa-download"></i> Export
                                        POs</button>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="po-search" placeholder="Search by PO ID, supplier, status...">
                                </div>
                                <div class="form-group">
                                    <select id="po-status-filter">
                                        <option value="all">All Statuses</option>
                                        <option value="pending">Pending</option>
                                        <option value="ordered">Ordered</option>
                                        <option value="received">Received</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>PO ID</th>
                                            <th>Supplier</th>
                                            <th>Order Date</th>
                                            <th>Expected Date</th>
                                            <th>Status</th>
                                            <th>Total Items</th>
                                            <th>Total Cost</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="purchase-orders-list">
                                        <tr>
                                            <td colspan="8">Loading purchase orders...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="pos-pagination">
                                    <button id="pos-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="pos-page-info">Page 1 of 1</span>
                                    <button id="pos-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'cart':
                    $customers_for_checkout = [];
                    $customers_result = $conn->query('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC');
                    if ($customers_result) {
                        while ($row = $customers_result->fetch_assoc()) {
                            $customers_for_checkout[] = $row;
                        }
                    }
                    ?>
                        <section id="cart" class="page-content <?php echo $page === 'cart' ? 'active' : ''; ?>">
                            <div class="page-header" style="margin-bottom: 10px;">
                                <h1>Point of Sale Register</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-secondary" id="view-sales-history-btn"><i class="fas fa-history"></i> Sales History</button>
                                </div>
                            </div>
                            
                            <div class="pos-wrapper">
                                <!-- Left Panel: Products -->
                                <div class="pos-main-panel">
                                    <div class="pos-header-controls">
                                        <input type="text" id="book-to-cart-search" placeholder="Search by name or barcode...">
                                        <select id="pos-category-filter"><option value="all">All Categories</option></select>
                                        <button type="button" class="btn btn-secondary" id="scan-pos-barcode-btn" style="border-radius: 10px;"><i class="fas fa-barcode"></i> Scan</button>
                                    </div>
                                    <div class="pos-grid" id="books-for-cart-list">
                                        <!-- Products injected here -->
                                    </div>
                                </div>
                                
                                <!-- Right Panel: Cart & Checkout -->
                                <div class="pos-cart-panel">
                                    <div class="pos-cart-header">
                                        Current Order
                                        <span class="status-pill success" id="cart-total-items" style="font-size: 14px;">0</span>
                                    </div>
                                    
                                    <div class="pos-cart-items-wrap" id="cart-items-table">
                                        <div style="text-align:center; padding: 30px 10px; color: var(--light-text-color);">Cart is empty. Tap products to add.</div>
                                    </div>
                                    
                                    <div class="pos-totals-panel">
                                        <div class="pos-summary-row">
                                            <span>Subtotal:</span>
                                            <span id="cart-subtotal-display"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span>
                                        </div>
                                        <div class="pos-summary-row">
                                            <span>Total Discount:</span>
                                            <span id="cart-discount-display"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span>
                                        </div>
                                        <div class="pos-summary-row grand">
                                            <span>Total:</span>
                                            <span id="cart-grand-total"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span>
                                        </div>
                                        
                                        <div style="display:flex; gap:10px; margin-bottom: 15px;">
                                            <input type="text" id="checkout-promotion-code" placeholder="Promo Code" style="flex:1; border-radius:10px; border: 1px solid var(--border-color); padding:0 12px; background: var(--surface-color); color: var(--text-color);">
                                            <button type="button" class="btn btn-info" id="apply-promo-btn" style="border-radius:10px;">Apply</button>
                                        </div>
                                        <p id="promo-message" style="color: var(--danger-color); font-size: 0.85em; margin-bottom: 10px; text-align:center;"></p>
                                        
                                        <div class="pos-action-btns">
                                            <button class="btn btn-danger" id="clear-cart-btn" disabled><i class="fas fa-trash"></i></button>
                                            <button class="btn btn-success" id="checkout-btn" disabled><i class="fas fa-money-check-alt"></i> Checkout</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <div id="checkout-modal" class="modal-overlay">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h3>Checkout</h3>
                                    <button class="modal-close"><i class="fas fa-times"></i></button>
                                </div>
                                <form id="checkout-form" method="POST" action="index.php?page=cart">
                                    <div class="form-group">
                                        <label for="checkout-customer">Select Customer (Optional)</label>
                                        <select id="checkout-customer">
                                            <option value="">Guest Customer</option>
                                            <?php foreach ($customers_for_checkout as $customer): ?>
                                                <option value="<?php echo html($customer['id']); ?>"><?php echo html($customer['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="checkout-subtotal">Subtotal</label>
                                        <input type="text" id="checkout-subtotal" readonly>
                                    </div>
                                    <div class="form-group" id="checkout-discount-display" style="display: none;">
                                        <label for="checkout-discount">Discount Applied</label>
                                        <input type="text" id="checkout-discount" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="checkout-total">Total Amount</label>
                                        <input type="text" id="checkout-total" readonly>
                                    </div>
                                    <input type="hidden" name="action" value="complete_sale">
                                    <input type="hidden" name="customer_id" id="checkout-customer-id-input">
                                    <input type="hidden" name="promotion_code" id="checkout-promotion-code-input">
                                    <input type="hidden" name="cart_items" id="checkout-cart-items-input">
                                    <div class="form-actions">
                                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                                        <button type="submit" class="btn btn-success">Complete Sale</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php
                    break;
                case 'sales-history':
                    ?>
                        <section id="sales-history" class="page-content <?php echo $page === 'sales-history' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Sales History</h1>
                                <button class="btn btn-secondary" id="back-to-cart-btn"><i class="fas fa-arrow-left"></i> Back to
                                    POS</button>
                                <?php if (isAdmin()): ?>
                                    <button class="btn btn-secondary" id="export-sales-btn"><i class="fas fa-download"></i> Export
                                        Sales</button>
                                <?php endif; ?>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="sale-search" placeholder="Search by customer name, product name, sale ID...">
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Sale ID</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Items</th>
                                            <th>Total</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sales-list">
                                        <tr>
                                            <td colspan="6">Loading sales history...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="sales-pagination">
                                    <button id="sales-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="sales-page-info">Page 1 of 1</span>
                                    <button id="sales-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'online-orders':
                    if (!isAdmin() && !isStaff()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                        <section id="online-orders" class="page-content <?php echo $page === 'online-orders' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Online Orders Management</h1>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="online-order-search" placeholder="Search by Order ID, customer name...">
                                </div>
                                <div class="form-group">
                                    <label for="online-order-status-filter">Status</label>
                                    <select id="online-order-status-filter">
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                        <option value="all">All</option>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive card">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Items</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="online-orders-list">
                                        <tr>
                                            <td colspan="7">Loading online orders...</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="pagination" id="online-orders-pagination">
                                    <button id="online-orders-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="online-orders-page-info">Page 1 of 1</span>
                                    <button id="online-orders-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'promotions':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    $all_products = $conn->query('SELECT id, name, author, product_type FROM books ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
                    $all_categories = $conn->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetch_all(MYSQLI_ASSOC);
                    ?>
                        <section id="promotions" class="page-content <?php echo $page === 'promotions' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Promotions & Discounts</h1>
                                <button class="btn btn-primary" id="add-promotion-btn"><i class="fas fa-plus"></i> Add New
                                    Promotion</button>
                            </div>
                            <div class="card">
                                <div class="card-header">Active Promotions</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Type</th>
                                                <th>Value</th>
                                                <th>Applies To</th>
                                                <th>Start Date</th>
                                                <th>End Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="promotions-list">
                                            <tr>
                                                <td colspan="7">Loading promotions...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'expenses':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                        <section id="expenses" class="page-content <?php echo $page === 'expenses' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Expense Tracking</h1>
                                <button class="btn btn-primary" id="add-expense-btn"><i class="fas fa-plus"></i> Add New
                                    Expense</button>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="expense-search" placeholder="Search by description, category...">
                                </div>
                                <div class="form-group">
                                    <label for="expense-month-filter">Month</label>
                                    <input type="month" id="expense-month-filter" value="<?php echo date('Y-m'); ?>">
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Monthly Expenses: <span id="monthly-expenses-total"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span></div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Category</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="expenses-list">
                                            <tr>
                                                <td colspan="5">Loading expenses...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="pagination" id="expenses-pagination">
                                    <button id="expenses-prev-page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                                    <span id="expenses-page-info">Page 1 of 1</span>
                                    <button id="expenses-next-page" disabled>Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'reports':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                        <section id="reports" class="page-content <?php echo $page === 'reports' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Reports & Analytics</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-secondary" id="export-current-report-btn" disabled><i class="fas fa-download"></i> Export Current Report</button>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Generate Reports</div>
                                <div class="report-filters">
                                    <div class="form-group">
                                        <label for="report-type">Report Type</label>
                                        <select id="report-type">
                                            <option value="sales-daily">Daily Sales</option>
                                            <option value="sales-weekly">Weekly Sales</option>
                                            <option value="sales-monthly">Monthly Sales</option>
                                            <option value="best-selling">Best-Selling Products</option>
                                            <option value="best-selling-authors">Best-Selling Authors (Books Only)</option>
                                            <option value="low-stock">Low Stock Products</option>
                                            <option value="expenses-summary">Expenses Summary</option>
                                        </select>
                                    </div>
                                    <div class="form-group" id="report-date-filter">
                                        <label for="report-date">Date</label>
                                        <input type="date" id="report-date" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="form-group" id="report-month-filter" style="display: none;">
                                        <label for="report-month">Month</label>
                                        <input type="month" id="report-month" value="<?php echo date('Y-m'); ?>">
                                    </div>
                                    <div class="form-group" id="report-year-filter" style="display: none;">
                                        <label for="report-year">Year</label>
                                        <input type="number" id="report-year" min="2000" max="2100" value="<?php echo date('Y'); ?>">
                                    </div>
                                </div>
                                <button class="btn btn-primary" id="generate-report-btn"><i class="fas fa-chart-bar"></i> Generate
                                    Report</button>
                            </div>
                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header" id="report-results-header">Report Results</div>
                                <div class="chart-container">
                                    <canvas id="report-chart"></canvas>
                                </div>
                                <div class="table-responsive">
                                    <table class="data-table" id="report-results-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Result</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td colspan="3">Select a report type and generate to see results.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'public-sale-links':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    $public_sale_links = [];
                    $links_query = $conn->query('SELECT psl.*, u.username AS creator_name FROM public_sale_links psl LEFT JOIN users u ON psl.created_by = u.id ORDER BY psl.created_at DESC');
                    if ($links_query) {
                        while ($row = $links_query->fetch_assoc()) {
                            $public_sale_links[] = $row;
                        }
                    }
                    $base_public_link = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/index.php?page=public-sale&token=';
                    ?>
                        <section id="public-sale-links" class="page-content <?php echo $page === 'public-sale-links' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Secure Sale Links</h1>
                                <button class="btn btn-primary" id="add-public-sale-link-btn"><i class="fas fa-plus"></i> New Secure Link</button>
                            </div>
                            <div class="card">
                                <div class="card-header">Password-Protected Public Selling Links</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Mode</th>
                                                <th>Status</th>
                                                <th>Secure Link</th>
                                                <th>Created</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($public_sale_links)): ?>
                                                <?php foreach ($public_sale_links as $secure_link): ?>
                                                    <?php $full_secure_link = $base_public_link . $secure_link['token']; ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo html($secure_link['link_name']); ?></strong>
                                                            <?php if (!empty($secure_link['notes'])): ?>
                                                                <div style="color: var(--light-text-color); font-size: 12px; margin-top: 4px;"><?php echo html($secure_link['notes']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="public-sale-rate-badge"><?php echo html(strtoupper($secure_link['price_mode'])); ?></span></td>
                                                        <td><?php echo $secure_link['is_active'] ? '<span class="status-pill success">Active</span>' : '<span class="status-pill muted">Inactive</span>'; ?></td>
                                                        <td>
                                                            <div class="secure-link-copy-wrap">
                                                                <input type="text" readonly value="<?php echo html($full_secure_link); ?>" class="secure-link-input">
                                                                <button type="button" class="btn btn-secondary btn-sm copy-secure-link-btn" data-link="<?php echo html($full_secure_link); ?>"><i class="fas fa-copy"></i></button>
                                                                <a href="<?php echo html($full_secure_link); ?>" target="_blank" class="btn btn-info btn-sm"><i class="fas fa-up-right-from-square"></i></a>
                                                            </div>
                                                        </td>
                                                        <td><?php echo format_date($secure_link['created_at']); ?></td>
                                                        <td class="actions">
                                                            <button type="button" class="btn btn-primary btn-sm edit-public-sale-link-btn"
                                                                data-id="<?php echo html($secure_link['id']); ?>"
                                                                data-name="<?php echo html($secure_link['link_name']); ?>"
                                                                data-mode="<?php echo html($secure_link['price_mode']); ?>"
                                                                data-notes="<?php echo html($secure_link['notes'] ?? ''); ?>"
                                                                data-active="<?php echo html($secure_link['is_active']); ?>"><i class="fas fa-edit"></i> Edit</button>
                                                            <form method="POST" action="index.php?page=public-sale-links" style="display:inline;">
                                                                <input type="hidden" name="action" value="toggle_public_sale_link">
                                                                <input type="hidden" name="link_id" value="<?php echo html($secure_link['id']); ?>">
                                                                <input type="hidden" name="current_status" value="<?php echo html($secure_link['is_active']); ?>">
                                                                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-power-off"></i> <?php echo $secure_link['is_active'] ? 'Disable' : 'Enable'; ?></button>
                                                            </form>
                                                            <form method="POST" action="index.php?page=public-sale-links" style="display:inline;" onsubmit="return confirm('Delete this secure sale link?');">
                                                                <input type="hidden" name="action" value="delete_public_sale_link">
                                                                <input type="hidden" name="link_id" value="<?php echo html($secure_link['id']); ?>">
                                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6">No secure sale links created yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php
                    break;
                case 'settings':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    $stmt_settings = $conn->prepare('SELECT setting_key, setting_value FROM settings');
                    $stmt_settings->execute();
                    $result_settings = $stmt_settings->get_result();
                    $current_settings = [];
                    while ($row = $result_settings->fetch_assoc()) {
                        $current_settings[$row['setting_key']] = $row['setting_value'];
                    }
                    $stmt_settings->close();
                    ?>
                        <section id="settings" class="page-content <?php echo $page === 'settings' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>System Settings</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">General Store Information</div>
                                <form action="index.php?page=settings" method="POST">
                                    <input type="hidden" name="action" value="save_settings">
                                    <div class="form-group">
                                        <label for="system-name">System Name</label>
                                        <input type="text" id="system-name" name="system_name" value="<?php echo html($current_settings['system_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="mission">Mission Statement</label>
                                        <textarea id="mission" name="mission" rows="3"><?php echo html($current_settings['mission'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="vision">Vision Statement</label>
                                        <textarea id="vision" name="vision" rows="3"><?php echo html($current_settings['vision'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="address">Address</label>
                                        <textarea id="address" name="address" rows="3"><?php echo html($current_settings['address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="phone">Phone Number</label>
                                        <input type="text" id="phone" name="phone" value="<?php echo html($current_settings['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="whatsapp-number">WhatsApp Number</label>
                                        <input type="text" id="whatsapp-number" name="whatsapp_number" value="<?php echo html($current_settings['whatsapp_number'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email" value="<?php echo html($current_settings['email'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="google-map-embed-url">Google Map Embed URL</label>
                                        <input type="url" id="google-map-embed-url" name="google_map_embed_url" value="<?php echo html($current_settings['google_map_embed_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="facebook-url">Facebook URL</label>
                                        <input type="url" id="facebook-url" name="facebook_url" value="<?php echo html($current_settings['facebook_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="instagram-url">Instagram URL</label>
                                        <input type="url" id="instagram-url" name="instagram_url" value="<?php echo html($current_settings['instagram_url'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="currency-symbol">Currency Symbol</label>
                                        <input type="text" id="currency-symbol" name="currency_symbol" value="<?php echo html($current_settings['currency_symbol'] ?? 'PKR '); ?>" required>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                                    </div>
                                </form>
                            </div>
                        </section>
                    <?php
                    break;
                case 'print-barcodes':
                    if (!isAdmin()) {
                        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                        redirect('dashboard');
                    }
                    ?>
                <section id="print-barcodes" class="page-content <?php echo $page === 'print-barcodes' ? 'active' : ''; ?>">
                    <div class="page-header">
                        <h1>Print Barcodes (A4)</h1>
                    </div>
                    <div class="card">
                        <div class="form-group">
                            <label>Select Product</label>
                            <select id="print-barcode-select" style="width:100%; padding: 10px;">
                                <option value="all">-- Print All Products --</option>
                                <?php
                                $books = $conn->query('SELECT id, name, barcode, retail_price, price FROM books WHERE barcode IS NOT NULL AND barcode != "" ORDER BY name ASC');
                                while ($b = $books->fetch_assoc()) {
                                    $bprice = $b['retail_price'] ?: $b['price'];
                                    echo '<option value="' . html($b['barcode']) . '" data-name="' . html($b['name']) . '" data-price="' . html($bprice) . '">' . html($b['name']) . ' (' . html($b['barcode']) . ')</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Copies per Barcode</label>
                            <input type="number" id="print-barcode-copies" value="1" min="1" style="padding: 10px; width: 100%;">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="generateBarcodePrint()"><i class="fas fa-print"></i> Generate A4 Print</button>
                    </div>
                    <script>
                        function generateBarcodePrint() {
                            const select = document.getElementById('print-barcode-select');
                            const copies = parseInt(document.getElementById('print-barcode-copies').value) || 1;
                            
                            let items = [];
                            if(select.value === 'all') {
                                for(let i=1; i<select.options.length; i++) {
                                    items.push({
                                        barcode: select.options[i].value,
                                        name: select.options[i].getAttribute('data-name'),
                                        price: select.options[i].getAttribute('data-price')
                                    });
                                }
                            } else {
                                const opt = select.options[select.selectedIndex];
                                items.push({
                                    barcode: opt.value,
                                    name: opt.getAttribute('data-name'),
                                    price: opt.getAttribute('data-price')
                                });
                            }
                            
                            let htmlContent = `
                                <html>
                                <head>
                                <title>Print Barcodes</title>
                                <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
                                <style>
                                    @page { size: A4; margin: 0; }
                                    body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: white; }
                                    .page { 
                                        width: 210mm;
                                        height: 297mm;
                                        display: flex; 
                                        flex-wrap: wrap; 
                                        align-content: flex-start;
                                        page-break-after: always;
                                        padding: 10mm;
                                        box-sizing: border-box;
                                    }
                                    .barcode-item {
                                        width: calc(100% / 3);
                                        height: calc((297mm - 20mm) / 6);
                                        box-sizing: border-box;
                                        padding: 5mm;
                                        text-align: center;
                                        display: flex;
                                        flex-direction: column;
                                        justify-content: center;
                                        align-items: center;
                                    }
                                    .barcode-item svg { max-width: 100%; max-height: 60%; }
                                    .b-name { font-size: 13px; font-weight: bold; margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
                                    .b-price { font-size: 14px; margin-top: 5px; font-weight: bold; }
                                </style>
                                </head>
                                <body>
                            `;
                            
                            let totalItems = [];
                            items.forEach(it => {
                                for(let i=0; i<copies; i++) totalItems.push(it);
                            });
                            
                            let pages = Math.ceil(totalItems.length / 18);
                            let itemIndex = 0;
                            
                            for(let p=0; p<pages; p++) {
                                htmlContent += `<div class="page">`;
                                for(let i=0; i<18 && itemIndex < totalItems.length; i++) {
                                    let it = totalItems[itemIndex++];
                                    htmlContent += `<div class="barcode-item">
                                                <div class="b-name">${it.name}</div>
                                                <svg class="barcode-svg" data-val="${it.barcode}"></svg>
                                                <div class="b-price">${currentCurrencySymbol} ${it.price}</div>
                                             </div>`;
                                }
                                htmlContent += `</div>`;
                            }
                            htmlContent += `
                                <script>
                                    window.onload = function() {
                                        document.querySelectorAll('.barcode-svg').forEach(el => {
                                            JsBarcode(el, el.getAttribute('data-val'), {
                                                format: "CODE128", width: 1.5, height: 50, displayValue: true, fontSize: 14, margin: 0
                                            });
                                        });
                                        setTimeout(() => { window.print(); }, 800);
                                    }
                                <\/script>
                                </body></html>
                            `;
                            
                            let printWin = window.open('', '_blank');
                            printWin.document.write(htmlContent);
                            printWin.document.close();
                        }
                    </script>
                </section>
                <?php
                break;
            case 'backup-restore':
                if (!isAdmin()) {
                    $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                    redirect('dashboard');
                }
                ?>
                        <section id="backup-restore" class="page-content <?php echo $page === 'backup-restore' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Backup & Restore</h1>
                            </div>
                            <div class="card">
                                <div class="card-header">Export All Data</div>
                                <p>Export all your <?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?> data (Products, Customers, Sales, Suppliers, POs, Promotions,
                                    Expenses, Users, Online Orders, Settings) as a JSON file. This file can
                                    be used to restore your data later.</p>
                                <form action="index.php?page=backup-restore" method="POST">
                                    <input type="hidden" name="action" value="export_all_data">
                                    <button type="submit" class="btn btn-primary" id="export-all-data-btn"><i class="fas fa-download"></i> Export All
                                        Data</button>
                                </form>
                            </div>
                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header">Import All Data</div>
                                <p>Import a previously exported JSON file to restore your <?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?> data. <strong>Warning:
                                        This will overwrite ALL existing data!</strong></p>
                                <form action="index.php?page=backup-restore" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="import_all_data">
                                    <div class="form-group">
                                        <label for="import-file">Select JSON File to Import</label>
                                        <input type="file" id="import-file" name="import_file" accept=".json">
                                    </div>
                                    <button type="submit" class="btn btn-danger" id="import-all-data-btn" disabled><i class="fas fa-upload"></i>
                                        Import All Data</button>
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
            </main>
        </div>
        <div id="book-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="book-modal-title">Add New Product</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="book-form" action="index.php?page=books" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_book">
                    <input type="hidden" id="book-id" name="book_id">
                    <input type="hidden" id="existing-cover-image" name="existing_cover_image">
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-name">Name</label>
                            <input type="text" id="book-name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="product-type">Product Type</label>
                            <select id="product-type" name="product_type" required>
                                <option value="book">Book</option>
                                <option value="general">General Item</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-category">Category</label>
                            <input type="text" id="book-category" name="category" required>
                        </div>
                        <div class="form-group" id="book-author-group">
                            <label for="book-author">Author</label>
                            <input type="text" id="book-author" name="author">
                        </div>
                    </div>
                    <div class="flex-group" id="book-details-group">
                        <div class="form-group" id="book-isbn-group">
                            <label for="book-isbn">ISBN</label>
                            <input type="text" id="book-isbn" name="isbn">
                        </div>
                        <div class="form-group" id="book-publisher-group">
                            <label for="book-publisher">Publisher</label>
                            <input type="text" id="book-publisher" name="publisher">
                        </div>
                        <div class="form-group" id="book-year-group">
                            <label for="book-year">Year</label>
                            <input type="number" id="book-year" name="year" min="1000" max="2100">
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-price">Price (<?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?>)</label>
                            <input type="number" id="book-price" name="price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="book-purchase-price">Purchase Price</label>
                            <input type="number" id="book-purchase-price" name="purchase_price" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="book-stock">Stock Quantity</label>
                            <input type="number" id="book-stock" name="stock" min="0" required>
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-retail-price">Retail Rate</label>
                            <input type="number" id="book-retail-price" name="retail_price" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label for="book-wholesale-price">Wholesale Rate</label>
                            <input type="number" id="book-wholesale-price" name="wholesale_price" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-barcode">Barcode</label>
                            <div class="inline-input-group">
                                <input type="text" id="book-barcode" name="barcode" placeholder="Scan or type barcode">
                                <button type="button" class="btn btn-secondary barcode-scan-btn" id="scan-book-barcode-btn"><i class="fas fa-barcode"></i> Scan</button>
                            </div>
                        </div>
                        <div class="form-group" style="align-self:flex-end;">
                            <button type="button" class="btn btn-secondary" id="print-book-barcode-btn"><i class="fas fa-print"></i> Print Barcode</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="book-description">Description</label>
                        <textarea id="book-description" name="description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="book-cover-image">Cover Image</label>
                        <input type="file" id="book-cover-image" name="cover_image" accept="image/*">
                        <div class="img-preview" id="book-cover-preview-container">
                            <img src="" alt="Cover Preview" style="display: none;" id="book-cover-preview-img">
                        </div>
                        <label id="remove-cover-label" style="display: none; margin-top: 10px;">
                            <input type="checkbox" id="remove-cover-image" name="remove_cover_image" value="true"> Remove Existing Image
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Product</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="import-books-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Import Products</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="import-books-form" action="index.php?page=books" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_books_action">
                    <div class="form-group">
                        <label for="import-books-file">Select JSON File to Import</label>
                        <input type="file" id="import-books-file" name="import_books_file" accept=".json" required>
                    </div>
                    <div class="form-group">
                        <label>If product with same ISBN (for books) or Name (for general items) exists:</label>
                        <div>
                            <input type="radio" id="import-books-skip" name="import_conflict_books" value="skip" checked>
                            <label for="import-books-skip">Skip (default)</label>
                        </div>
                        <div>
                            <input type="radio" id="import-books-update" name="import_conflict_books" value="update">
                            <label for="import-books-update">Update existing product</label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Import Products</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="restock-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Restock Product</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="restock-form" action="index.php?page=books" method="POST">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" id="restock-book-id" name="book_id">
                    <div class="form-group">
                        <label for="restock-book-name">Product Name</label>
                        <input type="text" id="restock-book-name" readonly>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="restock-current-stock">Current Stock</label>
                            <input type="number" id="restock-current-stock" readonly>
                        </div>
                        <div class="form-group">
                            <label for="restock-quantity">Quantity to Add</label>
                            <input type="number" id="restock-quantity" name="quantity_to_add" min="1" value="1" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Restock</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="user-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="user-modal-title">Add New User</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="user-form" action="index.php?page=users" method="POST">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" id="sys-user-id" name="user_id">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="sys-username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select id="sys-role-id" name="role_id" required></select>
                    </div>
                    <div class="form-group">
                        <label>Password <small>(Leave blank to keep existing)</small></label>
                        <input type="password" id="sys-password" name="password">
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save User</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="role-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3 id="role-modal-title">Add New Role</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="role-form" action="index.php?page=users" method="POST">
                    <input type="hidden" name="action" value="save_role">
                    <input type="hidden" id="sys-role-id-form" name="role_id">
                    <div class="form-group">
                        <label>Role Name</label>
                        <input type="text" id="sys-role-name" name="role_name" required>
                    </div>
                    <div class="form-group">
                        <label>Page Permissions</label>
                        <div id="sys-role-permissions" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;"></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Role</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="customer-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="customer-modal-title">Add New Customer</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="customer-form" action="index.php?page=customers" method="POST">
                    <input type="hidden" name="action" value="save_customer">
                    <input type="hidden" id="customer-id" name="customer_id">
                    <div class="form-group">
                        <label for="customer-name">Name</label>
                        <input type="text" id="customer-name" name="name" required>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="customer-phone">Phone</label>
                            <input type="tel" id="customer-phone" name="phone">
                        </div>
                        <div class="form-group">
                            <label for="customer-email">Email</label>
                            <input type="email" id="customer-email" name="email">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="customer-address">Address</label>
                        <textarea id="customer-address" name="address" rows="2"></textarea>
                    </div>
                    <div class="form-group" id="customer-password-group" style="display: none;">
                        <label for="customer-password">Password (leave empty to keep current)</label>
                        <input type="password" id="customer-password" name="password">
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Customer</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="import-customers-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Import Customers</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="import-customers-form" action="index.php?page=customers" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_customers_action">
                    <div class="form-group">
                        <label for="import-customers-file">Select JSON File to Import</label>
                        <input type="file" id="import-customers-file" name="import_customers_file" accept=".json" required>
                    </div>
                    <div class="form-group">
                        <label>If customer with same email exists:</label>
                        <div>
                            <input type="radio" id="import-customers-skip" name="import_conflict_customers" value="skip" checked>
                            <label for="import-customers-skip">Skip (default)</label>
                        </div>
                        <div>
                            <input type="radio" id="import-customers-update" name="import_conflict_customers" value="update">
                            <label for="import-customers-update">Update existing customer</label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Import Customers</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="customer-history-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3 id="customer-history-title">Purchase History for Customer</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sale ID</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody id="customer-history-list">
                            <tr>
                                <td colspan="4">No purchases found for this customer.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions" style="justify-content: center;">
                    <button type="button" class="btn btn-secondary modal-close">Close</button>
                </div>
            </div>
        </div>
        <div id="supplier-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="supplier-modal-title">Add New Supplier</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="supplier-form" action="index.php?page=suppliers" method="POST">
                    <input type="hidden" name="action" value="save_supplier">
                    <input type="hidden" id="supplier-id" name="supplier_id">
                    <div class="form-group">
                        <label for="supplier-name">Supplier Name</label>
                        <input type="text" id="supplier-name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="supplier-contact-person">Contact Person</label>
                        <input type="text" id="supplier-contact-person" name="contact_person">
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="supplier-phone">Phone</label>
                            <input type="tel" id="supplier-phone" name="phone">
                        </div>
                        <div class="form-group">
                            <label for="supplier-email">Email</label>
                            <input type="email" id="supplier-email" name="email">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="supplier-address">Address</label>
                        <textarea id="supplier-address" name="address" rows="2"></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Supplier</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="import-suppliers-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Import Suppliers</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="import-suppliers-form" action="index.php?page=suppliers" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_suppliers_action">
                    <div class="form-group">
                        <label for="import-suppliers-file">Select JSON File to Import</label>
                        <input type="file" id="import-suppliers-file" name="import_suppliers_file" accept=".json" required>
                    </div>
                    <div class="form-group">
                        <label>If supplier with same email exists:</label>
                        <div>
                            <input type="radio" id="import-suppliers-skip" name="import_conflict_suppliers" value="skip" checked>
                            <label for="import-suppliers-skip">Skip (default)</label>
                        </div>
                        <div>
                            <input type="radio" id="import-suppliers-update" name="import_conflict_suppliers" value="update">
                            <label for="import-suppliers-update">Update existing supplier</label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Import Suppliers</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="purchase-order-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3 id="po-modal-title">Create New Purchase Order</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="po-form" action="index.php?page=purchase-orders" method="POST">
                    <input type="hidden" name="action" value="save_po">
                    <input type="hidden" id="po-id" name="po_id">
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="po-supplier">Supplier</label>
                            <select id="po-supplier" name="supplier_id" required>
                                <?php
                                $suppliers = $conn->query('SELECT id, name FROM suppliers ORDER BY name ASC')->fetch_all(MYSQLI_ASSOC);
                                if (!empty($suppliers)) {
                                    foreach ($suppliers as $supplier) {
                                        echo '<option value="' . html($supplier['id']) . '">' . html($supplier['name']) . '</option>';
                                    }
                                } else {
                                    echo '<option value="">No Suppliers Available</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="po-order-date">Order Date</label>
                            <input type="date" id="po-order-date" name="order_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="po-expected-date">Expected Delivery Date</label>
                            <input type="date" id="po-expected-date" name="expected_date">
                        </div>
                        <div class="form-group">
                            <label for="po-status">Status</label>
                            <select id="po-status" name="status">
                                <option value="pending">Pending</option>
                                <option value="ordered">Ordered</option>
                                <option value="received">Received</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-header" style="margin-top: 20px;">Order Items</div>
                    <div class="flex-group">
                        <div class="form-group" style="flex-grow: 3;">
                            <label for="po-book-select">Add Product</label><div class="inline-input-group" style="margin-bottom:10px;"><input type="text" id="po-barcode-search" placeholder="Scan barcode for PO item"><button type="button" class="btn btn-secondary barcode-scan-btn" id="scan-po-barcode-btn"><i class="fas fa-barcode"></i> Scan</button></div>
                            <select id="po-book-select" style="width: 100%;">
                                <option value="">-- Select a Product to Add --</option>
                                <?php foreach ($all_books_for_po as $book): ?>
                                    <option
                                        value="<?php echo html($book['id']); ?>"
                                        data-name="<?php echo html($book['name']); ?>"
                                        data-price="<?php echo html($book['price']); ?>">
                                        <?php echo html($book['name'] . ($book['author'] ? ' by ' . $book['author'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="button" class="btn btn-primary" id="add-selected-book-to-po-btn">Add to PO</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Quantity</th>
                                    <th>Unit Cost</th>
                                    <th>Subtotal</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="po-items-list">
                                <tr>
                                    <td colspan="5">No items added to this purchase order.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="po-summary" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid var(--border-color); text-align: right; font-weight: bold;">
                        Total Cost: <span id="po-grand-total"><?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?> 0.00</span>
                    </div>
                    <input type="hidden" id="po-items-input" name="po_items">
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Purchase Order</button>
                        <button type="button" class="btn btn-success" id="receive-po-btn" style="display: none;"><i class="fas fa-check-double"></i> Receive Order</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="public-sale-link-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 620px;">
                <div class="modal-header">
                    <h3 id="public-sale-link-modal-title">Create Secure Sale Link</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="public-sale-link-form" action="index.php?page=public-sale-links" method="POST">
                    <input type="hidden" name="action" value="save_public_sale_link">
                    <input type="hidden" name="link_id" id="public-sale-link-id">
                    <div class="form-group">
                        <label for="public-sale-link-name">Link Name</label>
                        <input type="text" name="link_name" id="public-sale-link-name" required>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="public-sale-link-password">Password</label>
                            <input type="password" name="access_password" id="public-sale-link-password" placeholder="Required for new links">
                        </div>
                        <div class="form-group">
                            <label for="public-sale-link-mode">Selling Mode</label>
                            <select name="price_mode" id="public-sale-link-mode">
                                <option value="retail">Retail Rate</option>
                                <option value="wholesale">Wholesale Rate</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="public-sale-link-notes">Notes</label>
                        <textarea name="notes" id="public-sale-link-notes" rows="3" placeholder="Shown to admin only"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="is_active" id="public-sale-link-active" checked> Active</label>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Secure Link</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="receipt-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Sale Receipt</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <div id="receipt-content" style="font-family: monospace; font-size: 0.9em; line-height: 1.4;">
                    <p style="text-align: center; font-size: 1.2em; font-weight: bold; margin-bottom: 10px;"><?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?> Receipt</p>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p><strong>Sale ID:</strong> <span id="receipt-sale-id"></span></p>
                    <p><strong>Date:</strong> <span id="receipt-date"></span></p>
                    <p><strong>Customer:</strong> <span id="receipt-customer"></span></p>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p style="font-weight: bold;">Items:</p>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 2px 0;">Product</th>
                                <th style="text-align: right; padding: 2px 0;">Qty</th>
                                <th style="text-align: right; padding: 2px 0;">Price</th>
                                <th style="text-align: right; padding: 2px 0;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="receipt-items-list">
                        </tbody>
                    </table>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p style="text-align: right;">Subtotal: <span id="receipt-subtotal"></span></p>
                    <p style="text-align: right; display: none;" id="receipt-discount-line">Discount: <span id="receipt-discount-value"></span></p>
                    <p style="font-size: 1.1em; font-weight: bold; text-align: right;">Total: <span id="receipt-grand-total"></span></p>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p style="text-align: center; margin-top: 15px;">Thank you for your purchase!</p>
                </div>
                <div class="form-actions" style="margin-top: 20px; flex-wrap: wrap; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary modal-close">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printReceipt('a4')"><i class="fas fa-file-alt"></i> Print A4</button>
                    <button type="button" class="btn btn-info" onclick="printReceipt('thermal')"><i class="fas fa-receipt"></i> Print Thermal</button>
                    <button type="button" class="btn btn-success" id="download-receipt-btn"><i class="fas fa-download"></i> PDF</button>
                </div>
            </div>
        </div>
        <div id="view-sale-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Sale Details</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <div id="sale-details-content">
                    <p><strong>Sale ID:</strong> <span id="sale-details-id"></span></p>
                    <p><strong>Date:</strong> <span id="sale-details-date"></span></p>
                    <p><strong>Customer:</strong> <span id="sale-details-customer"></span></p>
                    <p style="font-weight: bold; margin-top: 15px;">Items:</p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Discount</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="sale-details-items">
                            </tbody>
                        </table>
                    </div>
                    <p style="font-size: 1.1em; font-weight: bold; text-align: right; margin-top: 15px;">Subtotal: <span id="sale-details-subtotal"></span></p>
                    <p style="font-size: 1.1em; font-weight: bold; text-align: right;" id="sale-details-discount-line">
                        Discount:
                        <span id="sale-details-discount-value"></span>
                    </p>
                    <p style="font-size: 1.1em; font-weight: bold; text-align: right;">Total: <span id="sale-details-total"></span></p>
                </div>
                <div class="form-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary modal-close">Close</button>
                    <button type="button" class="btn btn-info" id="reprint-receipt-btn"><i class="fas fa-print"></i> Print
                        Receipt</button>
                </div>
            </div>
        </div>
        <div id="promotion-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="promotion-modal-title">Add New Promotion</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="promotion-form" action="index.php?page=promotions" method="POST">
                    <input type="hidden" name="action" value="save_promotion">
                    <input type="hidden" id="promotion-id" name="promotion_id">
                    <div class="form-group">
                        <label for="promotion-code">Promotion Code</label>
                        <input type="text" id="promotion-code" name="code" required>
                    </div>
                    <div class="promotion-type-toggle">
                        <button type="button" id="promo-type-percentage" class="btn btn-secondary active" data-type="percentage">Percentage Off</button>
                        <button type="button" id="promo-type-fixed" class="btn btn-secondary" data-type="fixed">Fixed
                            Amount Off</button>
                    </div>
                    <input type="hidden" id="promotion-type" name="type" value="percentage">
                    <div class="form-group">
                        <label for="promotion-value">Discount Value</label>
                        <input type="number" id="promotion-value" name="value" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="promotion-applies-to">Applies To</label>
                        <select id="promotion-applies-to" name="applies_to" required>
                            <option value="all">Entire Order</option>
                            <option value="specific-book">Specific Product</option>
                            <option value="specific-category">Specific Category</option>
                        </select>
                    </div>
                    <div class="form-group" id="promotion-book-group" style="display: none;">
                        <label for="promotion-book-id">Select Product</label>
                        <select id="promotion-book-id" name="promotion_book_id">
                            <option value="">Select a Product</option>
                            <?php foreach ($all_products as $product): ?>
                                <option value="<?php echo html($product['id']); ?>"><?php echo html($product['name'] . ($product['author'] ? ' by ' . $product['author'] : ' (' . ucfirst($product['product_type']) . ')')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="promotion-category-group" style="display: none;">
                        <label for="promotion-category">Select Category</label>
                        <input type="text" id="promotion-category" name="promotion_category" list="book-categories-datalist">
                        <datalist id="book-categories-datalist">
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo html($cat['category']); ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="promotion-start-date">Start Date</label>
                            <input type="date" id="promotion-start-date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="promotion-end-date">End Date</label>
                            <input type="date" id="promotion-end-date" name="end_date">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Promotion</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="expense-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="expense-modal-title">Add New Expense</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="expense-form" action="index.php?page=expenses" method="POST">
                    <input type="hidden" name="action" value="save_expense">
                    <input type="hidden" id="expense-id" name="expense_id">
                    <div class="form-group">
                        <label for="expense-date">Date</label>
                        <input type="date" id="expense-date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="expense-category">Category</label>
                        <input type="text" id="expense-category" name="category" list="expense-categories-datalist" required>
                        <datalist id="expense-categories-datalist">
                            <option value="Rent">
                            <option value="Utilities">
                            <option value="Salaries">
                            <option value="Marketing">
                            <option value="Supplies">
                            <option value="Maintenance">
                            <option value="Other">
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="expense-description">Description</label>
                        <textarea id="expense-description" name="description" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="expense-amount">Amount (<?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?>)</label>
                        <input type="number" id="expense-amount" name="amount" min="0" step="0.01" required>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Expense</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <div id="view-online-order-modal" class="modal-overlay">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Online Order Details</h3>
                <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div id="online-order-details-content">
                <p><strong>Order ID:</strong> <span id="online-order-details-id"></span></p>
                <p><strong>Date:</strong> <span id="online-order-details-date"></span></p>
                <p><strong>Customer:</strong> <span id="online-order-details-customer"></span></p>
                <p><strong>Email:</strong> <span id="online-order-details-email"></span></p>
                <p><strong>Phone:</strong> <span id="online-order-details-phone"></span></p>
                <p><strong>Status:</strong> <span id="online-order-details-status"></span></p>
                <p style="font-weight: bold; margin-top: 15px;">Items:</p>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Discount</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="online-order-details-items">
                        </tbody>
                    </table>
                </div>
                <p style="font-size: 1.1em; font-weight: bold; text-align: right; margin-top: 15px;">Subtotal: <span id="online-order-details-subtotal"></span></p>
                <p style="font-size: 1.1em; font-weight: bold; text-align: right;" id="online-order-details-discount-line">
                    Discount:
                    <span id="online-order-details-discount-value"></span>
                </p>
                <p style="font-size: 1.1em; font-weight: bold; text-align: right;">Total: <span id="online-order-details-total"></span></p>
            </div>
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" class="btn btn-secondary modal-close">Close</button>
                <form action="index.php?page=online-orders" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="approve_online_order">
                    <input type="hidden" name="order_id" id="approve-order-id">
                    <button type="submit" class="btn btn-success" id="approve-order-btn"><i class="fas fa-check"></i> Approve</button>
                </form>
                <form action="index.php?page=online-orders" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reject_online_order">
                    <input type="hidden" name="order_id" id="reject-order-id">
                    <button type="submit" class="btn btn-danger" id="reject-order-btn"><i class="fas fa-times"></i> Reject</button>
                </form>
            </div>
        </div>
    </div>
    <div id="toast-container"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script>
        function html(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        const PAGE_SIZE = 10;
        let currentCart = <?php echo json_encode($_SESSION['cart']); ?>;
        let appliedPromotion = <?php echo json_encode($_SESSION['applied_promotion'] ?? null); ?>;
        let currentReportData = [];
        let reportChartInstance = null;
        let currentCurrencySymbol = "<?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?>";
        const elements = {
            appContainer: document.getElementById('app-container'),
            sidebar: document.querySelector('.sidebar'),
            hamburgerMenu: document.getElementById('hamburger-menu'),
            darkModeSwitch: document.getElementById('dark-mode-switch'),
            navLinks: document.querySelectorAll('.nav-link'),
            pageContents: document.querySelectorAll('.page-content'),
            toastContainer: document.getElementById('toast-container'),
            totalBooksCount: document.getElementById('total-books-count'),
            totalCustomersCount: document.getElementById('total-customers-count'),
            lowStockCount: document.getElementById('low-stock-count'),
            todaySalesTotal: document.getElementById('today-sales-total'),
            dashboardRecentSales: document.getElementById('dashboard-recent-sales'),
            dashboardLowStockBooks: document.getElementById('dashboard-low-stock-books'),
            addBookBtn: document.getElementById('add-book-btn'),
            exportBooksBtn: document.getElementById('export-books-btn'),
            importBooksBtn: document.getElementById('import-books-btn'),
            bookSearch: document.getElementById('book-search'),
            bookSort: document.getElementById('book-sort'),
            booksList: document.getElementById('books-list'),
            booksPagination: document.getElementById('books-pagination'),
            booksPrevPage: document.getElementById('books-prev-page'),
            booksNextPage: document.getElementById('books-next-page'),
            booksPageInfo: document.getElementById('books-page-info'),
            importBooksModal: document.getElementById('import-books-modal'),
            importBooksForm: document.getElementById('import-books-form'),
            importBooksFile: document.getElementById('import-books-file'),
            importBooksSkip: document.getElementById('import-books-skip'),
            importBooksUpdate: document.getElementById('import-books-update'),
            addCustomerBtn: document.getElementById('add-customer-btn'),
            exportCustomersBtn: document.getElementById('export-customers-btn'),
            importCustomersBtn: document.getElementById('import-customers-btn'),
            customerSearch: document.getElementById('customer-search'),
            customerFilterStatus: document.getElementById('customer-filter-status'),
            customersList: document.getElementById('customers-list'),
            customersPagination: document.getElementById('customers-pagination'),
            customersPrevPage: document.getElementById('customers-prev-page'),
            customersNextPage: document.getElementById('customers-next-page'),
            customersPageInfo: document.getElementById('customers-page-info'),
            importCustomersModal: document.getElementById('import-customers-modal'),
            importCustomersForm: document.getElementById('import-customers-form'),
            importCustomersFile: document.getElementById('import-customers-file'),
            importCustomersSkip: document.getElementById('import-customers-skip'),
            importCustomersUpdate: document.getElementById('import-customers-update'),
            addSupplierBtn: document.getElementById('add-supplier-btn'),
            exportSuppliersBtn: document.getElementById('export-suppliers-btn'),
            importSuppliersBtn: document.getElementById('import-suppliers-btn'),
            supplierSearch: document.getElementById('supplier-search'),
            suppliersList: document.getElementById('suppliers-list'),
            suppliersPagination: document.getElementById('suppliers-pagination'),
            suppliersPrevPage: document.getElementById('suppliers-prev-page'),
            suppliersNextPage: document.getElementById('suppliers-next-page'),
            suppliersPageInfo: document.getElementById('suppliers-page-info'),
            importSuppliersModal: document.getElementById('import-suppliers-modal'),
            importSuppliersForm: document.getElementById('import-suppliers-form'),
            importSuppliersFile: document.getElementById('import-suppliers-file'),
            importSuppliersSkip: document.getElementById('import-suppliers-skip'),
            importSuppliersUpdate: document.getElementById('import-suppliers-update'),
            createPoBtn: document.getElementById('create-po-btn'),
            exportPosBtn: document.getElementById('export-pos-btn'),
            poSearch: document.getElementById('po-search'),
            poStatusFilter: document.getElementById('po-status-filter'),
            purchaseOrdersList: document.getElementById('purchase-orders-list'),
            posPagination: document.getElementById('pos-pagination'),
            posPrevPage: document.getElementById('pos-prev-page'),
            posNextPage: document.getElementById('pos-next-page'),
            posPageInfo: document.getElementById('pos-page-info'),
            viewSalesHistoryBtn: document.getElementById('view-sales-history-btn'),
            exportSalesBtn: document.getElementById('export-sales-btn'),
            backToCartBtn: document.getElementById('back-to-cart-btn'),
            bookToCartSearch: document.getElementById('book-to-cart-search'),
            booksForCartList: document.getElementById('books-for-cart-list'),
            booksForCartPagination: document.getElementById('books-for-cart-pagination'),
            booksForCartPrevPage: document.getElementById('books-for-cart-prev-page'),
            booksForCartNextPage: document.getElementById('books-for-cart-next-page'),
            booksForCartPageInfo: document.getElementById('books-for-cart-page-info'),
            posCategoryFilter: document.getElementById('pos-category-filter'),
            cartTotalItems: document.getElementById('cart-total-items'),
            cartItemsTable: document.getElementById('cart-items-table'),
            cartGrandTotal: document.getElementById('cart-grand-total'),
            clearCartBtn: document.getElementById('clear-cart-btn'),
            checkoutBtn: document.getElementById('checkout-btn'),
            salesList: document.getElementById('sales-list'),
            salesPagination: document.getElementById('sales-pagination'),
            salesPrevPage: document.getElementById('sales-prev-page'),
            salesNextPage: document.getElementById('sales-next-page'),
            salesPageInfo: document.getElementById('sales-page-info'),
            saleSearch: document.getElementById('sale-search'),
            addPromotionBtn: document.getElementById('add-promotion-btn'),
            promotionsList: document.getElementById('promotions-list'),
            promotionTypePercentage: document.getElementById('promo-type-percentage'),
            promotionTypeFixed: document.getElementById('promo-type-fixed'),
            promotionType: document.getElementById('promotion-type'),
            promotionAppliesTo: document.getElementById('promotion-applies-to'),
            promotionBookGroup: document.getElementById('promotion-book-group'),
            promotionBookId: document.getElementById('promotion-book-id'),
            promotionCategoryGroup: document.getElementById('promotion-category-group'),
            promotionCategory: document.getElementById('promotion-category'),
            bookCategoriesDatalist: document.getElementById('book-categories-datalist'),
            addExpenseBtn: document.getElementById('add-expense-btn'),
            expenseSearch: document.getElementById('expense-search'),
            expenseMonthFilter: document.getElementById('expense-month-filter'),
            expensesList: document.getElementById('expenses-list'),
            monthlyExpensesTotal: document.getElementById('monthly-expenses-total'),
            expensesPagination: document.getElementById('expenses-pagination'),
            expensesPrevPage: document.getElementById('expenses-prev-page'),
            expensesNextPage: document.getElementById('expenses-next-page'),
            expensesPageInfo: document.getElementById('expenses-page-info'),
            reportType: document.getElementById('report-type'),
            reportDateFilter: document.getElementById('report-date-filter'),
            reportDate: document.getElementById('report-date'),
            reportMonthFilter: document.getElementById('report-month-filter'),
            reportMonth: document.getElementById('report-month'),
            reportYearFilter: document.getElementById('report-year-filter'),
            reportYear: document.getElementById('report-year'),
            generateReportBtn: document.getElementById('generate-report-btn'),
            exportCurrentReportBtn: document.getElementById('export-current-report-btn'),
            reportResultsHeader: document.getElementById('report-results-header'),
            reportResultsTable: document.getElementById('report-results-table'),
            reportChart: document.getElementById('report-chart'),
            exportAllDataBtn: document.getElementById('export-all-data-btn'),
            importFile: document.getElementById('import-file'),
            importAllDataBtn: document.getElementById('import-all-data-btn'),
            bookModal: document.getElementById('book-modal'),
            bookModalTitle: document.getElementById('book-modal-title'),
            bookForm: document.getElementById('book-form'),
            bookId: document.getElementById('book-id'),
            bookName: document.getElementById('book-name'),
            productType: document.getElementById('product-type'),
            bookAuthorGroup: document.getElementById('book-author-group'),
            bookAuthor: document.getElementById('book-author'),
            bookCategory: document.getElementById('book-category'),
            bookDetailsGroup: document.getElementById('book-details-group'),
            bookIsbnGroup: document.getElementById('book-isbn-group'),
            bookIsbn: document.getElementById('book-isbn'),
            bookPublisherGroup: document.getElementById('book-publisher-group'),
            bookPublisher: document.getElementById('book-publisher'),
            bookYearGroup: document.getElementById('book-year-group'),
            bookYear: document.getElementById('book-year'),
            bookPrice: document.getElementById('book-price'),
            bookStock: document.getElementById('book-stock'),
            bookDescription: document.getElementById('book-description'),
            bookCoverImage: document.getElementById('book-cover-image'),
            bookCoverPreview: document.getElementById('book-cover-preview-img'),
            existingCoverImage: document.getElementById('existing-cover-image'),
            removeCoverImage: document.getElementById('remove-cover-image'),
            removeCoverLabel: document.getElementById('remove-cover-label'),
            restockModal: document.getElementById('restock-modal'),
            restockForm: document.getElementById('restock-form'),
            restockBookId: document.getElementById('restock-book-id'),
            restockBookName: document.getElementById('restock-book-name'),
            restockCurrentStock: document.getElementById('restock-current-stock'),
            restockQuantity: document.getElementById('restock-quantity'),
            customerModal: document.getElementById('customer-modal'),
            customerModalTitle: document.getElementById('customer-modal-title'),
            customerForm: document.getElementById('customer-form'),
            customerId: document.getElementById('customer-id'),
            customerName: document.getElementById('customer-name'),
            customerPhone: document.getElementById('customer-phone'),
            customerEmail: document.getElementById('customer-email'),
            customerAddress: document.getElementById('customer-address'),
            customerPasswordGroup: document.getElementById('customer-password-group'),
            customerPassword: document.getElementById('customer-password'),
            customerHistoryModal: document.getElementById('customer-history-modal'),
            customerHistoryTitle: document.getElementById('customer-history-title'),
            customerHistoryList: document.getElementById('customer-history-list'),
            supplierModal: document.getElementById('supplier-modal'),
            supplierModalTitle: document.getElementById('supplier-modal-title'),
            supplierForm: document.getElementById('supplier-form'),
            supplierId: document.getElementById('supplier-id'),
            supplierName: document.getElementById('supplier-name'),
            supplierContactPerson: document.getElementById('supplier-contact-person'),
            supplierPhone: document.getElementById('supplier-phone'),
            supplierEmail: document.getElementById('supplier-email'),
            supplierAddress: document.getElementById('supplier-address'),
            purchaseOrderModal: document.getElementById('purchase-order-modal'),
            poModalTitle: document.getElementById('po-modal-title'),
            poForm: document.getElementById('po-form'),
            poId: document.getElementById('po-id'),
            poSupplier: document.getElementById('po-supplier'),
            poOrderDate: document.getElementById('po-order-date'),
            poExpectedDate: document.getElementById('po-expected-date'),
            poStatus: document.getElementById('po-status'),
            poBookSelect: document.getElementById('po-book-select'),
            addSelectedBookBtn: document.getElementById('add-selected-book-to-po-btn'),
            poBookSearchResults: document.getElementById('po-book-search-results'),
            poItemsList: document.getElementById('po-items-list'),
            poGrandTotal: document.getElementById('po-grand-total'),
            receivePoBtn: document.getElementById('receive-po-btn'),
            poItemsInput: document.getElementById('po-items-input'),
            checkoutModal: document.getElementById('checkout-modal'),
            checkoutForm: document.getElementById('checkout-form'),
            checkoutCustomer: document.getElementById('checkout-customer'),
            checkoutPromotionCode: document.getElementById('checkout-promotion-code'),
            applyPromoBtn: document.getElementById('apply-promo-btn'),
            checkoutSubtotal: document.getElementById('checkout-subtotal'),
            checkoutDiscountDisplay: document.getElementById('checkout-discount-display'),
            checkoutDiscount: document.getElementById('checkout-discount'),
            checkoutTotal: document.getElementById('checkout-total'),
            checkoutCustomerIdInput: document.getElementById('checkout-customer-id-input'),
            checkoutPromotionCodeInput: document.getElementById('checkout-promotion-code-input'),
            checkoutCartItemsInput: document.getElementById('checkout-cart-items-input'),
            receiptModal: document.getElementById('receipt-modal'),
            receiptSaleId: document.getElementById('receipt-sale-id'),
            receiptDate: document.getElementById('receipt-date'),
            receiptCustomer: document.getElementById('receipt-customer'),
            receiptItemsList: document.getElementById('receipt-items-list'),
            receiptSubtotal: document.getElementById('receipt-subtotal'),
            receiptDiscountLine: document.getElementById('receipt-discount-line'),
            receiptDiscountValue: document.getElementById('receipt-discount-value'),
            receiptGrandTotal: document.getElementById('receipt-grand-total'),
            printReceiptBtn: document.getElementById('print-receipt-btn'),
            downloadReceiptBtn: document.getElementById('download-receipt-btn'),
            receiptContent: document.getElementById('receipt-content'),
            viewSaleModal: document.getElementById('view-sale-modal'),
            saleDetailsId: document.getElementById('sale-details-id'),
            saleDetailsDate: document.getElementById('sale-details-date'),
            saleDetailsCustomer: document.getElementById('sale-details-customer'),
            saleDetailsItems: document.getElementById('sale-details-items'),
            saleDetailsSubtotal: document.getElementById('sale-details-subtotal'),
            saleDetailsDiscountLine: document.getElementById('sale-details-discount-line'),
            saleDetailsDiscountValue: document.getElementById('sale-details-discount-value'),
            saleDetailsTotal: document.getElementById('sale-details-total'),
            reprintReceiptBtn: document.getElementById('reprint-receipt-btn'),
            promotionModal: document.getElementById('promotion-modal'),
            promotionModalTitle: document.getElementById('promotion-modal-title'),
            promotionForm: document.getElementById('promotion-form'),
            promotionId: document.getElementById('promotion-id'),
            promotionCode: document.getElementById('promotion-code'),
            promotionValue: document.getElementById('promotion-value'),
            promotionStartDate: document.getElementById('promotion-start-date'),
            promotionEndDate: document.getElementById('promotion-end-date'),
            expenseModal: document.getElementById('expense-modal'),
            expenseModalTitle: document.getElementById('expense-modal-title'),
            expenseForm: document.getElementById('expense-form'),
            expenseId: document.getElementById('expense-id'),
            expenseDate: document.getElementById('expense-date'),
            expenseCategory: document.getElementById('expense-category'),
            expenseDescription: document.getElementById('expense-description'),
            expenseAmount: document.getElementById('expense-amount'),
            modalCloseButtons: document.querySelectorAll('.modal-close'),
            publicBookSearch: document.getElementById('public-book-search'),
            publicProductTypeFilter: document.getElementById('public-product-type-filter'),
            publicBookCategoryFilter: document.getElementById('public-book-category-filter'),
            publicBookSort: document.getElementById('public-book-sort'),
            publicBooksList: document.getElementById('public-books-list'),
            publicBooksPagination: document.getElementById('public-books-pagination'),
            publicBooksPrevPage: document.getElementById('public-books-prev-page'),
            publicBooksNextPage: document.getElementById('public-books-next-page'),
            publicBooksPageInfo: document.getElementById('public-books-page-info'),
            globalSearchInput: document.getElementById('global-search-input'),
            globalSearchResults: document.getElementById('global-search-results'),
            onlineOrdersList: document.getElementById('online-orders-list'),
            onlineOrdersPagination: document.getElementById('online-orders-pagination'),
            onlineOrdersPrevPage: document.getElementById('online-orders-prev-page'),
            onlineOrdersNextPage: document.getElementById('online-orders-next-page'),
            onlineOrdersPageInfo: document.getElementById('online-orders-page-info'),
            onlineOrderSearch: document.getElementById('online-order-search'),
            onlineOrderStatusFilter: document.getElementById('online-order-status-filter'),
            viewOnlineOrderModal: document.getElementById('view-online-order-modal'),
            onlineOrderDetailsId: document.getElementById('online-order-details-id'),
            onlineOrderDetailsDate: document.getElementById('online-order-details-date'),
            onlineOrderDetailsCustomer: document.getElementById('online-order-details-customer'),
            onlineOrderDetailsEmail: document.getElementById('online-order-details-email'),
            onlineOrderDetailsPhone: document.getElementById('online-order-details-phone'),
            onlineOrderDetailsStatus: document.getElementById('online-order-details-status'),
            onlineOrderDetailsItems: document.getElementById('online-order-details-items'),
            onlineOrderDetailsSubtotal: document.getElementById('online-order-details-subtotal'),
            onlineOrderDetailsDiscountLine: document.getElementById('online-order-details-discount-line'),
            onlineOrderDetailsDiscountValue: document.getElementById('online-order-details-discount-value'),
            onlineOrderDetailsTotal: document.getElementById('online-order-details-total'),
            approveOrderBtn: document.getElementById('approve-order-btn'),
            rejectOrderBtn: document.getElementById('reject-order-btn'),
            approveOrderId: document.getElementById('approve-order-id'),
            rejectOrderId: document.getElementById('reject-order-id'),
            onlineCartTotalItems: document.getElementById('online-cart-total-items'),
            onlineCartItemsTable: document.getElementById('online-cart-items-table'),
            onlineCartGrandTotal: document.getElementById('online-cart-grand-total'),
            onlineClearCartBtn: document.getElementById('online-clear-cart-btn'),
            placeOnlineOrderBtn: document.getElementById('place-online-order-btn'),
            onlineCheckoutPromotionCode: document.getElementById('online-checkout-promotion-code'),
            onlineApplyPromoBtn: document.getElementById('online-apply-promo-btn'),
            onlinePromoMessage: document.getElementById('online-promo-message'),
            onlineOrderModal: document.getElementById('online-order-modal'),
            onlineOrderSubtotal: document.getElementById('online-order-subtotal'),
            onlineOrderDiscountDisplay: document.getElementById('online-order-discount-display'),
            onlineOrderDiscount: document.getElementById('online-order-discount'),
            onlineOrderTotal: document.getElementById('online-order-total'),
            onlineOrderPromotionCodeInput: document.getElementById('online-order-promotion-code-input'),
            onlineOrderCartItemsInput: document.getElementById('online-order-cart-items-input'),
            customerMyOrdersList: document.getElementById('customer-my-orders-list'),
        };
        const formatCurrency = (amount) => `${currentCurrencySymbol}${parseFloat(amount).toFixed(2)}`;
        const formatDate = (timestamp) => new Date(timestamp).toLocaleDateString('en-PK', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        const formatShortDate = (timestamp) => new Date(timestamp).toLocaleDateString('en-PK', {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric'
        });

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.classList.add('toast', type);
            const icon = type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-times-circle' :
                type === 'warning' ? 'fa-exclamation-triangle' :
                'fa-info-circle';
            toast.innerHTML = `<i class="fas ${icon}"></i> <span>${message}</span>`;
            elements.toastContainer.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 5000);
        }

        function showModal(modalElement) {
            modalElement.classList.add('active');
        }

        function hideModal(modalElement) {
            modalElement.classList.remove('active');
        }
        const pagination = {
            books: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            customers: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            suppliers: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            purchaseOrders: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            booksForCart: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            sales: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            expenses: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            publicBooks: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
            onlineOrders: {
                currentPage: 1,
                totalPages: 1,
                elements: {
                    prev: null,
                    next: null,
                    info: null,
                }
            },
        };

        function updatePaginationControls(paginationConfig, totalItems) {
            const totalPages = Math.ceil(totalItems / PAGE_SIZE);
            paginationConfig.totalPages = totalPages === 0 ? 1 : totalPages;
            paginationConfig.currentPage = Math.min(paginationConfig.currentPage, paginationConfig.totalPages);
            if (paginationConfig.currentPage < 1) paginationConfig.currentPage = 1;
            if (paginationConfig.elements.info) {
                paginationConfig.elements.info.textContent = `Page ${paginationConfig.currentPage} of ${paginationConfig.totalPages}`;
                paginationConfig.elements.prev.disabled = paginationConfig.currentPage === 1;
                paginationConfig.elements.next.disabled = paginationConfig.currentPage === paginationConfig.totalPages;
            }
        }
        async function fetchJSON(url, options = {}) {
            try {
                const response = await fetch(url, options);
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Server responded with an error.');
                }
                return data;
            } catch (error) {
                console.error('Fetch error:', error);
                showToast(`Data fetch failed: ${error.message}`, 'error');
                return {
                    success: false,
                    message: error.message
                };
            }
        }
        let dashWeeklyChartInstance = null;
        let dashTopChartInstance = null;
        let dashMonthlyChartInstance = null;
        let dashOrdersChartInstance = null;
        
        async function updateDashboard() {
            if (!document.getElementById('dash-today-rev')) return;
            const statsData = await fetchJSON('index.php?action=get_dashboard_stats_json');
            if (statsData.success) {
                document.getElementById('dash-today-rev').textContent = formatCurrency(statsData.today_rev);
                document.getElementById('dash-today-orders').textContent = statsData.today_cnt;
                document.getElementById('dash-month-rev').textContent = formatCurrency(statsData.month_rev);
                
                const pendingEl = document.getElementById('dash-pending-orders');
                pendingEl.textContent = statsData.pending_orders;
                if(statsData.pending_orders > 0) pendingEl.classList.add('danger'); else pendingEl.classList.remove('danger');

                document.getElementById('dash-total-products').textContent = statsData.total_products;
                document.getElementById('dash-total-customers').textContent = statsData.total_customers;
                document.getElementById('dash-total-suppliers').textContent = statsData.total_suppliers;
                
                const lowStockEl = document.getElementById('dash-low-stock');
                lowStockEl.textContent = statsData.low_stock_cnt;
                if(statsData.low_stock_cnt > 0) lowStockEl.classList.add('danger'); else lowStockEl.classList.remove('danger');
                
                document.getElementById('dash-stock-value').textContent = formatCurrency(statsData.stock_value);
                document.getElementById('dash-lifetime-rev').textContent = formatCurrency(statsData.lifetime_rev);
                document.getElementById('dash-total-expenses').textContent = formatCurrency(statsData.total_expenses);
                document.getElementById('dash-active-promos').textContent = statsData.active_promos;

                const ctxWeekly = document.getElementById('dash-weekly-chart').getContext('2d');
                if (dashWeeklyChartInstance) dashWeeklyChartInstance.destroy();
                dashWeeklyChartInstance = new Chart(ctxWeekly, {
                    type: 'line',
                    data: {
                        labels: statsData.chart_weekly.labels,
                        datasets: [{
                            label: 'Revenue',
                            data: statsData.chart_weekly.data,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });

                const ctxTop = document.getElementById('dash-top-chart').getContext('2d');
                if (dashTopChartInstance) dashTopChartInstance.destroy();
                dashTopChartInstance = new Chart(ctxTop, {
                    type: 'doughnut',
                    data: {
                        labels: statsData.chart_top.map(i => i.name),
                        datasets: [{
                            data: statsData.chart_top.map(i => i.qty),
                            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
                });
                
                const ctxMonthly = document.getElementById('dash-monthly-chart').getContext('2d');
                if (dashMonthlyChartInstance) dashMonthlyChartInstance.destroy();
                dashMonthlyChartInstance = new Chart(ctxMonthly, {
                    type: 'bar',
                    data: {
                        labels: statsData.chart_monthly.labels,
                        datasets: [
                            {
                                label: 'Sales',
                                data: statsData.chart_monthly.sales,
                                backgroundColor: '#10b981'
                            },
                            {
                                label: 'Expenses',
                                data: statsData.chart_monthly.expenses,
                                backgroundColor: '#ef4444'
                            }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
                
                const ctxOrders = document.getElementById('dash-orders-chart').getContext('2d');
                if (dashOrdersChartInstance) dashOrdersChartInstance.destroy();
                
                const orderLabels = statsData.chart_orders.map(o => o.status.charAt(0).toUpperCase() + o.status.slice(1));
                const orderData = statsData.chart_orders.map(o => o.cnt);
                const orderColors = statsData.chart_orders.map(o => {
                    if(o.status === 'pending') return '#f59e0b';
                    if(o.status === 'approved') return '#10b981';
                    if(o.status === 'rejected') return '#ef4444';
                    return '#6b7280';
                });
                
                dashOrdersChartInstance = new Chart(ctxOrders, {
                    type: 'pie',
                    data: {
                        labels: orderLabels,
                        datasets: [{
                            data: orderData,
                            backgroundColor: orderColors
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
                });
            }

            const recentSalesData = await fetchJSON('index.php?action=get_sales_json&page_num=1&limit=5');
            if (recentSalesData.success && elements.dashboardRecentSales) {
                elements.dashboardRecentSales.innerHTML = recentSalesData.sales.length > 0 ? recentSalesData.sales.map(sale => `
                    <tr>
                        <td>${formatShortDate(html(sale.sale_date))}</td>
                        <td>${html(sale.customer_name || 'Guest')}</td>
                        <td>${formatCurrency(html(sale.total))}</td>
                    </tr>
                `).join('') : `<tr><td colspan="3">No recent sales.</td></tr>`;
            }

            const lowStockData = await fetchJSON('index.php?action=get_books_json&search=&sort=stock-asc&limit=10');
            if (lowStockData.success && elements.dashboardLowStockBooks) {
                const lowStockBooks = lowStockData.books.filter(book => book.stock < 5);
                elements.dashboardLowStockBooks.innerHTML = lowStockBooks.length > 0 ? lowStockBooks.map(book => `
                    <tr class="${book.stock < 5 ? 'low-stock' : ''}">
                        <td>${html(book.name)}</td>
                        <td>${html(book.stock)}</td>
                        <td>
                            <button class="btn btn-info btn-sm" onclick="openRestockModal(${html(book.id)}, '${html(book.name.replace(/'/g, "\\'"))}', ${html(book.stock)})"><i class="fas fa-box"></i></button>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="3">All stocks healthy.</td></tr>`;
            }
        }
        let allRolesData = [];
        const APP_PAGES = ['dashboard', 'books', 'users', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'online-orders', 'promotions', 'expenses', 'reports', 'live-sales', 'settings', 'public-sale-links', 'print-barcodes', 'backup-restore'];
        async function fetchUsersAndRoles() {
            if (!document.getElementById('users-list')) return;
            const roleData = await fetchJSON('index.php?action=get_roles_json');
            if (roleData.success) {
                allRolesData = roleData.roles;
                document.getElementById('roles-list').innerHTML = roleData.roles.map(r => `
                    <tr>
                        <td>${html(r.name)}</td>
                        <td class="actions">
                            <button class="btn btn-primary btn-sm" onclick="openRoleModal(${r.id})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=users" method="POST" style="display:inline;" onsubmit="return confirm('Delete this role?');">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_id" value="${r.id}">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                `).join('');
                
                const roleSelect = document.getElementById('sys-role-id');
                if (roleSelect) {
                    roleSelect.innerHTML = roleData.roles.map(r => `<option value="${r.id}">${html(r.name)}</option>`).join('');
                }
            }
            const userData = await fetchJSON('index.php?action=get_users_json');
            if (userData.success) {
                document.getElementById('users-list').innerHTML = userData.users.map(u => `
                    <tr>
                        <td>${html(u.username)}</td>
                        <td>${html(u.role_name || 'N/A')}</td>
                        <td class="actions">
                            <button class="btn btn-primary btn-sm" onclick="openUserModal(${u.id}, '${html(u.username)}', ${u.role_id})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=users" method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="${u.id}">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                `).join('');
            }
        }
        
        function openUserModal(id = '', username = '', roleId = '') {
            document.getElementById('sys-user-id').value = id;
            document.getElementById('sys-username').value = username;
            document.getElementById('sys-role-id').value = roleId;
            document.getElementById('sys-password').value = '';
            document.getElementById('sys-password').required = id === '';
            document.getElementById('user-modal-title').textContent = id ? 'Edit User' : 'Add New User';
            document.getElementById('user-modal').classList.add('active');
        }
        
        function openRoleModal(id = null) {
            document.getElementById('sys-role-id-form').value = id || '';
            let role = id ? allRolesData.find(r => r.id == id) : null;
            document.getElementById('sys-role-name').value = role ? role.name : '';
            document.getElementById('role-modal-title').textContent = role ? 'Edit Role' : 'Add New Role';
            
            const perms = role ? role.permissions : [];
            document.getElementById('sys-role-permissions').innerHTML = APP_PAGES.map(p => `
                <label style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="pages[]" value="${p}" ${perms.includes(p) ? 'checked' : ''}>
                    ${p.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')}
                </label>
            `).join('');
            
            document.getElementById('role-modal').classList.add('active');
        }
        let liveSalesInterval = null;
        async function fetchLiveSales() {
            if (!document.getElementById('live-sales-list')) return;
            const data = await fetchJSON('index.php?action=get_live_sales_json');
            if (data.success) {
                document.getElementById('live-today-rev').textContent = formatCurrency(data.summary.revenue);
                document.getElementById('live-today-orders').textContent = data.summary.orders;
                document.getElementById('live-today-disc').textContent = formatCurrency(data.summary.discount);

                const list = document.getElementById('live-sales-list');
                list.innerHTML = data.recent_sales.length > 0 ? data.recent_sales.map(sale => {
                    const timeOnly = new Date(sale.sale_date).toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit', second:'2-digit'});
                    let soldByText = html(sale.sold_by_user || 'System');
                    if (sale.promotion_code && sale.promotion_code.startsWith('PUBLIC-LINK-')) {
                        soldByText = `<span style="color:var(--info-color);" title="Public Scanner Sale"><i class="fas fa-satellite-dish"></i> ${html(sale.public_link_name || 'Secure Link')}</span>`;
                    }
                    return `
                    <tr>
                        <td style="font-weight: bold; color: var(--primary-color);">${timeOnly}</td>
                        <td>${html(sale.id)}</td>
                        <td>${html(sale.customer_name || 'Guest')}</td>
                        <td>${soldByText}</td>
                        <td>${html(sale.item_names)}</td>
                        <td>${formatCurrency(sale.discount)}</td>
                        <td><strong style="color: var(--success-color);">${formatCurrency(sale.total)}</strong></td>
                    </tr>
                `}).join('') : `<tr><td colspan="7">No transactions today yet.</td></tr>`;
            }
        }
        async function renderBooks() {
            if (!elements.booksList) return;
            const search = elements.bookSearch.value;
            const sort = elements.bookSort.value;
            const page = pagination.books.currentPage;
            const data = await fetchJSON(`index.php?action=get_books_json&search=${encodeURIComponent(search)}&sort=${sort}&page_num=${page}`);
            if (data.success) {
                const books = data.books;
                updatePaginationControls(pagination.books, data.total_items);
                elements.booksList.innerHTML = books.length > 0 ? books.map(book => `
                    <tr class="${book.stock < 5 ? 'low-stock' : ''}">
                        <td>${book.cover_image ? `<img src="${html(book.cover_image)}" alt="Cover" width="50" height="70" style="object-fit: cover; border-radius: 3px;">` : '<i class="fas fa-box-open fa-2x" style="color: var(--light-text-color);"></i>'}</td>
                        <td>${html(book.name)}</td>
                        <td>${html(ucfirst(book.product_type))}</td>
                        <td>${html(book.author || 'N/A')}</td>
                        <td>${html(book.category)}</td>
                        <td>${formatCurrency(book.price)}</td>
                        <td>${html(book.stock)}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="openRestockModal(${html(book.id)}, '${html(book.name)}', ${html(book.stock)})"><i class="fas fa-box"></i> Restock</button>
                            <?php if (isAdmin() || isStaff()): ?>
                                <div style="display: inline-flex; align-items: center; gap: 4px; margin-bottom: 4px;">
                                    <input type="number" id="qs-qty-${html(book.id)}" value="1" min="1" max="${html(book.stock)}" style="width: 50px; padding: 4px; height: 32px; border: 1px solid var(--border-color); border-radius: 6px;">
                                    <button class="btn btn-sm btn-success" onclick="quickSell(${html(book.id)}, 'qs-qty-${html(book.id)}')"><i class="fas fa-bolt"></i> Quick Sell</button>
                                </div>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                                <button class="btn btn-primary btn-sm" onclick="openBookModal(${html(book.id)})"><i class="fas fa-edit"></i> Edit</button>
                                <form action="index.php?page=books" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                    <input type="hidden" name="action" value="delete_book">
                                    <input type="hidden" name="book_id" value="${html(book.id)}">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="8">No products found.</td></tr>`;
            }
        }
        async function openBookModal(bookId = null) {
            if (!<?php echo isAdmin() || isStaff() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage products.', 'error');
                return;
            }
            elements.bookForm.reset();
            elements.bookId.value = '';
            elements.bookModalTitle.textContent = 'Add New Product';
            elements.bookCoverPreview.style.display = 'none';
            elements.bookCoverPreview.src = '';
            elements.bookCoverImage.value = '';
            elements.existingCoverImage.value = '';
            elements.removeCoverLabel.style.display = 'none';
            elements.removeCoverImage.checked = false;
            elements.bookAuthorGroup.style.display = 'block';
            elements.bookDetailsGroup.style.display = 'flex';
            if (bookId) {
                const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
                if (data.success && data.books.length > 0) {
                    const book = data.books[0];
                    elements.bookModalTitle.textContent = 'Edit Product';
                    elements.bookId.value = book.id;
                    elements.bookName.value = book.name;
                    elements.productType.value = book.product_type;
                    elements.bookAuthor.value = book.author;
                    elements.bookCategory.value = book.category;
                    elements.bookIsbn.value = book.isbn;
                    elements.bookPublisher.value = book.publisher;
                    elements.bookYear.value = book.year;
                    elements.bookPrice.value = book.price;
                    elements.bookStock.value = book.stock;
                    document.getElementById('book-purchase-price').value = book.purchase_price || '';
                    elements.bookDescription.value = book.description;
                    if (book.cover_image) {
                        elements.bookCoverPreview.src = book.cover_image;
                        elements.bookCoverPreview.style.display = 'block';
                        elements.existingCoverImage.value = book.cover_image;
                        elements.removeCoverLabel.style.display = 'block';
                    }
                    toggleBookFields(book.product_type);
                } else {
                    showToast('Product not found.', 'error');
                    return;
                }
            } else {
                toggleBookFields('book');
            }
            showModal(elements.bookModal);
        }

        function toggleBookFields(productType) {
            if (productType === 'book') {
                elements.bookAuthorGroup.style.display = 'block';
                elements.bookDetailsGroup.style.display = 'flex';
                elements.bookIsbnGroup.style.display = 'block';
                elements.bookPublisherGroup.style.display = 'block';
                elements.bookYearGroup.style.display = 'block';
                elements.bookAuthor.required = true;
                elements.bookIsbn.required = true;
            } else {
                elements.bookAuthorGroup.style.display = 'none';
                elements.bookDetailsGroup.style.display = 'none';
                elements.bookIsbnGroup.style.display = 'none';
                elements.bookPublisherGroup.style.display = 'none';
                elements.bookYearGroup.style.display = 'none';
                elements.bookAuthor.required = false;
                elements.bookIsbn.required = false;
            }
        }
        async function quickSell(bookId, qtyInputId = null) {
            let qty = 1;
            if (qtyInputId) {
                const qtyInput = document.getElementById(qtyInputId);
                if (qtyInput) qty = parseInt(qtyInput.value) || 1;
            }
            if (!confirm(`Quick Sell: Are you sure you want to sell ${qty} unit(s) of this product now?`)) {
                return;
            }
            const data = await fetchJSON(`index.php?action=ajax_quick_sell`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ book_id: bookId, quantity: qty })
            });
            if (data.success) {
                showToast(data.message, 'success');
                if (typeof renderBooks === 'function') renderBooks();
                if (typeof updateDashboard === 'function') updateDashboard();
                if (data.sale_id) {
                    const saleData = await fetchJSON(`index.php?action=get_sale_details_json&sale_id=${data.sale_id}`);
                    if (saleData.success && saleData.sale) {
                        openReceiptModal(saleData.sale);
                    }
                }
            } else {
                showToast(data.message || 'Quick sale failed.', 'error');
            }
        }
        async function openRestockModal(bookId, name, stock) {
            elements.restockBookId.value = bookId;
            elements.restockBookName.value = name;
            elements.restockCurrentStock.value = stock;
            elements.restockQuantity.value = 1;
            showModal(elements.restockModal);
        }
        async function renderCustomers() {
            if (!elements.customersList) return;
            const search = elements.customerSearch.value;
            const status = elements.customerFilterStatus.value;
            const page = pagination.customers.currentPage;
            const data = await fetchJSON(`index.php?action=get_customers_json&search=${encodeURIComponent(search)}&status=${status}&page_num=${page}`);
            if (data.success) {
                const customers = data.customers;
                updatePaginationControls(pagination.customers, data.total_items);
                elements.customersList.innerHTML = customers.length > 0 ? customers.map(customer => `
                    <tr class="${!customer.is_active ? 'inactive-customer' : ''}">
                        <td>${html(customer.name)}</td>
                        <td>${html(customer.phone || 'N/A')}</td>
                        <td>${html(customer.email || 'N/A')}</td>
                        <td>${html(customer.address || 'N/A')}</td>
                        <td>${customer.is_active ? 'Active' : 'Inactive'}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="viewCustomerHistory(${html(customer.id)}, '${html(customer.name)}')"><i class="fas fa-history"></i> History</button>
                            <?php if (isAdmin()): ?>
                                <button class="btn btn-primary btn-sm" onclick="openCustomerModal(${html(customer.id)})"><i class="fas fa-edit"></i> Edit</button>
                                <form action="index.php?page=customers" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_customer_status">
                                    <input type="hidden" name="customer_id" value="${html(customer.id)}">
                                    <input type="hidden" name="current_status" value="${customer.is_active ? 'true' : 'false'}">
                                    <button type="submit" class="btn ${customer.is_active ? 'btn-danger' : 'btn-success'} btn-sm"><i class="fas ${customer.is_active ? 'fa-user-slash' : 'fa-user-check'}"></i> ${customer.is_active ? 'Deactivate' : 'Activate'}</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="6">No customers found.</td></tr>`;
            }
        }
        async function openCustomerModal(customerId = null) {
            if (!elements.customerForm) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage customers.', 'error');
                return;
            }
            elements.customerForm.reset();
            elements.customerId.value = '';
            elements.customerModalTitle.textContent = 'Add New Customer';
            elements.customerPasswordGroup.style.display = 'block';
            if (customerId) {
                const data = await fetchJSON(`index.php?action=get_customers_json&customer_id=${customerId}`);
                if (data.success && data.customers.length > 0) {
                    const customer = data.customers[0];
                    elements.customerModalTitle.textContent = 'Edit Customer';
                    elements.customerId.value = customer.id;
                    elements.customerName.value = customer.name;
                    elements.customerPhone.value = customer.phone;
                    elements.customerEmail.value = customer.email;
                    elements.customerAddress.value = customer.address;
                    elements.customerPasswordGroup.style.display = 'block';
                    elements.customerPassword.placeholder = 'Leave empty to keep current password';
                } else {
                    showToast('Customer not found.', 'error');
                    return;
                }
            } else {
                elements.customerPassword.placeholder = '';
            }
            showModal(elements.customerModal);
        }
        async function viewCustomerHistory(customerId, customerName) {
            if (!elements.customerHistoryTitle) return;
            elements.customerHistoryTitle.textContent = `Purchase History for ${html(customerName)}`;
            const data = await fetchJSON(`index.php?action=get_customer_history_json&customer_id=${customerId}`);
            if (data.success) {
                const sales = data.sales;
                elements.customerHistoryList.innerHTML = sales.length > 0 ? sales.map(sale => `
                    <tr>
                        <td>${html(sale.id)}</td>
                        <td>${formatDate(html(sale.sale_date))}</td>
                        <td>${html(sale.item_names)}</td>
                        <td>${formatCurrency(html(sale.total))}</td>
                    </tr>
                `).join('') : `<tr><td colspan="4">No purchases found for this customer.</td></tr>`;
            }
            showModal(elements.customerHistoryModal);
        }
        async function renderSuppliers() {
            if (!elements.suppliersList) return;
            const search = elements.supplierSearch.value;
            const page = pagination.suppliers.currentPage;
            const data = await fetchJSON(`index.php?action=get_suppliers_json&search=${encodeURIComponent(search)}&page_num=${page}`);
            if (data.success) {
                const suppliers = data.suppliers;
                updatePaginationControls(pagination.suppliers, data.total_items);
                elements.suppliersList.innerHTML = suppliers.length > 0 ? suppliers.map(supplier => `
                    <tr>
                        <td>${html(supplier.name)}</td>
                        <td>${html(supplier.contact_person || 'N/A')}</td>
                        <td>${html(supplier.phone || 'N/A')}</td>
                        <td>${html(supplier.email || 'N/A')}</td>
                        <td class="actions">
                            <button class="btn btn-primary btn-sm" onclick="openSupplierModal(${html(supplier.id)})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=suppliers" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this supplier? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_supplier">
                                <input type="hidden" name="supplier_id" value="${html(supplier.id)}">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="5">No suppliers found.</td></tr>`;
            }
        }
        async function openSupplierModal(supplierId = null) {
            if (!elements.supplierForm) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage suppliers.', 'error');
                return;
            }
            elements.supplierForm.reset();
            elements.supplierId.value = '';
            elements.supplierModalTitle.textContent = 'Add New Supplier';
            if (supplierId) {
                const data = await fetchJSON(`index.php?action=get_suppliers_json&supplier_id=${supplierId}`);
                if (data.success && data.suppliers.length > 0) {
                    const supplier = data.suppliers[0];
                    elements.supplierModalTitle.textContent = 'Edit Supplier';
                    elements.supplierId.value = supplier.id;
                    elements.supplierName.value = supplier.name;
                    elements.supplierContactPerson.value = supplier.contact_person;
                    elements.supplierPhone.value = supplier.phone;
                    elements.supplierEmail.value = supplier.email;
                    elements.supplierAddress.value = supplier.address;
                } else {
                    showToast('Supplier not found.', 'error');
                    return;
                }
            }
            showModal(elements.supplierModal);
        }
        let currentPoItems = [];
        async function renderPurchaseOrders() {
            if (!elements.purchaseOrdersList) return;
            const search = elements.poSearch.value;
            const status = elements.poStatusFilter.value;
            const page = pagination.purchaseOrders.currentPage;
            const data = await fetchJSON(`index.php?action=get_pos_json&search=${encodeURIComponent(search)}&status=${status}&page_num=${page}`);
            if (data.success) {
                const purchaseOrders = data.purchase_orders;
                updatePaginationControls(pagination.purchaseOrders, data.total_items);
                elements.purchaseOrdersList.innerHTML = purchaseOrders.length > 0 ? purchaseOrders.map(po => `
                    <tr>
                        <td>${html(po.id)}</td>
                        <td>${html(po.supplier_name)}</td>
                        <td>${formatShortDate(html(po.order_date))}</td>
                        <td>${po.expected_date ? formatShortDate(html(po.expected_date)) : 'N/A'}</td>
                        <td>${html(po.status.charAt(0).toUpperCase() + po.status.slice(1))}</td>
                        <td>${po.items.reduce((sum, item) => sum + item.quantity, 0)}</td>
                        <td>${formatCurrency(po.total_cost)}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="openPurchaseOrderModal(${html(po.id)})"><i class="fas fa-eye"></i> View/Edit</button>
                            ${(po.status !== 'received' && po.status !== 'cancelled' && <?php echo isAdmin() ? 'true' : 'false'; ?>) ? `
                                <form action="index.php?page=purchase-orders" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to mark this Purchase Order as Received and update product stock?');">
                                    <input type="hidden" name="action" value="receive_po">
                                    <input type="hidden" name="po_id" value="${html(po.id)}">
                                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-truck-loading"></i> Receive</button>
                                </form>
                            ` : ''}
                            ${<?php echo isAdmin() ? 'true' : 'false'; ?> ? `
                                <form action="index.php?page=purchase-orders" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this purchase order? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_po">
                                    <input type="hidden" name="po_id" value="${html(po.id)}">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            ` : ''}
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="8">No purchase orders found.</td></tr>`;
            }
        }
        async function openPurchaseOrderModal(poId = null) {
            if (!elements.poForm) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage purchase orders.', 'error');
                return;
            }
            elements.poForm.reset();
            elements.poId.value = '';
            elements.poModalTitle.textContent = 'Create New Purchase Order';
            elements.poOrderDate.value = new Date().toISOString().split('T')[0];
            elements.poExpectedDate.value = '';
            elements.poStatus.value = 'pending';
            if (elements.poBookSelect) {
                elements.poBookSelect.selectedIndex = 0;
            }
            currentPoItems = [];
            renderPoItems();
            elements.receivePoBtn.style.display = 'none';
            if (poId) {
                const data = await fetchJSON(`index.php?action=get_pos_json&po_id=${poId}`);
                if (data.success && data.purchase_orders.length > 0) {
                    const po = data.purchase_orders[0];
                    elements.poModalTitle.textContent = 'Edit Purchase Order';
                    elements.poId.value = po.id;
                    elements.poSupplier.value = po.supplier_id;
                    elements.poOrderDate.value = po.order_date;
                    elements.poExpectedDate.value = po.expected_date || '';
                    elements.poStatus.value = po.status;
                    currentPoItems = JSON.parse(JSON.stringify(po.items));
                    renderPoItems();
                    if (po.status === 'ordered') {
                        elements.receivePoBtn.style.display = 'inline-flex';
                        elements.receivePoBtn.onclick = () => {
                            if (confirm('Are you sure you want to mark this Purchase Order as Received and update product stock?')) {
                                const form = document.createElement('form');
                                form.action = `index.php?page=purchase-orders`;
                                form.method = 'POST';
                                const actionInput = document.createElement('input');
                                actionInput.type = 'hidden';
                                actionInput.name = 'action';
                                actionInput.value = 'receive_po';
                                form.appendChild(actionInput);
                                const poIdInput = document.createElement('input');
                                poIdInput.type = 'hidden';
                                poIdInput.name = 'po_id';
                                poIdInput.value = po.id;
                                form.appendChild(poIdInput);
                                document.body.appendChild(form);
                                form.submit();
                            }
                        };
                    } else {
                        elements.receivePoBtn.style.display = 'none';
                    }
                } else {
                    showToast('Purchase Order not found.', 'error');
                    return;
                }
            }
            showModal(elements.purchaseOrderModal);
        }

        function addPoItem(product) {
            const existingItem = currentPoItems.find(item => item.bookId === product.id);
            if (existingItem) {
                existingItem.quantity++;
            } else {
                currentPoItems.push({
                    bookId: product.id,
                    name: product.name,
                    quantity: 1,
                    cost_per_unit: parseFloat((product.price * 0.7).toFixed(2)),
                });
            }
            renderPoItems();
        }

        function updatePoItemQuantity(bookId, quantity) {
            const itemIndex = currentPoItems.findIndex(item => item.bookId === bookId);
            if (itemIndex > -1) {
                currentPoItems[itemIndex].quantity = Math.max(1, quantity);
                renderPoItems();
            }
        }

        function updatePoItemCost(bookId, cost) {
            const itemIndex = currentPoItems.findIndex(item => item.bookId === bookId);
            if (itemIndex > -1) {
                currentPoItems[itemIndex].cost_per_unit = Math.max(0, cost);
                renderPoItems();
            }
        }

        function removePoItem(bookId) {
            currentPoItems = currentPoItems.filter(item => item.bookId !== bookId);
            renderPoItems();
        }

        function renderPoItems() {
            let totalCost = 0;
            if (currentPoItems.length === 0) {
                elements.poItemsList.innerHTML = `<tr><td colspan="5">No items added to this purchase order.</td></tr>`;
            } else {
                elements.poItemsList.innerHTML = currentPoItems.map(item => {
                    const costPerUnitNumber = parseFloat(item.cost_per_unit);
                    const subtotal = item.quantity * costPerUnitNumber;
                    totalCost += subtotal;
                    return `
                        <tr>
                            <td>${html(item.name)}</td>
                            <td><input type="number" min="1" value="${html(item.quantity)}" onchange="updatePoItemQuantity(${html(item.bookId)}, parseInt(this.value))" style="width: 70px;"></td>
                            <td><input type="number" min="0" step="0.01" value="${html(costPerUnitNumber.toFixed(2))}" onchange="updatePoItemCost(${html(item.bookId)}, parseFloat(this.value))" style="width: 100px;"></td>
                            <td>${formatCurrency(subtotal)}</td>
                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removePoItem(${html(item.bookId)})"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    `;
                }).join('');
            }
            elements.poGrandTotal.textContent = formatCurrency(totalCost);
            elements.poItemsInput.value = JSON.stringify(currentPoItems);
        }
        async function renderBooksForCart(isOnlineCart = false) {
            if (isOnlineCart) {
                const listElement = elements.publicBooksList;
                const paginationConfig = pagination.publicBooks;
                if (!listElement) return;
                const search = elements.publicBookSearch ? elements.publicBookSearch.value : '';
                const category = elements.publicBookCategoryFilter ? elements.publicBookCategoryFilter.value : 'all';
                const product_type = elements.publicProductTypeFilter ? elements.publicProductTypeFilter.value : 'all';
                const sort = elements.publicBookSort ? elements.publicBookSort.value : 'name-asc';
                const page = paginationConfig.currentPage;
                const data = await fetchJSON(`index.php?action=get_public_books_json&search=${encodeURIComponent(search)}&category=${encodeURIComponent(category)}&product_type=${encodeURIComponent(product_type)}&sort=${sort}&page_num=${page}`);
                if (data.success) {
                    updatePaginationControls(paginationConfig, data.total_items);
                    listElement.innerHTML = data.books.length > 0 ? data.books.map(product => `
                        <div class="book-card">
                            <img src="${product.cover_image ? html(product.cover_image) : 'https://via.placeholder.com/150x200?text=No+Cover'}" alt="${html(product.name)}">
                            <h3>${html(product.name)}</h3>
                            <p>${product.author ? 'by ' + html(product.author) : html(ucfirst(product.product_type))}</p>
                            <div class="price">${formatCurrency(product.price)}</div>
                            <div class="stock-info ${product.stock <= 5 && product.stock > 0 ? 'low' : ''} ${product.stock === 0 ? 'out' : ''}">
                                ${product.stock > 0 ? html(product.stock) + ' In Stock' : 'Out of Stock'}
                            </div>
                            <div class="public-product-actions">
                                <a href="https://wa.me/<?php echo html($public_settings['whatsapp_number'] ?? ''); ?>?text=Hello,%20I%20would%20like%20to%20order%20${encodeURIComponent(html(product.name))}%20-%20Price:%20${encodeURIComponent(formatCurrency(html(product.price)))}." target="_blank" class="whatsapp-btn"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                                ${<?php echo isCustomer() ? 'true' : 'false'; ?> ? `
                                    <button class="btn btn-primary" onclick="addToCart(${html(product.id)}, '${html(product.name.replace(/'/g, "\\'"))}', ${html(product.price)}, true)" ${product.stock <= 0 ? 'disabled' : ''}><i class="fas fa-cart-plus"></i> Add to Cart</button>
                                ` : `
                                    <a href="index.php?page=customer-login" class="btn btn-primary"><i class="fas fa-user-circle"></i> Login to Order</a>
                                `}
                            </div>
                        </div>
                    `).join('') : `<p>No products found matching your criteria.</p>`;
                }
            } else {
                // Admin/Staff POS layout
                if (!elements.booksForCartList) return;
                const search = elements.bookToCartSearch ? elements.bookToCartSearch.value : '';
                const category = elements.posCategoryFilter ? elements.posCategoryFilter.value : 'all';
                const data = await fetchJSON(`index.php?action=get_sidebar_products_json&search=${encodeURIComponent(search)}&category=${encodeURIComponent(category)}`);
                if (data.success) {
                    if (elements.posCategoryFilter && elements.posCategoryFilter.options.length <= 1) {
                        data.categories.forEach(cat => {
                            const opt = document.createElement('option');
                            opt.value = cat;
                            opt.textContent = cat;
                            elements.posCategoryFilter.appendChild(opt);
                        });
                    }
                    elements.booksForCartList.innerHTML = data.books.length > 0 ? data.books.map(product => {
                        const inCartQuantity = currentCart.find(item => item.bookId === product.id)?.quantity || 0;
                        const availableStock = product.stock - inCartQuantity;
                        const outOfStock = availableStock <= 0;
                        return `
                            <div class="pos-card ${outOfStock ? 'disabled' : ''}" onclick="if(!${outOfStock}) addToCart(${html(product.id)}, '${html(product.name.replace(/'/g, "\\'"))}', ${html(product.price)}, false)" style="${outOfStock ? 'opacity:0.5; cursor:not-allowed;' : ''}">
                                <div class="stock-badge" style="background:${outOfStock ? 'var(--danger-color)' : 'var(--primary-color)'}">${outOfStock ? 'Out' : availableStock}</div>
                                <div class="title" title="${html(product.name)}">${html(product.name)}</div>
                                <div class="price">${formatCurrency(product.price)}</div>
                            </div>
                        `;
                    }).join('') : `<div style="grid-column: 1/-1; text-align:center; padding: 20px; color: var(--light-text-color);">No products found.</div>`;
                }
            }
        }
        async function addToCart(bookId, name, price, isOnlineCart = false) {
            const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
            if (!data.success || data.books.length === 0) {
                showToast('Product not found in inventory.', 'error');
                return;
            }
            const product = data.books[0];
            const existingItemIndex = currentCart.findIndex(item => item.bookId === bookId);
            const currentQuantityInCart = existingItemIndex > -1 ? currentCart[existingItemIndex].quantity : 0;
            if (currentQuantityInCart >= product.stock) {
                showToast(`Cannot add more "${html(name)}". Only ${html(product.stock)} available.`, 'warning');
                return;
            }
            if (existingItemIndex > -1) {
                currentCart[existingItemIndex].quantity++;
            } else {
                currentCart.push({
                    bookId: bookId,
                    name: name,
                    price: price,
                    quantity: 1,
                    discount_per_unit: 0,
                    category: product.category,
                    product_type: product.product_type
                });
            }
            showToast(`"${html(name)}" added to cart.`, 'info');
            renderCart(isOnlineCart);
            if (!isOnlineCart) {
                renderBooksForCart(false);
            } else {
                renderBooksForCart(true);
            }
        }
        async function updateCartItemQuantity(bookId, newQuantity, isOnlineCart = false) {
            const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
            if (!data.success || data.books.length === 0) {
                showToast('Product not found in inventory.', 'error');
                return;
            }
            const product = data.books[0];
            const itemIndex = currentCart.findIndex(item => item.bookId === bookId);
            if (itemIndex > -1) {
                if (newQuantity <= 0) {
                    removeCartItem(bookId, isOnlineCart);
                    return;
                }
                if (newQuantity > product.stock) {
                    showToast(`Cannot add more than available stock (${html(product.stock)}) for "${html(product.name)}".`, 'warning');
                    currentCart[itemIndex].quantity = product.stock;
                } else {
                    currentCart[itemIndex].quantity = newQuantity;
                }
                renderCart(isOnlineCart);
                if (!isOnlineCart) {
                    renderBooksForCart(false);
                } else {
                    renderBooksForCart(true);
                }
            }
        }

        function removeCartItem(bookId, isOnlineCart = false) {
            currentCart = currentCart.filter(item => item.bookId !== bookId);
            showToast('Item removed from cart.', 'info');
            renderCart(isOnlineCart);
            if (!isOnlineCart) {
                renderBooksForCart(false);
            } else {
                renderBooksForCart(true);
            }
        }
        async function calculateCartTotals(forOnlineCart = false) {
            let subtotal = 0;
            let totalDiscount = 0;
            for (const item of currentCart) {
                const data = await fetchJSON(`index.php?action=get_books_json&book_id=${item.bookId}`);
                if (data.success && data.books.length > 0) {
                    const product = data.books[0];
                    item.price = product.price;
                    item.category = product.category;
                    subtotal += item.price * item.quantity;
                    item.discount_per_unit = 0;
                } else {
                    console.error(`Product ID ${item.bookId} not found for cart calculation.`);
                }
            }
            // First apply manual discounts per item
            for (const item of currentCart) {
                if (item.custom_discount && item.custom_discount > 0) {
                    const manualDisc = Math.min(item.custom_discount, item.price);
                    item.discount_per_unit = manualDisc;
                    totalDiscount += (manualDisc * item.quantity);
                }
            }

            if (appliedPromotion) {
                if (appliedPromotion.applies_to === 'all') {
                    const discountAmount = appliedPromotion.type === 'percentage' ?
                        subtotal * (appliedPromotion.value / 100) :
                        appliedPromotion.value;
                    const promoDiscount = Math.min(discountAmount, subtotal - totalDiscount);
                    totalDiscount += promoDiscount;
                    if (subtotal > 0) {
                        for (const item of currentCart) {
                            const itemValue = item.price * item.quantity;
                            item.discount_per_unit += (promoDiscount * (itemValue / subtotal)) / item.quantity;
                        }
                    }
                } else {
                    for (const item of currentCart) {
                        if ((appliedPromotion.applies_to === 'specific-book' && item.bookId == appliedPromotion.applies_to_value) ||
                            (appliedPromotion.applies_to === 'specific-category' && item.category === appliedPromotion.applies_to_value)) {
                            const itemTotalPrice = item.price * item.quantity;
                            const discountAmountForItem = appliedPromotion.type === 'percentage' ?
                                itemTotalPrice * (appliedPromotion.value / 100) :
                                appliedPromotion.value;
                            const added_discount = Math.min(discountAmountForItem / item.quantity, item.price - item.discount_per_unit);
                            item.discount_per_unit += added_discount;
                            totalDiscount += (added_discount * item.quantity);
                        }
                    }
                }
            }
            let finalTotal = subtotal - totalDiscount;
            finalTotal = Math.max(0, finalTotal);
            return {
                subtotal: subtotal,
                discount: totalDiscount,
                total: finalTotal
            };
        }
        async function updateCartItemDiscount(bookId, val) {
            const item = currentCart.find(i => i.bookId === bookId);
            if (item) {
                item.custom_discount = Math.max(0, parseFloat(val) || 0);
                if (item.custom_discount > item.price) item.custom_discount = item.price;
                renderCart();
            }
        }

        async function renderCart(isOnlineCart = false) {
            fetch('index.php?action=update_session_cart', {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cart: currentCart, promotion: appliedPromotion })
            }).catch(console.error);
            
            const containerElement = isOnlineCart ? elements.onlineCartItemsTable : elements.cartItemsTable;
            const totalItemsSpan = isOnlineCart ? elements.onlineCartTotalItems : elements.cartTotalItems;
            const grandTotalSpan = isOnlineCart ? elements.onlineCartGrandTotal : elements.cartGrandTotal;
            const clearBtn = isOnlineCart ? elements.onlineClearCartBtn : elements.clearCartBtn;
            const checkoutBtn = isOnlineCart ? elements.placeOnlineOrderBtn : elements.checkoutBtn;
            
            const { subtotal, discount, total } = await calculateCartTotals(isOnlineCart);
            
            totalItemsSpan.textContent = currentCart.reduce((sum, item) => sum + item.quantity, 0);
            
            if (currentCart.length === 0) {
                containerElement.innerHTML = isOnlineCart ? `<tr><td colspan="6">Cart is empty.</td></tr>` : `<div style="text-align:center; padding: 30px 10px; color: var(--light-text-color);">Cart is empty. Tap products to add.</div>`;
                clearBtn.disabled = true;
                checkoutBtn.disabled = true;
                if (elements.checkoutPromotionCode && !isOnlineCart) elements.checkoutPromotionCode.value = '';
                if (elements.onlineCheckoutPromotionCode && isOnlineCart) elements.onlineCheckoutPromotionCode.value = '';
                appliedPromotion = null;
            } else {
                if (isOnlineCart) {
                    containerElement.innerHTML = currentCart.map(item => {
                        const itemNetTotal = (item.price * item.quantity) - (item.discount_per_unit * item.quantity);
                        return `
                            <tr>
                                <td>${html(item.name)}</td>
                                <td>${formatCurrency(item.price)}</td>
                                <td class="quantity-controls">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity - 1}, true)">-</button>
                                    <input type="number" value="${html(item.quantity)}" min="1" onchange="updateCartItemQuantity(${html(item.bookId)}, parseInt(this.value), true)">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity + 1}, true)">+</button>
                                </td>
                                <td>${item.discount_per_unit > 0 ? formatCurrency(item.discount_per_unit * item.quantity) : 'N/A'}</td>
                                <td>${formatCurrency(itemNetTotal)}</td>
                                <td class="actions">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeCartItem(${html(item.bookId)}, true)"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                } else {
                    containerElement.innerHTML = currentCart.map(item => {
                        const itemNetTotal = (item.price * item.quantity) - ((item.custom_discount || 0) * item.quantity);
                        return `
                            <div class="pos-cart-item">
                                <div class="pos-cart-item-title">
                                    <span>${html(item.name)}</span>
                                    <span style="color:var(--primary-color);">${formatCurrency(itemNetTotal)}</span>
                                </div>
                                <div class="pos-cart-item-controls">
                                    <div class="pos-qty-group">
                                        <button type="button" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity - 1}, false)">-</button>
                                        <input type="number" value="${html(item.quantity)}" min="1" onchange="updateCartItemQuantity(${html(item.bookId)}, parseInt(this.value), false)">
                                        <button type="button" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity + 1}, false)">+</button>
                                    </div>
                                    <div class="pos-disc-group">
                                        <label>Disc:</label>
                                        <input type="number" step="0.01" min="0" max="${item.price}" placeholder="0.00" value="${item.custom_discount ? Number(item.custom_discount).toFixed(2) : ''}" onchange="updateCartItemDiscount(${html(item.bookId)}, this.value)">
                                    </div>
                                    <button type="button" class="btn btn-danger btn-sm" style="padding: 6px 10px;" onclick="removeCartItem(${html(item.bookId)}, false)"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
                clearBtn.disabled = false;
                checkoutBtn.disabled = false;
            }
            
            grandTotalSpan.textContent = formatCurrency(total);
            
            if (!isOnlineCart && elements.checkoutSubtotal) {
                const subEl = document.getElementById('cart-subtotal-display');
                const discEl = document.getElementById('cart-discount-display');
                if (subEl) subEl.textContent = formatCurrency(subtotal);
                if (discEl) discEl.textContent = formatCurrency(discount);
                
                elements.checkoutSubtotal.value = formatCurrency(subtotal);
                elements.checkoutDiscount.value = formatCurrency(discount);
                elements.checkoutTotal.value = formatCurrency(total);
                elements.checkoutDiscountDisplay.style.display = discount > 0 ? 'block' : 'none';
                elements.checkoutPromotionCodeInput.value = appliedPromotion ? appliedPromotion.code : '';
            }
            if (isOnlineCart && elements.onlineOrderSubtotal) {
                elements.onlineOrderSubtotal.value = formatCurrency(subtotal);
                elements.onlineOrderDiscount.value = formatCurrency(discount);
                elements.onlineOrderTotal.value = formatCurrency(total);
                elements.onlineOrderDiscountDisplay.style.display = discount > 0 ? 'block' : 'none';
                elements.onlineOrderPromotionCodeInput.value = appliedPromotion ? appliedPromotion.code : '';
            }
        }
        async function openCheckoutModal() {
            if (!elements.checkoutModal) return;
            if (currentCart.length === 0) {
                showToast('Cart is empty. Please add items to cart before checkout.', 'warning');
                return;
            }
            await renderCart();
            showModal(elements.checkoutModal);
        }
        async function openOnlineOrderModal() {
            if (!elements.onlineOrderModal) return;
            if (currentCart.length === 0) {
                showToast('Your cart is empty. Please add items before placing an order.', 'warning');
                return;
            }
            await renderCart(true);
            elements.onlineOrderCartItemsInput.value = JSON.stringify(currentCart);
            showModal(elements.onlineOrderModal);
        }
        async function applyPromotion(isOnlineCart = false) {
            const promoCodeInput = isOnlineCart ? elements.onlineCheckoutPromotionCode : elements.checkoutPromotionCode;
            const promoMessageDiv = isOnlineCart ? elements.onlinePromoMessage : document.getElementById('promo-message');
            if (!promoCodeInput) return;
            const promoCode = promoCodeInput.value.trim();
            if (!promoCode) {
                appliedPromotion = null;
                promoMessageDiv.textContent = '';
                renderCart(isOnlineCart);
                showToast('Promotion code cleared.', 'info');
                return;
            }
            const data = await fetchJSON(`index.php?action=get_promotions_json`);
            if (!data.success) {
                showToast('Failed to fetch promotions.', 'error');
                return;
            }
            const promotions = data.promotions;
            const now = new Date();
            const promotion = promotions.find(p =>
                p.code.toLowerCase() === promoCode.toLowerCase() &&
                new Date(p.start_date) <= now &&
                (!p.end_date || new Date(p.end_date) >= now)
            );
            if (promotion) {
                appliedPromotion = promotion;
                promoMessageDiv.textContent = `Applied: ${html(promotion.code)} - ${promotion.type === 'percentage' ? promotion.value + '%' : formatCurrency(promotion.value)} off ${html(promotion.applies_to_value_name)}`;
                promoMessageDiv.style.color = 'var(--success-color)';
                showToast(`Promotion "${html(promotion.code)}" applied!`, 'success');
            } else {
                appliedPromotion = null;
                promoMessageDiv.textContent = 'Invalid or expired promotion code.';
                promoMessageDiv.style.color = 'var(--danger-color)';
                showToast('Invalid or expired promotion code.', 'error');
            }
            renderCart(isOnlineCart);
        }

        function clearCart(isOnlineCart = false) {
            if (confirm('Are you sure you want to clear the entire cart?')) {
                currentCart = [];
                appliedPromotion = null;
                if (elements.checkoutPromotionCode && !isOnlineCart) elements.checkoutPromotionCode.value = '';
                if (elements.onlineCheckoutPromotionCode && isOnlineCart) elements.onlineCheckoutPromotionCode.value = '';
                if (document.getElementById('promo-message') && !isOnlineCart) document.getElementById('promo-message').textContent = '';
                if (elements.onlinePromoMessage && isOnlineCart) elements.onlinePromoMessage.textContent = '';
                renderCart(isOnlineCart);
                if (!isOnlineCart) {
                    renderBooksForCart(false);
                } else {
                    renderBooksForCart(true);
                }
                showToast('Cart cleared.', 'info');
            }
        }
        async function renderSalesHistory() {
            if (!elements.salesList) return;
            const search = elements.saleSearch.value;
            const page = pagination.sales.currentPage;
            const data = await fetchJSON(`index.php?action=get_sales_json&search=${encodeURIComponent(search)}&page_num=${page}`);
            if (data.success) {
                const sales = data.sales;
                updatePaginationControls(pagination.sales, data.total_items);
                elements.salesList.innerHTML = sales.length > 0 ? sales.map(sale => `
                    <tr>
                        <td>${html(sale.id)}</td>
                        <td>${formatDate(html(sale.sale_date))}</td>
                        <td>${html(sale.customer_name || 'Guest')}</td>
                        <td>${html(sale.item_names)}</td>
                        <td>${formatCurrency(html(sale.total))}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="viewSaleDetails(${html(sale.id)})"><i class="fas fa-eye"></i> View</button>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="6">No sales recorded.</td></tr>`;
            }
        }
        async function viewSaleDetails(saleId) {
            if (!elements.viewSaleModal) return;
            const data = await fetchJSON(`index.php?action=get_sale_details_json&sale_id=${saleId}`);
            if (data.success) {
                const sale = data.sale;
                elements.saleDetailsId.textContent = html(sale.id);
                elements.saleDetailsDate.textContent = formatDate(html(sale.sale_date));
                elements.saleDetailsCustomer.textContent = html(sale.customer_name || 'Guest');
                elements.saleDetailsSubtotal.textContent = formatCurrency(html(sale.subtotal));
                elements.saleDetailsTotal.textContent = formatCurrency(html(sale.total));
                if (sale.discount > 0) {
                    elements.saleDetailsDiscountLine.style.display = 'block';
                    elements.saleDetailsDiscountValue.textContent = formatCurrency(html(sale.discount));
                } else {
                    elements.saleDetailsDiscountLine.style.display = 'none';
                }
                elements.saleDetailsItems.innerHTML = sale.items.map(item => `
                    <tr>
                        <td>${html(item.name)}</td>
                        <td>${html(item.quantity)}</td>
                        <td>${formatCurrency(html(item.price_per_unit))}</td>
                        <td>${item.discount_per_unit > 0 ? formatCurrency(item.discount_per_unit * item.quantity) : 'N/A'}</td>
                        <td>${formatCurrency((item.price_per_unit * item.quantity) - (item.discount_per_unit * item.quantity))}</td>
                    </tr>
                `).join('');
                elements.reprintReceiptBtn.onclick = () => {
                    hideModal(elements.viewSaleModal);
                    openReceiptModal(sale);
                };
                showModal(elements.viewSaleModal);
            } else {
                showToast('Sale record not found.', 'error');
            }
        }
        let currentReceiptSale = null;
        async function openReceiptModal(sale) {
            if (!elements.receiptModal) return;
            currentReceiptSale = sale;
            elements.receiptSaleId.textContent = html(sale.id);
            elements.receiptDate.textContent = formatDate(html(sale.sale_date));
            elements.receiptCustomer.textContent = html(sale.customer_name || 'Guest');
            elements.receiptSubtotal.textContent = formatCurrency(html(sale.subtotal));
            elements.receiptGrandTotal.textContent = formatCurrency(html(sale.total));
            if (sale.discount > 0) {
                elements.receiptDiscountLine.style.display = 'block';
                elements.receiptDiscountValue.textContent = formatCurrency(html(sale.discount));
            } else {
                elements.receiptDiscountLine.style.display = 'none';
            }
            elements.receiptItemsList.innerHTML = sale.items.map(item => `
                <tr>
                    <td style="text-align: left; padding: 2px 0;">${html(item.name)}</td>
                    <td style="text-align: right; padding: 2px 0;">${html(item.quantity)}</td>
                    <td style="text-align: right; padding: 2px 0;">${formatCurrency(html(item.price_per_unit))}</td>
                    <td style="text-align: right; padding: 2px 0;">${formatCurrency((item.price_per_unit * item.quantity) - (item.discount_per_unit * item.quantity))}</td>
                </tr>
            `).join('');
            showModal(elements.receiptModal);
        }
        function printReceipt(type) {
            if (!currentReceiptSale) return;
            const sale = currentReceiptSale;
            const storeName = "<?php echo html($public_settings['system_name'] ?? 'General Store & Bookshop'); ?>";
            const storeAddress = "<?php echo html(str_replace(["\r", "\n", '"'], [' ', ' ', '\"'], $public_settings['address'] ?? '')); ?>";
            const storePhone = "<?php echo html($public_settings['phone'] ?? ''); ?>";
            const currency = "<?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?>";
            
            const dateStr = formatDate(sale.sale_date);
            let htmlContent = '';
            
            if (type === 'thermal') {
                htmlContent = `
                    <html><head><title>Thermal Receipt</title>
                    <style>
                        @page { margin: 0; }
                        body { font-family: 'Courier New', Courier, monospace; width: 80mm; margin: 0 auto; padding: 5mm; font-size: 12px; color: #000; box-sizing: border-box; }
                        .text-center { text-align: center; }
                        .text-right { text-align: right; }
                        table { width: 100%; border-collapse: collapse; margin: 10px 0; table-layout: fixed; }
                        th, td { padding: 4px 0; border-bottom: 1px dashed #000; vertical-align: top; word-wrap: break-word; }
                        th { border-top: 1px dashed #000; border-bottom: 1px dashed #000; text-align: left; font-weight: bold; }
                        .bold { font-weight: bold; }
                        .mb-1 { margin-bottom: 5px; }
                        .mb-2 { margin-bottom: 10px; }
                    </style>
                    </head><body>
                        <div class="text-center mb-2">
                            <h2 style="margin:0 0 5px 0; font-size: 16px;">${storeName}</h2>
                            <div class="mb-1">${storeAddress}</div>
                            <div class="mb-1">Tel: ${storePhone}</div>
                        </div>
                        <div class="mb-1"><strong>Sale ID:</strong> ${sale.id}</div>
                        <div class="mb-1"><strong>Date:</strong> ${dateStr}</div>
                        <div class="mb-2"><strong>Customer:</strong> ${sale.customer_name || 'Guest'}</div>
                        
                        <table>
                            <thead><tr><th style="width:40%;">Item</th><th class="text-right" style="width:15%;">Qty</th><th class="text-right" style="width:20%;">Price</th><th class="text-right" style="width:25%;">Total</th></tr></thead>
                            <tbody>
                                ${sale.items.map(item => `
                                    <tr>
                                        <td>${item.name}</td>
                                        <td class="text-right">${item.quantity}</td>
                                        <td class="text-right">${Number(item.price_per_unit).toFixed(2)}</td>
                                        <td class="text-right">${(Number(item.price_per_unit)*item.quantity - Number(item.discount_per_unit)*item.quantity).toFixed(2)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        <div class="text-right mb-1">Subtotal: ${currency}${Number(sale.subtotal).toFixed(2)}</div>
                        ${Number(sale.discount) > 0 ? `<div class="text-right mb-1">Discount: ${currency}${Number(sale.discount).toFixed(2)}</div>` : ''}
                        <div class="text-right bold mb-2" style="font-size: 16px;">Total: ${currency}${Number(sale.total).toFixed(2)}</div>
                        <div class="text-center mb-2" style="margin-top:20px; font-size:14px;">*** Thank You! ***</div>
                        <script>window.onload = function() { setTimeout(function(){ window.print(); }, 500); }<\/script>
                    </body></html>
                `;
            } else {
                htmlContent = `
                    <html><head><title>A4 Invoice</title>
                    <style>
                        @page { size: A4; margin: 20mm; }
                        body { font-family: Arial, sans-serif; margin: 0; padding: 0; color: #333; background: #fff; }
                        .invoice-box { width: 100%; max-width: 190mm; margin: 0 auto; box-sizing: border-box; }
                        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #2a9d8f; padding-bottom: 20px; margin-bottom: 20px; }
                        .header h1 { margin: 0; color: #2a9d8f; font-size: 28px; text-transform: uppercase; letter-spacing: 2px; }
                        .store-info { text-align: right; color: #555; line-height: 1.5; font-size: 14px; }
                        .invoice-details { display: flex; justify-content: space-between; margin-bottom: 30px; font-size: 14px; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        th { background: #f8f9fa; color: #333; padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        td { padding: 12px; border-bottom: 1px solid #eee; }
                        .text-right { text-align: right; }
                        .totals { width: 50%; float: right; margin-top: 10px; }
                        .totals table { width: 100%; border: none; }
                        .totals th { background: transparent; border: none; padding: 8px; text-align: right; color: #555; }
                        .totals td { border: none; padding: 8px; font-weight: bold; font-size: 16px; }
                        .clearfix::after { content: ""; clear: both; display: table; }
                        .footer { text-align: center; margin-top: 50px; color: #777; font-size: 12px; border-top: 1px solid #eee; padding-top: 20px; }
                    </style>
                    </head><body>
                        <div class="invoice-box">
                            <div class="header">
                                <div><h1>INVOICE</h1><br><h2 style="margin:0; color:#333;">${storeName}</h2></div>
                                <div class="store-info">${storeAddress}<br>Tel: ${storePhone}</div>
                            </div>
                            <div class="invoice-details">
                                <div><strong style="color:#777;">Invoice To:</strong><br><span style="font-size:16px;">${sale.customer_name || 'Guest Customer'}</span></div>
                                <div class="text-right"><strong style="color:#777;">Invoice #:</strong> ${sale.id}<br><strong style="color:#777;">Date:</strong> ${dateStr}</div>
                            </div>
                            <table>
                                <thead><tr><th>Description</th><th class="text-right">Rate</th><th class="text-right">Qty</th><th class="text-right">Amount</th></tr></thead>
                                <tbody>
                                    ${sale.items.map(item => `
                                        <tr>
                                            <td>${item.name}</td>
                                            <td class="text-right">${currency}${Number(item.price_per_unit).toFixed(2)}</td>
                                            <td class="text-right">${item.quantity}</td>
                                            <td class="text-right">${currency}${(Number(item.price_per_unit)*item.quantity - Number(item.discount_per_unit)*item.quantity).toFixed(2)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                            <div class="totals clearfix">
                                <table>
                                    <tr><th>Subtotal:</th><td class="text-right">${currency}${Number(sale.subtotal).toFixed(2)}</td></tr>
                                    ${Number(sale.discount) > 0 ? `<tr><th>Discount:</th><td class="text-right">-${currency}${Number(sale.discount).toFixed(2)}</td></tr>` : ''}
                                    <tr><th style="font-size:18px; color:#2a9d8f;">Grand Total:</th><td class="text-right" style="font-size:18px; color:#2a9d8f;">${currency}${Number(sale.total).toFixed(2)}</td></tr>
                                </table>
                            </div>
                            <div class="footer">Thank you for shopping with us!</div>
                        </div>
                        <script>window.onload = function() { setTimeout(function(){ window.print(); }, 500); }<\/script>
                    </body></html>
                `;
            }
            
            const printWin = window.open('', '_blank');
            if(printWin) {
                printWin.document.write(htmlContent);
                printWin.document.close();
            } else {
                showToast('Please allow popups to print receipts.', 'warning');
            }
        }
        async function downloadReceiptPdf() {
            if (!elements.receiptContent) return;
            const receiptContent = document.getElementById('receipt-content').cloneNode(true);
            receiptContent.style.padding = '20px';
            receiptContent.style.backgroundColor = 'white';
            receiptContent.style.color = 'black';
            receiptContent.style.position = 'absolute';
            receiptContent.style.left = '-9999px';
            document.body.appendChild(receiptContent);
            html2canvas(receiptContent, {
                scale: 2
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'px',
                    format: [canvas.width, canvas.height]
                });
                pdf.addImage(imgData, 'PNG', 0, 0, canvas.width, canvas.height);
                const saleId = elements.receiptSaleId.textContent;
                pdf.save(`Receipt_Sale_${saleId}.pdf`);
            }).catch(error => {
                console.error('Error generating PDF for download:', error);
                showToast('Failed to download receipt PDF.', 'error');
            }).finally(() => {
                receiptContent.remove();
            });
        }
        async function renderOnlineOrders(isCustomerView = false) {
            const listElement = isCustomerView ? elements.customerMyOrdersList : elements.onlineOrdersList;
            const paginationConfig = pagination.onlineOrders;
            const search = isCustomerView ? '' : elements.onlineOrderSearch.value;
            const status = isCustomerView ? 'all' : elements.onlineOrderStatusFilter.value;
            const page = paginationConfig.currentPage;
            if (!listElement) return;
            let url = `index.php?action=get_online_orders_json&status=${status}&page_num=${page}`;
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            const data = await fetchJSON(url);
            if (data.success) {
                const orders = data.online_orders;
                updatePaginationControls(paginationConfig, data.total_items);
                listElement.innerHTML = orders.length > 0 ? orders.map(order => `
                    <tr>
                        <td>${html(order.id)}</td>
                        <td>${formatDate(html(order.order_date))}</td>
                        <td>${html(order.customer_name)}</td>
                        <td>${order.items.map(item => `${html(item.name)} (${html(item.quantity)})`).join(', ')}</td>
                        <td>${formatCurrency(html(order.total))}</td>
                        <td>${html(ucfirst(order.status))}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="viewOnlineOrderDetails(${html(order.id)})"><i class="fas fa-eye"></i> View</button>
                            ${isCustomerView ? '' : `
                                ${order.status === 'pending' ? `
                                    <form action="index.php?page=online-orders" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to approve this online order? This will create a sale and deduct stock.');">
                                        <input type="hidden" name="action" value="approve_online_order">
                                        <input type="hidden" name="order_id" value="${html(order.id)}">
                                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Approve</button>
                                    </form>
                                    <form action="index.php?page=online-orders" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to reject this online order?');">
                                        <input type="hidden" name="action" value="reject_online_order">
                                        <input type="hidden" name="order_id" value="${html(order.id)}">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Reject</button>
                                    </form>
                                ` : ''}
                            `}
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="7">No online orders found.</td></tr>`;
            }
        }
        async function viewOnlineOrderDetails(orderId) {
            if (!elements.viewOnlineOrderModal) return;
            const data = await fetchJSON(`index.php?action=get_online_orders_json&order_id=${orderId}`);
            if (data.success && data.online_orders.length > 0) {
                const order = data.online_orders[0];
                elements.onlineOrderDetailsId.textContent = html(order.id);
                elements.onlineOrderDetailsDate.textContent = formatDate(html(order.order_date));
                elements.onlineOrderDetailsCustomer.textContent = html(order.customer_name);
                if (elements.onlineOrderDetailsEmail) elements.onlineOrderDetailsEmail.textContent = html(order.customer_email || 'N/A');
                if (elements.onlineOrderDetailsPhone) elements.onlineOrderDetailsPhone.textContent = html(order.customer_phone || 'N/A');
                elements.onlineOrderDetailsStatus.textContent = html(ucfirst(order.status));
                elements.onlineOrderDetailsSubtotal.textContent = formatCurrency(html(order.subtotal));
                elements.onlineOrderDetailsTotal.textContent = formatCurrency(html(order.total));
                if (order.discount > 0) {
                    elements.onlineOrderDetailsDiscountLine.style.display = 'block';
                    elements.onlineOrderDetailsDiscountValue.textContent = formatCurrency(html(order.discount));
                } else {
                    elements.onlineOrderDetailsDiscountLine.style.display = 'none';
                }
                elements.onlineOrderDetailsItems.innerHTML = order.items.map(item => `
                    <tr>
                        <td>${html(item.name)}</td>
                        <td>${html(item.quantity)}</td>
                        <td>${formatCurrency(html(item.price_per_unit))}</td>
                        <td>${item.discount_per_unit > 0 ? formatCurrency(item.discount_per_unit * item.quantity) : 'N/A'}</td>
                        <td>${formatCurrency((item.price_per_unit * item.quantity) - (item.discount_per_unit * item.quantity))}</td>
                    </tr>
                `).join('');
                if (order.status === 'pending' && (<?php echo isAdmin() ? 'true' : 'false'; ?> || <?php echo isStaff() ? 'true' : 'false'; ?>)) {
                    elements.approveOrderBtn.style.display = 'inline-flex';
                    elements.rejectOrderBtn.style.display = 'inline-flex';
                    elements.approveOrderId.value = order.id;
                    elements.rejectOrderId.value = order.id;
                } else {
                    elements.approveOrderBtn.style.display = 'none';
                    elements.rejectOrderBtn.style.display = 'none';
                }
                showModal(elements.viewOnlineOrderModal);
            } else {
                showToast('Online order not found.', 'error');
            }
        }
        async function renderPromotions() {
            if (!elements.promotionsList) return;
            const data = await fetchJSON('index.php?action=get_promotions_json');
            if (data.success) {
                const promotions = data.promotions;
                elements.promotionsList.innerHTML = promotions.length > 0 ? promotions.map(promo => `
                    <tr>
                        <td>${html(promo.code)}</td>
                        <td>${promo.type === 'percentage' ? 'Percentage Off' : 'Fixed Amount Off'}</td>
                        <td>${promo.type === 'percentage' ? `${html(promo.value)}%` : formatCurrency(html(promo.value))}</td>
                        <td>${html(promo.applies_to_value_name)}</td>
                        <td>${formatShortDate(html(promo.start_date))}</td>
                        <td>${promo.end_date ? formatShortDate(html(promo.end_date)) : 'No End Date'}</td>
                        <td class="actions">
                            <button class="btn btn-primary btn-sm" onclick="openPromotionModal(${html(promo.id)})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=promotions" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this promotion?');">
                                <input type="hidden" name="action" value="delete_promotion">
                                <input type="hidden" name="promotion_id" value="${html(promo.id)}">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="7">No promotions found.</td></tr>`;
            }
        }
        async function openPromotionModal(promotionId = null) {
            if (!elements.promotionForm) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage promotions.', 'error');
                return;
            }
            elements.promotionForm.reset();
            elements.promotionId.value = '';
            elements.promotionModalTitle.textContent = 'Add New Promotion';
            elements.promotionType.value = 'percentage';
            if (elements.promotionTypePercentage) elements.promotionTypePercentage.classList.add('active');
            if (elements.promotionTypeFixed) elements.promotionTypeFixed.classList.remove('active');
            elements.promotionAppliesTo.value = 'all';
            elements.promotionBookGroup.style.display = 'none';
            elements.promotionCategoryGroup.style.display = 'none';
            elements.promotionStartDate.value = new Date().toISOString().split('T')[0];
            elements.promotionEndDate.value = '';
            if (promotionId) {
                const data = await fetchJSON(`index.php?action=get_promotions_json&promotion_id=${promotionId}`);
                if (data.success && data.promotions.length > 0) {
                    const promo = data.promotions[0];
                    elements.promotionModalTitle.textContent = 'Edit Promotion';
                    elements.promotionId.value = promo.id;
                    elements.promotionCode.value = promo.code;
                    elements.promotionType.value = promo.type;
                    if (elements.promotionTypePercentage) elements.promotionTypePercentage.classList.remove('active');
                    if (elements.promotionTypeFixed) elements.promotionTypeFixed.classList.remove('active');
                    if (promo.type === 'percentage') {
                        if (elements.promotionTypePercentage) elements.promotionTypePercentage.classList.add('active');
                    } else {
                        if (elements.promotionTypeFixed) elements.promotionTypeFixed.classList.add('active');
                    }
                    elements.promotionValue.value = promo.value;
                    elements.promotionAppliesTo.value = promo.applies_to;
                    if (promo.applies_to === 'specific-book') {
                        elements.promotionBookGroup.style.display = 'block';
                        elements.promotionBookId.value = promo.applies_to_value;
                    } else if (promo.applies_to === 'specific-category') {
                        elements.promotionCategoryGroup.style.display = 'block';
                        elements.promotionCategory.value = promo.applies_to_value;
                    }
                    elements.promotionStartDate.value = promo.start_date;
                    elements.promotionEndDate.value = promo.end_date || '';
                } else {
                    showToast('Promotion not found.', 'error');
                    return;
                }
            }
            showModal(elements.promotionModal);
        }
        async function renderExpenses() {
            if (!elements.expensesList) return;
            const search = elements.expenseSearch.value;
            const month = elements.expenseMonthFilter.value;
            const page = pagination.expenses.currentPage;
            const data = await fetchJSON(`index.php?action=get_expenses_json&search=${encodeURIComponent(search)}&month=${month}&page_num=${page}`);
            if (data.success) {
                const expenses = data.expenses;
                updatePaginationControls(pagination.expenses, data.total_items);
                elements.monthlyExpensesTotal.textContent = formatCurrency(data.monthly_total);
                elements.expensesList.innerHTML = expenses.length > 0 ? expenses.map(expense => `
                    <tr>
                        <td>${formatShortDate(html(expense.expense_date))}</td>
                        <td>${html(expense.category)}</td>
                        <td>${html(expense.description)}</td>
                        <td>${formatCurrency(html(expense.amount))}</td>
                        <td class="actions">
                            <button class="btn btn-primary btn-sm" onclick="openExpenseModal(${html(expense.id)})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=expenses" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                <input type="hidden" name="action" value="delete_expense">
                                <input type="hidden" name="expense_id" value="${html(expense.id)}">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="5">No expenses recorded for this period.</td></tr>`;
            }
        }
        async function openExpenseModal(expenseId = null) {
            if (!elements.expenseForm) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to manage expenses.', 'error');
                return;
            }
            elements.expenseForm.reset();
            elements.expenseId.value = '';
            elements.expenseModalTitle.textContent = 'Add New Expense';
            elements.expenseDate.value = new Date().toISOString().split('T')[0];
            if (expenseId) {
                const data = await fetchJSON(`index.php?action=get_expenses_json&expense_id=${expenseId}`);
                if (data.success && data.expenses.length > 0) {
                    const expense = data.expenses[0];
                    elements.expenseModalTitle.textContent = 'Edit Expense';
                    elements.expenseId.value = expense.id;
                    elements.expenseDate.value = expense.expense_date;
                    elements.expenseCategory.value = expense.category;
                    elements.expenseDescription.value = expense.description;
                    elements.expenseAmount.value = expense.amount;
                } else {
                    showToast('Expense not found.', 'error');
                    return;
                }
            }
            showModal(elements.expenseModal);
        }

        function updateReportFilters() {
            if (!elements.reportType) return;
            const type = elements.reportType.value;
            elements.reportDateFilter.style.display = 'none';
            elements.reportMonthFilter.style.display = 'none';
            elements.reportYearFilter.style.display = 'none';
            const today = new Date();
            if (type === 'sales-daily') {
                elements.reportDateFilter.style.display = 'block';
                elements.reportDate.value = today.toISOString().slice(0, 10);
            } else if (type === 'sales-weekly' || type === 'sales-monthly' || type === 'expenses-summary') {
                elements.reportMonthFilter.style.display = 'block';
                elements.reportMonth.value = `${today.getFullYear()}-${(today.getMonth() + 1).toString().padStart(2, '0')}`;
            }
        }
        async function generateReport() {
            if (!elements.reportType) return;
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?>) {
                showToast('Unauthorized to generate reports.', 'error');
                return;
            }
            const type = elements.reportType.value;
            const date = elements.reportDate?.value;
            const month = elements.reportMonth?.value;
            const year = elements.reportYear?.value;
            elements.reportResultsHeader.textContent = `Report Results: ${elements.reportType.options[elements.reportType.selectedIndex].text}`;
            elements.exportCurrentReportBtn.disabled = true;
            if (reportChartInstance) {
                reportChartInstance.destroy();
                reportChartInstance = null;
            }
            let url = `index.php?action=get_report_data_json&report_type=${type}`;
            if (date) url += `&date=${date}`;
            if (month) url += `&month=${month}`;
            if (year) url += `&year=${year}`;
            const data = await fetchJSON(url);
            if (data.success) {
                elements.reportResultsTable.querySelector('tbody').innerHTML = data.report_data.table_html;
                currentReportData = data.report_data.raw_data;
                elements.exportCurrentReportBtn.disabled = currentReportData.length === 0;
                if (data.report_data.chart_data) {
                    if (data.report_data.chart_data.type === 'pie') {
                        const numColors = data.report_data.chart_data.datasets[0].data.length;
                        const backgroundColors = [];
                        const borderColors = [];
                        for (let i = 0; i < numColors; i++) {
                            const hue = (i * 137) % 360;
                            backgroundColors.push(`hsla(${hue}, 70%, 50%, 0.7)`);
                            borderColors.push(`hsla(${hue}, 70%, 50%, 1)`);
                        }
                        data.report_data.chart_data.datasets[0].backgroundColor = backgroundColors;
                        data.report_data.chart_data.datasets[0].borderColor = borderColors;
                    }
                    renderChart(data.report_data.chart_data.labels, data.report_data.chart_data.datasets, data.report_data.chart_data.type, data.report_data.chart_data.title);
                }
            }
        }

        function renderChart(labels, datasets, type, title) {
            if (!elements.reportChart) return;
            const ctx = elements.reportChart.getContext('2d');
            reportChartInstance = new Chart(ctx, {
                type: type,
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        },
                        title: {
                            display: true,
                            text: title,
                            color: getComputedStyle(document.documentElement).getPropertyValue('--primary-color'),
                            font: {
                                size: 16
                            }
                        }
                    },
                    scales: (type === 'pie' || type === 'doughnut') ? {} : {
                        x: {
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--light-text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        },
                        y: {
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--light-text-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            }
                        }
                    }
                }
            });
        }

        function exportCurrentReport() {
            if (!elements.exportCurrentReportBtn) return;
            if (currentReportData.length === 0) {
                showToast('No report data to export.', 'warning');
                return;
            }
            const reportType = elements.reportType.options[elements.reportType.selectedIndex].text;
            const filename = `${reportType.replace(/\s/g, '_')}_Report_${new Date().toISOString().slice(0, 10)}.json`;
            const jsonString = JSON.stringify(currentReportData, null, 2);
            const blob = new Blob([jsonString], {
                type: 'application/json'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Report data exported successfully!', 'success');
        }

        function downloadDataAsCsv(data, filename) {
            if (!data || data.length === 0) {
                showToast('No data to export.', 'warning');
                return;
            }
            const header = Object.keys(data[0]);
            const csv = [
                header.map(h => `"${h.replace(/"/g, '""')}"`).join(','),
                ...data.map(row => header.map(fieldName => {
                    let value = row[fieldName];
                    if (value === null || value === undefined) {
                        value = '';
                    } else if (typeof value === 'string') {
                        value = value.replace(/"/g, '""');
                    } else if (typeof value === 'object') {
                        value = JSON.stringify(value).replace(/"/g, '""');
                    }
                    return `"${value}"`;
                }).join(','))
            ].join('\n');
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                showToast('Data exported successfully!', 'success');
            } else {
                showToast('Your browser does not support downloading files.', 'error');
            }
        }
        async function exportBooks() {
            const data = await fetchJSON(`index.php?action=get_books_json&page_num=1&search=&sort=name-asc&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.books, 'products_export.csv');
            }
        }
        async function exportCustomers() {
            const data = await fetchJSON(`index.php?action=get_customers_json&page_num=1&search=&status=all&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.customers, 'customers_export.csv');
            }
        }
        async function exportSuppliers() {
            const data = await fetchJSON(`index.php?action=get_suppliers_json&page_num=1&search=&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.suppliers, 'suppliers_export.csv');
            }
        }
        async function exportPurchaseOrders() {
            const data = await fetchJSON(`index.php?action=get_pos_json&page_num=1&search=&status=all&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.purchase_orders.map(po => ({
                    id: po.id,
                    supplier_id: po.supplier_id,
                    supplier_name: po.supplier_name,
                    order_date: po.order_date,
                    expected_date: po.expected_date,
                    status: po.status,
                    items: JSON.stringify(po.items.map(item => ({
                        book_id: item.book_id,
                        name: item.name,
                        quantity: item.quantity,
                        cost_per_unit: item.cost_per_unit
                    }))),
                    total_cost: po.total_cost
                })), 'purchase_orders_export.csv');
            }
        }
        async function exportSales() {
            const data = await fetchJSON(`index.php?action=get_sales_json&page_num=1&search=&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.sales.map(s => ({
                    id: s.id,
                    sale_date: s.sale_date,
                    customer_id: s.customer_id,
                    customer_name: s.customer_name,
                    items: s.item_names,
                    subtotal: s.subtotal,
                    discount: s.discount,
                    total: s.total,
                    promotion_code: s.promotion_code
                })), 'sales_export.csv');
            }
        }
        async function renderPublicBooks() {
            if (!elements.publicBooksList) return;
            const search = elements.publicBookSearch ? elements.publicBookSearch.value : '';
            const category = elements.publicBookCategoryFilter ? elements.publicBookCategoryFilter.value : 'all';
            const product_type = elements.publicProductTypeFilter ? elements.publicProductTypeFilter.value : 'all';
            const sort = elements.publicBookSort ? elements.publicBookSort.value : 'name-asc';
            const page = pagination.publicBooks.currentPage;
            const data = await fetchJSON(`index.php?action=get_public_books_json&search=${encodeURIComponent(search)}&category=${encodeURIComponent(category)}&product_type=${encodeURIComponent(product_type)}&sort=${sort}&page_num=${page}`);
            if (data.success) {
                const products = data.books;
                updatePaginationControls(pagination.publicBooks, data.total_items);
                elements.publicBooksList.innerHTML = products.length > 0 ? products.map(product => `
                    <div class="book-card">
                        <img src="${product.cover_image ? html(product.cover_image) : 'https://via.placeholder.com/150x200?text=No+Cover'}" alt="${html(product.name)}">
                        <h3>${html(product.name)}</h3>
                        <p>${product.author ? 'by ' + html(product.author) : html(ucfirst(product.product_type))}</p>
                        <div class="price">${formatCurrency(product.price)}</div>
                        <div class="stock-info ${product.stock <= 5 && product.stock > 0 ? 'low' : ''} ${product.stock === 0 ? 'out' : ''}">
                            ${product.stock > 0 ? html(product.stock) + ' In Stock' : 'Out of Stock'}
                        </div>
                        <div class="public-product-actions">
                            <a href="https://wa.me/<?php echo html($public_settings['whatsapp_number'] ?? ''); ?>?text=Hello,%20I%20would%20like%20to%20order%20${encodeURIComponent(html(product.name))}%20-%20Price:%20${encodeURIComponent(formatCurrency(html(product.price)))}." target="_blank" class="whatsapp-btn"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                            ${<?php echo isCustomer() ? 'true' : 'false'; ?> ? `
                                <button class="btn btn-primary" onclick="addToCart(${html(product.id)}, '${html(product.name)}', ${html(product.price)}, true)" ${product.stock <= 0 ? 'disabled' : ''}><i class="fas fa-cart-plus"></i> Add to Cart</button>
                            ` : `
                                <a href="index.php?page=customer-login" class="btn btn-primary"><i class="fas fa-user-circle"></i> Login to Order</a>
                            `}
                        </div>
                    </div>
                `).join('') : `<p>No products found matching your criteria.</p>`;
            }
        }
        async function globalSearch() {
            if (!elements.globalSearchInput || !elements.globalSearchResults) return;
            const query = elements.globalSearchInput.value.trim();
            if (query.length < 2) {
                elements.globalSearchResults.innerHTML = '';
                elements.globalSearchResults.classList.remove('active');
                return;
            }
            const data = await fetchJSON(`index.php?action=global_search_json&query=${encodeURIComponent(query)}`);
            if (data.success) {
                elements.globalSearchResults.innerHTML = data.results.length > 0 ? data.results.map(item => `
                    <div onclick="window.location.href='${html(item.link)}${item.type === 'Book' || item.type === 'Product' ? `#edit-${item.id}` : (item.type === 'Customer' ? `#view-${item.id}` : `#sale-${item.id}`)}'">
                        <span class="type-label">${html(item.type)}</span> ${html(item.name)}
                    </div>
                `).join('') : `<div>No results found.</div>`;
                elements.globalSearchResults.classList.add('active');
            }
        }

        function ucfirst(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        function setupEventListeners() {
            if (elements.darkModeSwitch) {
                elements.darkModeSwitch.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        document.documentElement.setAttribute('data-theme', 'dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        document.documentElement.removeAttribute('data-theme');
                        localStorage.setItem('theme', 'light');
                    }
                });
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme === 'dark') {
                    elements.darkModeSwitch.checked = true;
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            }
            if (elements.navLinks) {
                elements.navLinks.forEach(link => {
                    link.addEventListener('click', (e) => {});
                });
            }
            if (elements.hamburgerMenu) {
                elements.hamburgerMenu.addEventListener('click', () => {
                    elements.sidebar.classList.toggle('active');
                });
            }
            if (document.getElementById('mobile-nav-toggle')) {
                document.getElementById('mobile-nav-toggle').addEventListener('click', () => {
                    elements.sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-open');
                });
            }
            if (elements.modalCloseButtons) {
                elements.modalCloseButtons.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const modal = e.target.closest('.modal-overlay');
                        if (modal) hideModal(modal);
                    });
                });
            }
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        hideModal(overlay);
                    }
                });
            });
            if (elements.globalSearchInput) {
                let searchTimeout;
                elements.globalSearchInput.addEventListener('input', () => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(globalSearch, 300);
                });
                elements.globalSearchInput.addEventListener('focus', () => {
                    if (elements.globalSearchInput.value.length > 1) {
                        elements.globalSearchResults.classList.add('active');
                    }
                });
                elements.globalSearchInput.addEventListener('blur', () => {
                    setTimeout(() => {
                        elements.globalSearchResults.classList.remove('active');
                    }, 200);
                });
            }
            if (elements.addBookBtn) elements.addBookBtn.addEventListener('click', () => openBookModal());
            if (elements.bookSearch) elements.bookSearch.addEventListener('input', () => {
                pagination.books.currentPage = 1;
                renderBooks();
            });
            if (elements.bookSort) elements.bookSort.addEventListener('change', () => {
                pagination.books.currentPage = 1;
                renderBooks();
            });
            if (elements.booksPrevPage) elements.booksPrevPage.addEventListener('click', () => {
                pagination.books.currentPage--;
                renderBooks();
            });
            if (elements.booksNextPage) elements.booksNextPage.addEventListener('click', () => {
                pagination.books.currentPage++;
                renderBooks();
            });
            if (elements.productType) elements.productType.addEventListener('change', (e) => {
                toggleBookFields(e.target.value);
            });
            if (elements.bookCoverImage) elements.bookCoverImage.addEventListener('change', (event) => {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        elements.bookCoverPreview.src = e.target.result;
                        elements.bookCoverPreview.style.display = 'block';
                        elements.removeCoverLabel.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (!elements.existingCoverImage.value) {
                        elements.bookCoverPreview.src = '';
                        elements.bookCoverPreview.style.display = 'none';
                        elements.removeCoverLabel.style.display = 'none';
                    }
                }
                elements.removeCoverImage.checked = false;
            });
            if (elements.removeCoverImage) elements.removeCoverImage.addEventListener('change', (event) => {
                if (event.target.checked) {
                    elements.bookCoverPreview.src = '';
                    elements.bookCoverPreview.style.display = 'none';
                    elements.bookCoverImage.value = '';
                } else if (elements.existingCoverImage.value) {
                    elements.bookCoverPreview.src = elements.existingCoverImage.value;
                    elements.bookCoverPreview.style.display = 'block';
                }
            });
            if (elements.exportBooksBtn) elements.exportBooksBtn.addEventListener('click', exportBooks);
            if (elements.importBooksBtn) elements.importBooksBtn.addEventListener('click', () => {
                showModal(elements.importBooksModal);
            });
            if (elements.addCustomerBtn) elements.addCustomerBtn.addEventListener('click', () => openCustomerModal());
            if (elements.customerSearch) elements.customerSearch.addEventListener('input', () => {
                pagination.customers.currentPage = 1;
                renderCustomers();
            });
            if (elements.customerFilterStatus) elements.customerFilterStatus.addEventListener('change', () => {
                pagination.customers.currentPage = 1;
                renderCustomers();
            });
            if (elements.customersPrevPage) elements.customersPrevPage.addEventListener('click', () => {
                pagination.customers.currentPage--;
                renderCustomers();
            });
            if (elements.customersNextPage) elements.customersNextPage.addEventListener('click', () => {
                pagination.customers.currentPage++;
                renderCustomers();
            });
            if (elements.exportCustomersBtn) elements.exportCustomersBtn.addEventListener('click', exportCustomers);
            if (elements.importCustomersBtn) elements.importCustomersBtn.addEventListener('click', () => {
                showModal(elements.importCustomersModal);
            });
            if (elements.addSupplierBtn) elements.addSupplierBtn.addEventListener('click', () => openSupplierModal());
            if (elements.supplierSearch) elements.supplierSearch.addEventListener('input', () => {
                pagination.suppliers.currentPage = 1;
                renderSuppliers();
            });
            if (elements.suppliersPrevPage) elements.suppliersPrevPage.addEventListener('click', () => {
                pagination.suppliers.currentPage--;
                renderSuppliers();
            });
            if (elements.suppliersNextPage) elements.suppliersNextPage.addEventListener('click', () => {
                pagination.suppliers.currentPage++;
                renderSuppliers();
            });
            if (elements.exportSuppliersBtn) elements.exportSuppliersBtn.addEventListener('click', exportSuppliers);
            if (elements.importSuppliersBtn) elements.importSuppliersBtn.addEventListener('click', () => {
                showModal(elements.importSuppliersModal);
            });
            if (elements.createPoBtn) elements.createPoBtn.addEventListener('click', () => openPurchaseOrderModal());
            if (elements.poSearch) elements.poSearch.addEventListener('input', () => {
                pagination.purchaseOrders.currentPage = 1;
                renderPurchaseOrders();
            });
            if (elements.poStatusFilter) elements.poStatusFilter.addEventListener('change', () => {
                pagination.purchaseOrders.currentPage = 1;
                renderPurchaseOrders();
            });
            if (elements.posPrevPage) elements.posPrevPage.addEventListener('click', () => {
                pagination.purchaseOrders.currentPage--;
                renderPurchaseOrders();
            });
            if (elements.posNextPage) elements.posNextPage.addEventListener('click', () => {
                pagination.purchaseOrders.currentPage++;
                renderPurchaseOrders();
            });
            if (elements.exportPosBtn) elements.exportPosBtn.addEventListener('click', exportPurchaseOrders);
            if (elements.addSelectedBookBtn) {
                elements.addSelectedBookBtn.addEventListener('click', () => {
                    const selectedOption = elements.poBookSelect.options[elements.poBookSelect.selectedIndex];
                    if (!selectedOption || !selectedOption.value) {
                        showToast('Please select a product to add.', 'warning');
                        return;
                    }
                    const product = {
                        id: parseInt(selectedOption.value),
                        name: selectedOption.dataset.name,
                        price: parseFloat(selectedOption.dataset.price)
                    };
                    addPoItem(product);
                    elements.poBookSelect.selectedIndex = 0;
                });
            }
            if (elements.bookToCartSearch) elements.bookToCartSearch.addEventListener('input', () => {
                renderBooksForCart(false);
            });
            if (elements.posCategoryFilter) elements.posCategoryFilter.addEventListener('change', () => {
                renderBooksForCart(false);
            });
            if (elements.booksForCartPrevPage) elements.booksForCartPrevPage.addEventListener('click', () => {
                pagination.booksForCart.currentPage--;
                renderBooksForCart(false);
            });
            if (elements.booksForCartNextPage) elements.booksForCartNextPage.addEventListener('click', () => {
                pagination.booksForCart.currentPage++;
                renderBooksForCart(false);
            });
            if (elements.clearCartBtn) elements.clearCartBtn.addEventListener('click', () => clearCart(false));
            if (elements.checkoutBtn) elements.checkoutBtn.addEventListener('click', openCheckoutModal);
            if (elements.applyPromoBtn) elements.applyPromoBtn.addEventListener('click', () => applyPromotion(false));
            if (elements.checkoutForm) elements.checkoutForm.addEventListener('submit', (e) => {
                elements.checkoutCustomerIdInput.value = elements.checkoutCustomer.value;
                elements.checkoutPromotionCodeInput.value = elements.checkoutPromotionCode.value;
                elements.checkoutCartItemsInput.value = JSON.stringify(currentCart);
            });
            if (elements.checkoutModal) elements.checkoutModal.addEventListener('focusout', (e) => {
                if (!elements.checkoutModal.contains(e.relatedTarget)) {
                    renderCart(false);
                }
            });
            if (elements.viewSalesHistoryBtn) elements.viewSalesHistoryBtn.addEventListener('click', () => window.location.href = 'index.php?page=sales-history');
            if (elements.backToCartBtn) elements.backToCartBtn.addEventListener('click', () => window.location.href = 'index.php?page=cart');
            if (elements.saleSearch) elements.saleSearch.addEventListener('input', () => {
                pagination.sales.currentPage = 1;
                renderSalesHistory();
            });
            if (elements.salesPrevPage) elements.salesPrevPage.addEventListener('click', () => {
                pagination.sales.currentPage--;
                renderSalesHistory();
            });
            if (elements.salesNextPage) elements.salesNextPage.addEventListener('click', () => {
                pagination.sales.currentPage++;
                renderSalesHistory();
            });
            if (elements.exportSalesBtn) elements.exportSalesBtn.addEventListener('click', exportSales);
            if (elements.downloadReceiptBtn) elements.downloadReceiptBtn.addEventListener('click', downloadReceiptPdf);
            if (elements.addPromotionBtn) elements.addPromotionBtn.addEventListener('click', () => openPromotionModal());
            if (elements.promotionTypePercentage) elements.promotionTypePercentage.addEventListener('click', () => {
                elements.promotionType.value = 'percentage';
                elements.promotionTypePercentage.classList.add('active');
                elements.promotionTypeFixed.classList.remove('active');
            });
            if (elements.promotionTypeFixed) elements.promotionTypeFixed.addEventListener('click', () => {
                elements.promotionType.value = 'fixed';
                elements.promotionTypeFixed.classList.add('active');
                elements.promotionTypePercentage.classList.remove('active');
            });
            if (elements.promotionAppliesTo) elements.promotionAppliesTo.addEventListener('change', () => {
                elements.promotionBookGroup.style.display = 'none';
                elements.promotionCategoryGroup.style.display = 'none';
                if (elements.promotionAppliesTo.value === 'specific-book') {
                    elements.promotionBookGroup.style.display = 'block';
                } else if (elements.promotionAppliesTo.value === 'specific-category') {
                    elements.promotionCategoryGroup.style.display = 'block';
                }
            });
            if (elements.addExpenseBtn) elements.addExpenseBtn.addEventListener('click', () => openExpenseModal());
            if (elements.expenseSearch) elements.expenseSearch.addEventListener('input', () => {
                pagination.expenses.currentPage = 1;
                renderExpenses();
            });
            if (elements.expenseMonthFilter) elements.expenseMonthFilter.addEventListener('change', () => {
                pagination.expenses.currentPage = 1;
                renderExpenses();
            });
            if (elements.expensesPrevPage) elements.expensesPrevPage.addEventListener('click', () => {
                pagination.expenses.currentPage--;
                renderExpenses();
            });
            if (elements.expensesNextPage) elements.expensesNextPage.addEventListener('click', () => {
                pagination.expenses.currentPage++;
                renderExpenses();
            });
            if (elements.reportType) elements.reportType.addEventListener('change', updateReportFilters);
            if (elements.generateReportBtn) elements.generateReportBtn.addEventListener('click', generateReport);
            if (elements.exportCurrentReportBtn) elements.exportCurrentReportBtn.addEventListener('click', exportCurrentReport);
            if (elements.importFile) elements.importFile.addEventListener('change', () => {
                elements.importAllDataBtn.disabled = !elements.importFile.files.length;
            });
            if (elements.publicBookSearch) elements.publicBookSearch.addEventListener('input', () => {
                pagination.publicBooks.currentPage = 1;
                renderPublicBooks();
            });
            if (elements.publicProductTypeFilter) elements.publicProductTypeFilter.addEventListener('change', () => {
                pagination.publicBooks.currentPage = 1;
                renderPublicBooks();
            });
            if (elements.publicBookCategoryFilter) elements.publicBookCategoryFilter.addEventListener('change', () => {
                pagination.publicBooks.currentPage = 1;
                renderPublicBooks();
            });
            if (elements.publicBookSort) elements.publicBookSort.addEventListener('change', () => {
                pagination.publicBooks.currentPage = 1;
                renderPublicBooks();
            });
            if (elements.publicBooksPrevPage) elements.publicBooksPrevPage.addEventListener('click', () => {
                pagination.publicBooks.currentPage--;
                renderPublicBooks();
            });
            if (elements.publicBooksNextPage) elements.publicBooksNextPage.addEventListener('click', () => {
                pagination.publicBooks.currentPage++;
                renderPublicBooks();
            });
            if (elements.onlineOrderSearch) elements.onlineOrderSearch.addEventListener('input', () => {
                pagination.onlineOrders.currentPage = 1;
                renderOnlineOrders(false);
            });
            if (elements.onlineOrderStatusFilter) elements.onlineOrderStatusFilter.addEventListener('change', () => {
                pagination.onlineOrders.currentPage = 1;
                renderOnlineOrders(false);
            });
            if (elements.onlineOrdersPrevPage) elements.onlineOrdersPrevPage.addEventListener('click', () => {
                pagination.onlineOrders.currentPage--;
                renderOnlineOrders(false);
            });
            if (elements.onlineOrdersNextPage) elements.onlineOrdersNextPage.addEventListener('click', () => {
                pagination.onlineOrders.currentPage++;
                renderOnlineOrders(false);
            });
            if (elements.onlineClearCartBtn) elements.onlineClearCartBtn.addEventListener('click', () => clearCart(true));
            if (elements.placeOnlineOrderBtn) elements.placeOnlineOrderBtn.addEventListener('click', openOnlineOrderModal);
            if (elements.onlineApplyPromoBtn) elements.onlineApplyPromoBtn.addEventListener('click', () => applyPromotion(true));
            if (elements.onlineOrderForm) elements.onlineOrderForm.addEventListener('submit', (e) => {
                elements.onlineOrderCartItemsInput.value = JSON.stringify(currentCart);
            });
        }
        document.addEventListener('DOMContentLoaded', async () => {
            currentCurrencySymbol = "<?php echo html($public_settings['currency_symbol'] ?? 'PKR '); ?>";
            if (elements.booksPagination) {
                pagination.books.elements = {
                    prev: elements.booksPrevPage,
                    next: elements.booksNextPage,
                    info: elements.booksPageInfo
                };
            }
            if (elements.customersPagination) {
                pagination.customers.elements = {
                    prev: elements.customersPrevPage,
                    next: elements.customersNextPage,
                    info: elements.customersPageInfo
                };
            }
            if (elements.suppliersPagination) {
                pagination.suppliers.elements = {
                    prev: elements.suppliersPrevPage,
                    next: elements.suppliersNextPage,
                    info: elements.suppliersPageInfo
                };
            }
            if (elements.posPagination) {
                pagination.purchaseOrders.elements = {
                    prev: elements.posPrevPage,
                    next: elements.posNextPage,
                    info: elements.posPageInfo
                };
            }
            if (elements.booksForCartPagination) {
                pagination.booksForCart.elements = {
                    prev: elements.booksForCartPrevPage,
                    next: elements.booksForCartNextPage,
                    info: elements.booksForCartPageInfo
                };
            }
            if (elements.salesPagination) {
                pagination.sales.elements = {
                    prev: elements.salesPrevPage,
                    next: elements.salesNextPage,
                    info: elements.salesPageInfo
                };
            }
            if (elements.expensesPagination) {
                pagination.expenses.elements = {
                    prev: elements.expensesPrevPage,
                    next: elements.expensesNextPage,
                    info: elements.expensesPageInfo
                };
            }
            if (elements.publicBooksPagination) {
                pagination.publicBooks.elements = {
                    prev: elements.publicBooksPrevPage,
                    next: elements.publicBooksNextPage,
                    info: elements.publicBooksPageInfo
                };
            }
            if (elements.onlineOrdersPagination) {
                pagination.onlineOrders.elements = {
                    prev: elements.onlineOrdersPrevPage,
                    next: elements.onlineOrdersNextPage,
                    info: elements.onlineOrdersPageInfo
                };
            }
            setupEventListeners();
            const initialToastData = document.getElementById('initial-toast-data');
            if (initialToastData) {
                showToast(initialToastData.dataset.message, initialToastData.dataset.type);
            }
            const currentPage = "<?php echo $page; ?>";
            if (currentPage === 'dashboard') {
                await updateDashboard();
            } else if (currentPage === 'users') {
                await fetchUsersAndRoles();
            } else if (currentPage === 'live-sales') {
                await fetchLiveSales();
                liveSalesInterval = setInterval(fetchLiveSales, 5000);
            } else if (currentPage === 'books') {
                await renderBooks();
            } else if (currentPage === 'customers') {
                await renderCustomers();
            } else if (currentPage === 'suppliers') {
                await renderSuppliers();
            } else if (currentPage === 'purchase-orders') {
                await renderPurchaseOrders();
            } else if (currentPage === 'cart') {
                await renderBooksForCart(false);
                await renderCart(false);
                const lastSaleId = "<?php echo $_SESSION['last_sale_id'] ?? '';
unset($_SESSION['last_sale_id']); ?>";
                if (lastSaleId) {
                    const data = await fetchJSON(`index.php?action=get_sale_details_json&sale_id=${lastSaleId}`);
                    if (data.success) {
                        openReceiptModal(data.sale);
                    }
                }
            } else if (currentPage === 'sales-history') {
                await renderSalesHistory();
            } else if (currentPage === 'online-orders') {
                await renderOnlineOrders(false);
            } else if (currentPage === 'promotions') {
                await renderPromotions();
            } else if (currentPage === 'expenses') {
                await renderExpenses();
            } else if (currentPage === 'reports') {
                updateReportFilters();
            } else if (currentPage === 'customer-dashboard') {
                fetchJSON('index.php?action=get_customer_details_json')
                    .then(data => {
                        if (data.success) {
                            const detailsDiv = document.getElementById('customer-profile-details');
                            const c = data.customer;
                            detailsDiv.innerHTML = `
                    <p><strong>Name:</strong> ${html(c.name)}</p>
                    <p><strong>Email:</strong> ${html(c.email)}</p>
                    <p><strong>Phone:</strong> ${html(c.phone)}</p>
                    <p><strong>Address:</strong> ${html(c.address || 'Not provided')}</p>
                `;
                        }
                    });
                fetchJSON('index.php?action=get_customer_history_json')
                    .then(data => {
                        if (data.success) {
                            const historyList = document.getElementById('customer-dashboard-history-list');
                            historyList.innerHTML = data.sales.length > 0 ? data.sales.map(sale => `
                    <tr>
                        <td>${html(sale.id)}</td>
                        <td>${formatDate(sale.sale_date)}</td>
                        <td>${html(sale.item_names)}</td>
                        <td>${formatCurrency(sale.total)}</td>
                    </tr>
                `).join('') : `<tr><td colspan="4">You have no past purchases.</td></tr>`;
                        }
                    });
                await renderOnlineOrders(true);
            } else if (currentPage === 'online-shop-cart') {
                await renderCart(true);
            } else if (currentPage === 'my-orders') {
                await renderOnlineOrders(true);
            }
            if (currentPage === 'books-public' || currentPage === 'home') {
                await renderPublicBooks();
            }
            async function openGlobalSearchTarget() {
                if (!window.location.hash) return;
                const hash = window.location.hash;
                if (hash.startsWith('#edit-')) {
                    const id = hash.split('-')[1];
                    if (currentPage === 'books' && elements.addBookBtn) {
                        await openBookModal(id);
                    }
                } else if (hash.startsWith('#view-')) {
                    const id = hash.split('-')[1];
                    if (currentPage === 'customers' && elements.addCustomerBtn) {
                        const customerData = await fetchJSON(`index.php?action=get_customers_json&customer_id=${id}`);
                        if (customerData.success && customerData.customers.length > 0) {
                            viewCustomerHistory(id, customerData.customers[0].name);
                        } else {
                            showToast('Customer not found.', 'error');
                        }
                    }
                } else if (hash.startsWith('#sale-')) {
                    const id = hash.split('-')[1];
                    if (currentPage === 'sales-history') {
                        viewSaleDetails(id);
                    }
                }
            }

            await openGlobalSearchTarget();

            window.addEventListener('hashchange', function () {
                openGlobalSearchTarget();
            });
        });
    </script>

    <script id="minimalist-mobile-ux">
        (function () {
            function applyResponsiveTableLabels(root) {
                (root || document).querySelectorAll('.data-table').forEach(function (table) {
                    var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
                        return th.textContent.trim();
                    });
                    table.querySelectorAll('tbody tr').forEach(function (row) {
                        Array.prototype.forEach.call(row.children, function (cell, index) {
                            if (headers[index] && !cell.getAttribute('data-label')) {
                                cell.setAttribute('data-label', headers[index]);
                            }
                        });
                    });
                });
            }

            function ensureBackdrop() {
                if (document.querySelector('.sidebar-backdrop')) return;
                var backdrop = document.createElement('div');
                backdrop.className = 'sidebar-backdrop';
                backdrop.addEventListener('click', function () {
                    closeSidebar();
                });
                document.body.appendChild(backdrop);
            }

            function closeSidebar() {
                var sidebar = document.querySelector('aside.sidebar');
                if (!sidebar) return;
                sidebar.classList.remove('active');
                document.body.classList.remove('sidebar-open');
            }

            function syncSidebarState() {
                var sidebar = document.querySelector('aside.sidebar');
                if (!sidebar) return;
                if (window.innerWidth > 900) {
                    document.body.classList.remove('sidebar-open');
                    sidebar.classList.remove('active');
                } else {
                    document.body.classList.toggle('sidebar-open', sidebar.classList.contains('active'));
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                ensureBackdrop();
                applyResponsiveTableLabels(document);

                var sidebar = document.querySelector('aside.sidebar');
                var hamburger = document.getElementById('hamburger-menu');
                if (sidebar && hamburger) {
                    hamburger.addEventListener('click', function () {
                        setTimeout(syncSidebarState, 0);
                    });
                    document.querySelectorAll('aside.sidebar nav a').forEach(function (link) {
                        link.addEventListener('click', function () {
                            if (window.innerWidth <= 900) {
                                closeSidebar();
                            }
                        });
                    });
                }

                var observer = new MutationObserver(function () {
                    applyResponsiveTableLabels(document);
                });
                observer.observe(document.body, { childList: true, subtree: true });

                var contactForm = document.getElementById('contact-message-form');
                if (contactForm) {
                    contactForm.addEventListener('submit', function (event) {
                        event.preventDefault();
                        if (typeof showToast === 'function') {
                            showToast('Thank you for your message! We will get back to you soon.', 'success');
                        }
                        contactForm.reset();
                    });
                }

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeSidebar();
                    }
                });

                window.addEventListener('resize', syncSidebarState);
                syncSidebarState();
            });
        })();
    </script>
    <script id="advanced-sales-enhancements">
        (function () {
            const sidebarStateKey = 'bookshop_sidebar_state_v2';
            let scannerInstance = null;
            let scannerTargetHandler = null;
            let publicSaleCart = [];
            function qs(sel, root) { return (root || document).querySelector(sel); }
            function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
            function formatMoney(value) {
                const num = Number(value || 0);
                return (window.currentCurrencySymbol || 'PKR ') + num.toFixed(2);
            }
            function showAppToast(message, type) {
                if (typeof window.showToast === 'function') {
                    window.showToast(message, type || 'info');
                }
            }
            function cloneHamburger() {
                const oldBtn = document.getElementById('hamburger-menu');
                if (!oldBtn) return null;
                const newBtn = oldBtn.cloneNode(true);
                oldBtn.parentNode.replaceChild(newBtn, oldBtn);
                return newBtn;
            }
            function applySidebarState() {
                const sidebar = document.querySelector('aside.sidebar');
                if (!sidebar) return;
                const saved = localStorage.getItem(sidebarStateKey) || 'expanded';
                if (window.innerWidth <= 900) {
                    document.body.classList.remove('sidebar-collapsed');
                    document.body.classList.remove('sidebar-open');
                    sidebar.classList.remove('active');
                } else {
                    document.body.classList.remove('sidebar-open');
                    sidebar.classList.remove('active');
                    document.body.classList.toggle('sidebar-collapsed', saved === 'collapsed');
                }
            }
            function toggleSidebarState() {
                const sidebar = document.querySelector('aside.sidebar');
                if (!sidebar) return;
                if (window.innerWidth <= 900) {
                    const opening = !sidebar.classList.contains('active');
                    sidebar.classList.toggle('active', opening);
                    document.body.classList.toggle('sidebar-open', opening);
                    localStorage.setItem(sidebarStateKey, opening ? 'mobile-open' : 'mobile-closed');
                } else {
                    const collapse = !document.body.classList.contains('sidebar-collapsed');
                    document.body.classList.toggle('sidebar-collapsed', collapse);
                    localStorage.setItem(sidebarStateKey, collapse ? 'collapsed' : 'expanded');
                }
            }
            function closeMobileSidebar() {
                const sidebar = document.querySelector('aside.sidebar');
                if (!sidebar || window.innerWidth > 900) return;
                sidebar.classList.remove('active');
                document.body.classList.remove('sidebar-open');
                localStorage.setItem(sidebarStateKey, 'mobile-closed');
            }
            function setupSidebar() {
                const btn = cloneHamburger();
                if (btn) {
                    btn.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleSidebarState();
                    });
                }
                qsa('aside.sidebar nav a').forEach(function (link) {
                    link.addEventListener('click', closeMobileSidebar);
                });
                window.addEventListener('resize', applySidebarState);
                applySidebarState();
            }
            function ensureBarcodeModal() {
                if (document.getElementById('barcode-scanner-modal')) return;
                const modal = document.createElement('div');
                modal.id = 'barcode-scanner-modal';
                modal.className = 'modal-overlay';
                modal.innerHTML = `
                    <div class="modal-content" style="max-width:700px;">
                        <div class="modal-header">
                            <h3>Scan Barcode</h3>
                            <button type="button" class="modal-close" data-close-barcode-modal><i class="fas fa-times"></i></button>
                        </div>
                        <div id="barcode-scanner-reader" style="width:100%; min-height:320px; border-radius:18px; overflow:hidden; background:#111827;"></div>
                        <div class="form-group" style="margin-top:16px;">
                            <label for="barcode-scanner-manual">Manual Barcode</label>
                            <div class="inline-input-group">
                                <input type="text" id="barcode-scanner-manual" placeholder="Type barcode manually">
                                <button type="button" class="btn btn-primary" id="barcode-scanner-manual-submit">Use Barcode</button>
                            </div>
                        </div>
                    </div>`;
                document.body.appendChild(modal);
                modal.addEventListener('click', function (event) {
                    if (event.target === modal || event.target.closest('[data-close-barcode-modal]')) {
                        stopBarcodeScanner();
                        modal.classList.remove('active');
                    }
                });
                qs('#barcode-scanner-manual-submit', modal).addEventListener('click', function () {
                    const value = qs('#barcode-scanner-manual', modal).value.trim();
                    if (!value || !scannerTargetHandler) return;
                    scannerTargetHandler(value);
                    stopBarcodeScanner();
                    modal.classList.remove('active');
                });
            }
            function stopBarcodeScanner() {
                try {
                    if (scannerInstance && typeof scannerInstance.stop === 'function') {
                        scannerInstance.stop().catch(function () {});
                    }
                } catch (e) {}
                scannerInstance = null;
                scannerTargetHandler = null;
            }
            function openBarcodeScanner(onDetected) {
                ensureBarcodeModal();
                const modal = document.getElementById('barcode-scanner-modal');
                const readerId = 'barcode-scanner-reader';
                scannerTargetHandler = function (decodedText) {
                    onDetected(decodedText);
                };
                modal.classList.add('active');
                setTimeout(function () { window.location.href = 'index.php?page=public-sale&token=' + encodeURIComponent(token); }, 8 * 60 * 60 * 1000);
                if (window.Html5Qrcode) {
                    stopBarcodeScanner();
                    scannerInstance = new Html5Qrcode(readerId);
                    scannerInstance.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 250, height: 120 } }, function (decodedText) {
                        if (!scannerTargetHandler) return;
                        scannerTargetHandler(decodedText);
                        stopBarcodeScanner();
                        modal.classList.remove('active');
                    }, function () {}).catch(function () {
                        showAppToast('Camera could not start. Use manual barcode entry.', 'warning');
                    });
                }
            }
            async function fetchBookByBarcode(barcode, token) {
                const url = `index.php?action=get_book_by_barcode_json&barcode=${encodeURIComponent(barcode)}${token ? `&token=${encodeURIComponent(token)}` : ''}`;
                const response = await fetch(url);
                return response.json();
            }
            function printBarcodeLabel(payload) {
                if (!window.JsBarcode) {
                    showAppToast('Barcode printer library failed to load.', 'error');
                    return;
                }
                let copiesStr = prompt("How many copies do you want to print? (Layout is A4, 18 items per page)", "1");
                if (copiesStr === null) return;
                let copies = parseInt(copiesStr);
                if (isNaN(copies) || copies < 1) copies = 1;

                const popup = window.open('', '_blank', 'width=800,height=900');
                if (!popup) {
                    showAppToast('Please allow popups to print barcode labels.', 'warning');
                    return;
                }
                const barcodeValue = payload.barcode || payload.isbn || String(payload.id || '');
                let htmlContent = `<!DOCTYPE html><html><head><title>Print Barcodes</title>
                    <style>
                        @page { size: A4; margin: 0; }
                        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: white; }
                        .page { 
                            width: 210mm; height: 297mm; display: flex; flex-wrap: wrap; align-content: flex-start;
                            page-break-after: always; padding: 10mm; box-sizing: border-box;
                        }
                        .barcode-item {
                            width: calc(100% / 3); height: calc((297mm - 20mm) / 6);
                            box-sizing: border-box; padding: 5mm; text-align: center;
                            display: flex; flex-direction: column; justify-content: center; align-items: center;
                        }
                        .barcode-item svg { max-width: 100%; max-height: 60%; }
                        .b-name { font-size: 13px; font-weight: bold; margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
                        .b-price { font-size: 14px; margin-top: 5px; font-weight: bold; }
                    </style>
                    </head><body>`;

                let pages = Math.ceil(copies / 18);
                let printed = 0;
                let displayPrice = payload.retail_price || payload.price || 0;
                
                for(let p = 0; p < pages; p++) {
                    htmlContent += `<div class="page">`;
                    for(let i = 0; i < 18 && printed < copies; i++) {
                        htmlContent += `<div class="barcode-item">
                                    <div class="b-name">${payload.name || 'Product'}</div>
                                    <svg class="barcode-svg" data-val="${barcodeValue}"></svg>
                                    <div class="b-price">${window.currentCurrencySymbol || 'PKR '} ${Number(displayPrice).toFixed(2)}</div>
                                 </div>`;
                        printed++;
                    }
                    htmlContent += `</div>`;
                }
                
                htmlContent += `<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
                    <script>
                        window.onload = function() {
                            document.querySelectorAll('.barcode-svg').forEach(el => {
                                JsBarcode(el, el.getAttribute('data-val'), {
                                    format: "CODE128", width: 1.5, height: 50, displayValue: true, fontSize: 14, margin: 0
                                });
                            });
                            setTimeout(() => { window.print(); }, 800);
                        }
                    <\/script></body></html>`;

                popup.document.write(htmlContent);
                popup.document.close();
            }
            function enhanceBookForm() {
                const retailInput = document.getElementById('book-retail-price');
                const priceInput = document.getElementById('book-price');
                const wholesaleInput = document.getElementById('book-wholesale-price');
                const barcodeInput = document.getElementById('book-barcode');
                if (!priceInput || !retailInput || !wholesaleInput || !barcodeInput) return;
                if (!retailInput.value && priceInput.value) retailInput.value = priceInput.value;
                priceInput.addEventListener('input', function () {
                    retailInput.value = priceInput.value;
                    if (!wholesaleInput.dataset.touched) wholesaleInput.value = priceInput.value;
                });
                wholesaleInput.addEventListener('input', function () {
                    wholesaleInput.dataset.touched = '1';
                });
                const scanBtn = document.getElementById('scan-book-barcode-btn');
                if (scanBtn && !scanBtn.dataset.bound) {
                    scanBtn.dataset.bound = '1';
                    scanBtn.addEventListener('click', function () {
                        openBarcodeScanner(function (code) {
                            barcodeInput.value = code;
                        });
                    });
                }
                const printBtn = document.getElementById('print-book-barcode-btn');
                if (printBtn && !printBtn.dataset.bound) {
                    printBtn.dataset.bound = '1';
                    printBtn.addEventListener('click', function () {
                        printBarcodeLabel({
                            id: document.getElementById('book-id') ? document.getElementById('book-id').value : '',
                            name: document.getElementById('book-name') ? document.getElementById('book-name').value : 'Product',
                            category: document.getElementById('book-category') ? document.getElementById('book-category').value : '',
                            barcode: barcodeInput.value,
                            price: priceInput.value,
                            retail_price: retailInput.value || priceInput.value,
                            wholesale_price: wholesaleInput.value || priceInput.value
                        });
                    });
                }
            }
            function patchOpenBookModal() {
                if (typeof window.openBookModal !== 'function') return;
                const original = window.openBookModal;
                window.openBookModal = async function (bookId) {
                    await original(bookId);
                    enhanceBookForm();
                    const priceInput = document.getElementById('book-price');
                    const retailInput = document.getElementById('book-retail-price');
                    const wholesaleInput = document.getElementById('book-wholesale-price');
                    const barcodeInput = document.getElementById('book-barcode');
                    if (!bookId) {
                        if (priceInput && retailInput && !retailInput.value) retailInput.value = priceInput.value || '';
                        if (priceInput && wholesaleInput && !wholesaleInput.value) wholesaleInput.value = priceInput.value || '';
                        if (barcodeInput) barcodeInput.value = '';
                        return;
                    }
                    try {
                        const data = await fetch(`index.php?action=get_books_json&book_id=${encodeURIComponent(bookId)}`).then(r => r.json());
                        if (data.success && data.books && data.books[0]) {
                            const book = data.books[0];
                            if (retailInput) retailInput.value = book.retail_price || book.price || '';
                            if (wholesaleInput) wholesaleInput.value = book.wholesale_price || book.price || '';
                            if (barcodeInput) barcodeInput.value = book.barcode || '';
                        }
                    } catch (e) {}
                };
            }
            function patchRenderBooks() {
                if (typeof window.renderBooks !== 'function') return;
                const original = window.renderBooks;
                window.renderBooks = async function () {
                    await original();
                    const search = document.getElementById('book-search');
                    const sort = document.getElementById('book-sort');
                    try {
                        const data = await fetch(`index.php?action=get_books_json&search=${encodeURIComponent(search ? search.value : '')}&sort=${encodeURIComponent(sort ? sort.value : 'name-asc')}&page_num=${window.pagination && window.pagination.books ? window.pagination.books.currentPage : 1}`).then(r => r.json());
                        if (!data.success) return;
                        const rows = qsa('#books-list tr');
                        data.books.forEach(function (book, index) {
                            const row = rows[index];
                            if (!row) return;
                            const nameCell = row.children[1];
                            const priceCell = row.children[5];
                            const actionCell = row.children[7];
                            if (nameCell && !nameCell.querySelector('.barcode-badge')) {
                                const badge = document.createElement('div');
                                badge.className = 'barcode-badge';
                                badge.innerHTML = `<i class="fas fa-barcode"></i> ${html(book.barcode || book.isbn || 'No barcode')}`;
                                nameCell.appendChild(badge);
                            }
                            if (priceCell) {
                                priceCell.innerHTML = `${formatMoney(book.retail_price || book.price)}<div style="font-size:11px;color:var(--light-text-color);margin-top:4px;">WS: ${formatMoney(book.wholesale_price || book.price)}</div>`;
                            }
                            if (actionCell && !actionCell.querySelector('.print-book-barcode-row-btn')) {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'btn btn-secondary btn-sm print-book-barcode-row-btn';
                                btn.innerHTML = '<i class="fas fa-print"></i> Barcode';
                                btn.addEventListener('click', function () { printBarcodeLabel(book); });
                                actionCell.appendChild(btn);
                            }
                        });
                    } catch (e) {}
                };
            }
            function bindPosAndPoBarcodeTools() {
                const posBtn = document.getElementById('scan-pos-barcode-btn');
                if (posBtn && !posBtn.dataset.bound) {
                    posBtn.dataset.bound = '1';
                    posBtn.addEventListener('click', function () {
                        openBarcodeScanner(async function (barcode) {
                            const data = await fetchBookByBarcode(barcode, '');
                            if (data.success && data.book && typeof window.addToCart === 'function') {
                                window.addToCart(Number(data.book.id), data.book.name, Number(data.book.retail_price || data.book.price || 0), false);
                            } else {
                                showAppToast(data.message || 'No product found for that barcode.', 'warning');
                            }
                        });
                    });
                }
                const poBtn = document.getElementById('scan-po-barcode-btn');
                const poInput = document.getElementById('po-barcode-search');
                const poSelect = document.getElementById('po-book-select');
                const poAdd = document.getElementById('add-selected-book-to-po-btn');
                function handlePoBarcode(code) {
                    if (poInput) poInput.value = code;
                    fetchBookByBarcode(code, '').then(function (data) {
                        if (!data.success || !data.book) {
                            showAppToast(data.message || 'No product found for that barcode.', 'warning');
                            return;
                        }
                        if (poSelect) poSelect.value = String(data.book.id);
                        if (poAdd) poAdd.click();
                    });
                }
                if (poBtn && !poBtn.dataset.bound) {
                    poBtn.dataset.bound = '1';
                    poBtn.addEventListener('click', function () { openBarcodeScanner(handlePoBarcode); });
                }
                if (poInput && !poInput.dataset.bound) {
                    poInput.dataset.bound = '1';
                    poInput.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            handlePoBarcode(poInput.value.trim());
                        }
                    });
                }
            }
            function initSidebarProductNavigator() {
                const sidebar = document.querySelector('aside.sidebar');
                if (!sidebar || document.querySelector('.sidebar-product-navigator')) return;
                const container = document.createElement('div');
                container.className = 'sidebar-product-navigator';
                container.innerHTML = `
                    <div class="mini-title">Quick Products</div>
                    <div class="form-group" style="margin-bottom:8px;"><input type="text" id="sidebar-product-search" placeholder="Search products"></div>
                    <div class="sidebar-product-list" id="sidebar-product-list"><div style="font-size:12px;color:var(--light-text-color);">Loading…</div></div>`;
                const userInfo = sidebar.querySelector('.user-info');
                if (userInfo) sidebar.insertBefore(container, userInfo);
                const input = container.querySelector('#sidebar-product-search');
                const list = container.querySelector('#sidebar-product-list');
                async function load() {
                    try {
                        const data = await fetch(`index.php?action=get_sidebar_products_json&search=${encodeURIComponent(input.value.trim())}`).then(r => r.json());
                        if (!data.success) return;
                        list.innerHTML = data.books.slice(0, 12).map(function (book) {
                            return `<div class="sidebar-product-chip" data-book-id="${book.id}" data-book-name="${html(book.name)}" data-book-price="${Number(book.retail_price || book.price || 0)}">
<strong>${html(book.name)}</strong>
<div style="font-size:11px;color:var(--light-text-color);">${html(book.barcode || book.isbn || 'No barcode')} • ${formatMoney(book.display_price || book.retail_price || book.price || 0)}</div>
</div>`;
                        }).join('') || '<div style="font-size:12px;color:var(--light-text-color);">No products found.</div>';
                        qsa('.sidebar-product-chip', list).forEach(function (chip) {
                            chip.addEventListener('click', function () {
                                if (typeof window.quickSell === 'function') {
                                    window.quickSell(Number(chip.dataset.bookId));
                                }
                            });
                            chip.addEventListener('contextmenu', function (e) {
                                e.preventDefault();
                                if (typeof window.openBookModal === 'function') {
                                    window.openBookModal(Number(chip.dataset.bookId));
                                } else {
                                    window.location.href = 'index.php?page=books#edit-' + chip.dataset.bookId;
                                }
                            });
                        });
                    } catch (e) {}
                }
                input.addEventListener('input', load);
                load();
            }
            function initSecureLinkManager() {
                const addBtn = document.getElementById('add-public-sale-link-btn');
                const modal = document.getElementById('public-sale-link-modal');
                const form = document.getElementById('public-sale-link-form');
                if (!addBtn || !modal || !form) return;
                function openModal(data) {
                    form.reset();
                    qs('#public-sale-link-id').value = data && data.id ? data.id : '';
                    qs('#public-sale-link-name').value = data && data.name ? data.name : '';
                    qs('#public-sale-link-mode').value = data && data.mode ? data.mode : 'retail';
                    qs('#public-sale-link-notes').value = data && data.notes ? data.notes : '';
                    qs('#public-sale-link-active').checked = !data || String(data.active) !== '0';
                    qs('#public-sale-link-password').value = '';
                    qs('#public-sale-link-modal-title').textContent = data && data.id ? 'Edit Secure Sale Link' : 'Create Secure Sale Link';
                    modal.classList.add('active');
                }
                addBtn.addEventListener('click', function () { openModal(null); });
                qsa('.edit-public-sale-link-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        openModal({
                            id: btn.dataset.id,
                            name: btn.dataset.name,
                            mode: btn.dataset.mode,
                            notes: btn.dataset.notes,
                            active: btn.dataset.active
                        });
                    });
                });
                qsa('.copy-secure-link-btn').forEach(function (btn) {
                    btn.addEventListener('click', async function () {
                        try {
                            await navigator.clipboard.writeText(btn.dataset.link);
                            showAppToast('Secure link copied.', 'success');
                        } catch (e) {
                            showAppToast('Could not copy the link.', 'warning');
                        }
                    });
                });
            }
            function initPublicSalePage() {
                const page = document.getElementById('public-sale-page');
                if (!page || page.dataset.access !== '1') return;
                const token = page.dataset.token;
                const rateMode = page.dataset.priceMode || 'retail';
                const list = document.getElementById('public-sale-sidebar-products');
                const cartTable = document.getElementById('public-sale-cart-items');
                const cartTotal = document.getElementById('public-sale-grand-total');
                const cartInput = document.getElementById('public-sale-cart-input');
                const search = document.getElementById('public-sale-sidebar-search');
                const category = document.getElementById('public-sale-sidebar-category');
                const type = document.getElementById('public-sale-sidebar-type');
                const scannerStatus = document.getElementById('public-sale-scanner-status');
                const manualInput = document.getElementById('public-sale-manual-barcode');
                const lastSaleId = Number(page.dataset.lastSaleId || 0);
                function savePublicCart() {
                    sessionStorage.setItem('public_sale_cart_' + token, JSON.stringify(publicSaleCart));
                }
                function loadPublicCart() {
                    try {
                        const raw = sessionStorage.getItem('public_sale_cart_' + token);
                        publicSaleCart = raw ? JSON.parse(raw) : [];
                    } catch (e) {
                        publicSaleCart = [];
                    }
                }
                function updatePublicCartView() {
                    let total = 0;
                    if (!publicSaleCart.length) {
                        cartTable.innerHTML = '<tr><td colspan="6">No products scanned yet.</td></tr>';
                    } else {
                        cartTable.innerHTML = publicSaleCart.map(function (item, index) {
                            const subtotal = Number(item.price) * Number(item.quantity);
                            total += subtotal;
                            return `<tr>
<td><strong>${html(item.name)}</strong><div style="font-size:11px;color:var(--light-text-color);">${html(item.category || '')}</div></td>
<td>${html(item.barcode || '-')}</td>
<td>${formatMoney(item.price)}</td>
<td><input type="number" min="1" value="${Number(item.quantity)}" data-public-sale-qty="${index}" style="width:82px;"></td>
<td>${formatMoney(subtotal)}</td>
<td><button type="button" class="btn btn-danger btn-sm" data-public-sale-remove="${index}"><i class="fas fa-trash"></i></button></td>
</tr>`;
                        }).join('');
                    }
                    cartTotal.textContent = formatMoney(total);
                    cartInput.value = JSON.stringify(publicSaleCart.map(function (item) { return { bookId: item.bookId, quantity: item.quantity }; }));
                    savePublicCart();
                    qsa('[data-public-sale-remove]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            publicSaleCart.splice(Number(btn.dataset.publicSaleRemove), 1);
                            updatePublicCartView();
                        });
                    });
                    qsa('[data-public-sale-qty]').forEach(function (input) {
                        input.addEventListener('input', function () {
                            const idx = Number(input.dataset.publicSaleQty);
                            const value = Math.max(1, Number(input.value || 1));
                            publicSaleCart[idx].quantity = value;
                            updatePublicCartView();
                        });
                    });
                }
                function addPublicSaleItem(book) {
                    const price = Number(book.link_price || book.display_price || (rateMode === 'wholesale' ? (book.wholesale_price || book.retail_price || book.price) : (book.retail_price || book.price)) || 0);
                    const existing = publicSaleCart.find(function (item) { return Number(item.bookId) === Number(book.id); });
                    if (existing) {
                        existing.quantity += 1;
                    } else {
                        publicSaleCart.push({
                            bookId: Number(book.id),
                            name: book.name,
                            barcode: book.barcode || book.isbn || '',
                            price: price,
                            quantity: 1,
                            category: book.category || ''
                        });
                    }
                    updatePublicCartView();
                    if (scannerStatus) scannerStatus.textContent = 'Added ' + book.name;
                }
                async function loadSidebarProducts() {
                    try {
                        const data = await fetch(`index.php?action=get_sidebar_products_json&token=${encodeURIComponent(token)}&search=${encodeURIComponent(search.value)}&category=${encodeURIComponent(category.value)}&product_type=${encodeURIComponent(type.value)}`).then(r => r.json());
                        if (!data.success) return;
                        if (category && category.options.length <= 1) {
                            data.categories.forEach(function (cat) {
                                const opt = document.createElement('option');
                                opt.value = cat;
                                opt.textContent = cat;
                                category.appendChild(opt);
                            });
                        }
                        list.innerHTML = data.books.map(function (book) {
                            return `<div class="public-sale-product-item" data-public-sale-book='${JSON.stringify(book).replace(/'/g, '&apos;')}'>
<strong>${html(book.name)}</strong>
<div style="font-size:12px;color:var(--light-text-color);">${html(book.barcode || book.isbn || 'No barcode')} • ${html(book.category || '')}</div>
<div style="margin-top:6px;font-weight:700;">${formatMoney(book.display_price)}</div>
</div>`;
                        }).join('') || '<div style="font-size:12px;color:var(--light-text-color);">No products found.</div>';
                        qsa('.public-sale-product-item', list).forEach(function (card) {
                            card.addEventListener('click', function () {
                                const data = JSON.parse(card.getAttribute('data-public-sale-book').replace(/&apos;/g, "'"));
                                addPublicSaleItem(data);
                            });
                        });
                    } catch (e) {}
                }
                async function processBarcode(code) {
                    if (!code) return;
                    try {
                        const data = await fetchBookByBarcode(code, token);
                        if (data.success && data.book) {
                            addPublicSaleItem(data.book);
                            if (manualInput) manualInput.value = '';
                        } else {
                            showAppToast(data.message || 'No product found for this barcode.', 'warning');
                            if (scannerStatus) scannerStatus.textContent = 'No match for ' + code;
                        }
                    } catch (e) {
                        showAppToast('Barcode lookup failed.', 'error');
                    }
                }
                search.addEventListener('input', loadSidebarProducts);
                category.addEventListener('change', loadSidebarProducts);
                type.addEventListener('change', loadSidebarProducts);
                document.getElementById('public-sale-barcode-submit').addEventListener('click', function () { processBarcode(manualInput.value.trim()); });
                manualInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        processBarcode(manualInput.value.trim());
                    }
                });
                document.getElementById('public-sale-clear-cart').addEventListener('click', function () {
                    publicSaleCart = [];
                    updatePublicCartView();
                });
                document.getElementById('public-sale-submit-form').addEventListener('submit', function (event) {
                    if (!publicSaleCart.length) {
                        event.preventDefault();
                        showAppToast('Scan at least one product first.', 'warning');
                    }
                });
                loadPublicCart();
                updatePublicCartView();
                loadSidebarProducts();
                setTimeout(function () { window.location.href = 'index.php?page=public-sale&token=' + encodeURIComponent(token); }, 8 * 60 * 60 * 1000);
                if (window.Html5Qrcode) {
                    const scanner = new Html5Qrcode('public-sale-scanner');
                    scanner.start({ facingMode: 'environment' }, { fps: 12, qrbox: { width: 260, height: 120 } }, function (decodedText) {
                        processBarcode(decodedText);
                    }, function () {}).then(function () {
                        if (scannerStatus) scannerStatus.textContent = 'Live scanner running';
                    }).catch(function () {
                        if (scannerStatus) scannerStatus.textContent = 'Camera unavailable. Use manual barcode entry.';
                    });
                    window.addEventListener('beforeunload', function () {
                        try { scanner.stop(); } catch (e) {}
                    });
                }
                if (lastSaleId && typeof window.fetchJSON === 'function' && typeof window.openReceiptModal === 'function') {
                    window.fetchJSON(`index.php?action=get_sale_details_json&sale_id=${lastSaleId}&token=${encodeURIComponent(token)}`).then(function (data) {
                        if (data.success && data.sale) {
                            window.openReceiptModal(data.sale);
                            sessionStorage.removeItem('public_sale_cart_' + token);
                            publicSaleCart = [];
                            updatePublicCartView();
                        }
                    });
                }
            }
            document.addEventListener('DOMContentLoaded', function () {
                setupSidebar();
                ensureBarcodeModal();
                patchOpenBookModal();
                patchRenderBooks();
                enhanceBookForm();
                const currentPage = '<?php echo $page; ?>';
                if (currentPage === 'books' && typeof window.renderBooks === 'function') { window.renderBooks(); }
                bindPosAndPoBarcodeTools();
                initSidebarProductNavigator();
                initSecureLinkManager();
                initPublicSalePage();
            });
        })();
    </script>

</body>

</html>