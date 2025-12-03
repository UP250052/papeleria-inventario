<?php
// ===============================================
// INICIO DE SESIÓN Y SEGURIDAD
// ===============================================
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

// ===============================================
// CONFIGURACIÓN BASE DE DATOS
// ===============================================
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "papeleria";

error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['error' => "Conexion fallida: " . $conn->connect_error]);
    exit();
}
$conn->set_charset("utf8mb4");

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? $action;
}

// =====================================================
// =============== EXPORTACIONES CSV ===================
// =====================================================

if ($action === "export_stock_bajo") {

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=reporte_stock_bajo.csv");

    echo "ID,Nombre,Stock\n";

    $sql = "SELECT id, nombre, stock FROM Productos WHERE stock <= 10 ORDER BY stock ASC";
    $res = $conn->query($sql);

    while ($p = $res->fetch_assoc()) {
        echo "{$p['id']},{$p['nombre']},{$p['stock']}\n";
    }
    exit;
}

if ($action === "export_valor_total") {

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=reporte_valor_total.csv");

    $sql = "SELECT SUM(precio_venta * stock) AS total FROM Productos";
    $res = $conn->query($sql)->fetch_assoc();

    echo "Valor Total (precio_venta)\n";
    echo $res['total'];
    exit;
}

if ($action === "export_categoria") {

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=reporte_por_categoria.csv");

    echo "Categoria,NumProductos,StockTotal\n";

    $sql = "SELECT categoria, COUNT(*) AS num, SUM(stock) AS total 
            FROM Productos 
            WHERE categoria != '' 
            GROUP BY categoria";

    $res = $conn->query($sql);

    while ($p = $res->fetch_assoc()) {
        echo "{$p['categoria']},{$p['num']},{$p['total']}\n";
    }
    exit;
}

// =====================================================
// =============== API NORMAL GET =======================
// =====================================================
if ($method === 'GET') {

    switch ($action) {

        case "get_producto_details":
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM Productos WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_assoc());
            exit;

        case "get_proveedores":
            $res = $conn->query("SELECT * FROM Proveedores ORDER BY nombre");
            $data = [];
            while ($p = $res->fetch_assoc()) $data[] = $p;
            echo json_encode($data);
            exit;

        case "get_historial":
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM Movimientos WHERE producto_id=? ORDER BY fecha DESC");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = [];
            $q = $stmt->get_result();
            while ($r = $q->fetch_assoc()) $result[] = $r;
            echo json_encode($result);
            exit;

        default:
            $res = $conn->query("
                SELECT p.*, COALESCE(pr.nombre,'Sin proveedor') AS proveedor_nombre
                FROM Productos p
                LEFT JOIN Proveedores pr ON pr.id = p.proveedor_id
                ORDER BY p.nombre
            ");

            $productos = [];
            while ($row = $res->fetch_assoc()) {
                $row['precio'] = floatval($row['precio']);
                $row['precio_venta'] = floatval($row['precio_venta']);
                $row['stock'] = intval($row['stock']);
                $productos[] = $row;
            }

            echo json_encode($productos);
            exit;
    }
}

