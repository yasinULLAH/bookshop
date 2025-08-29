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
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
function html($text)
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}
function redirect($page, $params = [])
{
    $queryString = http_build_query($params);
    header("Location: index.php?page=$page" . ($queryString ? "&$queryString" : ""));
    exit();
}
function isAdmin()
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
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
function format_currency($amount)
{
    return 'PKR ' . number_format($amount, 2);
}
function format_date($timestamp)
{
    return date('M d, Y h:i A', is_numeric($timestamp) ? $timestamp : strtotime($timestamp));
}
function format_short_date($timestamp)
{
    return date('Y-m-d', is_numeric($timestamp) ? $timestamp : strtotime($timestamp));
}
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'logout') {
        session_destroy();
        session_start();
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'You have been logged out.'];
        redirect('login');
    }
    if (in_array($_GET['action'], ['get_public_books_json'])) {
    } elseif (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    header('Content-Type: application/json');
    $action = $_GET['action'];
    switch ($action) {
        case 'get_books_json':
            $book_id = $_GET['book_id'] ?? null;
            $page = $_GET['page_num'] ?? 1;
            $search = $_GET['search'] ?? '';
            $sort = $_GET['sort'] ?? 'title-asc';
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;
            $where_clauses = [];
            $params = [];
            $types = '';
            if ($book_id) {
                $where_clauses[] = "id = ?";
                $params[] = $book_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ?)";
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
                $types .= 'ssss';
            }
            $order_by = '';
            switch ($sort) {
                case 'title-asc':
                    $order_by = 'title ASC';
                    break;
                case 'title-desc':
                    $order_by = 'title DESC';
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
            $sort = $_GET['sort'] ?? 'title-asc';
            $limit = $_GET['limit'] ?? 12;
            $offset = ($page - 1) * $limit;
            $where_clauses = ["stock > 0"];
            $params = [];
            $types = '';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(title LIKE ? OR author LIKE ? OR isbn LIKE ? OR description LIKE ?)";
                $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
                $types .= 'ssss';
            }
            if ($category !== 'all') {
                $where_clauses[] = "category = ?";
                $params[] = $category;
                $types .= 's';
            }
            $order_by = '';
            switch ($sort) {
                case 'title-asc':
                    $order_by = 'title ASC';
                    break;
                case 'title-desc':
                    $order_by = 'title DESC';
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
            $sql = "SELECT id, title, author, category, isbn, price, stock, description, cover_image FROM books $where_sql ORDER BY $order_by LIMIT ? OFFSET ?";
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
                $where_clauses[] = "id = ?";
                $params[] = $customer_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            if ($status !== 'all') {
                $where_clauses[] = "is_active = ?";
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
            $sql = "SELECT * FROM customers $where_sql ORDER BY name ASC LIMIT ? OFFSET ?";
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
                $where_clauses[] = "id = ?";
                $params[] = $supplier_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)";
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
                $where_clauses[] = "po.id = ?";
                $params[] = $po_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(po.id LIKE ? OR s.name LIKE ? OR po.status LIKE ?)";
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            if ($status !== 'all') {
                $where_clauses[] = "po.status = ?";
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
                $po_items_stmt = $conn->prepare("SELECT poi.*, b.title FROM po_items poi JOIN books b ON poi.book_id = b.id WHERE poi.po_id = ?");
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
            $where_clauses = ["stock > 0"];
            $params = [];
            $types = '';
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
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
            $sql = "SELECT * FROM books $where_sql ORDER BY title ASC LIMIT ? OFFSET ?";
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
                $where_clauses[] = "(s.id LIKE ? OR c.name LIKE ? OR b.title LIKE ?)";
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
                $types .= 'sss';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $join_sql = "LEFT JOIN customers c ON s.customer_id = c.id LEFT JOIN sale_items si ON s.id = si.sale_id LEFT JOIN books b ON si.book_id = b.id";
            $group_by = "GROUP BY s.id";
            $stmt = $conn->prepare("SELECT COUNT(DISTINCT s.id) AS total FROM sales s $join_sql $where_sql");
            if ($types) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total_items = $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            $sql = "SELECT s.*, c.name AS customer_name,
                    GROUP_CONCAT(CONCAT(b.title, ' (', si.quantity, ')') SEPARATOR ', ') AS item_titles
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
            $stmt = $conn->prepare("SELECT name, phone, email, address FROM customers WHERE id = ?");
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
            if (!isAdmin() && !isStaff()) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit();
            }
            $sale_id = $_GET['sale_id'] ?? '';
            $stmt = $conn->prepare("SELECT s.*, c.name AS customer_name 
                                    FROM sales s 
                                    LEFT JOIN customers c ON s.customer_id = c.id 
                                    WHERE s.id = ?");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $sale = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($sale) {
                $stmt_items = $conn->prepare("SELECT si.*, b.title FROM sale_items si JOIN books b ON si.book_id = b.id WHERE si.sale_id = ?");
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
                                    GROUP_CONCAT(CONCAT(b.title, ' (', si.quantity, ')') SEPARATOR ', ') AS item_titles
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
                $where_clauses[] = "p.id = ?";
                $params[] = $promotion_id;
                $types .= 'i';
            }
            $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            $promotions = [];
            $sql = "SELECT p.*, b.title AS book_title, b.author AS book_author 
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
                    if ($row['applies_to'] === 'specific-book' && $row['book_title']) {
                        $row['applies_to_value_title'] = html($row['book_title'] . ' by ' . $row['book_author']);
                    } else if ($row['applies_to'] === 'specific-category' && $row['applies_to_value']) {
                        $row['applies_to_value_title'] = html($row['applies_to_value']);
                    } else if ($row['applies_to'] === 'all') {
                        $row['applies_to_value_title'] = 'Entire Order';
                    } else {
                        $row['applies_to_value_title'] = 'N/A';
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
                $where_clauses[] = "id = ?";
                $params[] = $expense_id;
                $types .= 'i';
            }
            if ($search) {
                $search_term = '%' . $search . '%';
                $where_clauses[] = "(description LIKE ? OR category LIKE ?)";
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
        case 'get_report_data_json':
            if (!isAdmin()) {
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
                    $stmt = $conn->prepare("SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?");
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
                    $report_data['table_html'] = "
                        <tr><td>Date</td><td>" . html($selected_date) . "</td><td></td></tr>
                        <tr><td>Total Sales</td><td>" . format_currency($total_sales) . "</td><td></td></tr>
                        <tr><td>Number of Sales</td><td>" . html($num_sales) . "</td><td></td></tr>
                        <tr><td>Total Items Sold</td><td>" . html($total_items_sold) . "</td><td></td></tr>
                        <tr><td>Total Discount Applied</td><td>" . format_currency($total_discount_applied) . "</td><td></td></tr>
                    ";
                    $report_data['chart_data'] = [
                        'labels' => ['Total Sales', 'Total Discount'],
                        'datasets' => [
                            ['label' => 'Amount (PKR)', 'data' => [$total_sales, $total_discount_applied], 'backgroundColor' => ['#2a9d8f', '#f4a261'], 'borderColor' => ['#2a9d8f', '#f4a261'], 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => "Daily Sales Report for " . html($selected_date)
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
                    $year = (int)substr($selected_month_str, 0, 4);
                    $month = (int)substr($selected_month_str, 5, 2);
                    $first_day_of_month = new DateTime("$year-$month-01");
                    $last_day_of_month = new DateTime("$year-$month-" . $first_day_of_month->format('t'));
                    $week_starts = [];
                    $current_week_start = clone $first_day_of_month;
                    $current_week_start->modify('last sunday');
                    if ($current_week_start > $first_day_of_month && (int)$current_week_start->format('m') === $month && (int)$current_week_start->format('d') > (int)$first_day_of_month->format('d')) {
                        $current_week_start = clone $first_day_of_month;
                    }
                    if ((int)$current_week_start->format('m') < $month) {
                        $current_week_start = clone $first_day_of_month;
                    }
                    while ($current_week_start <= $last_day_of_month || (int)$current_week_start->format('m') === $month) {
                        $week_end = clone $current_week_start;
                        $week_end->modify('+6 days');
                        $week_key = $current_week_start->format('Y-m-d') . ' - ' . $week_end->format('Y-m-d');
                        $week_starts[$week_key] = ['start' => clone $current_week_start, 'end' => clone $week_end, 'total' => 0, 'count' => 0, 'items' => 0];
                        $current_week_start->modify('+7 days');
                    }
                    $sales = [];
                    $stmt = $conn->prepare("SELECT s.total, si.quantity, s.sale_date FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?");
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
                        $html .= "<tr><td>" . html(format_short_date($data['start']->getTimestamp())) . " - " . html(format_short_date($data['end']->getTimestamp())) . "</td><td>" . format_currency($data['total']) . "</td><td>" . html($data['count']) . " sales, " . html($data['items']) . " items</td></tr>";
                        $chart_labels[] = html(format_short_date($data['start']->getTimestamp()));
                        $chart_data_sales[] = $data['total'];
                        $raw_data_array[] = ['Metric' => $key, 'Value' => format_currency($data['total']) . " (" . $data['count'] . " sales, " . $data['items'] . " items)"];
                    }
                    $report_data['table_html'] = $html ?: `<tr><td colspan="3">No weekly sales found for ` . html($selected_month_str) . `.</td></tr>`;
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Weekly Sales (PKR)', 'data' => $chart_data_sales, 'backgroundColor' => 'rgba(42, 157, 143, 0.7)', 'borderColor' => 'rgba(42, 157, 143, 1)', 'borderWidth' => 1, 'fill' => false, 'tension' => 0.3]
                        ],
                        'type' => 'line',
                        'title' => "Weekly Sales Report for " . html($selected_month_str)
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'sales-monthly':
                    $selected_month_str = $_GET['month'] ?? date('Y-m');
                    $year = (int)substr($selected_month_str, 0, 4);
                    $month = (int)substr($selected_month_str, 5, 2);
                    $start_of_month = $selected_month_str . '-01 00:00:00';
                    $end_of_month = date('Y-m-t', strtotime($selected_month_str)) . ' 23:59:59';
                    $stmt = $conn->prepare("SELECT s.total, s.discount, si.quantity FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.sale_date BETWEEN ? AND ?");
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
                    $report_data['table_html'] = "
                        <tr><td>Month</td><td>" . html($selected_month_str) . "</td><td></td></tr>
                        <tr><td>Total Sales</td><td>" . format_currency($total_sales) . "</td><td></td></tr>
                        <tr><td>Number of Sales</td><td>" . html($num_sales) . "</td><td></td></tr>
                        <tr><td>Total Items Sold</td><td>" . html($total_items_sold) . "</td><td></td></tr>
                        <tr><td>Total Discount Applied</td><td>" . format_currency($total_discount_applied) . "</td><td></td></tr>
                    ";
                    $report_data['chart_data'] = [
                        'labels' => ['Total Sales', 'Total Discount'],
                        'datasets' => [
                            ['label' => 'Amount (PKR)', 'data' => [$total_sales, $total_discount_applied], 'backgroundColor' => ['#2a9d8f', '#f4a261'], 'borderColor' => ['#2a9d8f', '#f4a261'], 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => "Monthly Sales Report for " . html($selected_month_str)
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
                    $stmt = $conn->prepare("SELECT b.title, b.author, SUM(si.quantity) AS total_quantity_sold, SUM((si.price_per_unit * si.quantity) - si.discount_per_unit) AS total_revenue 
                                            FROM sale_items si 
                                            JOIN books b ON si.book_id = b.id 
                                            GROUP BY b.id 
                                            ORDER BY total_quantity_sold DESC 
                                            LIMIT 10");
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
                        $html .= "<tr><td>" . html($index + 1) . "</td><td>" . html($book['title']) . "</td><td>" . html($book['total_quantity_sold']) . " units sold, " . format_currency($book['total_revenue']) . " revenue</td></tr>";
                        $chart_labels[] = html($book['title']);
                        $chart_data_sales[] = $book['total_quantity_sold'];
                        $chart_data_revenue[] = $book['total_revenue'];
                        $raw_data_array[] = ['Rank' => $index + 1, 'Title' => $book['title'], 'Units Sold' => $book['total_quantity_sold'], 'Revenue' => format_currency($book['total_revenue'])];
                    }
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No sales data to generate best-selling report.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Units Sold', 'data' => $chart_data_sales, 'backgroundColor' => 'rgba(42, 157, 143, 0.7)', 'borderColor' => 'rgba(42, 157, 143, 1)', 'borderWidth' => 1],
                            ['label' => 'Revenue (PKR)', 'data' => $chart_data_revenue, 'backgroundColor' => 'rgba(244, 162, 97, 0.7)', 'borderColor' => 'rgba(244, 162, 97, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Top 10 Best-Selling Books'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'best-selling-authors':
                    $stmt = $conn->prepare("SELECT b.author, SUM(si.quantity) AS total_quantity_sold, SUM((si.price_per_unit * si.quantity) - si.discount_per_unit) AS total_revenue 
                                            FROM sale_items si 
                                            JOIN books b ON si.book_id = b.id 
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
                        $html .= "<tr><td>" . html($index + 1) . "</td><td>" . html($author['author']) . "</td><td>" . html($author['total_quantity_sold']) . " units sold, " . format_currency($author['total_revenue']) . " revenue</td></tr>";
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
                            ['label' => 'Revenue (PKR)', 'data' => $chart_data_revenue, 'backgroundColor' => 'rgba(244, 162, 97, 0.7)', 'borderColor' => 'rgba(244, 162, 97, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Top 10 Best-Selling Authors'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'low-stock':
                    $stmt = $conn->prepare("SELECT title, author, stock, isbn FROM books WHERE stock < 5 ORDER BY stock ASC");
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
                        $html .= "<tr class='low-stock'><td>" . html($index + 1) . "</td><td>" . html($book['title']) . " (" . html($book['author']) . ")</td><td>" . html($book['stock']) . " in stock</td></tr>";
                        $chart_labels[] = html($book['title']);
                        $chart_data_stock[] = $book['stock'];
                        $raw_data_array[] = ['Rank' => $index + 1, 'Title' => $book['title'], 'Author' => $book['author'], 'Stock' => $book['stock'], 'ISBN' => $book['isbn']];
                    }
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No books currently low in stock.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Stock Quantity', 'data' => $chart_data_stock, 'backgroundColor' => 'rgba(231, 111, 81, 0.7)', 'borderColor' => 'rgba(231, 111, 81, 1)', 'borderWidth' => 1]
                        ],
                        'type' => 'bar',
                        'title' => 'Books Low in Stock (< 5 units)'
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
                case 'expenses-summary':
                    $selected_month_str = $_GET['month'] ?? date('Y-m');
                    $start_of_month = $selected_month_str . '-01 00:00:00';
                    $end_of_month = date('Y-m-t', strtotime($selected_month_str)) . ' 23:59:59';
                    $stmt = $conn->prepare("SELECT category, SUM(amount) AS total_amount FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total_amount DESC");
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
                        $html .= "<tr><td>-</td><td>" . html($expense['category']) . "</td><td>" . format_currency($expense['total_amount']) . "</td></tr>";
                        $chart_labels[] = html($expense['category']);
                        $chart_data_amounts[] = $expense['total_amount'];
                        $raw_data_array[] = ['Metric' => $expense['category'], 'Value' => format_currency($expense['total_amount'])];
                    }
                    $html .= "<tr><td><strong>Total</strong></td><td></td><td><strong>" . format_currency($total_expenses) . "</strong></td></tr>";
                    $report_data['table_html'] = $html ?: '<tr><td colspan="3">No expenses recorded for ' . html($selected_month_str) . '.</td></tr>';
                    $report_data['chart_data'] = [
                        'labels' => $chart_labels,
                        'datasets' => [
                            ['label' => 'Amount (PKR)', 'data' => $chart_data_amounts, 'backgroundColor' => [], 'borderColor' => [], 'borderWidth' => 1]
                        ],
                        'type' => 'pie',
                        'title' => "Expenses Summary for " . html($selected_month_str)
                    ];
                    $report_data['raw_data'] = $raw_data_array;
                    break;
            }
            echo json_encode(['success' => true, 'report_data' => $report_data]);
            exit();
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            exit();
    }
}
if (isset($_POST['action']) && isLoggedIn()) {
    $action = $_POST['action'];
    $message_type = 'error';
    $message = 'An unknown error occurred.';
    switch ($action) {
        case 'login':
            break;
        case 'save_book':
            if (!isAdmin()) {
                $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                redirect('books');
            }
            $book_id = $_POST['book_id'] ?? null;
            $title = $_POST['title'];
            $author = $_POST['author'];
            $category = $_POST['category'];
            $isbn = $_POST['isbn'];
            $publisher = $_POST['publisher'] ?? null;
            $year = $_POST['year'] ?? null;
            $price = $_POST['price'];
            $stock = $_POST['stock'];
            $description = $_POST['description'] ?? null;
            $cover_image_path = $_POST['existing_cover_image'] ?? null;
            if (empty($title) || empty($author) || empty($isbn) || empty($price) || !isset($stock)) {
                $message = 'All required book fields must be filled.';
                break;
            }
            if (!is_numeric($price) || $price < 0) {
                $message = 'Invalid price.';
                break;
            }
            if (!is_numeric($stock) || $stock < 0) {
                $message = 'Invalid stock quantity.';
                break;
            }
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['cover_image']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
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
                $stmt = $conn->prepare("UPDATE books SET title=?, author=?, category=?, isbn=?, publisher=?, year=?, price=?, stock=?, description=?, cover_image=? WHERE id=?");
                $stmt->bind_param("sssssidddsi", $title, $author, $category, $isbn, $publisher, $year, $price, $stock, $description, $cover_image_path, $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Book updated successfully!';
                } else {
                    $message = 'Failed to update book: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO books (title, author, category, isbn, publisher, year, price, stock, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssiddds", $title, $author, $category, $isbn, $publisher, $year, $price, $stock, $description, $cover_image_path);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Book added successfully!';
                } else {
                    $message = 'Failed to add book: ' . $stmt->error;
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
                $sales_check_stmt = $conn->prepare("SELECT COUNT(*) FROM sale_items WHERE book_id = ?");
                $sales_check_stmt->bind_param('i', $book_id);
                $sales_check_stmt->execute();
                $has_sales = $sales_check_stmt->get_result()->fetch_row()[0] > 0;
                $sales_check_stmt->close();
                $po_check_stmt = $conn->prepare("SELECT COUNT(*) FROM po_items WHERE book_id = ?");
                $po_check_stmt->bind_param('i', $book_id);
                $po_check_stmt->execute();
                $has_pos = $po_check_stmt->get_result()->fetch_row()[0] > 0;
                $po_check_stmt->close();
                if ($has_sales || $has_pos) {
                    $message = 'Cannot delete book with existing sales or purchase orders.';
                    break;
                }
                $stmt = $conn->prepare("SELECT cover_image FROM books WHERE id = ?");
                $stmt->bind_param('i', $book_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $book = $result->fetch_assoc();
                $stmt->close();
                if ($book && $book['cover_image'] && file_exists($book['cover_image'])) {
                    unlink($book['cover_image']);
                }
                $stmt = $conn->prepare("DELETE FROM books WHERE id=?");
                $stmt->bind_param("i", $book_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Book deleted successfully!';
                } else {
                    $message = 'Failed to delete book: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Book ID not provided.';
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
            $stmt = $conn->prepare("UPDATE books SET stock = stock + ? WHERE id = ?");
            $stmt->bind_param("ii", $quantity_to_add, $book_id);
            if ($stmt->execute()) {
                $message_type = 'success';
                $message = 'Book stock updated successfully!';
            } else {
                $message = 'Failed to update stock: ' . $stmt->error;
            }
            $stmt->close();
            break;
        case 'save_customer':
            $customer_id = $_POST['customer_id'] ?? null;
            $name = $_POST['name'];
            $phone = $_POST['phone'] ?? null;
            $email = $_POST['email'] ?? null;
            $address = $_POST['address'] ?? null;
            if (empty($name)) {
                $message = 'Customer name is required.';
                break;
            }
            if ($email) {
                $stmt_check = $conn->prepare("SELECT id FROM customers WHERE email = ? AND id != ?");
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
                $stmt = $conn->prepare("UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=?");
                $stmt->bind_param("ssssi", $name, $phone, $email, $address, $customer_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Customer updated successfully!';
                } else {
                    $message = 'Failed to update customer: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $phone, $email, $address);
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
                $stmt = $conn->prepare("UPDATE customers SET is_active=? WHERE id=?");
                $stmt->bind_param("ii", $new_status, $customer_id);
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
                $stmt_check = $conn->prepare("SELECT id FROM suppliers WHERE email = ? AND id != ?");
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
                $stmt = $conn->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?");
                $stmt->bind_param("sssssi", $name, $contact_person, $phone, $email, $address, $supplier_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Supplier updated successfully!';
                } else {
                    $message = 'Failed to update supplier: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $name, $contact_person, $phone, $email, $address);
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
                $stmt_check = $conn->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
                $stmt_check->bind_param('i', $supplier_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                    $message = 'Cannot delete supplier with existing purchase orders.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE id=?");
                $stmt->bind_param("i", $supplier_id);
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
                $total_cost += $item['quantity'] * $item['unitCost'];
            }
            if ($po_id) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE purchase_orders SET supplier_id=?, user_id=?, status=?, order_date=?, expected_date=?, total_cost=? WHERE id=?");
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param("iisssdi", $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost, $po_id);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare("DELETE FROM po_items WHERE po_id=?");
                    $stmt->bind_param("i", $po_id);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO po_items (po_id, book_id, quantity, cost_per_unit) VALUES (?, ?, ?, ?)");
                    foreach ($po_items as $item) {
                        $stmt->bind_param("iiid", $po_id, $item['bookId'], $item['quantity'], $item['unitCost']);
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
                    $stmt = $conn->prepare("INSERT INTO purchase_orders (supplier_id, user_id, status, order_date, expected_date, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
                    $user_id = $_SESSION['user_id'];
                    $stmt->bind_param("iisssd", $supplier_id, $user_id, $status, $order_date, $expected_date, $total_cost);
                    $stmt->execute();
                    $po_id = $conn->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO po_items (po_id, book_id, quantity, cost_per_unit) VALUES (?, ?, ?, ?)");
                    foreach ($po_items as $item) {
                        $stmt->bind_param("iiid", $po_id, $item['bookId'], $item['quantity'], $item['unitCost']);
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
                $stmt = $conn->prepare("DELETE FROM purchase_orders WHERE id=?");
                $stmt->bind_param("i", $po_id);
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
                    $stmt = $conn->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                    $stmt->bind_param('i', $po_id);
                    $stmt->execute();
                    $current_status = $stmt->get_result()->fetch_assoc()['status'] ?? null;
                    $stmt->close();
                    if ($current_status !== 'received') {
                        $stmt_items = $conn->prepare("SELECT book_id, quantity FROM po_items WHERE po_id = ?");
                        $stmt_items->bind_param('i', $po_id);
                        $stmt_items->execute();
                        $items = $stmt_items->get_result();
                        while ($item = $items->fetch_assoc()) {
                            $stmt_update_stock = $conn->prepare("UPDATE books SET stock = stock + ? WHERE id = ?");
                            $stmt_update_stock->bind_param("ii", $item['quantity'], $item['book_id']);
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
                        $message = 'Purchase Order received and book stock updated!';
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
            $promotion_code = $_POST['promotion_code'] ?? null;
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
                    $stmt_book = $conn->prepare("SELECT stock, price, category FROM books WHERE id = ?");
                    $stmt_book->bind_param("i", $cart_item['bookId']);
                    $stmt_book->execute();
                    $book_data = $stmt_book->get_result()->fetch_assoc();
                    $stmt_book->close();
                    if (!$book_data || $book_data['stock'] < $cart_item['quantity']) {
                        throw new Exception("Not enough stock for " . html($cart_item['title']) . ". Available: " . ($book_data['stock'] ?? 0) . ", Needed: " . $cart_item['quantity'] . ".");
                    }
                    $subtotal += $book_data['price'] * $cart_item['quantity'];
                    $cart_item['price_per_unit'] = $book_data['price'];
                    $cart_item['discount_per_unit'] = 0;
                    $cart_item['category'] = $book_data['category'];
                }
                unset($cart_item);
                if ($promotion_code) {
                    $stmt_promo = $conn->prepare("SELECT * FROM promotions WHERE code = ? AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())");
                    $stmt_promo->bind_param("s", $promotion_code);
                    $stmt_promo->execute();
                    $promotion = $stmt_promo->get_result()->fetch_assoc();
                    $stmt_promo->close();
                    if ($promotion) {
                        if ($promotion['applies_to'] === 'all') {
                            $discount_amount = ($promotion['type'] === 'percentage') ? ($subtotal * ($promotion['value'] / 100)) : $promotion['value'];
                            $total_discount = min($discount_amount, $subtotal);
                            foreach ($cart_items as &$cart_item) {
                                $item_subtotal_proportion = ($cart_item['price_per_unit'] * $cart_item['quantity']) / $subtotal;
                                $cart_item['discount_per_unit'] = ($total_discount * $item_subtotal_proportion);
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-book') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['bookId'] == $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $cart_item['discount_per_unit'] = min($discount_amount, $item_total_price);
                                    $total_discount += $cart_item['discount_per_unit'];
                                }
                            }
                            unset($cart_item);
                        } else if ($promotion['applies_to'] === 'specific-category') {
                            foreach ($cart_items as &$cart_item) {
                                if ($cart_item['category'] === $promotion['applies_to_value']) {
                                    $item_total_price = $cart_item['price_per_unit'] * $cart_item['quantity'];
                                    $discount_amount = ($promotion['type'] === 'percentage') ? ($item_total_price * ($promotion['value'] / 100)) : $promotion['value'];
                                    $cart_item['discount_per_unit'] = min($discount_amount, $item_total_price);
                                    $total_discount += $cart_item['discount_per_unit'];
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
                $stmt_sale = $conn->prepare("INSERT INTO sales (customer_id, user_id, subtotal, discount, total, promotion_code) VALUES (?, ?, ?, ?, ?, ?)");
                $user_id = $_SESSION['user_id'];
                $stmt_sale->bind_param("iiddds", $customer_id, $user_id, $subtotal, $total_discount, $final_total, $promotion_code);
                $stmt_sale->execute();
                $sale_id = $conn->insert_id;
                $stmt_sale->close();
                $stmt_sale_item = $conn->prepare("INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)");
                $stmt_update_stock = $conn->prepare("UPDATE books SET stock = stock - ? WHERE id = ?");
                foreach ($cart_items as $item) {
                    $stmt_sale_item->bind_param("iiidd", $sale_id, $item['bookId'], $item['quantity'], $item['price_per_unit'], $item['discount_per_unit'] / $item['quantity']);
                    $stmt_sale_item->execute();
                    $stmt_update_stock->bind_param("ii", $item['quantity'], $item['bookId']);
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
                $message = 'Please select a book for this promotion.';
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
            $stmt_check = $conn->prepare("SELECT id FROM promotions WHERE code = ? AND id != ?");
            $stmt_check->bind_param('si', $code, $promotion_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $message = 'A promotion with this code already exists.';
                $stmt_check->close();
                break;
            }
            $stmt_check->close();
            if ($promotion_id) {
                $stmt = $conn->prepare("UPDATE promotions SET code=?, type=?, value=?, applies_to=?, applies_to_value=?, start_date=?, end_date=? WHERE id=?");
                $stmt->bind_param("ssdsissi", $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date, $promotion_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Promotion updated successfully!';
                } else {
                    $message = 'Failed to update promotion: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO promotions (code, type, value, applies_to, applies_to_value, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsiss", $code, $type, $value, $applies_to, $applies_to_value, $start_date, $end_date);
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
                $stmt_check = $conn->prepare("SELECT COUNT(*) FROM sales WHERE promotion_code IN (SELECT code FROM promotions WHERE id = ?)");
                $stmt_check->bind_param('i', $promotion_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->fetch_row()[0] > 0) {
                    $message = 'Cannot delete promotion that has been used in sales.';
                    $stmt_check->close();
                    break;
                }
                $stmt_check->close();
                $stmt = $conn->prepare("DELETE FROM promotions WHERE id=?");
                $stmt->bind_param("i", $promotion_id);
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
                $stmt = $conn->prepare("UPDATE expenses SET user_id=?, category=?, description=?, amount=?, expense_date=? WHERE id=?");
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param("isdsdi", $user_id, $category, $description, $amount, $date, $expense_id);
                if ($stmt->execute()) {
                    $message_type = 'success';
                    $message = 'Expense updated successfully!';
                } else {
                    $message = 'Failed to update expense: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO expenses (user_id, category, description, amount, expense_date) VALUES (?, ?, ?, ?, ?)");
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param("isds", $user_id, $category, $description, $amount, $date);
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
                $stmt = $conn->prepare("DELETE FROM expenses WHERE id=?");
                $stmt->bind_param("i", $expense_id);
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
                $message = 'Invalid JSON file. Expected an array of books.';
                break;
            }
            $new_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $conn->begin_transaction();
            try {
                $stmt_insert = $conn->prepare("INSERT INTO books (title, author, category, isbn, publisher, year, price, stock, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_update = $conn->prepare("UPDATE books SET title=?, author=?, category=?, publisher=?, year=?, price=?, stock=?, description=?, cover_image=? WHERE isbn=?");
                foreach ($books_data as $book) {
                    if (!isset($book['isbn']) || !isset($book['title']) || !isset($book['author']) || !isset($book['price']) || !isset($book['stock'])) {
                        $skipped_count++;
                        continue;
                    }
                    $stmt_check = $conn->prepare("SELECT id FROM books WHERE isbn = ?");
                    $stmt_check->bind_param("s", $book['isbn']);
                    $stmt_check->execute();
                    $existing_book_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    if ($existing_book_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                "ssssidddss",
                                $book['title'],
                                $book['author'],
                                $book['category'] ?? null,
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
                            "sssssiddds",
                            $book['title'],
                            $book['author'],
                            $book['category'] ?? null,
                            $book['isbn'],
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
                $message = "Books imported: $new_count new, $updated_count updated, $skipped_count skipped.";
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error during book import: ' . $e->getMessage();
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
                $stmt_insert = $conn->prepare("INSERT INTO customers (name, phone, email, address, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt_update = $conn->prepare("UPDATE customers SET name=?, phone=?, address=?, is_active=? WHERE email=?");
                foreach ($customers_data as $customer) {
                    if (!isset($customer['name']) || !isset($customer['email'])) {
                        $skipped_count++;
                        continue;
                    }
                    $stmt_check = $conn->prepare("SELECT id FROM customers WHERE email = ?");
                    $stmt_check->bind_param("s", $customer['email']);
                    $stmt_check->execute();
                    $existing_customer_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    if ($existing_customer_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                "sssbs",
                                $customer['name'],
                                $customer['phone'] ?? null,
                                $customer['address'] ?? null,
                                $customer['is_active'] ?? 1,
                                $customer['email']
                            );
                            $stmt_update->execute();
                            $updated_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $stmt_insert->bind_param(
                            "ssssi",
                            $customer['name'],
                            $customer['phone'] ?? null,
                            $customer['email'],
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
                $stmt_insert = $conn->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                $stmt_update = $conn->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, address=? WHERE email=?");
                foreach ($suppliers_data as $supplier) {
                    if (!isset($supplier['name']) || !isset($supplier['email'])) {
                        $skipped_count++;
                        continue;
                    }
                    $stmt_check = $conn->prepare("SELECT id FROM suppliers WHERE email = ?");
                    $stmt_check->bind_param("s", $supplier['email']);
                    $stmt_check->execute();
                    $existing_supplier_id = $stmt_check->get_result()->fetch_assoc()['id'] ?? null;
                    $stmt_check->close();
                    if ($existing_supplier_id) {
                        if ($conflict_resolution === 'update') {
                            $stmt_update->bind_param(
                                "sssss",
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
                            "sssss",
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
            $tables = ['users', 'books', 'customers', 'suppliers', 'sales', 'sale_items', 'purchase_orders', 'po_items', 'expenses', 'promotions'];
            foreach ($tables as $table) {
                $result = $conn->query("SELECT * FROM " . $table);
                if ($result) {
                    $all_data[$table] = $result->fetch_all(MYSQLI_ASSOC);
                } else {
                    error_log("Failed to fetch data for table: " . $table . " - " . $conn->error);
                }
            }
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="bookshop_data_backup_' . date('Y-m-d') . '.json"');
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
            $tables_in_order = ['users', 'books', 'customers', 'suppliers', 'promotions', 'expenses', 'purchase_orders', 'po_items', 'sales', 'sale_items'];
            $tables_delete_order = array_reverse($tables_in_order);
            $conn->begin_transaction();
            try {
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($tables_delete_order as $table) {
                    $conn->query("TRUNCATE TABLE " . $table);
                }
                foreach ($tables_in_order as $table) {
                    if (isset($imported_data[$table]) && is_array($imported_data[$table])) {
                        if (empty($imported_data[$table])) continue;
                        $columns = array_keys($imported_data[$table][0]);
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $column_names = implode(', ', $columns);
                        $stmt = $conn->prepare("INSERT INTO " . $table . " (" . $column_names . ") VALUES (" . $placeholders . ")");
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
                                    $values[] = (int)$value;
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
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->commit();
                $message_type = 'success';
                $message = 'All data imported successfully!';
            } catch (Exception $e) {
                $conn->rollback();
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $message = 'Error during data import: ' . $e->getMessage();
            }
            break;
        default:
            $message = 'Invalid action.';
            break;
    }
    $_SESSION['toast'] = ['type' => $message_type, 'message' => $message];
    redirect($_GET['page'] ?? 'dashboard');
}
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
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
    $phone = $_POST['phone'] ?? '';
    $stmt = $conn->prepare("SELECT id, name, phone FROM customers WHERE email = ? AND is_active = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();
    if ($customer && $phone === $customer['phone']) {
        session_regenerate_id(true);
        $_SESSION['customer_id'] = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        $_SESSION['user_role'] = 'customer';
        $_SESSION['toast'] = ['type' => 'success', 'message' => 'Welcome, ' . html($customer['name']) . '!'];
        redirect('customer-dashboard');
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Invalid email or phone number.'];
        redirect('customer-login');
    }
}
$stmt = $conn->prepare("SELECT COUNT(*) FROM users");
$stmt->execute();
$user_count = $stmt->get_result()->fetch_row()[0];
$stmt->close();
if ($user_count == 0) {
    $admin_password = password_hash('admin123', PASSWORD_BCRYPT);
    $staff_password = password_hash('staff123', PASSWORD_BCRYPT);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->bind_param("ss", $username = 'admin', $admin_password);
        $stmt->execute();
        $admin_user_id = $conn->insert_id;
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'staff')");
        $stmt->bind_param("ss", $username = 'staff', $staff_password);
        $stmt->execute();
        $staff_user_id = $conn->insert_id;
        $stmt->close();
        $current_system_user_id = $admin_user_id;
        $sampleBooks = [
            ['title' => 'The Alchemist', 'author' => 'Paulo Coelho', 'category' => 'Fiction', 'isbn' => '978-0061122415', 'publisher' => 'HarperOne', 'year' => 1988, 'price' => 850.00, 'stock' => 12, 'description' => 'A philosophical novel about a young shepherd boy named Santiago who journeys to find a treasure.', 'cover_image' => ''],
            ['title' => 'Sapiens: A Brief History of Humankind', 'author' => 'Yuval Noah Harari', 'category' => 'History', 'isbn' => '978-0062316097', 'publisher' => 'Harper Perennial', 'year' => 2014, 'price' => 1200.00, 'stock' => 7, 'description' => 'Explores the history of humanity from the Stone Age to the twenty-first century.', 'cover_image' => ''],
            ['title' => 'The Art of Thinking Clearly', 'author' => 'Rolf Dobelli', 'category' => 'Self-Help', 'isbn' => '978-0062218391', 'publisher' => 'HarperCollins', 'year' => 2011, 'price' => 700.00, 'stock' => 3, 'description' => '99 ways to improve your decision-making and avoid common thinking errors.', 'cover_image' => ''],
            ['title' => '1984', 'author' => 'George Orwell', 'category' => 'Dystopian', 'isbn' => '978-0451524935', 'publisher' => 'Signet Classic', 'year' => 1949, 'price' => 600.00, 'stock' => 20, 'description' => 'A dystopian social science fiction novel and cautionary tale.', 'cover_image' => ''],
            ['title' => 'Rich Dad Poor Dad', 'author' => 'Robert Kiyosaki', 'category' => 'Finance', 'isbn' => '978-0446677455', 'publisher' => 'Plata Publishing', 'year' => 1997, 'price' => 950.00, 'stock' => 4, 'description' => 'Explodes the myth that you need to earn a high income to become rich.', 'cover_image' => ''],
            ['title' => 'To Kill a Mockingbird', 'author' => 'Harper Lee', 'category' => 'Classic', 'isbn' => '978-0446310789', 'publisher' => 'Grand Central Publishing', 'year' => 1960, 'price' => 750.00, 'stock' => 15, 'description' => 'A novel about the serious issues of rape and racial inequality.', 'cover_image' => ''],
        ];
        $book_ids = [];
        $stmt = $conn->prepare("INSERT INTO books (title, author, category, isbn, publisher, year, price, stock, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($sampleBooks as $book) {
            $stmt->bind_param("sssssiddds", $book['title'], $book['author'], $book['category'], $book['isbn'], $book['publisher'], $book['year'], $book['price'], $book['stock'], $book['description'], $book['cover_image']);
            $stmt->execute();
            $book_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $sampleCustomers = [
            ['name' => 'Ali Khan', 'phone' => '03001234567', 'email' => 'ali.khan@example.com', 'address' => 'Street 5, Sector G-8, Islamabad', 'is_active' => 1],
            ['name' => 'Sara Ahmed', 'phone' => '03337654321', 'email' => 'sara.ahmed@example.com', 'address' => 'House 12, Gulberg III, Lahore', 'is_active' => 1],
            ['name' => 'Usman Tariq', 'phone' => '03219876543', 'email' => 'usman.tariq@example.com', 'address' => 'Block A, DHA Phase V, Karachi', 'is_active' => 1],
            ['name' => 'Fatima Zohra', 'phone' => '03451122334', 'email' => 'fatima.z@example.com', 'address' => 'Apartment 7, F-10 Markaz, Islamabad', 'is_active' => 0],
        ];
        $customer_ids = [];
        $stmt = $conn->prepare("INSERT INTO customers (name, phone, email, address, is_active) VALUES (?, ?, ?, ?, ?)");
        foreach ($sampleCustomers as $customer) {
            $stmt->bind_param("ssssi", $customer['name'], $customer['phone'], $customer['email'], $customer['address'], $customer['is_active']);
            $stmt->execute();
            $customer_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $sampleSuppliers = [
            ['name' => 'ABC Publishers', 'contact_person' => 'Zain Ali', 'phone' => '021-34567890', 'email' => 'info@abcpubs.com', 'address' => 'D-34, Main Boulevard, Karachi'],
            ['name' => 'Global Books Distributors', 'contact_person' => 'Maria Khan', 'phone' => '042-12345678', 'email' => 'sales@globalbooks.pk', 'address' => 'Model Town, Lahore'],
            ['name' => 'Local Importers', 'contact_person' => 'Ahmed Raza', 'phone' => '051-98765432', 'email' => 'contact@localimporters.com', 'address' => 'I-8 Markaz, Islamabad'],
            ['name' => 'Book Hub Pvt Ltd', 'contact_person' => 'Hassan Iqbal', 'phone' => '051-5432109', 'email' => 'hassan@bookhub.pk', 'address' => 'Blue Area, Islamabad'],
        ];
        $supplier_ids = [];
        $stmt = $conn->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)");
        foreach ($sampleSuppliers as $supplier) {
            $stmt->bind_param("sssss", $supplier['name'], $supplier['contact_person'], $supplier['phone'], $supplier['email'], $supplier['address']);
            $stmt->execute();
            $supplier_ids[] = $conn->insert_id;
        }
        $stmt->close();
        $sale_timestamp1 = time() - (2 * 24 * 60 * 60);
        $sale_timestamp2 = time() - (10 * 24 * 60 * 60);
        $sale_timestamp3 = time() - (15 * 24 * 60 * 60);
        $sale_timestamp4 = time();
        $sale_items1 = [
            ['book_id' => $book_ids[0], 'quantity' => 1, 'price_per_unit' => 850.00, 'discount_per_unit' => 0],
            ['book_id' => $book_ids[1], 'quantity' => 1, 'price_per_unit' => 1200.00, 'discount_per_unit' => 0],
        ];
        $subtotal1 = array_reduce($sale_items1, function ($sum, $item) {
            return $sum + ($item['price_per_unit'] * $item['quantity']);
        }, 0);
        $total1 = $subtotal1;
        $sale_items2 = [
            ['book_id' => $book_ids[4], 'quantity' => 2, 'price_per_unit' => 950.00, 'discount_per_unit' => 0],
        ];
        $subtotal2 = array_reduce($sale_items2, function ($sum, $item) {
            return $sum + ($item['price_per_unit'] * $item['quantity']);
        }, 0);
        $total2 = $subtotal2;
        $sale_items3 = [
            ['book_id' => $book_ids[3], 'quantity' => 1, 'price_per_unit' => 600.00, 'discount_per_unit' => 0],
        ];
        $subtotal3 = array_reduce($sale_items3, function ($sum, $item) {
            return $sum + ($item['price_per_unit'] * $item['quantity']);
        }, 0);
        $total3 = $subtotal3;
        $sale_items4 = [
            ['book_id' => $book_ids[2], 'quantity' => 1, 'price_per_unit' => 700.00, 'discount_per_unit' => 0],
        ];
        $subtotal4 = array_reduce($sale_items4, function ($sum, $item) {
            return $sum + ($item['price_per_unit'] * $item['quantity']);
        }, 0);
        $total4 = $subtotal4;
        $sales_data = [
            ['customer_id' => $customer_ids[0], 'user_id' => $current_system_user_id, 'subtotal' => $subtotal1, 'discount' => 0, 'total' => $total1, 'promotion_code' => null, 'sale_date' => date('Y-m-d H:i:s', $sale_timestamp1), 'items' => $sale_items1],
            ['customer_id' => $customer_ids[1], 'user_id' => $current_system_user_id, 'subtotal' => $subtotal2, 'discount' => 0, 'total' => $total2, 'promotion_code' => null, 'sale_date' => date('Y-m-d H:i:s', $sale_timestamp2), 'items' => $sale_items2],
            ['customer_id' => null, 'user_id' => $current_system_user_id, 'subtotal' => $subtotal3, 'discount' => 0, 'total' => $total3, 'promotion_code' => null, 'sale_date' => date('Y-m-d H:i:s', $sale_timestamp3), 'items' => $sale_items3],
            ['customer_id' => $customer_ids[0], 'user_id' => $current_system_user_id, 'subtotal' => $subtotal4, 'discount' => 0, 'total' => $total4, 'promotion_code' => null, 'sale_date' => date('Y-m-d H:i:s', $sale_timestamp4), 'items' => $sale_items4],
        ];
        $stmt_sale = $conn->prepare("INSERT INTO sales (customer_id, user_id, subtotal, discount, total, promotion_code, sale_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_sale_item = $conn->prepare("INSERT INTO sale_items (sale_id, book_id, quantity, price_per_unit, discount_per_unit) VALUES (?, ?, ?, ?, ?)");
        foreach ($sales_data as $sale) {
            $stmt_sale->bind_param("iidddss", $sale['customer_id'], $sale['user_id'], $sale['subtotal'], $sale['discount'], $sale['total'], $sale['promotion_code'], $sale['sale_date']);
            $stmt_sale->execute();
            $sale_id = $conn->insert_id;
            foreach ($sale['items'] as $item) {
                $stmt_sale_item->bind_param("iiidd", $sale_id, $item['book_id'], $item['quantity'], $item['price_per_unit'], $item['discount_per_unit']);
                $stmt_sale_item->execute();
            }
        }
        $stmt_sale->close();
        $stmt_sale_item->close();
        $po_date1 = date('Y-m-d', time() - (7 * 24 * 60 * 60));
        $expected_date1 = date('Y-m-d', time() + (7 * 24 * 60 * 60));
        $po_date2 = date('Y-m-d', time() - (30 * 24 * 60 * 60));
        $received_date2 = date('Y-m-d', time() - (20 * 24 * 60 * 60));
        $po_items1 = [
            ['book_id' => $book_ids[5], 'quantity' => 10, 'cost_per_unit' => 750 * 0.7],
            ['book_id' => $book_ids[0], 'quantity' => 5, 'cost_per_unit' => 850 * 0.7],
        ];
        $po_cost1 = array_reduce($po_items1, function ($sum, $item) {
            return $sum + ($item['cost_per_unit'] * $item['quantity']);
        }, 0);
        $po_items2 = [
            ['book_id' => $book_ids[3], 'quantity' => 15, 'cost_per_unit' => 600 * 0.6],
        ];
        $po_cost2 = array_reduce($po_items2, function ($sum, $item) {
            return $sum + ($item['cost_per_unit'] * $item['quantity']);
        }, 0);
        $purchase_orders_data = [
            ['supplier_id' => $supplier_ids[0], 'user_id' => $current_system_user_id, 'status' => 'ordered', 'order_date' => $po_date1, 'expected_date' => $expected_date1, 'total_cost' => $po_cost1, 'items' => $po_items1],
            ['supplier_id' => $supplier_ids[1], 'user_id' => $current_system_user_id, 'status' => 'received', 'order_date' => $po_date2, 'expected_date' => $received_date2, 'total_cost' => $po_cost2, 'items' => $po_items2],
        ];
        $stmt_po = $conn->prepare("INSERT INTO purchase_orders (supplier_id, user_id, status, order_date, expected_date, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_po_item = $conn->prepare("INSERT INTO po_items (po_id, book_id, quantity, cost_per_unit) VALUES (?, ?, ?, ?)");
        foreach ($purchase_orders_data as $po) {
            $stmt_po->bind_param("iisssd", $po['supplier_id'], $po['user_id'], $po['status'], $po['order_date'], $po['expected_date'], $po['total_cost']);
            $stmt_po->execute();
            $po_id = $conn->insert_id;
            foreach ($po['items'] as $item) {
                $stmt_po_item->bind_param("iiid", $po_id, $item['book_id'], $item['quantity'], $item['cost_per_unit']);
                $stmt_po_item->execute();
            }
        }
        $stmt_po->close();
        $stmt_po_item->close();
        $expense_date1 = date('Y-m-d', time() - (3 * 24 * 60 * 60));
        $expense_date2 = date('Y-m-d', time() - (15 * 24 * 60 * 60));
        $expense_date3 = date('Y-m-d', time() - (20 * 24 * 60 * 60));
        $expense_date4 = date('Y-m-d');
        $sampleExpenses = [
            ['user_id' => $current_system_user_id, 'category' => 'Utilities', 'description' => 'Electricity bill for July', 'amount' => 8500.00, 'expense_date' => $expense_date1],
            ['user_id' => $current_system_user_id, 'category' => 'Rent', 'description' => 'Monthly shop rent', 'amount' => 50000.00, 'expense_date' => $expense_date2],
            ['user_id' => $current_system_user_id, 'category' => 'Supplies', 'description' => 'Office stationery and packing material', 'amount' => 3200.00, 'expense_date' => $expense_date3],
            ['user_id' => $current_system_user_id, 'category' => 'Marketing', 'description' => 'Social media ad campaign for new releases', 'amount' => 15000.00, 'expense_date' => $expense_date4],
        ];
        $stmt = $conn->prepare("INSERT INTO expenses (user_id, category, description, amount, expense_date) VALUES (?, ?, ?, ?, ?)");
        foreach ($sampleExpenses as $expense) {
            $stmt->bind_param("isd", $expense['user_id'], $expense['category'], $expense['description'], $expense['amount'], $expense['expense_date']);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        $_SESSION['toast'] = ['type' => 'info', 'message' => 'Initial data (users, books, customers, etc.) added to the database.'];
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Failed to insert initial data: " . $e->getMessage());
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Failed to set up initial data: ' . $e->getMessage()];
    }
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$page = $_GET['page'] ?? 'home';
if (isLoggedIn()) {
    if (($page === 'login' || $page === 'customer-login' || $page === 'home')) {
        redirect(isCustomer() ? 'customer-dashboard' : 'dashboard');
    }
}
$admin_pages = ['dashboard', 'books', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'promotions', 'expenses', 'reports', 'backup-restore'];
if (isCustomer() && in_array($page, $admin_pages)) {
    redirect('customer-dashboard');
}
$authenticated_pages = ['dashboard', 'books', 'customers', 'suppliers', 'purchase-orders', 'cart', 'sales-history', 'promotions', 'expenses', 'reports', 'backup-restore', 'customer-dashboard'];
if (!isLoggedIn() && in_array($page, $authenticated_pages)) {
    $_SESSION['toast'] = ['type' => 'info', 'message' => 'Please log in to access this page.'];
    $redirect_page = ($page === 'customer-dashboard') ? 'customer-login' : 'login';
    redirect($redirect_page);
}
/*
-- Database Creation
CREATE DATABASE IF NOT EXISTS bookshop_management;
USE bookshop_management;
-- Table Structure
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    isbn VARCHAR(13) UNIQUE NOT NULL,
    publisher VARCHAR(255),
    year INT,
    price DECIMAL(10, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    description TEXT,
    cover_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(255) UNIQUE,
    address TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(255) UNIQUE,
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    user_id INT NOT NULL, -- User who created the PO
    order_date DATE NOT NULL,
    expected_date DATE,
    status ENUM('pending', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'pending',
    total_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS po_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    book_id INT NOT NULL,
    quantity INT NOT NULL,
    cost_per_unit DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('percentage', 'fixed') NOT NULL,
    value DECIMAL(10, 2) NOT NULL,
    applies_to ENUM('all', 'specific-book', 'specific-category') NOT NULL,
    applies_to_value VARCHAR(255), -- Book ID, Category Name, or NULL for 'all'
    start_date DATE NOT NULL,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT, -- NULL for guest sales
    user_id INT NOT NULL, -- User who made the sale
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    subtotal DECIMAL(10, 2) NOT NULL,
    discount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10, 2) NOT NULL,
    promotion_code VARCHAR(50),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (promotion_code) REFERENCES promotions(code) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    book_id INT NOT NULL,
    quantity INT NOT NULL,
    price_per_unit DECIMAL(10, 2) NOT NULL,
    discount_per_unit DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
);
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, -- User who recorded the expense
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
*/
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="A complete Bookshop Management Web App and Public Website with PHP and MySQL. Manage books, customers, sales, suppliers, purchase orders, reports, and expenses with role-based access control. Browse available books, find out about us, and contact us.">
    <meta name="keywords" content="Bookshop, Management, Web App, PHP, MySQL, Books, Customers, Sales, Reports, Inventory, Suppliers, Purchase Orders, Expenses, Promotions, Analytics, Admin, Staff, Online Book Store, Pakistan Books, New Releases">
    <meta name="author" content="Yasin Ullah, Pakistan">
    <title>Bookshop Management</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%232a9d8f' d='M18 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2m-1 15H7V5h10v12M9 7h6v2H9V7m0 4h6v2H9v-2m0 4h6v2H9v-2z'/%3E%3C/svg%3E" type="image/svg+xml">
    <style>
        @import url("https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css");

        :root {
            --primary-color: #2a9d8f;
            --primary-dark-color: #218579;
            --accent-color: #f4a261;
            --background-color: #f5f7fa;
            --surface-color: #ffffff;
            --text-color: #333;
            --light-text-color: #666;
            --border-color: #e0e0e0;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --danger-color: #e76f51;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --disabled-color: #cccccc;
        }

        [data-theme='dark'] {
            --primary-color: #55b7a8;
            --primary-dark-color: #4a9d91;
            --accent-color: #f4a261;
            --background-color: #2c3e50;
            --surface-color: #34495e;
            --text-color: #ecf0f1;
            --light-text-color: #bdc3c7;
            --border-color: #4a657e;
            --shadow-color: rgba(0, 0, 0, 0.3);
            --danger-color: #e76f51;
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            transition: background-color 0.3s, color 0.3s;
        }

        #app-container,
        #public-site-container {
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
            padding: 40px;
            margin: 20px auto;
            background-color: var(--surface-color);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow-color);
            width: 80%;
        }

        .hero-section {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark-color));
            color: white;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .hero-section h1 {
            font-size: 3.5em;
            margin-bottom: 15px;
            font-weight: 700;
        }

        .hero-section p {
            font-size: 1.3em;
            margin-bottom: 30px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-section .btn-primary {
            padding: 15px 30px;
            font-size: 1.2em;
        }

        .book-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .book-card {
            background-color: var(--background-color);
            border-radius: 8px;
            box-shadow: 0 2px 8px var(--shadow-color);
            padding: 15px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px var(--shadow-color);
        }

        .book-card img {
            max-width: 100%;
            height: 180px;
            object-fit: contain;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .book-card h3 {
            font-size: 1.2em;
            margin-bottom: 5px;
            color: var(--primary-color);
        }

        .book-card p {
            font-size: 0.9em;
            color: var(--light-text-color);
            margin-bottom: 10px;
        }

        .book-card .price {
            font-size: 1.1em;
            font-weight: bold;
            color: var(--accent-color);
            margin-top: auto;
            margin-bottom: 10px;
        }

        .book-card .stock-info {
            font-size: 0.8em;
            color: var(--light-text-color);
        }

        .book-card .stock-info.low {
            color: var(--danger-color);
            font-weight: bold;
        }

        .book-card .stock-info.out {
            color: var(--danger-color);
            font-weight: bold;
            text-decoration: line-through;
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
            width: 250px;
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
            background-color: #ffe0b2 !important;
            color: #ff6f00 !important;
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
        }

        @media (max-width: 480px) {
            .modal-content {
                padding: 20px;
            }
        }

        .hamburger-menu {
            display: none;
        }
    </style>
</head>

<body>
    <?php if ($page === 'login' || $page === 'customer-login') : ?>
        <div id="login-container">
            <div class="login-card">
                <?php if ($page === 'login') : ?>
                    <h2>Bookshop Manager Login</h2>
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
                <?php else :
                ?>
                    <h2>Customer Login</h2>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="customer_login">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number (as Password)</label>
                            <input type="tel" id="phone" name="phone" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Login</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (in_array($page, ['home', 'books-public', 'about', 'contact', 'customer-dashboard'])) :
    ?>
        <div id="public-site-container">
            <header class="public-header">
                <a href="index.php?page=home" class="logo">Bookshop.pk</a>
                <nav>
                    <ul>
                        <li><a href="index.php?page=home" class="nav-link <?php echo $page === 'home' ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="index.php?page=books-public" class="nav-link <?php echo $page === 'books-public' ? 'active' : ''; ?>">Books</a></li>
                        <li><a href="index.php?page=about" class="nav-link <?php echo $page === 'about' ? 'active' : ''; ?>">About Us</a></li>
                        <li><a href="index.php?page=contact" class="nav-link <?php echo $page === 'contact' ? 'active' : ''; ?>">Contact Us</a></li>
                    </ul>
                </nav>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <?php if (isCustomer()) : ?>
                        <a href="index.php?page=customer-dashboard" class="login-btn">My Dashboard</a>
                        <a href="index.php?action=logout" style="color: white; font-weight: 500;">Logout</a>
                    <?php else : ?>
                        <a href="index.php?page=customer-login" style="color: white; font-weight: 500;">Customer Login</a>
                        <a href="index.php?page=login" class="login-btn">Admin Login</a>
                    <?php endif; ?>
                </div>
            </header>
            <main class="public-content">
                <?php
                if (isset($_SESSION['toast'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
                    unset($_SESSION['toast']);
                }
                switch ($page) {
                    case 'home':
                        $latest_books_query = "SELECT id, title, author, price, cover_image, stock FROM books WHERE stock > 0 ORDER BY created_at DESC LIMIT 4";
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
                                <h1>Welcome to Bookshop.pk</h1>
                                <p>Your one-stop destination for the latest and greatest books. Explore our vast collection and find your next read!</p>
                                <a href="index.php?page=books-public" class="btn btn-primary">Browse Books <i class="fas fa-arrow-right"></i></a>
                            </div>
                            <div class="card">
                                <div class="card-header">New Arrivals</div>
                                <div class="book-grid" id="latest-books-list">
                                    <?php if (!empty($latest_books)) : ?>
                                        <?php foreach ($latest_books as $book) : ?>
                                            <div class="book-card">
                                                <img src="<?php echo $book['cover_image'] ?: 'https://via.placeholder.com/150x200?text=No+Cover'; ?>" alt="<?php echo html($book['title']); ?>">
                                                <h3><?php echo html($book['title']); ?></h3>
                                                <p>by <?php echo html($book['author']); ?></p>
                                                <div class="price"><?php echo format_currency(html($book['price'])); ?></div>
                                                <div class="stock-info <?php echo $book['stock'] <= 5 ? 'low' : ''; ?> <?php echo $book['stock'] === 0 ? 'out' : ''; ?>">
                                                    <?php
                                                    if ($book['stock'] > 0) {
                                                        echo html($book['stock']) . ' In Stock';
                                                    } else {
                                                        echo 'Out of Stock';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
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
                                <h1>Our Books Collection</h1>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="public-book-search">Search Books</label>
                                    <input type="text" id="public-book-search" placeholder="Search by title, author, ISBN...">
                                </div>
                                <div class="form-group">
                                    <label for="public-book-category-filter">Category</label>
                                    <select id="public-book-category-filter">
                                        <option value="all">All Categories</option>
                                        <?php foreach ($all_categories_public as $cat) : ?>
                                            <option value="<?php echo html($cat['category']); ?>"><?php echo html($cat['category']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="public-book-sort">Sort By</label>
                                    <select id="public-book-sort">
                                        <option value="title-asc">Title (A-Z)</option>
                                        <option value="title-desc">Title (Z-A)</option>
                                        <option value="price-asc">Price (Low to High)</option>
                                        <option value="price-desc">Price (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="book-grid" id="public-books-list">
                                <p>Loading books...</p>
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
                            <div class="page-header">
                                <h1>About Bookshop.pk</h1>
                            </div>
                            <div class="card">
                                <p>Welcome to <strong>Bookshop.pk</strong>, your premier destination for books in Pakistan. We are passionate about reading and committed to bringing a diverse collection of books right to your doorstep.</p>
                                <p>Founded in 2023, our mission is to foster a love for reading across the nation by providing easy access to a wide range of genres, from classic literature to contemporary bestsellers, educational materials, and children's books. We believe that every book holds a new adventure, a new lesson, or a new perspective, and we strive to make these discoveries accessible to everyone.</p>
                                <p>At Bookshop.pk, we pride ourselves on:</p>
                                <ul>
                                    <li><strong>Extensive Collection:</strong> A carefully curated selection of books from local and international authors.</li>
                                    <li><strong>Affordable Prices:</strong> Competitive pricing to make reading accessible to all.</li>
                                    <li><strong>Customer Satisfaction:</strong> Dedicated to providing excellent service and a seamless shopping experience.</li>
                                    <li><strong>Community Engagement:</strong> Supporting local authors and promoting literary events.</li>
                                </ul>
                                <p>Join us on our journey to build a community of readers and make knowledge and imagination readily available. Happy reading!</p>
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
                        </section>
                    <?php
                        break;
                    case 'contact':
                    ?>
                        <section id="public-contact" class="page-content active">
                            <div class="page-header">
                                <h1>Contact Us</h1>
                            </div>
                            <div class="card">
                                <p>Have questions, feedback, or need assistance? We're here to help!</p>
                                <p>You can reach us through the following channels:</p>
                                <div class="form-group">
                                    <label><strong>Email:</strong></label>
                                    <p><a href="mailto:info@bookshop.pk">info@bookshop.pk</a></p>
                                </div>
                                <div class="form-group">
                                    <label><strong>Phone:</strong></label>
                                    <p><a href="tel:+923001234567">+92 300 1234567</a></p>
                                </div>
                                <div class="form-group">
                                    <label><strong>Address:</strong></label>
                                    <p>123 Book Lane, Gulberg III,<br>Lahore, Pakistan</p>
                                </div>
                                <div class="form-group">
                                    <label><strong>Business Hours:</strong></label>
                                    <p>Monday - Saturday: 9:00 AM - 7:00 PM (PST)</p>
                                    <p>Sunday: Closed</p>
                                </div>
                                <p>We look forward to hearing from you!</p>
                            </div>
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
                <p>&copy; <?php echo date('Y'); ?> Bookshop.pk. All rights reserved. Designed by Yasin Ullah, Pakistan.</p>
            </footer>
        </div>
    <?php else : ?>
        <div id="app-container">
            <aside class="sidebar">
                <button class="hamburger-menu" id="hamburger-menu"><i class="fas fa-bars"></i></button>
                <h2>Bookshop Manager</h2>
                <nav>
                    <ul>
                        <li><a href="index.php?page=dashboard" class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li><a href="index.php?page=books" class="nav-link <?php echo $page === 'books' ? 'active' : ''; ?>"><i class="fas fa-book"></i> Books</a></li>
                        <li><a href="index.php?page=customers" class="nav-link <?php echo $page === 'customers' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Customers</a></li>
                        <?php if (isAdmin()) : ?>
                            <li><a href="index.php?page=suppliers" class="nav-link <?php echo $page === 'suppliers' ? 'active' : ''; ?>"><i class="fas fa-truck-moving"></i> Suppliers</a></li>
                            <li><a href="index.php?page=purchase-orders" class="nav-link <?php echo $page === 'purchase-orders' ? 'active' : ''; ?>"><i class="fas fa-boxes"></i> Purchase Orders</a></li>
                        <?php endif; ?>
                        <li><a href="index.php?page=cart" class="nav-link <?php echo $page === 'cart' || $page === 'sales-history' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart"></i> Cart & Sales</a></li>
                        <?php if (isAdmin()) : ?>
                            <li><a href="index.php?page=promotions" class="nav-link <?php echo $page === 'promotions' ? 'active' : ''; ?>"><i class="fas fa-tag"></i> Promotions</a></li>
                            <li><a href="index.php?page=expenses" class="nav-link <?php echo $page === 'expenses' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Expenses</a></li>
                            <li><a href="index.php?page=reports" class="nav-link <?php echo $page === 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Reports</a></li>
                            <li><a href="index.php?page=backup-restore" class="nav-link <?php echo $page === 'backup-restore' ? 'active' : ''; ?>"><i class="fas fa-database"></i> Backup/Restore</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <div class="user-info">
                    Logged in as <span><?php echo html(isCustomer() ? $_SESSION['customer_name'] : $_SESSION['username']); ?> (<?php echo html($_SESSION['user_role']); ?>)</span><br>
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
                <?php
                if (isset($_SESSION['toast'])) {
                    echo "<div id='initial-toast-data' style='display:none;' data-type='" . html($_SESSION['toast']['type']) . "' data-message='" . html($_SESSION['toast']['message']) . "'></div>";
                    unset($_SESSION['toast']);
                }
                switch ($page) {
                    case 'dashboard':
                        $total_books_count = $conn->query("SELECT COUNT(*) FROM books")->fetch_row()[0];
                        $total_customers_count = $conn->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetch_row()[0];
                        $low_stock_count = $conn->query("SELECT COUNT(*) FROM books WHERE stock < 5")->fetch_row()[0];
                        $today_start = date('Y-m-d 00:00:00');
                        $today_end = date('Y-m-d 23:59:59');
                        $stmt_today_sales = $conn->prepare("SELECT SUM(total) FROM sales WHERE sale_date BETWEEN ? AND ?");
                        $stmt_today_sales->bind_param("ss", $today_start, $today_end);
                        $stmt_today_sales->execute();
                        $today_sales_total = $stmt_today_sales->get_result()->fetch_row()[0] ?? 0;
                        $stmt_today_sales->close();
                        $recent_sales_query = "SELECT s.id, s.sale_date, c.name AS customer_name, s.total, 
                                            GROUP_CONCAT(CONCAT(b.title, ' (', si.quantity, ')') SEPARATOR ', ') AS item_titles
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
                        $low_stock_books_query = "SELECT id, title, author, stock FROM books WHERE stock < 5 ORDER BY stock ASC";
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
                                <h1>Dashboard</h1>
                            </div>
                            <div class="dashboard-grid">
                                <div class="dashboard-card">
                                    <h3>Total Books</h3>
                                    <p id="total-books-count"><?php echo html($total_books_count); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Total Customers</h3>
                                    <p id="total-customers-count"><?php echo html($total_customers_count); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Books Low in Stock</h3>
                                    <p id="low-stock-count" class="<?php echo $low_stock_count > 0 ? 'danger' : ''; ?>"><?php echo html($low_stock_count); ?></p>
                                </div>
                                <div class="dashboard-card">
                                    <h3>Today's Sales</h3>
                                    <p id="today-sales-total"><?php echo format_currency($today_sales_total); ?></p>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Recent Sales</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Customer</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dashboard-recent-sales">
                                            <?php if (!empty($recent_sales)) : ?>
                                                <?php foreach ($recent_sales as $sale) : ?>
                                                    <tr>
                                                        <td><?php echo format_date(html($sale['sale_date'])); ?></td>
                                                        <td><?php echo html($sale['customer_name'] ?? 'Guest'); ?></td>
                                                        <td><?php echo html($sale['item_titles']); ?></td>
                                                        <td><?php echo format_currency(html($sale['total'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <tr>
                                                    <td colspan="4">No recent sales.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Low Stock Books</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Author</th>
                                                <th>Stock</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dashboard-low-stock-books">
                                            <?php if (!empty($low_stock_books)) : ?>
                                                <?php foreach ($low_stock_books as $book) : ?>
                                                    <tr class="<?php echo $book['stock'] < 5 ? 'low-stock' : ''; ?>">
                                                        <td><?php echo html($book['title']); ?></td>
                                                        <td><?php echo html($book['author']); ?></td>
                                                        <td><?php echo html($book['stock']); ?></td>
                                                        <td class="actions">
                                                            <button class="btn btn-info btn-sm" onclick="openRestockModal('<?php echo html($book['id']); ?>')"><i class="fas fa-box"></i> Restock</button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <tr>
                                                    <td colspan="4">No books currently low in stock.</td>
                                                </tr>
                                            <?php endif; ?>
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
                                <h1>Books Management</h1>
                                <div style="display: flex; gap: 10px;">
                                    <?php if (isAdmin()) : ?>
                                        <button class="btn btn-primary" id="add-book-btn"><i class="fas fa-plus"></i> Add New
                                            Book</button>
                                        <button class="btn btn-secondary" id="export-books-btn"><i class="fas fa-download"></i> Export
                                            Books</button>
                                        <button class="btn btn-secondary" id="import-books-btn"><i class="fas fa-upload"></i> Import
                                            Books</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <label for="book-search">Search Books</label>
                                    <input type="text" id="book-search" placeholder="Search by title, author, ISBN, category...">
                                </div>
                                <div class="form-group">
                                    <label for="book-sort">Sort By</label>
                                    <select id="book-sort">
                                        <option value="title-asc">Title (A-Z)</option>
                                        <option value="title-desc">Title (Z-A)</option>
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
                                            <th>Title</th>
                                            <th>Author</th>
                                            <th>Category</th>
                                            <th>ISBN</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="books-list">
                                        <tr>
                                            <td colspan="8">Loading books...</td>
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
                    case 'customers':
                    ?>
                        <section id="customers" class="page-content <?php echo $page === 'customers' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Customers Management</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-primary" id="add-customer-btn"><i class="fas fa-plus"></i> Add New
                                        Customer</button>
                                    <?php if (isAdmin()) : ?>
                                        <button class="btn btn-secondary" id="export-customers-btn"><i class="fas fa-download"></i>
                                            Export Customers</button>
                                        <button class="btn btn-secondary" id="import-customers-btn"><i class="fas fa-upload"></i>
                                            Import
                                            Customers</button>
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
                                    <label for="supplier-search">Search Suppliers</label>
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
                        $customers_result = $conn->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name ASC");
                        if ($customers_result) {
                            while ($row = $customers_result->fetch_assoc()) {
                                $customers_for_checkout[] = $row;
                            }
                        }
                    ?>
                        <section id="cart" class="page-content <?php echo $page === 'cart' ? 'active' : ''; ?>">
                            <div class="page-header">
                                <h1>Cart & Sales</h1>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-secondary" id="view-sales-history-btn"><i class="fas fa-history"></i>
                                        View Sales History</button>
                                    <?php if (isAdmin()) : ?>
                                        <button class="btn btn-secondary" id="export-sales-btn"><i class="fas fa-download"></i> Export
                                            Sales</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header">Add Books to Cart</div>
                                <div class="search-sort-controls" style="margin-top: 0;">
                                    <div class="form-group">
                                        <input type="text" id="book-to-cart-search" placeholder="Search book to add to cart...">
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Author</th>
                                                <th>Price</th>
                                                <th>Stock</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="books-for-cart-list">
                                            <tr>
                                                <td colspan="5">Loading books...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="pagination" id="books-for-cart-pagination">
                                        <button id="books-for-cart-prev-page" disabled><i class="fas fa-chevron-left"></i>
                                            Previous</button>
                                        <span id="books-for-cart-page-info">Page 1 of 1</span>
                                        <button id="books-for-cart-next-page" disabled>Next <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header">Current Cart (<span id="cart-total-items">0</span> items)</div>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Price</th>
                                                <th>Quantity</th>
                                                <th>Discount</th>
                                                <th>Subtotal</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="cart-items-table">
                                            <tr>
                                                <td colspan="6">Cart is empty.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div id="cart-summary">
                                    <span>Total:</span>
                                    <span id="cart-grand-total">PKR 0.00</span>
                                </div>
                                <div id="cart-actions">
                                    <button class="btn btn-danger" id="clear-cart-btn" disabled><i class="fas fa-trash"></i> Clear
                                        Cart</button>
                                    <button class="btn btn-success" id="checkout-btn" disabled><i class="fas fa-money-check-alt"></i> Checkout</button>
                                </div>
                            </div>
                        </section>
                        <div id="checkout-modal" class="modal-overlay">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h3>Checkout</h3>
                                    <button class="modal-close"><i class="fas fa-times"></i></button>
                                </div>
                                <form id="checkout-form">
                                    <div class="form-group">
                                        <label for="checkout-customer">Select Customer (Optional)</label>
                                        <select id="checkout-customer">
                                            <option value="">Guest Customer</option>
                                            <?php foreach ($customers_for_checkout as $customer) : ?>
                                                <option value="<?php echo html($customer['id']); ?>"><?php echo html($customer['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="checkout-promotion-code">Promotion Code (Optional)</label>
                                        <input type="text" id="checkout-promotion-code" placeholder="Enter promo code">
                                        <button type="button" class="btn btn-sm btn-info" id="apply-promo-btn" style="margin-top: 5px;">Apply</button>
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
                                    Cart</button>
                            </div>
                            <div class="search-sort-controls card">
                                <div class="form-group">
                                    <input type="text" id="sale-search" placeholder="Search by customer name, book title, sale ID...">
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
                    case 'promotions':
                        if (!isAdmin()) {
                            $_SESSION['toast'] = ['type' => 'error', 'message' => 'Unauthorized access.'];
                            redirect('dashboard');
                        }
                        $all_books = $conn->query("SELECT id, title, author FROM books ORDER BY title ASC")->fetch_all(MYSQLI_ASSOC);
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
                                <div class="card-header">Monthly Expenses: <span id="monthly-expenses-total">PKR 0.00</span></div>
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
                                            <option value="best-selling">Best-Selling Books</option>
                                            <option value="best-selling-authors">Best-Selling Authors</option>
                                            <option value="low-stock">Low Stock Books</option>
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
                                <p>Export all your Bookshop Management data (Books, Customers, Sales, Suppliers, POs, Promotions,
                                    Expenses, Users) as a JSON file. This file can
                                    be used to restore your data later.</p>
                                <form action="index.php?page=backup-restore" method="POST">
                                    <input type="hidden" name="action" value="export_all_data">
                                    <button type="submit" class="btn btn-primary" id="export-all-data-btn"><i class="fas fa-download"></i> Export All
                                        Data</button>
                                </form>
                            </div>
                            <div class="card" style="margin-top: 30px;">
                                <div class="card-header">Import All Data</div>
                                <p>Import a previously exported JSON file to restore your Bookshop Management data. <strong>Warning:
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
                    <h3 id="book-modal-title">Add New Book</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="book-form" action="index.php?page=books" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_book">
                    <input type="hidden" id="book-id" name="book_id">
                    <input type="hidden" id="existing-cover-image" name="existing_cover_image">
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-title">Title</label>
                            <input type="text" id="book-title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="book-author">Author</label>
                            <input type="text" id="book-author" name="author" required>
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-category">Category</label>
                            <input type="text" id="book-category" name="category" required>
                        </div>
                        <div class="form-group">
                            <label for="book-isbn">ISBN</label>
                            <input type="text" id="book-isbn" name="isbn" required>
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-publisher">Publisher</label>
                            <input type="text" id="book-publisher" name="publisher">
                        </div>
                        <div class="form-group">
                            <label for="book-year">Year</label>
                            <input type="number" id="book-year" name="year" min="1000" max="2100">
                        </div>
                    </div>
                    <div class="flex-group">
                        <div class="form-group">
                            <label for="book-price">Price (PKR)</label>
                            <input type="number" id="book-price" name="price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="book-stock">Stock Quantity</label>
                            <input type="number" id="book-stock" name="stock" min="0" required>
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
                        <button type="submit" class="btn btn-primary">Save Book</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="import-books-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Import Books</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="import-books-form" action="index.php?page=books" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_books_action">
                    <div class="form-group">
                        <label for="import-books-file">Select JSON File to Import</label>
                        <input type="file" id="import-books-file" name="import_books_file" accept=".json" required>
                    </div>
                    <div class="form-group">
                        <label>If book with same ISBN exists:</label>
                        <div>
                            <input type="radio" id="import-books-skip" name="import_conflict_books" value="skip" checked>
                            <label for="import-books-skip">Skip (default)</label>
                        </div>
                        <div>
                            <input type="radio" id="import-books-update" name="import_conflict_books" value="update">
                            <label for="import-books-update">Update existing book</label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                        <button type="submit" class="btn btn-primary">Import Books</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="restock-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Restock Book</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form id="restock-form" action="index.php?page=books" method="POST">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" id="restock-book-id" name="book_id">
                    <div class="form-group">
                        <label for="restock-book-title">Book Title</label>
                        <input type="text" id="restock-book-title" readonly>
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
                                $suppliers = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
                                if (!empty($suppliers)) {
                                    foreach ($suppliers as $supplier) {
                                        echo "<option value=\"" . html($supplier['id']) . "\">" . html($supplier['name']) . "</option>";
                                    }
                                } else {
                                    echo "<option value=\"\">No Suppliers Available</option>";
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
                    <div class="search-sort-controls">
                        <div class="form-group item-picker">
                            <label for="po-book-search">Add Book</label>
                            <input type="text" id="po-book-search" placeholder="Search book to add..." autocomplete="off">
                            <div id="po-book-search-results" class="item-picker-results"></div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
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
                        Total Cost: <span id="po-grand-total">PKR 0.00</span>
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
        <div id="receipt-modal" class="modal-overlay">
            <div class="modal-content" style="max-width: 450px;">
                <div class="modal-header">
                    <h3>Sale Receipt</h3>
                    <button type="button" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <div id="receipt-content" style="font-family: monospace; font-size: 0.9em; line-height: 1.4;">
                    <p style="text-align: center; font-size: 1.2em; font-weight: bold; margin-bottom: 10px;">Bookshop
                        Receipt</p>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p><strong>Sale ID:</strong> <span id="receipt-sale-id"></span></p>
                    <p><strong>Date:</strong> <span id="receipt-date"></span></p>
                    <p><strong>Customer:</strong> <span id="receipt-customer"></span></p>
                    <hr style="border: 1px dashed var(--border-color); margin: 10px 0;">
                    <p style="font-weight: bold;">Items:</p>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 2px 0;">Book</th>
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
                <div class="form-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary modal-close">Close</button>
                    <button type="button" class="btn btn-info" id="print-receipt-btn"><i class="fas fa-print"></i>
                        Print</button>
                    <button type="button" class="btn btn-success" id="download-receipt-btn"><i class="fas fa-download"></i>
                        Download PDF</button>
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
                                    <th>Book</th>
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
                            <option value="specific-book">Specific Book</option>
                            <option value="specific-category">Specific Category</option>
                        </select>
                    </div>
                    <div class="form-group" id="promotion-book-group" style="display: none;">
                        <label for="promotion-book-id">Select Book</label>
                        <select id="promotion-book-id" name="promotion_book_id">
                            <option value="">Select a Book</option>
                            <?php foreach ($all_books as $book) : ?>
                                <option value="<?php echo html($book['id']); ?>"><?php echo html($book['title'] . ' by ' . $book['author']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="promotion-category-group" style="display: none;">
                        <label for="promotion-category">Select Category</label>
                        <input type="text" id="promotion-category" name="promotion_category" list="book-categories-datalist">
                        <datalist id="book-categories-datalist">
                            <?php foreach ($all_categories as $cat) : ?>
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
                        <label for="expense-amount">Amount (PKR)</label>
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
    <div id="toast-container"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            bookTitle: document.getElementById('book-title'),
            bookAuthor: document.getElementById('book-author'),
            bookCategory: document.getElementById('book-category'),
            bookIsbn: document.getElementById('book-isbn'),
            bookPublisher: document.getElementById('book-publisher'),
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
            restockBookTitle: document.getElementById('restock-book-title'),
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
            poBookSearch: document.getElementById('po-book-search'),
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
            publicBookCategoryFilter: document.getElementById('public-book-category-filter'),
            publicBookSort: document.getElementById('public-book-sort'),
            publicBooksList: document.getElementById('public-books-list'),
            publicBooksPagination: document.getElementById('public-books-pagination'),
            publicBooksPrevPage: document.getElementById('public-books-prev-page'),
            publicBooksNextPage: document.getElementById('public-books-next-page'),
            publicBooksPageInfo: document.getElementById('public-books-page-info'),
        };
        const formatCurrency = (amount) => `PKR ${parseFloat(amount).toFixed(2)}`;
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
            }
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
        async function fetchJSON(url) {
            try {
                const response = await fetch(url);
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
        async function updateDashboard() {}
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
                        <td>${book.cover_image ? `<img src="${html(book.cover_image)}" alt="Cover" width="50" height="70" style="object-fit: cover; border-radius: 3px;">` : '<i class="fas fa-book-open fa-2x" style="color: var(--light-text-color);"></i>'}</td>
                        <td>${html(book.title)}</td>
                        <td>${html(book.author)}</td>
                        <td>${html(book.category)}</td>
                        <td>${html(book.isbn)}</td>
                        <td>${formatCurrency(book.price)}</td>
                        <td>${html(book.stock)}</td>
                        <td class="actions">
                            <button class="btn btn-info btn-sm" onclick="openRestockModal(${html(book.id)}, '${html(book.title)}', ${html(book.stock)})"><i class="fas fa-box"></i> Restock</button>
                            <?php if (isAdmin()) : ?>
                                <button class="btn btn-primary btn-sm" onclick="openBookModal(${html(book.id)})"><i class="fas fa-edit"></i> Edit</button>
                                <form action="index.php?page=books" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to deletebook?');">
                                    <input type="hidden" name="action" value="delete_book">
                                    <input type="hidden" name="book_id" value="${html(book.id)}">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="8">No books found.</td></tr>`;
            }
        }
        async function openBookModal(bookId = null) {
            if (!<?php echo isAdmin() ? 'true' : 'false'; ?> && bookId) {
                showToast('Unauthorized to edit book details.', 'error');
                return;
            }
            elements.bookForm.reset();
            elements.bookId.value = '';
            elements.bookModalTitle.textContent = 'Add New Book';
            elements.bookCoverPreview.style.display = 'none';
            elements.bookCoverPreview.src = '';
            elements.bookCoverImage.value = '';
            elements.existingCoverImage.value = '';
            elements.removeCoverLabel.style.display = 'none';
            elements.removeCoverImage.checked = false;
            if (bookId) {
                const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
                if (data.success && data.books.length > 0) {
                    const book = data.books[0];
                    elements.bookModalTitle.textContent = 'Edit Book';
                    elements.bookId.value = book.id;
                    elements.bookTitle.value = book.title;
                    elements.bookAuthor.value = book.author;
                    elements.bookCategory.value = book.category;
                    elements.bookIsbn.value = book.isbn;
                    elements.bookPublisher.value = book.publisher;
                    elements.bookYear.value = book.year;
                    elements.bookPrice.value = book.price;
                    elements.bookStock.value = book.stock;
                    elements.bookDescription.value = book.description;
                    if (book.cover_image) {
                        elements.bookCoverPreview.src = book.cover_image;
                        elements.bookCoverPreview.style.display = 'block';
                        elements.existingCoverImage.value = book.cover_image;
                        elements.removeCoverLabel.style.display = 'block';
                    }
                } else {
                    showToast('Book not found.', 'error');
                    return;
                }
            }
            showModal(elements.bookModal);
        }
        async function openRestockModal(bookId, title, stock) {
            elements.restockBookId.value = bookId;
            elements.restockBookTitle.value = title;
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
                            <button class="btn btn-primary btn-sm" onclick="openCustomerModal(${html(customer.id)})"><i class="fas fa-edit"></i> Edit</button>
                            <form action="index.php?page=customers" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_customer_status">
                                <input type="hidden" name="customer_id" value="${html(customer.id)}">
                                <input type="hidden" name="current_status" value="${customer.is_active ? 'true' : 'false'}">
                                <button type="submit" class="btn ${customer.is_active ? 'btn-danger' : 'btn-success'} btn-sm"><i class="fas ${customer.is_active ? 'fa-user-slash' : 'fa-user-check'}"></i> ${customer.is_active ? 'Deactivate' : 'Activate'}</button>
                            </form>
                        </td>
                    </tr>
                `).join('') : `<tr><td colspan="6">No customers found.</td></tr>`;
            }
        }
        async function openCustomerModal(customerId = null) {
            if (!elements.customerForm) return;
            elements.customerForm.reset();
            elements.customerId.value = '';
            elements.customerModalTitle.textContent = 'Add New Customer';
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
                } else {
                    showToast('Customer not found.', 'error');
                    return;
                }
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
                        <td>${html(sale.item_titles)}</td>
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
                                <form action="index.php?page=purchase-orders" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to mark this Purchase Order as Received and update book stock?');">
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
            elements.poBookSearch.value = '';
            elements.poBookSearchResults.innerHTML = '';
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
                            if (confirm('Are you sure you want to mark this Purchase Order as Received and update book stock?')) {
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
        async function searchBooksForPo(query) {
            if (!elements.poBookSearchResults) return;
            elements.poBookSearchResults.innerHTML = '';
            if (query.length < 2) {
                return;
            }
            const data = await fetchJSON(`index.php?action=get_books_json&search=${encodeURIComponent(query)}&limit=5`);
            if (data.success) {
                const filteredBooks = data.books;
                filteredBooks.forEach(book => {
                    const div = document.createElement('div');
                    div.textContent = `${html(book.title)} by ${html(book.author)} (ISBN: ${html(book.isbn)})`;
                    div.onclick = () => addPoItem(book);
                    elements.poBookSearchResults.appendChild(div);
                });
            }
        }

        function addPoItem(book) {
            const existingItem = currentPoItems.find(item => item.bookId === book.id);
            if (existingItem) {
                existingItem.quantity++;
            } else {
                currentPoItems.push({
                    bookId: book.id,
                    title: book.title,
                    quantity: 1,
                    unitCost: parseFloat((book.price * 0.7).toFixed(2)),
                });
            }
            elements.poBookSearch.value = '';
            elements.poBookSearchResults.innerHTML = '';
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
                currentPoItems[itemIndex].unitCost = Math.max(0, cost);
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
                    const subtotal = item.quantity * item.unitCost;
                    totalCost += subtotal;
                    return `
                        <tr>
                            <td>${html(item.title)}</td>
                            <td><input type="number" min="1" value="${html(item.quantity)}" onchange="updatePoItemQuantity(${html(item.bookId)}, parseInt(this.value))" style="width: 70px;"></td>
                            <td><input type="number" min="0" step="0.01" value="${html(item.unitCost.toFixed(2))}" onchange="updatePoItemCost(${html(item.bookId)}, parseFloat(this.value))" style="width: 100px;"></td>
                            <td>${formatCurrency(subtotal)}</td>
                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removePoItem(${html(item.bookId)})"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    `;
                }).join('');
            }
            elements.poGrandTotal.textContent = formatCurrency(totalCost);
            elements.poItemsInput.value = JSON.stringify(currentPoItems);
        }
        async function renderBooksForCart() {
            if (!elements.booksForCartList) return;
            const search = elements.bookToCartSearch.value;
            const page = pagination.booksForCart.currentPage;
            const data = await fetchJSON(`index.php?action=get_books_for_cart_json&search=${encodeURIComponent(search)}&page_num=${page}`);
            if (data.success) {
                const books = data.books;
                updatePaginationControls(pagination.booksForCart, data.total_items);
                elements.booksForCartList.innerHTML = books.length > 0 ? books.map(book => {
                    const inCartQuantity = currentCart.find(item => item.bookId === book.id)?.quantity || 0;
                    const availableStock = book.stock - inCartQuantity;
                    return `
                        <tr>
                            <td>${html(book.title)}</td>
                            <td>${html(book.author)}</td>
                            <td>${formatCurrency(book.price)}</td>
                            <td class="${availableStock < 5 && availableStock > 0 ? 'low-stock' : (availableStock <= 0 ? 'danger' : '')}">${html(availableStock)}</td>
                            <td>
                                <button class="btn btn-primary btn-sm" onclick="addToCart(${html(book.id)}, '${html(book.title)}', ${html(book.price)})" ${availableStock <= 0 ? 'disabled' : ''}><i class="fas fa-cart-plus"></i> Add to Cart</button>
                            </td>
                        </tr>
                    `;
                }).join('') : `<tr><td colspan="5">No books found to add to cart.</td></tr>`;
            }
        }
        async function addToCart(bookId, title, price) {
            const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
            if (!data.success || data.books.length === 0) {
                showToast('Book not found in inventory.', 'error');
                return;
            }
            const book = data.books[0];
            const existingItemIndex = currentCart.findIndex(item => item.bookId === bookId);
            const currentQuantityInCart = existingItemIndex > -1 ? currentCart[existingItemIndex].quantity : 0;
            if (currentQuantityInCart >= book.stock) {
                showToast(`Cannot add more "${html(title)}". Only ${html(book.stock)} available.`, 'warning');
                return;
            }
            if (existingItemIndex > -1) {
                currentCart[existingItemIndex].quantity++;
            } else {
                currentCart.push({
                    bookId: bookId,
                    title: title,
                    price: price,
                    quantity: 1,
                    discount_per_unit: 0,
                    category: book.category
                });
            }
            showToast(`"${html(title)}" added to cart.`, 'info');
            renderCart();
            renderBooksForCart();
        }
        async function updateCartItemQuantity(bookId, newQuantity) {
            const data = await fetchJSON(`index.php?action=get_books_json&book_id=${bookId}`);
            if (!data.success || data.books.length === 0) {
                showToast('Book not found in inventory.', 'error');
                return;
            }
            const book = data.books[0];
            const itemIndex = currentCart.findIndex(item => item.bookId === bookId);
            if (itemIndex > -1) {
                if (newQuantity <= 0) {
                    removeCartItem(bookId);
                    return;
                }
                if (newQuantity > book.stock) {
                    showToast(`Cannot add more than available stock (${html(book.stock)}) for "${html(book.title)}".`, 'warning');
                    currentCart[itemIndex].quantity = book.stock;
                } else {
                    currentCart[itemIndex].quantity = newQuantity;
                }
                renderCart();
                renderBooksForCart();
            }
        }

        function removeCartItem(bookId) {
            currentCart = currentCart.filter(item => item.bookId !== bookId);
            showToast('Item removed from cart.', 'info');
            renderCart();
            renderBooksForCart();
        }
        async function calculateCartTotals() {
            let subtotal = 0;
            let totalDiscount = 0;
            for (const item of currentCart) {
                const data = await fetchJSON(`index.php?action=get_books_json&book_id=${item.bookId}`);
                if (data.success && data.books.length > 0) {
                    const book = data.books[0];
                    item.price = book.price;
                    item.category = book.category;
                    subtotal += item.price * item.quantity;
                    item.discount_per_unit = 0;
                } else {
                    console.error(`Book ID ${item.bookId} not found for cart calculation.`);
                }
            }
            if (appliedPromotion) {
                if (appliedPromotion.applies_to === 'all') {
                    const discountAmount = appliedPromotion.type === 'percentage' ?
                        subtotal * (appliedPromotion.value / 100) :
                        appliedPromotion.value;
                    totalDiscount = Math.min(discountAmount, subtotal);
                    if (subtotal > 0) {
                        for (const item of currentCart) {
                            const itemValue = item.price * item.quantity;
                            item.discount_per_unit = (totalDiscount * (itemValue / subtotal)) / item.quantity;
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
                            item.discount_per_unit = Math.min(discountAmountForItem / item.quantity, item.price);
                            totalDiscount += (item.discount_per_unit * item.quantity);
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
        async function renderCart() {
            if (!elements.cartItemsTable) return;
            const {
                subtotal,
                discount,
                total
            } = await calculateCartTotals();
            elements.cartTotalItems.textContent = currentCart.reduce((sum, item) => sum + item.quantity, 0);
            if (currentCart.length === 0) {
                elements.cartItemsTable.innerHTML = `<tr><td colspan="6">Cart is empty.</td></tr>`;
                elements.clearCartBtn.disabled = true;
                elements.checkoutBtn.disabled = true;
                if (elements.checkoutPromotionCode) elements.checkoutPromotionCode.value = '';
                appliedPromotion = null;
            } else {
                elements.cartItemsTable.innerHTML = currentCart.map(item => {
                    const itemSubtotal = item.price * item.quantity;
                    const itemDiscount = item.discount_per_unit * item.quantity;
                    const itemNetTotal = itemSubtotal - itemDiscount;
                    return `
                        <tr>
                            <td>${html(item.title)}</td>
                            <td>${formatCurrency(item.price)}</td>
                            <td class="quantity-controls">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity - 1})">-</button>
                                <input type="number" value="${html(item.quantity)}" min="1" onchange="updateCartItemQuantity(${html(item.bookId)}, parseInt(this.value))">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="updateCartItemQuantity(${html(item.bookId)}, ${item.quantity + 1})">+</button>
                            </td>
                            <td>${itemDiscount > 0 ? formatCurrency(itemDiscount) : 'N/A'}</td>
                            <td>${formatCurrency(itemNetTotal)}</td>
                            <td class="actions">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeCartItem(${html(item.bookId)})"><i class="fas fa-trash"></i> Remove</button>
                            </td>
                        </tr>
                    `;
                }).join('');
                elements.clearCartBtn.disabled = false;
                elements.checkoutBtn.disabled = false;
            }
            elements.cartGrandTotal.textContent = formatCurrency(total);
            if (elements.checkoutSubtotal) elements.checkoutSubtotal.value = formatCurrency(subtotal);
            if (elements.checkoutDiscount) elements.checkoutDiscount.value = formatCurrency(discount);
            if (elements.checkoutTotal) elements.checkoutTotal.value = formatCurrency(total);
            if (elements.checkoutDiscountDisplay) elements.checkoutDiscountDisplay.style.display = discount > 0 ? 'block' : 'none';
            fetch('index.php?action=update_session_cart', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    cart: currentCart,
                    promotion: appliedPromotion
                })
            }).then(response => response.json()).then(data => {
                if (!data.success) {
                    console.error('Failed to update session cart:', data.message);
                }
            }).catch(error => {
                console.error('Error updating session cart:', error);
            });
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
        async function applyPromotion() {
            if (!elements.checkoutPromotionCode) return;
            const promoCode = elements.checkoutPromotionCode.value.trim();
            if (!promoCode) {
                appliedPromotion = null;
                renderCart();
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
                showToast(`Promotion "${html(promotion.code)}" applied!`, 'success');
            } else {
                appliedPromotion = null;
                showToast('Invalid or expired promotion code.', 'error');
            }
            renderCart();
        }

        function clearCart() {
            if (confirm('Are you sure you want to clear the entire cart?')) {
                currentCart = [];
                appliedPromotion = null;
                if (elements.checkoutPromotionCode) elements.checkoutPromotionCode.value = '';
                renderCart();
                renderBooksForCart();
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
                        <td>${html(sale.item_titles)}</td>
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
                        <td>${html(item.title)}</td>
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
        async function openReceiptModal(sale) {
            if (!elements.receiptModal) return;
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
                    <td style="text-align: left; padding: 2px 0;">${html(item.title)}</td>
                    <td style="text-align: right; padding: 2px 0;">${html(item.quantity)}</td>
                    <td style="text-align: right; padding: 2px 0;">${formatCurrency(html(item.price_per_unit))}</td>
                    <td style="text-align: right; padding: 2px 0;">${formatCurrency((item.price_per_unit * item.quantity) - (item.discount_per_unit * item.quantity))}</td>
                </tr>
            `).join('');
            showModal(elements.receiptModal);
        }
        async function printReceipt() {
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
                pdf.autoPrint();
                window.open(pdf.output('bloburl'), '_blank');
            }).catch(error => {
                console.error('Error generating PDF for print:', error);
                showToast('Failed to generate printable receipt.', 'error');
            }).finally(() => {
                receiptContent.remove();
            });
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
                        <td>${html(promo.applies_to_value_title)}</td>
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
            const data = await fetchJSON(`index.php?action=get_books_json&page_num=1&search=&sort=title-asc&limit=99999`);
            if (data.success) {
                downloadDataAsCsv(data.books, 'books_export.csv');
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
                    items: JSON.stringify(po.items),
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
                    items: s.item_titles,
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
            const sort = elements.publicBookSort ? elements.publicBookSort.value : 'title-asc';
            const page = pagination.publicBooks.currentPage;
            const data = await fetchJSON(`index.php?action=get_public_books_json&search=${encodeURIComponent(search)}&category=${encodeURIComponent(category)}&sort=${sort}&page_num=${page}`);
            if (data.success) {
                const books = data.books;
                updatePaginationControls(pagination.publicBooks, data.total_items);
                elements.publicBooksList.innerHTML = books.length > 0 ? books.map(book => `
                    <div class="book-card">
                        <img src="${book.cover_image ? html(book.cover_image) : 'https://via.placeholder.com/150x200?text=No+Cover'}" alt="${html(book.title)}">
                        <h3>${html(book.title)}</h3>
                        <p>by ${html(book.author)}</p>
                        <div class="price">${formatCurrency(book.price)}</div>
                        <div class="stock-info ${book.stock <= 5 && book.stock > 0 ? 'low' : ''} ${book.stock === 0 ? 'out' : ''}">
                            ${book.stock > 0 ? html(book.stock) + ' In Stock' : 'Out of Stock'}
                        </div>
                    </div>
                `).join('') : `<p>No books found matching your criteria.</p>`;
            }
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
            if (elements.poBookSearch) {
                elements.poBookSearch.addEventListener('input', (e) => searchBooksForPo(e.target.value));
                elements.poBookSearch.addEventListener('focus', (e) => searchBooksForPo(e.target.value));
                document.addEventListener('click', (e) => {
                    if (!elements.poBookSearchResults.contains(e.target) && e.target !== elements.poBookSearch) {
                        elements.poBookSearchResults.innerHTML = '';
                    }
                });
            }
            if (elements.bookToCartSearch) elements.bookToCartSearch.addEventListener('input', () => {
                pagination.booksForCart.currentPage = 1;
                renderBooksForCart();
            });
            if (elements.booksForCartPrevPage) elements.booksForCartPrevPage.addEventListener('click', () => {
                pagination.booksForCart.currentPage--;
                renderBooksForCart();
            });
            if (elements.booksForCartNextPage) elements.booksForCartNextPage.addEventListener('click', () => {
                pagination.booksForCart.currentPage++;
                renderBooksForCart();
            });
            if (elements.clearCartBtn) elements.clearCartBtn.addEventListener('click', clearCart);
            if (elements.checkoutBtn) elements.checkoutBtn.addEventListener('click', openCheckoutModal);
            if (elements.applyPromoBtn) elements.applyPromoBtn.addEventListener('click', applyPromotion);
            if (elements.checkoutForm) elements.checkoutForm.addEventListener('submit', (e) => {
                elements.checkoutCustomerIdInput.value = elements.checkoutCustomer.value;
                elements.checkoutPromotionCodeInput.value = elements.checkoutPromotionCode.value;
                elements.checkoutCartItemsInput.value = JSON.stringify(currentCart);
            });
            if (elements.checkoutModal) elements.checkoutModal.addEventListener('focusout', (e) => {
                if (!elements.checkoutModal.contains(e.relatedTarget)) {
                    renderCart();
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
            if (elements.printReceiptBtn) elements.printReceiptBtn.addEventListener('click', printReceipt);
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
        }
        document.addEventListener('DOMContentLoaded', async () => {
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
            setupEventListeners();
            const initialToastData = document.getElementById('initial-toast-data');
            if (initialToastData) {
                showToast(initialToastData.dataset.message, initialToastData.dataset.type);
            }
            const currentPage = "<?php echo $page; ?>";
            if (currentPage === 'books') {
                await renderBooks();
            } else if (currentPage === 'customers') {
                await renderCustomers();
            } else if (currentPage === 'suppliers') {
                await renderSuppliers();
            } else if (currentPage === 'purchase-orders') {
                await renderPurchaseOrders();
            } else if (currentPage === 'cart') {
                await renderBooksForCart();
                await renderCart();
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
                        <td>${html(sale.item_titles)}</td>
                        <td>${formatCurrency(sale.total)}</td>
                    </tr>
                `).join('') : `<tr><td colspan="4">You have no past purchases.</td></tr>`;
                        }
                    });
            }
            if (currentPage === 'books-public') {
                await renderPublicBooks();
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>