// =====================================================
// =============== API POST =============================
// =====================================================
if ($method === 'POST') {

    if (!$data) {
        echo json_encode(['error' => 'POST sin datos']);
        exit;
    }

    switch ($action) {

        // ------------------------------
        // CREAR PRODUCTO
        // ------------------------------
        case "create":
            $d = $data['data'];

            $sql = "INSERT INTO Productos
            (nombre, marca, descripcion, precio, precio_venta, categoria, stock, ubicacion, proveedor_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $prov = $d['proveedor_id'] == 0 ? null : $d['proveedor_id'];

            $stmt->bind_param(
                "sssddisis",
                $d['nombre'],
                $d['marca'],
                $d['descripcion'],
                $d['precio'],
                $d['precio_venta'],
                $d['categoria'],
                $d['stock'],
                $d['ubicacion'],
                $prov
            );

            if ($stmt->execute()) {
                echo json_encode(["id" => $stmt->insert_id]);
            } else {
                echo json_encode(["error" => $stmt->error]);
            }
            exit;

        // ------------------------------
        // ACTUALIZAR PRODUCTO
        // ------------------------------
        case "update_producto":
            $d = $data['data'];
            $id = (int)$data['id'];

            $sql = "UPDATE Productos SET 
                nombre=?, marca=?, descripcion=?, precio=?, precio_venta=?, categoria=?, ubicacion=?, proveedor_id=?
                WHERE id=?";

            $stmt = $conn->prepare($sql);

            $prov = $d['proveedor_id'] == 0 ? null : $d['proveedor_id'];

            $stmt->bind_param(
                "sssddssii",
                $d['nombre'], $d['marca'], $d['descripcion'],
                $d['precio'], $d['precio_venta'],
                $d['categoria'], $d['ubicacion'],
                $prov, $id
            );

            if ($stmt->execute()) echo json_encode(["message" => "OK"]);
            else echo json_encode(["error" => $stmt->error]);

            exit;

        // ------------------------------
        // STOCK
        // ------------------------------
        case "update_stock":
            $id = (int)$data['id'];
            $cant = (int)$data['cantidad'];
            $tipo = $data['tipo'];

            $conn->begin_transaction();

            $q = $conn->prepare("SELECT stock FROM Productos WHERE id=? FOR UPDATE");
            $q->bind_param("i", $id);
            $q->execute();
            $prod = $q->get_result()->fetch_assoc();

            if (!$prod) exit(json_encode(["error" => "No existe"]));

            $stock = (int)$prod['stock'];
            $nuevo = ($tipo == "entrada") ? $stock + $cant : $stock - $cant;

            if ($nuevo < 0) {
                echo json_encode(["error" => "Stock insuficiente"]);
                exit;
            }

            $u = $conn->prepare("UPDATE Productos SET stock=? WHERE id=?");
            $u->bind_param("ii", $nuevo, $id);
            $u->execute();

            $m = $conn->prepare("INSERT INTO Movimientos (producto_id,tipo,cantidad) VALUES (?,?,?)");
            $m->bind_param("isi", $id, $tipo, $cant);
            $m->execute();

            $conn->commit();

            echo json_encode(["nuevoStock" => $nuevo]);
            exit;

        // ------------------------------
        // ELIMINAR
        // ------------------------------
        case "delete":
            $id = (int)$data['id'];
            $stmt = $conn->prepare("DELETE FROM Productos WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            echo json_encode(["message" => "OK"]);
            exit;

        // ------------------------------
        // PROVEEDOR
        // ------------------------------
        case "create_proveedor":
            $nombre = $data['nombre'];
            $stmt = $conn->prepare("INSERT INTO Proveedores (nombre) VALUES (?)");
            $stmt->bind_param("s", $nombre);
            $stmt->execute();
            echo json_encode(["id" => $stmt->insert_id]);
            exit;

        // ------------------------------
        // STOCK BAJO
        // ------------------------------
        case 'report_stock_bajo':
            $q = $conn->query("SELECT id,nombre,stock FROM Productos WHERE stock <= 10 ORDER BY stock ASC");
            $arr = [];
            while ($r = $q->fetch_assoc()) $arr[] = $r;
            echo json_encode($arr);
            exit;

        // ------------------------------
        // VALOR TOTAL (precio_venta)
        // ------------------------------
        case 'report_valor_total':
            $q = $conn->query("SELECT SUM(precio_venta * stock) AS valor_total FROM Productos");
            echo json_encode($q->fetch_assoc());
            exit;

        // ------------------------------
        // POR CATEGORIA
        // ------------------------------
        case 'report_por_categoria':
            $q = $conn->query("
                SELECT categoria, COUNT(*) AS num_productos, SUM(stock) AS stock_total
                FROM Productos
                WHERE categoria IS NOT NULL AND categoria != ''
                GROUP BY categoria
            ");
            $data = [];
            while ($r = $q->fetch_assoc()) $data[] = $r;
            echo json_encode($data);
            exit;
    }
}

$conn->close();
?>
