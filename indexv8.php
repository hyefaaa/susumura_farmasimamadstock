<?php
// Mulakan sesi dengan parameter keselamatan tambahan
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// --- KONFIGURASI DATABASE MYSQL ---
// Sila ubah nilai ini mengikut tetapan pelayan MySQL/XAMPP anda
$db_host = 'localhost';
$db_user = 'susumura_admin_farmasimamad';
$db_pass = 'tMe620mAmad'; // Lalai untuk XAMPP adalah kosong, ubah jika perlu
$db_name = 'susumura_farmasimamadstock';



try {
    // 1. Menyambung ke MySQL dan memastikan pangkalan data terbina jika belum wujud
    $db = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE `$db_name`");

    // 2. Membina jadual-jadual sistem yang diperlukan
    $db->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) UNIQUE NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(20) NOT NULL,
        `fullname` VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sku` VARCHAR(50) UNIQUE NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `cost_price` DECIMAL(10,2) NOT NULL,
        `retail_price` DECIMAL(10,2) NOT NULL,
        `carton_size` INT NOT NULL DEFAULT 12,
        `category` VARCHAR(100) NOT NULL DEFAULT 'UHT 125ml',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `deliveries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `expiry_date` DATE DEFAULT NULL,
        `batch_no` VARCHAR(100) DEFAULT NULL,
        `delivered_by` VARCHAR(100) NOT NULL,
        `delivery_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `sales` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `sale_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `stock_takes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `chiller_qty` INT NOT NULL DEFAULT 0,
        `shelf_qty` INT NOT NULL DEFAULT 0,
        `store_qty` INT NOT NULL DEFAULT 0,
        `physical_qty` INT NOT NULL,
        `theoretical_qty` INT NOT NULL,
        `variance` INT NOT NULL,
        `taken_by` VARCHAR(100) NOT NULL,
        `take_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Jadual untuk menampung rekod Stok Rosak / Defect
    $db->exec("CREATE TABLE IF NOT EXISTS `damaged_stock` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `expiry_date` DATE DEFAULT NULL,
        `batch_no` VARCHAR(100) DEFAULT NULL,
        `image_data` LONGTEXT DEFAULT NULL,
        `reported_by` VARCHAR(100) NOT NULL,
        `report_role` VARCHAR(50) NOT NULL,
        `issue_type` VARCHAR(100) NOT NULL,
        `status` VARCHAR(50) NOT NULL DEFAULT 'Dilaporkan',
        `returned_by` VARCHAR(100) DEFAULT NULL,
        `return_date` DATETIME DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- AUTO-MIGRATION SCHEMAS (PEMULIHAN SKEMA AUTOMATIK) ---
    try {
        $db->query("SELECT `chiller_qty` FROM `stock_takes` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `stock_takes` ADD COLUMN `chiller_qty` INT NOT NULL DEFAULT 0 AFTER `product_id`");
        $db->exec("ALTER TABLE `stock_takes` ADD COLUMN `shelf_qty` INT NOT NULL DEFAULT 0 AFTER `chiller_qty`");
        $db->exec("ALTER TABLE `stock_takes` ADD COLUMN `store_qty` INT NOT NULL DEFAULT 0 AFTER `shelf_qty`");
    }

    try {
        $db->query("SELECT `carton_size` FROM `products` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `carton_size` INT NOT NULL DEFAULT 12 AFTER `retail_price`");
    }

    try {
        $db->query("SELECT `category` FROM `products` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `products` ADD COLUMN `category` VARCHAR(100) NOT NULL DEFAULT 'UHT 125ml' AFTER `carton_size`");
    }

    try {
        $db->query("SELECT `expiry_date` FROM `deliveries` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `deliveries` ADD COLUMN `expiry_date` DATE DEFAULT NULL AFTER `quantity`");
    }

    try {
        $db->query("SELECT `batch_no` FROM `deliveries` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `deliveries` ADD COLUMN `batch_no` VARCHAR(100) DEFAULT NULL AFTER `expiry_date`");
    }

    try {
        $db->query("SELECT `expiry_date` FROM `damaged_stock` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `damaged_stock` ADD COLUMN `expiry_date` DATE DEFAULT NULL AFTER `quantity`");
    }

    try {
        $db->query("SELECT `batch_no` FROM `damaged_stock` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `damaged_stock` ADD COLUMN `batch_no` VARCHAR(100) DEFAULT NULL AFTER `expiry_date`");
    }

    try {
        $db->query("SELECT `image_data` FROM `damaged_stock` LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("ALTER TABLE `damaged_stock` ADD COLUMN `image_data` LONGTEXT DEFAULT NULL AFTER `batch_no`");
    }

    // Memasukkan data pengguna default sekiranya kosong
    $check_users = $db->query("SELECT COUNT(*) as total FROM users")->fetch();
    if ($check_users['total'] == 0) {
        $stmt = $db->prepare("INSERT INTO users (username, password, role, fullname) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_BCRYPT), 'admin', 'Pengurus Sistem (Admin)']);
        $stmt->execute(['penghantar', password_hash('hantar123', PASSWORD_BCRYPT), 'penghantar', 'Ahmad (Staf Penghantaran)']);
        $stmt->execute(['outlet', password_hash('outlet123', PASSWORD_BCRYPT), 'outlet', 'Siti (Staf Outlet Nuansa)']);
    }

    // Memasukkan produk contoh awal sekiranya kosong
    $check_products = $db->query("SELECT COUNT(*) as total FROM products")->fetch();
    if ($check_products['total'] == 0) {
        $stmt = $db->prepare("INSERT INTO products (sku, name, cost_price, retail_price, carton_size, category) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['SKU-COF-125ML', 'Minuman Kopi Premium 125ml', 48.00, 2.50, 32, 'UHT 125ml']);
        $stmt->execute(['SKU-COF-200ML', 'Minuman Kopi Premium 200ml', 48.00, 3.20, 24, 'UHT 200ml']);
        $stmt->execute(['SKU-COF-1L', 'Minuman Kopi Premium 1L', 102.00, 12.00, 12, 'UHT 1L']);
        $stmt->execute(['SKU-COF-2L', 'Minuman Kopi Premium 2L', 90.00, 22.00, 6, 'PST 2L']);
    }

} catch (PDOException $e) {
    die("Gagal menyambung ke pangkalan data MySQL: " . $e->getMessage());
}

// Pengendali Notifikasi Sesi
$msg = '';
$msg_type = ''; 

function set_msg($text, $type = 'success') {
    $_SESSION['flash_msg'] = $text;
    $_SESSION['flash_type'] = $type;
}

if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// --- FUNGSI AMARAN TARIKH LUPUT PINTAR (UHT/IC 20 HARI, PST 10 HARI) ---
function dapatkan_status_expiry($expiry_date, $category) {
    if (!$expiry_date) {
        return ['label' => 'Tiada Rekod', 'class' => 'neutral-variance', 'warn' => false, 'days' => 999];
    }
    
    $now = new DateTime(date('Y-m-d'));
    $exp = new DateTime($expiry_date);
    
    // Kira baki hari
    $diff = $now->diff($exp);
    $days_left = (int)$diff->format("%r%a");

    if ($days_left < 0) {
        return [
            'label' => 'TELAH LUPUT (' . abs($days_left) . ' hari lepas)', 
            'class' => 'negative-variance', 
            'warn' => true, 
            'days' => $days_left
        ];
    }

    $is_uht_or_ic = (stripos($category, 'UHT') !== false || stripos($category, 'IC') !== false);
    $is_pst = (stripos($category, 'PST') !== false || stripos($category, 'Butter') !== false);

    $threshold = 0;
    if ($is_uht_or_ic) {
        $threshold = 20;
    } elseif ($is_pst) {
        $threshold = 10;
    }

    if ($days_left <= $threshold) {
        return [
            'label' => 'AMARAN (' . $days_left . ' hari berbaki)', 
            'class' => 'negative-variance', 
            'warn' => true, 
            'days' => $days_left
        ];
    }

    return [
        'label' => 'Selamat (' . $days_left . ' hari berbaki)', 
        'class' => 'positive-variance', 
        'warn' => false, 
        'days' => $days_left
    ];
}

// --- PROSES PERMINTAAN POST (ACTIONS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. LOG MASUK (LOGIN)
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username !== '' && $password !== '') {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['fullname'] = $user['fullname'];
                
                set_msg("Selamat kembali, " . $user['fullname'] . "!", "success");
                header("Location: index.php");
                exit;
            } else {
                $msg = "Nama pengguna atau kata laluan salah!";
                $msg_type = "error";
            }
        } else {
            $msg = "Sila isi semua ruangan!";
            $msg_type = "error";
        }
    }

    // B. LOG KELUAR (LOGOUT)
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header("Location: index.php");
        exit;
    }

    // C1. TAMBAH PRODUK BARU (ADMIN ONLY)
    if ($action === 'admin_add_product') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            die("Akses dinafikan.");
        }

        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $cost_price = floatval($_POST['cost_price'] ?? 0); // HARGA KOS KARTON (RM)
        $retail_price = floatval($_POST['retail_price'] ?? 0); // HARGA JUAL RUNCIT PER PCS (RM)
        $carton_size = intval($_POST['carton_size'] ?? 12);
        $category = trim($_POST['category'] ?? 'UHT 125ml');

        if ($sku !== '' && $name !== '' && $cost_price >= 0 && $retail_price >= 0 && $carton_size > 0 && $category !== '') {
            try {
                $stmt = $db->prepare("INSERT INTO products (sku, name, cost_price, retail_price, carton_size, category) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $cost_price, $retail_price, $carton_size, $category]);
                set_msg("Produk [$sku] $name berjaya didaftarkan di bawah kategori $category!", "success");
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_msg("Ralat: Kod SKU telah wujud dalam sistem.", "error");
                } else {
                    set_msg("Ralat pangkalan data: " . $e->getMessage(), "error");
                }
            }
        } else {
            set_msg("Sila pastikan semua maklumat produk diisi dengan betul.", "error");
        }
        header("Location: index.php");
        exit;
    }

    // C2. KEMASKINI PRODUK (ADMIN ONLY)
    if ($action === 'admin_edit_product') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            die("Akses dinafikan.");
        }

        $id = intval($_POST['id'] ?? 0);
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $retail_price = floatval($_POST['retail_price'] ?? 0);
        $carton_size = intval($_POST['carton_size'] ?? 12);
        $category = trim($_POST['category'] ?? 'UHT 125ml');

        if ($id > 0 && $sku !== '' && $name !== '' && $cost_price >= 0 && $retail_price >= 0 && $carton_size > 0 && $category !== '') {
            try {
                $stmt = $db->prepare("UPDATE products SET sku = ?, name = ?, cost_price = ?, retail_price = ?, carton_size = ?, category = ? WHERE id = ?");
                $stmt->execute([$sku, $name, $cost_price, $retail_price, $carton_size, $category, $id]);
                set_msg("Maklumat produk [$sku] berjaya dikemaskini!", "success");
            } catch (PDOException $e) {
                set_msg("Gagal mengemaskini maklumat produk: " . $e->getMessage(), "error");
            }
        } else {
            set_msg("Maklumat input tidak lengkap atau tidak sah.", "error");
        }
        header("Location: index.php");
        exit;
    }

    // C3. PADAM PRODUK (ADMIN ONLY)
    if ($action === 'admin_delete_product') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            die("Akses dinafikan.");
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$id]);
                set_msg("Produk berjaya dipadamkan daripada pangkalan data.", "success");
            } catch (PDOException $e) {
                set_msg("Gagal memadam produk: " . $e->getMessage(), "error");
            }
        }
        header("Location: index.php");
        exit;
    }

    // C4. IMPORT DATA PRODUK SECARA BATCH DARI EXCEL (ADMIN ONLY - UPSERT)
    if ($action === 'admin_import_excel') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            die("Akses dinafikan.");
        }

        $import_data_raw = $_POST['import_data'] ?? '';
        $import_items = json_decode($import_data_raw, true);

        if (is_array($import_items) && !empty($import_items)) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO products (sku, name, cost_price, retail_price, carton_size, category) 
                    VALUES (:sku, :name, :cost_price, :retail_price, :carton_size, :category)
                    ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    cost_price = VALUES(cost_price),
                    retail_price = VALUES(retail_price),
                    carton_size = VALUES(carton_size),
                    category = VALUES(category)");

                $count = 0;
                foreach ($import_items as $item) {
                    $sku = strtoupper(trim($item['sku'] ?? ''));
                    $name = trim($item['name'] ?? '');
                    $cost_price = floatval($item['cost_price'] ?? 0); // HARGA KOS KARTON (RM) - DIBOLEHKAN 0
                    $retail_price = floatval($item['retail_price'] ?? 0); // RSP PER PCS
                    $carton_size = intval($item['carton_size'] ?? 12);
                    $category = trim($item['category'] ?? 'UHT 125ml');

                    // Import dibenarkan sekiranya maklumat asas mencukupi (Harga Kos boleh disetkan sementara sebagai 0 jika masih kosong)
                    if ($sku !== '' && $name !== '') {
                        $stmt->execute([
                            ':sku' => $sku,
                            ':name' => $name,
                            ':cost_price' => $cost_price,
                            ':retail_price' => $retail_price,
                            ':carton_size' => $carton_size,
                            ':category' => $category
                        ]);
                        $count++;
                    }
                }
                $db->commit();
                set_msg("Berjaya mengimport/kemaskini sebanyak $count produk daripada fail Excel ke dalam pangkalan data!", "success");
            } catch (Exception $e) {
                $db->rollBack();
                set_msg("Gagal mengimport data: " . $e->getMessage(), "error");
            }
        } else {
            set_msg("Tiada data sah dikesan untuk diimport.", "error");
        }
        header("Location: index.php");
        exit;
    }

    // D. REKOD PENGHANTARAN (STAF PENGHANTARAN ONLY)
    if ($action === 'delivery_add') {
        if (($_SESSION['role'] ?? '') !== 'penghantar') {
            die("Akses dinafikan.");
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $delivery_carton = intval($_POST['delivery_carton'] ?? 0);
        $delivery_pcs = intval($_POST['delivery_pcs'] ?? 0);
        $expiry_date = trim($_POST['expiry_date'] ?? '');
        $batch_no = strtoupper(trim($_POST['batch_no'] ?? ''));
        $delivered_by = $_SESSION['fullname'];

        if ($product_id > 0 && ($delivery_carton > 0 || $delivery_pcs > 0) && $expiry_date !== '' && $batch_no !== '') {
            $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
            $stmtProd->execute([$product_id]);
            $prod_info = $stmtProd->fetch();

            $final_pcs = ($delivery_carton * $prod_info['carton_size']) + $delivery_pcs;
            
            if ($delivery_carton > 0 && $delivery_pcs > 0) {
                $msg_detail = "$delivery_carton Karton + $delivery_pcs pcs ($final_pcs pcs)";
            } elseif ($delivery_carton > 0) {
                $msg_detail = "$delivery_carton Karton ($final_pcs pcs)";
            } else {
                $msg_detail = "$delivery_pcs pcs";
            }

            $stmt = $db->prepare("INSERT INTO deliveries (product_id, quantity, expiry_date, batch_no, delivered_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$product_id, $final_pcs, $expiry_date, $batch_no, $delivered_by]);
            set_msg("Berjaya merekod penghantaran sebanyak $msg_detail (Tarikh Luput: " . date('d/m/Y', strtotime($expiry_date)) . ", Kelompok: $batch_no) bagi produk " . htmlspecialchars($prod_info['name']) . ".", "success");
        } else {
            set_msg("Sila masukkan kuantiti hantar, Tarikh Luput, dan Nombor Kelompok yang sah!", "error");
        }
        header("Location: index.php");
        exit;
    }

    // F. REKOD PENGIRAAN STOK (STOCK TAKE) SECARA PUKAL DENGAN AUTO BILLING JUALAN
    if ($action === 'outlet_add_stocktake') {
        if (($_SESSION['role'] ?? '') !== 'outlet') {
            die("Akses dinafikan.");
        }

        $stocktake_data = $_POST['stocktake'] ?? [];
        $taken_by = $_SESSION['fullname'];
        $success_count = 0;
        $total_auto_sales = 0;

        if (!empty($stocktake_data)) {
            $db->beginTransaction();
            try {
                foreach ($stocktake_data as $product_id => $counts) {
                    $product_id = intval($product_id);
                    if ($product_id <= 0) continue;

                    // Ambil maklumat produk
                    $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
                    $stmtProd->execute([$product_id]);
                    $prod_info = $stmtProd->fetch();
                    if (!$prod_info) continue;

                    $carton_size = intval($prod_info['carton_size']);

                    // Ambil nilai input Carton + Pcs dari form
                    $chiller_carton = intval($counts['chiller_carton'] ?? 0);
                    $chiller_pcs = intval($counts['chiller_pcs'] ?? 0);
                    $shelf_carton = intval($counts['shelf_carton'] ?? 0);
                    $shelf_pcs = intval($counts['shelf_pcs'] ?? 0);
                    $store_carton = intval($counts['store_carton'] ?? 0);
                    $store_pcs = intval($counts['store_pcs'] ?? 0);

                    // Kira kuantiti fizikal dalam unit pieces
                    $chiller_qty = ($chiller_carton * $carton_size) + $chiller_pcs;
                    $shelf_qty = ($shelf_carton * $carton_size) + $shelf_pcs;
                    $store_qty = ($store_carton * $carton_size) + $store_pcs;
                    $physical_qty = $chiller_qty + $shelf_qty + $store_qty;

                    // Hitung total delivered bagi produk ini
                    $stmtDel = $db->prepare("SELECT SUM(quantity) as total FROM deliveries WHERE product_id = ?");
                    $stmtDel->execute([$product_id]);
                    $total_delivered = intval($stmtDel->fetch()['total'] ?? 0);

                    // Hitung total sold setakat ini (sebelum rekod stocktake baru ini)
                    $stmtSale = $db->prepare("SELECT SUM(quantity) as total FROM sales WHERE product_id = ?");
                    $stmtSale->execute([$product_id]);
                    $total_sold_before = intval($stmtSale->fetch()['total'] ?? 0);

                    // Hitung total stok rosak yang sedia dilaporkan/dibawa balik setakat ini
                    $stmtDmg = $db->prepare("SELECT SUM(quantity) as total FROM damaged_stock WHERE product_id = ?");
                    $stmtDmg->execute([$product_id]);
                    $total_damaged_before = intval($stmtDmg->fetch()['total'] ?? 0);

                    // Baki jangkaan teoretikal dikurangkan dengan jualan dan stok rosak sedia ada
                    $theoretical_qty_before = $total_delivered - $total_sold_before - $total_damaged_before;

                    // Sekiranya baki jangkaan <= 0, dan staf tidak memasukkan sebarang nilai (semua 0), kita skip untuk mengelakkan lambakan data kosong
                    if ($theoretical_qty_before <= 0 && $chiller_carton === 0 && $chiller_pcs === 0 && $shelf_carton === 0 && $shelf_pcs === 0 && $store_carton === 0 && $store_pcs === 0) {
                        continue;
                    }

                    // Hitung variansi (Kiraan Fizikal - Jangkaan Sistem)
                    $variance = $physical_qty - $theoretical_qty_before;

                    // Masukkan rekod pengiraan stok ke database
                    $stmt = $db->prepare("INSERT INTO stock_takes (product_id, chiller_qty, shelf_qty, store_qty, physical_qty, theoretical_qty, variance, taken_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$product_id, $chiller_qty, $shelf_qty, $store_qty, $physical_qty, $theoretical_qty_before, $variance, $taken_by]);

                    // AUTO-BILLING: Jika baki fizikal berkurang (stok tidak tally/hilang/terjual), auto-rekod sebagai JUALAN
                    if ($variance < 0) {
                        $auto_sales_qty = abs($variance);
                        $stmtAutoSale = $db->prepare("INSERT INTO sales (product_id, quantity) VALUES (?, ?)");
                        $stmtAutoSale->execute([$product_id, $auto_sales_qty]);
                        $total_auto_sales += $auto_sales_qty;
                    }
                    $success_count++;
                }

                $db->commit();
                
                if ($total_auto_sales > 0) {
                    set_msg("Pengiraan Stok secara pukal disimpan! Sebanyak $success_count produk berjaya dikira, dan baki jualan sebanyak $total_auto_sales pcs telah dibilkan secara automatik sebagai JUALAN.", "success");
                } else {
                    set_msg("Pengiraan Stok secara pukal berjaya disimpan! Sebanyak $success_count produk dikira dengan baki yang seimbang & tally.", "success");
                }
            } catch (PDOException $e) {
                $db->rollBack();
                set_msg("Ralat pangkalan data semasa merekod baki stok secara pukal: " . $e->getMessage(), "error");
            }
        } else {
            set_msg("Tiada data pengiraan stok yang dihantar.", "error");
        }
        header("Location: index.php");
        exit;
    }

    // G. REKOD STOK ROSAK (LAPORAN BARU DARI OUTLET / PENGHANTAR)
    if ($action === 'outlet_add_damaged') {
        if (($_SESSION['role'] ?? '') !== 'outlet' && ($_SESSION['role'] ?? '') !== 'penghantar') {
            die("Akses dinafikan.");
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $damaged_carton = intval($_POST['damaged_carton'] ?? 0);
        $damaged_pcs = intval($_POST['damaged_pcs'] ?? 0);
        $expiry_date = trim($_POST['expiry_date'] ?? '');
        $batch_no = strtoupper(trim($_POST['batch_no'] ?? ''));
        $issue_type = $_POST['issue_type'] ?? 'Factory Defect';
        $reported_by = $_SESSION['fullname'];
        $report_role = $_SESSION['role'];

        // Proses lampiran gambar dan tukar kepada Base64
        $image_base64 = null;
        if (isset($_FILES['damaged_image']) && $_FILES['damaged_image']['tmp_name'] != '') {
            $check_file = getimagesize($_FILES['damaged_image']['tmp_name']);
            if ($check_file !== false) {
                $ext = pathinfo($_FILES['damaged_image']['name'], PATHINFO_EXTENSION);
                $binary_data = file_get_contents($_FILES['damaged_image']['tmp_name']);
                $image_base64 = 'data:image/' . $ext . ';base64,' . base64_encode($binary_data);
            } else {
                set_msg("Fail yang dilampirkan bukan fail imej yang sah!", "error");
                header("Location: index.php");
                exit;
            }
        }

        if ($product_id > 0 && ($damaged_carton > 0 || $damaged_pcs > 0) && $expiry_date !== '' && $batch_no !== '' && $image_base64 !== null) {
            $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
            $stmtProd->execute([$product_id]);
            $prod_info = $stmtProd->fetch();

            $final_pcs = ($damaged_carton * $prod_info['carton_size']) + $damaged_pcs;

            $stmt = $db->prepare("INSERT INTO damaged_stock (product_id, quantity, expiry_date, batch_no, image_data, reported_by, report_role, issue_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Dilaporkan')");
            $stmt->execute([$product_id, $final_pcs, $expiry_date, $batch_no, $image_base64, $reported_by, $report_role, $issue_type]);

            set_msg("Berjaya melaporkan stok rosak sebanyak $final_pcs pcs ($damaged_carton ctn, $damaged_pcs pcs) bagi " . htmlspecialchars($prod_info['name']) . " (Kelompok: $batch_no, Tarikh Luput: " . date('d/m/Y', strtotime($expiry_date)) . "). Bukti imej disimpan.", "success");
        } else {
            set_msg("Ralat: Sila pastikan semua ruangan (Kuantiti, Tarikh Luput, Kelompok, dan Gambar Bukti Kerosakan) dilengkapkan!", "error");
        }
        header("Location: index.php");
        exit;
    }

    // H. PROSES BAWA BALIK STOK ROSAK OLEH PENGHANTAR (RN PROCESS)
    if ($action === 'delivery_return_damaged') {
        if (($_SESSION['role'] ?? '') !== 'penghantar') {
            die("Akses dinafikan.");
        }

        $damaged_id = intval($_POST['damaged_id'] ?? 0);
        $returned_by = $_SESSION['fullname'];

        if ($damaged_id > 0) {
            $stmt = $db->prepare("UPDATE damaged_stock SET status = 'Dibawa Balik', returned_by = ?, return_date = NOW() WHERE id = ?");
            $stmt->execute([$returned_by, $damaged_id]);
            set_msg("Stok rosak berjaya ditandakan sebagai 'Dibawa Balik' ke gudang untuk proses tuntutan Return Note (RN) kilang.", "success");
        } else {
            set_msg("Ralat memproses penukaran status stok rosak.", "error");
        }
        header("Location: index.php");
        exit;
    }
}

// Ambil senarai produk untuk kegunaan borang
$products_list = $db->query("SELECT * FROM products ORDER BY name ASC")->fetchAll();

// --- PRE-LOAD SEMUA BATCH UNTUK RUJUKAN EXPIRY (KONGSI BAGI SEMUA PERANAN) ---
$all_expiry_batches = [];
$total_expiry_warnings = 0;
$stmt_all_expiry = $db->query("SELECT d.*, p.name as p_name, p.sku as p_sku, p.category, p.carton_size
    FROM deliveries d
    JOIN products p ON d.product_id = p.id
    ORDER BY d.expiry_date ASC");
$raw_batches = $stmt_all_expiry->fetchAll();
foreach ($raw_batches as $batch) {
    $status_data = dapatkan_status_expiry($batch['expiry_date'], $batch['category']);
    if ($status_data['warn']) {
        $total_expiry_warnings++;
    }
    $batch['status_info'] = $status_data;
    $all_expiry_batches[] = $batch;
}

// Kira stok ringkasan untuk dipamerkan di Admin Dashboard
$admin_summary = [];
$total_products_count = 0;
$total_delivered_units = 0;
$total_sold_units = 0;
$total_variance_alerts = 0;
$total_sales_revenue = 0;
$total_wholesale_cost = 0; // Pemboleh ubah kos keseluruhan borang (invois outlet)

$total_damaged_reported_pcs = 0;
$total_damaged_returned_pcs = 0;
$all_damaged_logs = [];
$near_expiry_batches = [];
$popular_skus = [];
$weekly_sales_trend = [];
$monthly_sales_trend = [];
$takes = []; // Memastikan audit takes log terbina dengan selamat di bahagian atas

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $sql = "SELECT p.*, 
            (SELECT COALESCE(SUM(quantity), 0) FROM deliveries WHERE product_id = p.id) as total_delivered,
            (SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE product_id = p.id) as total_sold,
            (SELECT COALESCE(SUM(quantity), 0) FROM damaged_stock WHERE product_id = p.id) as total_damaged,
            (SELECT variance FROM stock_takes WHERE product_id = p.id ORDER BY take_date DESC LIMIT 1) as last_variance,
            (SELECT take_date FROM stock_takes WHERE product_id = p.id ORDER BY take_date DESC LIMIT 1) as last_take_date
            FROM products p ORDER BY p.sku ASC";
    $admin_summary = $db->query($sql)->fetchAll();
    
    $total_products_count = count($admin_summary);
    foreach ($admin_summary as $item) {
        $total_delivered_units += $item['total_delivered'];
        $total_sold_units += $item['total_sold'];
        
        // Kira nilai jualan runcit (RSP) terkumpul (Pcs * Harga Jual Runcit)
        $total_sales_revenue += ($item['total_sold'] * $item['retail_price']);
        
        // KIRA KOS INVOIS OUTLET (pcs terjual * harga kos sekeping)
        // Formula: s.quantity * (cost_price_karton / carton_size)
        $cost_per_piece = $item['carton_size'] > 0 ? ($item['cost_price'] / $item['carton_size']) : 0;
        $total_wholesale_cost += ($item['total_sold'] * $cost_per_piece);

        if ($item['last_variance'] !== null && intval($item['last_variance']) != 0) {
            $total_variance_alerts++;
        }
    }

    // ANALISIS SKU POPULAR (Admin Only)
    $stmt_popular = $db->query("SELECT p.sku, p.name, p.carton_size, p.category, COALESCE(SUM(s.quantity), 0) as total_sold, SUM(s.quantity * p.retail_price) as total_revenue
        FROM products p
        LEFT JOIN sales s ON p.id = s.product_id
        GROUP BY p.id
        ORDER BY total_sold DESC
        LIMIT 5");
    $popular_skus = $stmt_popular->fetchAll();

    // SEJARAH JUALAN MINGGUAN KATEGORI (Admin Only)
    $weekly_sales_trend = $db->query("SELECT YEAR(s.sale_date) as yr, WEEK(s.sale_date) as wk, p.category, 
        SUM(s.quantity) as total_pcs, 
        SUM(s.quantity / p.carton_size) as total_cartons, 
        SUM(s.quantity * p.retail_price) as total_revenue
        FROM sales s
        JOIN products p ON s.product_id = p.id
        GROUP BY YEAR(s.sale_date), WEEK(s.sale_date), p.category
        ORDER BY yr DESC, wk DESC, total_revenue DESC
        LIMIT 30")->fetchAll();

    // SEJARAH JUALAN BULANAN KATEGORI (Admin Only)
    $monthly_sales_trend = $db->query("SELECT YEAR(s.sale_date) as yr, MONTH(s.sale_date) as mth, p.category, 
        SUM(s.quantity) as total_pcs, 
        SUM(s.quantity / p.carton_size) as total_cartons, 
        SUM(s.quantity * p.retail_price) as total_revenue
        FROM sales s
        JOIN products p ON s.product_id = p.id
        GROUP BY YEAR(s.sale_date), MONTH(s.sale_date), p.category
        ORDER BY yr DESC, mth DESC, total_revenue DESC
        LIMIT 30")->fetchAll();

    // REKOD SEMUA STOK ROSAK (Admin Only)
    $stmt_all_damaged = $db->query("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size, p.category 
        FROM damaged_stock ds 
        JOIN products p ON ds.product_id = p.id 
        ORDER BY ds.created_at DESC");
    $all_damaged_logs = $stmt_all_damaged->fetchAll();

    foreach ($all_damaged_logs as $dmg) {
        if ($dmg['status'] === 'Dilaporkan') {
            $total_damaged_reported_pcs += $dmg['quantity'];
        } else {
            $total_damaged_returned_pcs += $dmg['quantity'];
        }
    }

    // MEMBUAT CARIAN REKOD SEMUA STOCK TAKE TERKINI UNTUK AUDIT LOG ADMIN (GUNA UNTUK TAB AUDIT)
    $takes = $db->query("SELECT s.*, p.name as p_name, p.sku as p_sku FROM stock_takes s JOIN products p ON s.product_id = p.id ORDER BY s.take_date DESC LIMIT 15")->fetchAll();

    // --- PROSES MENYEDIAKAN CADANGAN RESTOK PINTAR DENGAN PENYUSUNAN (SORTING) ---
    // Diasingkan dan disusun: priority_score 1 (Kritikal) -> 2 (Sederhana) -> 3 (Mencukupi paling bawah)
    $processed_restock_list = [];
    foreach ($admin_summary as $item) {
        $current_stock = $item['total_delivered'] - $item['total_sold'] - $item['total_damaged'];
        
        // Dapatkan jualan 7 hari terakhir
        $stmt_recent = $db->prepare("SELECT COALESCE(SUM(quantity), 0) as qty FROM sales WHERE product_id = ? AND sale_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt_recent->execute([$item['id']]);
        $recent_sales = intval($stmt_recent->fetch()['qty'] ?? 0);

        $target_stock = max($recent_sales * 2, $item['carton_size']);
        $suggested_restock = $target_stock - $current_stock;

        if ($current_stock <= ($recent_sales * 0.5) || $current_stock <= 0) {
            $priority_score = 1; // Kritikal
        } elseif ($current_stock < $target_stock) {
            $priority_score = 2; // Sederhana
        } else {
            $priority_score = 3; // Mencukupi (paling bawah)
        }

        $item['current_stock_pcs'] = $current_stock;
        $item['recent_sales_pcs'] = $recent_sales;
        $item['target_stock_pcs'] = $target_stock;
        $item['suggested_restock_pcs'] = $suggested_restock;
        $item['priority_score'] = $priority_score;

        $processed_restock_list[] = $item;
    }

    // Penyusunan usort: Prioriti 1 & 2 di atas, prioriti 3 (Mencukupi) di susun paling bawah.
    usort($processed_restock_list, function($a, $b) {
        if ($a['priority_score'] === $b['priority_score']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['priority_score'] <=> $b['priority_score'];
    });
}

// PEMATUHAN JADUAL MINGGUAN OUTLET (Enforce Wajib Seminggu Sekali)
$days_since_last_take = null;
$last_take_date_formatted = "Belum Pernah";
$outlet_stocks = []; // Diisytiharkan awal

if (isset($_SESSION['role']) && $_SESSION['role'] === 'outlet') {
    $last_take_stmt = $db->query("SELECT MAX(take_date) as last_date FROM stock_takes");
    $last_take = $last_take_stmt->fetch();
    if ($last_take && $last_take['last_date']) {
        $last_take_date_formatted = date('d/m/Y', strtotime($last_take['last_date']));
        $datetime_last = new DateTime($last_take['last_date']);
        $datetime_now = new DateTime();
        $interval = $datetime_last->diff($datetime_now);
        $days_since_last_take = $interval->days;
    }

    // Rekod baki anggaran stok semasa di kedai bagi rujukan outlet (disusun baki terbanyak di atas)
    $outlet_stocks = $db->query("SELECT p.id, p.name, p.sku, p.carton_size, p.category, 
        ((SELECT COALESCE(SUM(quantity), 0) FROM deliveries WHERE product_id = p.id) - 
         (SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE product_id = p.id) -
         (SELECT COALESCE(SUM(quantity), 0) FROM damaged_stock WHERE product_id = p.id)) as baki 
        FROM products p ORDER BY baki DESC, p.name ASC")->fetchAll();

    // Rekod stok rosak di peringkat outlet sedia ada
    $stmt_outlet_damaged = $db->prepare("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size 
        FROM damaged_stock ds 
        JOIN products p ON ds.product_id = p.id 
        ORDER BY ds.created_at DESC LIMIT 10");
    $stmt_outlet_damaged->execute();
    $outlet_damaged_list = $stmt_outlet_damaged->fetchAll();
}

// Terjemahan nama bulan Melayu
$bulan_melayu = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Mac', 4 => 'April', 
    5 => 'Mei', 6 => 'Jun', 7 => 'Julai', 8 => 'Ogos', 
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Disember'
];
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Konsignasi Inventori - Susumura Farmasi Mamad Stock</title>
    <!-- Tambah Pustaka SheetJS (XLSX) untuk membaca fail Excel dari browser secara pantas -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
            --primary-light: #e0e7ff;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #475569;
            --border-color: #e2e8f0;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            line-height: 1.6;
            padding-bottom: 60px;
        }

        header {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .logo-section h1 {
            font-size: 1.5rem;
            color: var(--primary-color);
            font-weight: 800;
            letter-spacing: -0.025em;
        }

        .logo-section p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .user-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-admin { background-color: var(--danger-light); color: #991b1b; }
        .badge-penghantar { background-color: var(--primary-light); color: #1e3a8a; }
        .badge-outlet { background-color: var(--success-light); color: #065f46; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-color);
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            text-align: center;
            gap: 8px;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-danger {
            background-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .btn-outline {
            background-color: transparent;
            color: var(--text-main);
            border: 1px solid var(--border-color);
            box-shadow: none;
        }

        .btn-outline:hover {
            background-color: #f1f5f9;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid transparent;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-success {
            background-color: var(--success-light);
            color: #065f46;
            border-color: #a7f3d0;
        }

        .alert-error {
            background-color: var(--danger-light);
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-warning {
            background-color: var(--warning-light);
            color: #92400e;
            border-color: #fde68a;
        }

        /* Stats Section */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
        }

        /* Login Layout */
        .login-card {
            max-width: 440px;
            margin: 80px auto;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            padding: 40px;
        }

        .login-card h2 {
            margin-bottom: 8px;
            font-size: 1.75rem;
            font-weight: 800;
            text-align: center;
            letter-spacing: -0.025em;
        }

        .login-card p.subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 32px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-muted);
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s ease;
            color: var(--text-main);
            background-color: #fff;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-control:disabled, .form-control[readonly] {
            background-color: #f8fafc;
            color: var(--text-muted);
            cursor: not-allowed;
        }

        /* Dashboard Grid Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        @media (min-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1.1fr 0.9fr;
            }
            .full-width {
                grid-column: span 2;
            }
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 28px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        }

        .card-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }

        /* Table Styling */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            text-align: left;
        }

        th, td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: #f8fafc;
            font-weight: 700;
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Demo credentials helper card */
        .demo-helper {
            background-color: #f0fdf4;
            border: 1px dashed #bbf7d0;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            font-size: 0.85rem;
        }

        .demo-helper h4 {
            color: #166534;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .demo-helper ul {
            padding-left: 20px;
            color: #14532d;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        /* Tabs System */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
            overflow-x: auto;
            white-space: nowrap;
        }

        .tab-btn {
            background: none;
            border: none;
            padding: 10px 20px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-muted);
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .tab-btn:hover {
            background-color: #f1f5f9;
            color: var(--text-main);
        }

        .tab-btn.active {
            color: var(--primary-color);
            background-color: var(--primary-light);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .negative-variance {
            color: var(--danger);
            font-weight: 700;
            background-color: var(--danger-light);
            padding: 4px 8px;
            border-radius: 4px;
        }

        .positive-variance {
            color: var(--success);
            font-weight: 700;
            background-color: var(--success-light);
            padding: 4px 8px;
            border-radius: 4px;
        }

        .neutral-variance {
            color: var(--text-muted);
            font-weight: 700;
            background-color: #f1f5f9;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .helper-text {
            font-size: 0.85rem;
            color: var(--primary-color);
            margin-top: 8px;
            font-weight: 600;
        }

        .location-badge {
            display: inline-flex;
            align-items: center;
            background-color: #f1f5f9;
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            margin-right: 6px;
            margin-top: 6px;
            font-weight: 600;
            color: var(--text-muted);
        }

        /* Modal Simple */
        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--card-bg);
            padding: 32px;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 800;
        }

        .close-btn {
            font-size: 1.5rem;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-muted);
        }

        .search-container {
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
        }

        /* Pembahagi input Carton + Pcs (Stock Take & Delivery) */
        .carton-pcs-container {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 4px;
        }

        .carton-pcs-input {
            flex: 1;
            min-width: 70px;
        }

        .carton-pcs-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* Popular SKU progress bars */
        .ranking-bar-container {
            margin-bottom: 15px;
        }
        .ranking-label-group {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .ranking-bar-bg {
            background-color: #f1f5f9;
            height: 10px;
            border-radius: 9999px;
            overflow: hidden;
        }
        .ranking-bar-fill {
            background-color: var(--primary-color);
            height: 100%;
            border-radius: 9999px;
        }

        /* Image preview thumbnail for defect image */
        .defect-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .defect-thumbnail:hover {
            transform: scale(1.1);
        }

        /* Expired status indicator styling */
        .expiry-warning-badge {
            background-color: var(--danger-light);
            color: var(--danger);
            border: 1px solid #fca5a5;
            font-weight: 800;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>

    <!-- HEADER SEKSYEN -->
    <header>
        <div class="logo-section">
            <h1>Susumura Farmasi Mamad Stock</h1>
            <p>Sistem Aliran Inventori Konsignasi & Kawalan Lokasi Outlet</p>
        </div>
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="user-meta">
                <span>
                    Log masuk sebagai: <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong> 
                    <span class="badge badge-<?= $_SESSION['role'] ?>"><?= $_SESSION['role'] ?></span>
                </span>
                <form action="index.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-danger btn-outline btn-sm">Log Keluar</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <div class="container">
        
        <!-- Notifikasi Mesej Semasa -->
        <?php if ($msg !== ''): ?>
            <div class="alert alert-<?= $msg_type ?>">
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- ================= Halaman Log Masuk (Belum Autentikasi) ================= -->
        <?php if (!isset($_SESSION['user_id'])): ?>
            <div class="login-card">
                <h2>Selamat Datang</h2>
                <p class="subtitle">Sila log masuk ke portal susumura_farmasimamadstock</p>
                
                <form action="index.php" method="POST">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label for="username">Nama Pengguna (Username)</label>
                        <input type="text" name="username" id="username" class="form-control" placeholder="cth: admin" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password">Kata Laluan (Password)</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <button type="submit" class="btn" style="width: 100%; margin-top: 10px;">Log Masuk</button>
                </form>

                <div class="demo-helper">
                    <h4>🔑 Kredensial Ujian Standard:</h4>
                    <ul>
                        <li><strong>Admin:</strong> <code>admin</code> / <code>admin123</code></li>
                        <li><strong>Staf Penghantaran:</strong> <code>penghantar</code> / <code>hantar123</code></li>
                        <li><strong>Staf Outlet:</strong> <code>outlet</code> / <code>outlet123</code></li>
                    </ul>
                </div>
            </div>

        <!-- ================= DASHBOARD: ADMIN ================= -->
        <?php elseif ($_SESSION['role'] === 'admin'): ?>
            <!-- STATS CARDS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Jenis SKU Aktif</span>
                    <span class="stat-value"><?= $total_products_count ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Jumlah Unit Dihantar</span>
                    <span class="stat-value"><?= $total_delivered_units ?> pcs</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Jumlah Unit Terjual</span>
                    <span class="stat-value" style="color: var(--success);"><?= $total_sold_units ?> pcs</span>
                </div>
                <div class="stat-card" style="border-left: 4px solid var(--primary-color);">
                    <span class="stat-label">Invois Tuntutan Outlet (Wholesale Cost)</span>
                    <span class="stat-value" style="color: var(--primary-color);">RM <?= number_format($total_wholesale_cost, 2) ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Nilai Jualan Runcit (RSP Value)</span>
                    <span class="stat-value" style="color: var(--success);">RM <?= number_format($total_sales_revenue, 2) ?></span>
                </div>
                <div class="stat-card" style="border-left: 4px solid var(--danger);">
                    <span class="stat-label">Kelompok Amaran Luput</span>
                    <span class="stat-value" style="color: var(--danger);"><?= $total_expiry_warnings ?> Batches</span>
                </div>
            </div>

            <!-- TABS UNTUK ADMIN -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('tab-produk')">📋 Senarai Produk & Stok</button>
                <button class="tab-btn" onclick="switchTab('tab-analisis-pintar')">📊 Analisis & Cadangan Pintar</button>
                <button class="tab-btn" onclick="switchTab('tab-stok-rosak')">💔 Pengurusan Stok Rosak & RN</button>
                <button class="tab-btn" onclick="switchTab('tab-admin-expiry')">📅 Pemantauan Tarikh Luput</button>
                <button class="tab-btn" onclick="switchTab('tab-audit-stok')">🕒 Audit Log Stock Take</button>
            </div>

            <!-- TAB 1: SENARAI PRODUK & STOK -->
            <div id="tab-produk" class="tab-content active">
                <div class="dashboard-grid">
                    <!-- Borang Tambah Produk Baru oleh Admin -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Daftar Produk Baru</span>
                        </div>
                        <form action="index.php" method="POST">
                            <input type="hidden" name="action" value="admin_add_product">
                            
                            <div class="form-group">
                                <label for="sku">Kod SKU (Mesti Unik)</label>
                                <input type="text" name="sku" id="sku" class="form-control" placeholder="cth: SKU-COF-200ML" required>
                            </div>

                            <div class="form-group">
                                <label for="name">Nama Produk & Isipadu</label>
                                <input type="text" name="name" id="name" class="form-control" placeholder="cth: Susu Kurma Premium 200ml" required>
                            </div>

                            <div class="form-group">
                                <label for="category">Kategori Produk</label>
                                <select name="category" id="category" class="form-control" required>
                                    <optgroup label="Kategori UHT">
                                        <option value="UHT 100ml">UHT 100ml</option>
                                        <option value="UHT 115ml">UHT 115ml</option>
                                        <option value="UHT 125ml">UHT 125ml</option>
                                        <option value="UHT 200ml">UHT 200ml</option>
                                        <option value="UHT 1L">UHT 1L</option>
                                    </optgroup>
                                    <optgroup label="Kategori PST">
                                        <option value="PST 120g">PST 120g</option>
                                        <option value="PST 1.4kg">PST 1.4kg</option>
                                        <option value="PST 100ml">PST 100ml</option>
                                        <option value="PST 200ml">PST 200ml</option>
                                        <option value="PST 568ml">PST 568ml</option>
                                        <option value="PST 700ml">PST 700ml</option>
                                        <option value="PST 1L">PST 1L</option>
                                        <option value="PST 2L">PST 2L</option>
                                        <option value="Butter">Butter</option>
                                    </optgroup>
                                    <optgroup label="Kategori Ice Cream">
                                        <option value="IC 55ml">IC 55ml</option>
                                        <option value="IC 75ml">IC 75ml</option>
                                        <option value="IC 109ml">IC 109ml</option>
                                    </optgroup>
                                    <optgroup label="Lain-lain">
                                        <option value="Merchandise">Merchandise</option>
                                    </optgroup>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="cost_price">Harga Kos Karton (RM)</label>
                                    <input type="number" step="0.01" name="cost_price" id="cost_price" class="form-control" placeholder="0.00" required>
                                </div>
                                <div class="form-group">
                                    <label for="retail_price">Harga Jual se-Unit / RSP (RM)</label>
                                    <input type="number" step="0.01" name="retail_price" id="retail_price" class="form-control" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="carton_size">Kapasiti Carton (Unit per Carton)</label>
                                <select name="carton_size" id="carton_size" class="form-control" required>
                                    <option value="6">6 unit per Carton (cth: Saiz Besar / 2L)</option>
                                    <option value="12" selected>12 unit per Carton (cth: Saiz Sederhana / 1L)</option>
                                    <option value="20">20 unit per Carton</option>
                                    <option value="24">24 unit per Carton (cth: Saiz Sederhana / 200ml)</option>
                                    <option value="32">32 unit per Carton (cth: Saiz Kecil / 125ml)</option>
                                </select>
                            </div>

                            <button type="submit" class="btn" style="width: 100%;">Daftarkan Produk Baru</button>
                        </form>
                    </div>

                    <!-- Rumusan Teoretikal & Kawalan Lokasi -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Formula Kawalan Aliran & Lokasi</span>
                        </div>
                        <div style="background-color: var(--bg-color); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.85rem; line-height: 1.6; border-left: 4px solid var(--primary-color); margin-bottom: 12px; color: var(--text-muted); text-align: left;">
                            Stok Jangkaan = Total Dihantar - Total Jual Sedia Ada - Total Rosak Sedia Ada<br><br>
                            Jualan Baru (Auto-Billed) = Stok Jangkaan - Kiraan Fizikal Terkini<br><br>
                            * Nilai Kos se-Unit = Harga Kos Karton / Saiz Karton.<br>
                            * Invois Outlet = Jumlah Terjual (pcs) * Nilai Kos se-Unit.
                        </div>
                        <p style="font-size: 0.88rem; color: var(--text-muted); line-height: 1.5;">
                            Sistem kini menggunakan <strong>Model Pengiraan Pintar Sell-Through</strong>. Staf outlet hanya perlu menghantar pengiraan baki fizikal, dan sistem secara automatik mengira jualan serta menjana rekod jualan baharu jika terdapat pengurangan baki di kedai.
                        </p>
                    </div>

                    <!-- Senarai Produk & Import Excel Pukal -->
                    <div class="card">
                        <div class="card-header" style="border-bottom: 2px solid var(--success);">
                            <span class="card-title">📥 Import Produk Pukal (Excel / CSV)</span>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px; line-height: 1.5;">
                            Gunakan fungsi ini untuk memuat naik senarai SKU baru atau mengemas kini harga/saiz karton yang sedia ada sekaligus.
                        </p>
                        
                        <div class="form-group" style="background-color: #f1f5f9; padding: 15px; border-radius: 8px; border: 1px dashed var(--border-color); text-align: center;">
                            <label for="excel_file_input" class="btn btn-outline btn-sm" style="display: inline-flex; cursor: pointer; margin-bottom: 8px;">📂 Pilih Fail Excel/CSV</label>
                            <input type="file" id="excel_file_input" accept=".xlsx, .xls, .csv" onchange="bacaFailExcel(event)" style="display: none;">
                            <p id="file_name_display" style="font-size: 0.82rem; font-weight: bold; color: var(--text-main); margin-bottom: 8px;">Tiada fail dipilih</p>
                            
                            <button type="button" class="btn btn-sm" onclick="janaDanTurunTemplatCSV()" style="background-color: var(--success); font-size: 0.78rem;">⬇️ Muat Turun Templat Excel (.csv)</button>
                        </div>

                        <!-- KOTAK NOTIFIKASI RALAT JUMPA DATA EXCEL (Ganti alert browser) -->
                        <div id="import_error_container" class="alert alert-error" style="display: none; margin-top: 15px;">
                            <span style="font-size: 1.2rem;">⚠️</span>
                            <div id="import_error_text" style="margin-left: 10px;"></div>
                        </div>

                        <!-- Pratonton visual data sebelum dihantar ke pangkalan data -->
                        <div id="excel_preview_section" style="display: none; margin-top: 15px;">
                            <h4 style="font-size: 0.9rem; font-weight: bold; color: var(--primary-color); margin-bottom: 8px;">👀 Pratonton Data Excel (Sah):</h4>
                            <div class="table-responsive" style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border-color);">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>SKU</th>
                                            <th>Nama Produk</th>
                                            <th>Kategori</th>
                                            <th class="text-right">Harga Kos Karton (RM)</th>
                                            <th class="text-right">Harga Jual (Pcs)</th>
                                            <th class="text-center">Carton Size</th>
                                        </tr>
                                    </thead>
                                    <tbody id="excel_preview_tbody">
                                        <!-- Baris data dimuat masuk secara dinamik menggunakan JS -->
                                    </tbody>
                                </table>
                            </div>

                            <form action="index.php" method="POST" style="margin-top: 15px;">
                                <input type="hidden" name="action" value="admin_import_excel">
                                <input type="hidden" name="import_data" id="import_data_field" value="">
                                <button type="submit" id="btn_import_submit" class="btn" style="width: 100%; background-color: var(--primary-color);">🚀 Sahkan & Proses Import Data</button>
                            </form>
                        </div>
                    </div>

                    <!-- Senarai Produk & Ringkasan Semasa (Admin) -->
                    <div class="card full-width">
                        <div class="card-header">
                            <span class="card-title">Senarai Produk & Aliran Stok Semasa</span>
                        </div>
                        
                        <div class="search-container">
                            <input type="text" id="adminSearchInput" onkeyup="filterAdminTable()" class="form-control" placeholder="Cari nama produk atau SKU...">
                        </div>

                        <div class="table-responsive">
                            <table id="adminProductTable">
                                <thead>
                                    <tr>
                                        <th>Kod SKU</th>
                                        <th>Nama Produk</th>
                                        <th class="text-center">Kategori</th>
                                        <th class="text-center">Kapasiti Carton</th>
                                        <th class="text-right">Harga Kos Karton</th>
                                        <th class="text-right">Harga Kos Pcs (Kira)</th>
                                        <th class="text-right">Harga Jual Runcit (Pcs)</th>
                                        <th class="text-center">Total Dihantar</th>
                                        <th class="text-center">Total Dijual</th>
                                        <th class="text-center" style="background-color: #fcfcfd;">Total Rosak</th>
                                        <th class="text-center" style="background-color: #f8fafc;">Baki Teoretikal (Pcs)</th>
                                        <th class="text-center">Variansi Terakhir</th>
                                        <th class="text-center">Tindakan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($admin_summary)): ?>
                                        <tr>
                                            <td colspan="13" class="text-center" style="color: var(--text-muted);">Tiada produk didaftarkan lagi.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($admin_summary as $item): ?>
                                            <?php 
                                                $theo_stock = $item['total_delivered'] - $item['total_sold'] - $item['total_damaged'];
                                                $v_class = 'neutral-variance';
                                                $v_display = 'Tiada Kiraan';
                                                if ($item['last_variance'] !== null) {
                                                    $v_val = intval($item['last_variance']);
                                                    if ($v_val < 0) {
                                                        $v_class = 'negative-variance';
                                                        $v_display = "$v_val pcs (Kurang)";
                                                    } elseif ($v_val > 0) {
                                                        $v_class = 'positive-variance';
                                                        $v_display = "+$v_val pcs (Lebihan)";
                                                    } else {
                                                        $v_class = 'neutral-variance';
                                                        $v_display = "0 (Seimbang)";
                                                    }
                                                }
                                                // Kira kos per keping berasaskan Harga Kos Karton / Carton Size
                                                $cost_per_pcs = $item['carton_size'] > 0 ? ($item['cost_price'] / $item['carton_size']) : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($item['sku']) ?></strong></td>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td class="text-center"><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($item['category']) ?></span></td>
                                                <td class="text-center"><span class="badge badge-penghantar">1 Carton = <?= $item['carton_size'] ?> pcs</span></td>
                                                <td class="text-right" style="font-weight: 600;">RM <?= number_format($item['cost_price'], 2) ?></td>
                                                <td class="text-right" style="color: var(--text-muted); font-style: italic;">RM <?= number_format($cost_per_pcs, 4) ?></td>
                                                <td class="text-right" style="color: var(--success); font-weight: bold;">RM <?= number_format($item['retail_price'], 2) ?></td>
                                                <td class="text-center"><?= $item['total_delivered'] ?> pcs</td>
                                                <td class="text-center" style="color: var(--success); font-weight: bold;"><?= $item['total_sold'] ?> pcs</td>
                                                <td class="text-center" style="color: var(--danger); font-weight: bold; background-color: #fef2f2;"><?= $item['total_damaged'] ?> pcs</td>
                                                <td class="text-center" style="font-weight: bold; background-color: #f8fafc;"><?= $theo_stock ?> pcs</td>
                                                <td class="text-center" style="font-size: 0.85rem;"><span class="<?= $v_class ?>"><?= $v_display ?></span></td>
                                                <td class="text-center">
                                                    <div style="display: flex; gap: 6px; justify-content: center;">
                                                        <button type="button" class="btn btn-outline btn-sm" onclick="openEditModal(<?= $item['id'] ?>, '<?= htmlspecialchars($item['sku']) ?>', '<?= htmlspecialchars($item['name']) ?>', <?= $item['cost_price'] ?>, <?= $item['retail_price'] ?>, <?= $item['carton_size'] ?>, '<?= htmlspecialchars($item['category']) ?>')">Edit</button>
                                                        <button type="button" class="btn btn-danger btn-sm" onclick="openConfirmModal(<?= $item['id'] ?>)">Padam</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: ANALISIS & CADANGAN PINTAR -->
            <div id="tab-analisis-pintar" class="tab-content">
                <div class="dashboard-grid">
                    
                    <!-- AMARAN BATCH HAMPIR EXPIRED (< 30 HARI) -->
                    <div class="card full-width" style="border-left: 5px solid var(--danger);">
                        <div class="card-header">
                            <span class="card-title" style="color: var(--danger);">⚠️ Stok Hampir Tamat Tempoh</span>
                        </div>
                        <?php if (empty($near_expiry_batches)): ?>
                            <p class="text-center" style="color: var(--success); font-weight: 600; padding: 15px 0;">🎉 Syabas! Tiada kelompok stok dikesan tamat tempoh dalam masa terdekat.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>SKU & Nama Produk</th>
                                            <th class="text-center">Kuantiti Dihantar (Asal)</th>
                                            <th class="text-center">Tarikh Luput</th>
                                            <th class="text-center">Baki Hari Berbaki</th>
                                            <th class="text-center">Status Keselamatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($near_expiry_batches as $batch): ?>
                                            <tr>
                                                <td><strong>[<?= htmlspecialchars($batch['p_sku']) ?>]</strong> <?= htmlspecialchars($batch['p_name']) ?></td>
                                                <td class="text-center"><?= $batch['quantity'] ?> pcs</td>
                                                <td class="text-center" style="color: var(--danger); font-weight: bold;"><?= date('d/m/Y', strtotime($batch['expiry_date'])) ?></td>
                                                <td class="text-center" style="font-weight: bold;"><?= $batch['days_left'] ?> Hari</td>
                                                <td class="text-center">
                                                    <span class="badge badge-admin" style="font-size: 0.75rem;">Kritikal / Luput Dekat</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Penarafan SKU Popular -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">🔥 5 SKU Paling Popular (Top Selling)</span>
                        </div>
                        <?php if (empty($popular_skus)): ?>
                            <p class="text-center" style="color: var(--text-muted); padding: 20px 0;">Belum ada data jualan direkodkan untuk analisis.</p>
                        <?php else: ?>
                            <?php 
                            $max_sales = !empty($popular_skus) ? max(array_column($popular_skus, 'total_sold')) : 1;
                            $max_sales = $max_sales > 0 ? $max_sales : 1;
                            foreach ($popular_skus as $index => $sku): 
                                $percentage = ($sku['total_sold'] / $max_sales) * 100;
                            ?>
                                <div class="ranking-bar-container">
                                    <div class="ranking-label-group">
                                        <span>No. <?= $index + 1 ?>: [<?= htmlspecialchars($sku['sku']) ?>] <?= htmlspecialchars($sku['name']) ?> (<?= htmlspecialchars($sku['category']) ?>)</span>
                                        <strong><?= $sku['total_sold'] ?> pcs (RM <?= number_format($sku['total_revenue'], 2) ?>)</strong>
                                    </div>
                                    <div class="ranking-bar-bg">
                                        <div class="ranking-bar-fill" style="width: platform <?= $percentage ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Enjin Cadangan Restok Pintar (KINI DIATUR SUPAYA "MENCUKUPI" DI SUSUN PALING BAWAH) -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">💡 Cadangan Kuantiti Restok Automatik</span>
                        </div>
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 15px;">
                            Sistem mengira kelajuan jualan dalam tempoh <strong>7 hari terakhir</strong> bagi menentukan sasaran buffer stok 2 minggu. Data disusun supaya keutamaan **Kritikal** dan **Sederhana** berada di atas, manakala **Mencukupi** berada di bahagian bawah.
                        </p>
                        <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th class="text-center">Baki</th>
                                        <th class="text-center">Saranan Restok</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processed_restock_list as $item): ?>
                                        <?php 
                                        $suggested_restock = $item['suggested_restock_pcs'];
                                        $current_stock = $item['current_stock_pcs'];

                                        if ($suggested_restock > 0) {
                                            $ctn_needed = $item['carton_size'] > 0 ? floor($suggested_restock / $item['carton_size']) : 0;
                                            $pcs_needed = $item['carton_size'] > 0 ? ($suggested_restock % $item['carton_size']) : $suggested_restock;
                                            
                                            $restock_text_parts = [];
                                            if ($ctn_needed > 0) $restock_text_parts[] = "{$ctn_needed} ctn";
                                            if ($pcs_needed > 0) $restock_text_parts[] = "{$pcs_needed} pcs";
                                            
                                            $restock_display = implode(" + ", $restock_text_parts);
                                        } else {
                                            $restock_display = "<span style='color: var(--text-muted); font-style: italic;'>Selesa</span>";
                                        }

                                        // Warna prioriti
                                        if ($item['priority_score'] === 1) {
                                            $p_badge = "🚨 KRITIKAL";
                                            $p_class = "negative-variance";
                                        } elseif ($item['priority_score'] === 2) {
                                            $p_badge = "⚠️ SEDERHANA";
                                            $p_class = "neutral-variance";
                                        } else {
                                            $p_badge = "✅ MENCUKUPI";
                                            $p_class = "positive-variance";
                                        }
                                        ?>
                                        <tr>
                                            <td><strong>[<?= htmlspecialchars($item['sku']) ?>]</strong></td>
                                            <td class="text-center"><?= $current_stock ?> pcs</td>
                                            <td class="text-center" style="font-weight: 700; color: var(--primary-color);"><?= $restock_display ?></td>
                                            <td class="text-center"><span class="<?= $p_class ?>" style="font-size:0.78rem;"><?= $p_badge ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- JADUAL LOG SALES MINGGUAN -->
                    <div class="card full-width">
                        <div class="card-header" style="border-bottom: 2px solid var(--primary-color);">
                            <span class="card-title">📈 Log Jualan Mingguan (Karton, Kategori, Ringgit)</span>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tahun / Minggu</th>
                                        <th>Kategori Produk</th>
                                        <th class="text-center">Jumlah Jualan (Carton)</th>
                                        <th class="text-center">Jumlah Jualan (Pcs)</th>
                                        <th class="text-right">Hasil Jualan (Ringgit RM)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($weekly_sales_trend)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="color: var(--text-muted);">Belum ada sejarah jualan mingguan direkodkan.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($weekly_sales_trend as $row): ?>
                                            <tr>
                                                <td><strong>Tahun <?= $row['yr'] ?>, Minggu <?= $row['wk'] ?></strong></td>
                                                <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($row['category']) ?></span></td>
                                                <td class="text-center" style="font-weight: bold; color: var(--primary-color);"><?= number_format($row['total_cartons'], 2) ?> ctn</td>
                                                <td class="text-center"><?= number_format($row['total_pcs']) ?> pcs</td>
                                                <td class="text-right" style="font-weight: bold; color: var(--success);">RM <?= number_format($row['total_revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- JADUAL LOG SALES BULANAN -->
                    <div class="card full-width">
                        <div class="card-header" style="border-bottom: 2px solid var(--success);">
                            <span class="card-title">📅 Log Jualan Bulanan (Karton, Kategori, Ringgit)</span>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tahun / Bulan</th>
                                        <th>Kategori Produk</th>
                                        <th class="text-center">Jumlah Jualan (Carton)</th>
                                        <th class="text-center">Jumlah Jualan (Pcs)</th>
                                        <th class="text-right">Hasil Jualan (Ringgit RM)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($monthly_sales_trend)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="color: var(--text-muted);">Belum ada sejarah jualan bulanan direkodkan.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($monthly_sales_trend as $row): ?>
                                            <?php 
                                                $nama_mth = $bulan_melayu[intval($row['mth'])] ?? $row['mth'];
                                            ?>
                                            <tr>
                                                <td><strong><?= $nama_mth ?> <?= $row['yr'] ?></strong></td>
                                                <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($row['category']) ?></span></td>
                                                <td class="text-center" style="font-weight: bold; color: var(--primary-color);"><?= number_format($row['total_cartons'], 2) ?> ctn</td>
                                                <td class="text-center"><?= number_format($row['total_pcs']) ?> pcs</td>
                                                <td class="text-right" style="font-weight: bold; color: var(--success);">RM <?= number_format($row['total_revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            <!-- TAB 3: PENGURUSAN STOK ROSAK & RN -->
            <div id="tab-stok-rosak" class="tab-content">
                <div class="stats-grid">
                    <div class="stat-card" style="border-left: 4px solid var(--warning);">
                        <span class="stat-label">Rosak Dilaporkan (Belum RN)</span>
                        <span class="stat-value" style="color: var(--warning);"><?= $total_damaged_reported_pcs ?> pcs</span>
                    </div>
                    <div class="stat-card" style="border-left: 4px solid var(--success);">
                        <span class="stat-label">Bawa Balik ke Warehouse (Sedia RN)</span>
                        <span class="stat-value" style="color: var(--success);"><?= $total_damaged_returned_pcs ?> pcs</span>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <span class="card-title">💔 Rekod Semua Stok Rosak / Defect (Untuk Tuntutan RN)</span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Masa Laporan</th>
                                    <th>Kategori</th>
                                    <th>SKU & Produk</th>
                                    <th class="text-center">Kelompok (Batch No)</th>
                                    <th class="text-center">Tarikh Luput</th>
                                    <th class="text-center">Kuantiti (Carton + Pcs)</th>
                                    <th class="text-center">Gambar Bukti</th>
                                    <th>Isu / Defect</th>
                                    <th>Dilapor Oleh</th>
                                    <th class="text-center">Status</th>
                                    <th>Pengambil (Bawa Balik)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_damaged_logs)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center" style="color: var(--text-muted); padding: 20px 0;">Tiada rekod stok rosak dikesan dalam pangkalan data.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_damaged_logs as $dmg): ?>
                                        <?php 
                                            $ctn = floor($dmg['quantity'] / $dmg['carton_size']);
                                            $pcs = $dmg['quantity'] % $dmg['carton_size'];
                                            $ctn_pcs_text = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
                                            if (empty($ctn_pcs_text)) $ctn_pcs_text = "0 pcs";

                                            $status_class = ($dmg['status'] === 'Dilaporkan') ? 'negative-variance' : 'positive-variance';
                                            $status_text = ($dmg['status'] === 'Dilaporkan') ? '⚠️ Di Outlet' : '✅ Di Warehouse';
                                        ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i', strtotime($dmg['created_at'])) ?></td>
                                            <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($dmg['category']) ?></span></td>
                                            <td><strong>[<?= htmlspecialchars($dmg['p_sku']) ?>]</strong> <?= htmlspecialchars($dmg['p_name']) ?></td>
                                            <td class="text-center"><code><?= htmlspecialchars($dmg['batch_no'] ?? 'N/A') ?></code></td>
                                            <td class="text-center" style="color: var(--danger); font-weight: bold;"><?= $dmg['expiry_date'] ? date('d/m/Y', strtotime($dmg['expiry_date'])) : 'N/A' ?></td>
                                            <td class="text-center" style="font-weight: bold;"><?= $ctn_pcs_text ?></td>
                                            <td class="text-center">
                                                <?php if (!empty($dmg['image_data'])): ?>
                                                    <img src="<?= $dmg['image_data'] ?>" class="defect-thumbnail" onclick="paparLightbox('<?= $dmg['image_data'] ?>')" alt="Defect">
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-style: italic;">Tiada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span style="color: var(--danger); font-weight: 600;"><?= htmlspecialchars($dmg['issue_type']) ?></span></td>
                                            <td><?= htmlspecialchars($dmg['reported_by']) ?> (<?= ucfirst($dmg['report_role']) ?>)</td>
                                            <td class="text-center"><span class="<?= $status_class ?>" style="font-size: 0.8rem;"><?= $status_text ?></span></td>
                                            <td>
                                                <?php if ($dmg['status'] === 'Dibawa Balik'): ?>
                                                    <strong><?= htmlspecialchars($dmg['returned_by']) ?></strong><br>
                                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($dmg['return_date'])) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-style: italic;">Masih di outlet</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: SHARED EXPIRY MONITOR -->
            <div id="tab-admin-expiry" class="tab-content">
                <div class="card full-width">
                    <div class="card-header" style="border-bottom: 2px solid var(--danger);">
                        <span class="card-title" style="color: var(--danger);">📅 Papan Pemantauan Tarikh Luput Kelompok (Expiry Date Board)</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">
                        Berikut adalah laporan kelangsungan kesegaran stok yang dihantar ke outlet. Amaran keselamatan makanan diaktifkan apabila produk **UHT & Ice Cream menghampiri 20 hari sebelum tamat**, manakala produk **PST menghampiri 10 hari sebelum tamat**.
                    </p>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Nama Produk</th>
                                    <th>Kategori</th>
                                    <th class="text-center">Kelompok (Batch No)</th>
                                    <th class="text-center">Kuantiti Diterima (Pcs)</th>
                                    <th class="text-center">Tarikh Luput (Expiry Date)</th>
                                    <th class="text-center">Status Amaran Kelangsungan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_expiry_batches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center" style="color: var(--text-muted); padding: 20px 0;">Tiada rekod kelompok penghantaran stok aktif dijumpai.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_expiry_batches as $batch): ?>
                                        <?php 
                                            $badge_style = $batch['status_info']['warn'] ? 'expiry-warning-badge' : $batch['status_info']['class'];
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($batch['p_sku']) ?></strong></td>
                                            <td><?= htmlspecialchars($batch['p_name']) ?></td>
                                            <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($batch['category']) ?></span></td>
                                            <td class="text-center"><code><?= htmlspecialchars($batch['batch_no'] ?? 'N/A') ?></code></td>
                                            <td class="text-center" style="font-weight: 600;"><?= $batch['quantity'] ?> pcs</td>
                                            <td class="text-center" style="font-weight: bold;"><?= date('d/m/Y', strtotime($batch['expiry_date'])) ?></td>
                                            <td class="text-center">
                                                <span class="badge <?= $badge_style ?>"><?= $batch['status_info']['label'] ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 5: AUDIT LOG STOCK TAKE -->
            <div id="tab-audit-stok" class="tab-content">
                <div class="card full-width">
                    <div class="card-header">
                        <span class="card-title">Audit Log: Sejarah Pengiraan Stok & Lokasi Pecahan</span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Masa & Tarikh</th>
                                    <th>Produk</th>
                                    <th class="text-center">Pecahan Fizikal Mengikut Lokasi</th>
                                    <th class="text-center">Jumlah Fizikal</th>
                                    <th class="text-center">Stok Jangkaan Sistem</th>
                                    <th class="text-center">Variansi Dikesan</th>
                                    <th>Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($takes)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center" style="color: var(--text-muted); padding: 20px 0;">Belum ada rekod pengiraan stok (stock take) dibuat oleh staf outlet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($takes as $take): ?>
                                        <?php 
                                            $v_val = intval($take['variance']);
                                            $v_class = ($v_val < 0) ? 'negative-variance' : (($v_val > 0) ? 'positive-variance' : 'neutral-variance');
                                        ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i', strtotime($take['take_date'])) ?></td>
                                            <td>[<?= htmlspecialchars($take['p_sku']) ?>] <?= htmlspecialchars($take['p_name']) ?></td>
                                            <td>
                                                <span class="location-badge">❄️ Chiller: <strong><?= $take['chiller_qty'] ?></strong> pcs</span>
                                                <span class="location-badge">🪵 Rak: <strong><?= $take['shelf_qty'] ?></strong> pcs</span>
                                                <span class="location-badge">📦 Stor: <strong><?= $take['store_qty'] ?></strong> pcs</span>
                                            </td>
                                            <td class="text-center" style="font-weight: bold; background-color: #f8fafc;"><?= $take['physical_qty'] ?> pcs</td>
                                            <td class="text-center"><?= $take['theoretical_qty'] ?> pcs</td>
                                            <td class="text-center"><span class="<?= $v_class ?>"><?= $v_val ?></span></td>
                                            <td><?= htmlspecialchars($take['taken_by']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- EDIT MODAL -->
            <div id="editModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="modal-title">Edit Maklumat Produk</span>
                        <span class="close-btn" onclick="closeEditModal()">&times;</span>
                    </div>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="admin_edit_product">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="form-group">
                            <label for="edit_sku">Kod SKU</label>
                            <input type="text" name="sku" id="edit_sku" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_name">Nama Produk & Isipadu</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_category">Kategori Produk</label>
                            <select name="category" id="edit_category" class="form-control" required>
                                <optgroup label="Kategori UHT">
                                    <option value="UHT 100ml">UHT 100ml</option>
                                    <option value="UHT 115ml">UHT 115ml</option>
                                    <option value="UHT 125ml">UHT 125ml</option>
                                    <option value="UHT 200ml">UHT 200ml</option>
                                    <option value="UHT 1L">UHT 1L</option>
                                </optgroup>
                                <optgroup label="Kategori PST">
                                    <option value="PST 120g">PST 120g</option>
                                    <option value="PST 1.4kg">PST 1.4kg</option>
                                    <option value="PST 100ml">PST 100ml</option>
                                    <option value="PST 200ml">PST 200ml</option>
                                    <option value="PST 568ml">PST 568ml</option>
                                    <option value="PST 700ml">PST 700ml</option>
                                    <option value="PST 1L">PST 1L</option>
                                    <option value="PST 2L">PST 2L</option>
                                    <option value="Butter">Butter</option>
                                </optgroup>
                                <optgroup label="Kategori Ice Cream">
                                    <option value="IC 55ml">IC 55ml</option>
                                    <option value="IC 75ml">IC 75ml</option>
                                    <option value="IC 109ml">IC 109ml</option>
                                </optgroup>
                                <optgroup label="Lain-lain">
                                    <option value="Merchandise">Merchandise</option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_cost_price">Harga Kos Karton (RM)</label>
                                <input type="number" step="0.01" name="cost_price" id="edit_cost_price" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_retail_price">Harga Jual se-Unit / RSP (RM)</label>
                                <input type="number" step="0.01" name="retail_price" id="edit_retail_price" class="form-control" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="edit_carton_size">Kapasiti Carton (Unit per Carton)</label>
                            <select name="carton_size" id="edit_carton_size" class="form-control" required>
                                <option value="6">6 unit per Carton</option>
                                <option value="12">12 unit per Carton</option>
                                <option value="20">20 unit per Carton</option>
                                <option value="24">24 unit per Carton</option>
                                <option value="32">32 unit per Carton</option>
                            </select>
                        </div>

                        <button type="submit" class="btn" style="width: 100%;">Kemaskini Produk</button>
                    </form>
                </div>
            </div>

            <!-- CUSTOM CONFIRMATION MODAL FOR DELETION -->
            <div id="confirmModal" class="modal">
                <div class="modal-content" style="max-width: 420px; text-align: center;">
                    <div style="font-size: 3rem; color: var(--danger); margin-bottom: 15px;">⚠️</div>
                    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 10px;">Padam Produk?</h3>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5;">
                        Adakah anda pasti mahu memadam produk ini? Semua rekod jualan, penghantaran, dan pengiraan stok berkaitan akan terhapus selama-lamanya daripada sistem.
                    </p>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="action" value="admin_delete_product">
                        <input type="hidden" name="id" id="delete_product_id">
                        <div style="display: flex; gap: 12px; justify-content: center;">
                            <button type="button" class="btn btn-outline" onclick="closeConfirmModal()" style="flex: 1;">Batal</button>
                            <button type="submit" class="btn btn-danger" style="flex: 1;">Ya, Padam</button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- ================= DASHBOARD: STAF PENGHANTARAN ================= -->
        <?php elseif ($_SESSION['role'] === 'penghantar'): ?>
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('tab-hantar-stok')">🚚 Penghantaran Stok Baru</button>
                <button class="tab-btn" onclick="switchTab('tab-hantar-rosak')">💔 Ambil Stok Rosak (RN)</button>
                <button class="tab-btn" onclick="switchTab('tab-penghantar-expiry')">📅 Pemantauan Tarikh Luput</button>
            </div>

            <!-- SUB-TAB 1: HANTAR STOK BARU -->
            <div id="tab-hantar-stok" class="tab-content active">
                <div class="dashboard-grid">
                    <!-- Borang Rekod Penghantaran oleh Out-Staff (Sokongan Carton + Pcs + Expiry Date + Batch No) -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Rekod Hantar Stok Baru</span>
                        </div>
                        <form action="index.php" method="POST">
                            <input type="hidden" name="action" value="delivery_add">
                            
                            <!-- BARU: RUANGAN CARIAN PRODUK UNTUK PENGHANTAR -->
                            <div class="form-group" style="background-color:#f8fafc; padding: 10px; border-radius: 8px; border:1px solid var(--border-color);">
                                <label for="product_search_delivery" style="font-weight:700; color:var(--primary-color);">🔍 Cari Produk (SKU / Nama)</label>
                                <input type="text" id="product_search_delivery" class="form-control" onkeyup="tapisProdukPenghantar()" placeholder="Taip SKU atau nama produk untuk menapis...">
                            </div>

                            <div class="form-group">
                                <label for="product_id">Pilih Produk</label>
                                <select name="product_id" id="product_id" class="form-control" onchange="hitungPecahanPenghantar()" required>
                                    <option value="">-- Pilih satu produk --</option>
                                    <?php foreach ($products_list as $prod): ?>
                                        <option value="<?= $prod['id'] ?>" data-carton="<?= $prod['carton_size'] ?>">[<?= htmlspecialchars($prod['sku']) ?>] <?= htmlspecialchars($prod['name']) ?> (1 ctn = <?= $prod['carton_size'] ?> pcs)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Kuantiti yang Dihantar</label>
                                <div class="carton-pcs-container">
                                    <input type="number" name="delivery_carton" id="delivery_carton" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungPecahanPenghantar()" placeholder="0">
                                    <span class="carton-pcs-label">carton</span>
                                    <input type="number" name="delivery_pcs" id="delivery_pcs" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungPecahanPenghantar()" placeholder="0">
                                    <span class="carton-pcs-label">pcs</span>
                                </div>
                                <span id="delivery_calc_helper" class="helper-text" style="display: block; margin-top: 8px; font-weight: 600;">Jumlah Keseluruhan: 0 pcs</span>
                            </div>

                            <!-- NOMBOR KELOMPOK (BATCH NO) - MANDATORI -->
                            <div class="form-group">
                                <label for="batch_no">🏷️ Nombor Kelompok (Batch No)</label>
                                <input type="text" name="batch_no" id="batch_no" class="form-control" placeholder="cth: BATCH-2026A" required>
                                <small class="text-muted" style="display: block; margin-top: 4px;">*Wajib diisi mengikut label kod kelompok fizikal pada pembungkusan.</small>
                            </div>

                            <!-- TARIKH LUPUT (EXPIRY DATE) - MANDATORI -->
                            <div class="form-group">
                                <label for="expiry_date">📅 Tarikh Luput Kelompok Ini (Expiry Date)</label>
                                <input type="date" name="expiry_date" id="expiry_date" class="form-control" required>
                                <small class="text-muted" style="display: block; margin-top: 4px;">*Sila semak bungkusan karton fizikal untuk menetapkan tarikh luput yang tepat.</small>
                            </div>

                            <button type="submit" class="btn" style="width: 100%; margin-top: 15px;">Sahkan & Rekod Penghantaran</button>
                        </form>
                    </div>

                    <!-- SOP Ringkas Penghantar -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">SOP Penghantar Stok</span>
                        </div>
                        <ol style="padding-left: 20px; font-size: 0.88rem; color: var(--text-muted); line-height: 1.8;">
                            <li style="margin-bottom: 8px;">Gunakan kotak carian hijau di atas senarai dropdown untuk mencari nama produk dengan pantas.</li>
                            <li style="margin-bottom: 8px;">Letakkan stok di kawasan stor atau zon pemindahan di outlet.</li>
                            <li style="margin-bottom: 8px;">Sila rekodkan **Tarikh Luput (Expiry Date)** & **Nombor Kelompok (Batch No)** yang tertera pada bungkusan untuk membolehkan pengesanan keselamatan produk.</li>
                        </ol>
                    </div>

                    <!-- Rekod Penghantaran Peribadi -->
                    <div class="card full-width">
                        <div class="card-header">
                            <span class="card-title">Sejarah Rekod Penghantaran Anda</span>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Masa & Tarikh</th>
                                        <th>Produk</th>
                                        <th class="text-center">Kelompok (Batch No)</th>
                                        <th class="text-center">Kuantiti (Pcs)</th>
                                        <th class="text-center">Tarikh Luput (Expiry)</th>
                                        <th>Penerima / Penghantar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $user_dels = $db->query("SELECT d.*, p.name as p_name, p.sku as p_sku FROM deliveries d JOIN products p ON d.product_id = p.id ORDER BY d.delivery_date DESC LIMIT 10")->fetchAll();
                                    if (empty($user_dels)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center" style="color: var(--text-muted);">Belum ada rekod penghantaran dibuat.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($user_dels as $del): ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i', strtotime($del['delivery_date'])) ?></td>
                                                <td>[<?= htmlspecialchars($del['p_sku']) ?>] <?= htmlspecialchars($del['p_name']) ?></td>
                                                <td class="text-center"><code><?= htmlspecialchars($del['batch_no'] ?? 'N/A') ?></code></td>
                                                <td class="text-center" style="font-weight: bold;"><?= $del['quantity'] ?> pcs</td>
                                                <td class="text-center" style="color: var(--danger); font-weight: bold;">
                                                    <?= $del['expiry_date'] ? date('d/m/Y', strtotime($del['expiry_date'])) : '-' ?>
                                                </td>
                                                <td><?= htmlspecialchars($del['delivered_by']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SUB-TAB 2: AMBIL STOK ROSAK (RN) -->
            <div id="tab-hantar-rosak" class="tab-content">
                <div class="card full-width">
                    <div class="card-header">
                        <span class="card-title">💔 Pengambilan Stok Rosak dari Outlet (Proses Balik Warehouse / RN)</span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
                        Berikut adalah senarai stok rosak yang telah dilaporkan oleh staf outlet. Sila sahkan pengutipan fizikal dan klik butang **\"Sahkan Ambil & Bawa Balik\"** untuk membawanya balik ke gudang bagi tujuan Return Note (RN).
                    </p>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tarikh Dilapor</th>
                                    <th>SKU & Produk</th>
                                    <th class="text-center">Kelompok</th>
                                    <th class="text-center">Tarikh Luput</th>
                                    <th class="text-center">Kuantiti Rosak</th>
                                    <th class="text-center">Gambar Bukti</th>
                                    <th>Isu Defect</th>
                                    <th>Dilapor Oleh</th>
                                    <th class="text-center">Status Semasa</th>
                                    <th class="text-center">Tindakan Logistik</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $stmt_pending_dmg = $db->query("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size 
                                    FROM damaged_stock ds 
                                    JOIN products p ON ds.product_id = p.id 
                                    WHERE ds.status = 'Dilaporkan'
                                    ORDER BY ds.created_at DESC");
                                $pending_dmg_list = $stmt_pending_dmg->fetchAll();

                                if (empty($pending_dmg_list)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center" style="color: var(--text-muted); padding: 20px 0;">🎉 Hebat! Tiada stok rosak tertunggak yang perlu dibawa balik dari outlet buat masa ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pending_dmg_list as $pdmg): ?>
                                        <?php 
                                            $ctn = floor($pdmg['quantity'] / $pdmg['carton_size']);
                                            $pcs = $pdmg['quantity'] % $pdmg['carton_size'];
                                            $ctn_pcs_text = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
                                            if (empty($ctn_pcs_text)) $ctn_pcs_text = "0 pcs";
                                        ?>
                                        <tr>
                                            <td><?= date('d/m/Y H:i', strtotime($pdmg['created_at'])) ?></td>
                                            <td><strong>[<?= htmlspecialchars($pdmg['p_sku']) ?>]</strong> <?= htmlspecialchars($pdmg['p_name']) ?></td>
                                            <td class="text-center"><code><?= htmlspecialchars($pdmg['batch_no'] ?? 'N/A') ?></code></td>
                                            <td class="text-center"><?= $pdmg['expiry_date'] ? date('d/m/Y', strtotime($pdmg['expiry_date'])) : 'N/A' ?></td>
                                            <td class="text-center" style="font-weight: bold;"><?= $ctn_pcs_text ?> (<?= $pdmg['quantity'] ?> pcs)</td>
                                            <td class="text-center">
                                                <?php if (!empty($pdmg['image_data'])): ?>
                                                    <img src="<?= $pdmg['image_data'] ?>" class="defect-thumbnail" onclick="paparLightbox('<?= $pdmg['image_data'] ?>')" alt="Defect">
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-style: italic;">Tiada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span style="color: var(--danger); font-weight: bold;"><?= htmlspecialchars($pdmg['issue_type']) ?></span></td>
                                            <td><?= htmlspecialchars($pdmg['reported_by']) ?></td>
                                            <td class="text-center"><span class="negative-variance">⚠️ Menunggu Kutipan</span></td>
                                            <td class="text-center">
                                                <!-- FORM BAWA BALIK STOK ROSAK (Iframe safe confirmation) -->
                                                <form action="index.php" method="POST" onsubmit="return confirmAction(event, 'Adakah anda mengesahkan telah mengambil stok rosak ini secara fizikal dari outlet?')">
                                                    <input type="hidden" name="action" value="delivery_return_damaged">
                                                    <input type="hidden" name="damaged_id" value="<?= $pdmg['id'] ?>">
                                                    <button type="submit" class="btn btn-sm" style="background-color: var(--success);">🚚 Sahkan Bawa Balik</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SUB-TAB 3: SHARED EXPIRY MONITOR -->
            <div id="tab-penghantar-expiry" class="tab-content">
                <div class="card full-width">
                    <div class="card-header" style="border-bottom: 2px solid var(--danger);">
                        <span class="card-title" style="color: var(--danger);">📅 Pemantauan Tarikh Luput Kelompok (Expiry Monitor)</span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Nama Produk</th>
                                    <th>Kategori</th>
                                    <th class="text-center">Kelompok (Batch No)</th>
                                    <th class="text-center">Kuantiti Diterima (Pcs)</th>
                                    <th class="text-center">Tarikh Luput (Expiry Date)</th>
                                    <th class="text-center">Status Amaran Kelangsungan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_expiry_batches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center" style="color: var(--text-muted); padding: 20px 0;">Tiada rekod kelompok penghantaran stok aktif dijumpai.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_expiry_batches as $batch): ?>
                                        <?php 
                                            $badge_style = $batch['status_info']['warn'] ? 'expiry-warning-badge' : $batch['status_info']['class'];
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($batch['p_sku']) ?></strong></td>
                                            <td><?= htmlspecialchars($batch['p_name']) ?></td>
                                            <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($batch['category']) ?></span></td>
                                            <td class="text-center"><code><?= htmlspecialchars($batch['batch_no'] ?? 'N/A') ?></code></td>
                                            <td class="text-center" style="font-weight: 600;"><?= $batch['quantity'] ?> pcs</td>
                                            <td class="text-center" style="font-weight: bold;"><?= date('d/m/Y', strtotime($batch['expiry_date'])) ?></td>
                                            <td class="text-center">
                                                <span class="badge <?= $badge_style ?>"><?= $batch['status_info']['label'] ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>


        <!-- ================= DASHBOARD: STAF OUTLET ================= -->
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'outlet'): ?>
            <!-- BANNER PEMATUHAN STOK MINGGUAN -->
            <?php if ($days_since_last_take === null): ?>
                <div class="alert alert-error">
                    <span style="font-size: 1.2rem;">⚠️</span>
                    <div>
                        <strong>Peringatan Pematuhan:</strong> Anda belum pernah menghantar pengiraan stok (*Stock Take*). Sila jalankan pengiraan wajib mingguan anda hari ini.
                    </div>
                </div>
            <?php elseif ($days_since_last_take > 7): ?>
                <div class="alert alert-error">
                    <span style="font-size: 1.2rem;">🚨</span>
                    <div>
                        <strong>Amaran Kelewatan:</strong> Pengiraan stok terakhir anda dibuat pada <strong><?= $last_take_date_formatted ?></strong> (<?= $days_since_last_take ?> hari yang lalu). *Stock Take* wajib dijalankan sekurang-kurangnya <strong>seminggu sekali</strong>! Sila kemaskini baki hari ini juga.
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    <span style="font-size: 1.2rem;">✅</span>
                    <div>
                        <strong>Status Pematuhan Selamat:</strong> Pengiraan terakhir dibuat pada <strong><?= $last_take_date_formatted ?></strong> (<?= $days_since_last_take ?> hari lalu). Terima kasih kerana mematuhi jadual pengiraan mingguan outlet!
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB UNTUK OUTLET -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('tab-outlet-stocktake')">📦 Pengiraan Stok & Lokasi</button>
                <button class="tab-btn" onclick="switchTab('tab-outlet-damaged')">💔 Laporkan Stok Rosak / Defect (Wajib Gambar)</button>
                <button class="tab-btn" onclick="switchTab('tab-outlet-expiry')">📅 Pemantauan Tarikh Luput</button>
            </div>

            <!-- TAB 1: STOCK TAKE DENGAN PECAHAN LOKASI SECARA PUKAL (KINI WAJIB UNTUK BAKI > 0) -->
            <div id="tab-outlet-stocktake" class="tab-content active">
                <div style="margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color);">
                    <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 800;">📦 Pengiraan Stok & Lokasi Fizikal Pukal</h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                        Semua SKU yang mempunyai baki jangkaan sistem **wajib diisi** (masukkan '0' jika tiada fizikal). SKU yang tiada baki jangkaan sistem adalah pilihan. Sistem akan auto-mengira jualan berasaskan pengurangan baki.
                    </p>
                </div>

                <!-- Carian Produk Untuk Stock Take Pukal -->
                <div class="form-group" style="background-color:#f8fafc; padding: 12px; border-radius: 8px; border:1px solid var(--border-color); margin-bottom: 20px;">
                    <label for="product_search_take" style="font-weight:700; color:var(--primary-color);">🔍 Cari Produk dalam Senarai Pengiraan (SKU / Nama)</label>
                    <input type="text" id="product_search_take" class="form-control" onkeyup="tapisProdukOutletPukal()" placeholder="Taip SKU atau nama produk untuk menapis senarai di bawah...">
                </div>

                <!-- KOTAK NOTIFIKASI RALAT INPUT STOCK TAKE -->
                <div id="stocktake_error_container" class="alert alert-error" style="display: none; margin-bottom: 20px;">
                    <span style="font-size: 1.2rem;">⚠️</span>
                    <div id="stocktake_error_text" style="margin-left: 10px;"></div>
                </div>

                <form action="index.php" method="POST" onsubmit="return validasiStockTakePukal(event)">
                    <input type="hidden" name="action" value="outlet_add_stocktake">

                    <div id="stocktake_rows_container">
                        <?php foreach ($outlet_stocks as $stk): 
                            $is_mandatory = ($stk['baki'] > 0);
                        ?>
                            <div class="stocktake-row card" data-id="<?= $stk['id'] ?>" data-sku="<?= htmlspecialchars($stk['sku']) ?>" data-name="<?= htmlspecialchars($stk['name']) ?>" data-mandatory="<?= $is_mandatory ? 'true' : 'false' ?>" style="margin-bottom: 20px; padding: 20px; border-left: 5px solid <?= $is_mandatory ? 'var(--danger)' : 'var(--border-color)' ?>;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                    <div>
                                        <strong style="color: var(--primary-color);">[<?= htmlspecialchars($stk['sku']) ?>]</strong> 
                                        <span style="font-weight:700; color:var(--text-main);"><?= htmlspecialchars($stk['name']) ?></span>
                                        <span class="badge badge-penghantar" style="font-size:0.75rem; padding: 2px 6px; text-transform:none;">1 ctn = <?= $stk['carton_size'] ?> pcs</span>
                                    </div>
                                    <div>
                                        <span class="badge <?= $is_mandatory ? 'badge-admin' : 'badge-outlet' ?>" style="font-size: 0.75rem;">
                                            <?= $is_mandatory ? '⚠️ WAJIB (Jangkaan: ' . $stk['baki'] . ' pcs)' : 'PILIHAN (Jangkaan: 0 pcs)' ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px; align-items: end;">
                                    <!-- Chiller -->
                                    <div>
                                        <label style="font-size:0.8rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:4px;">❄️ Kuantiti Chiller</label>
                                        <div class="carton-pcs-container">
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][chiller_carton]" id="ctn_c_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">ctn</span>
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][chiller_pcs]" id="pcs_c_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">pcs</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Rak Display -->
                                    <div>
                                        <label style="font-size:0.8rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:4px;">🪵 Kuantiti di Rak / Display</label>
                                        <div class="carton-pcs-container">
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][shelf_carton]" id="ctn_s_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">ctn</span>
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][shelf_pcs]" id="pcs_s_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">pcs</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Stor Backroom -->
                                    <div>
                                        <label style="font-size:0.8rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:4px;">📦 Kuantiti dalam Stor</label>
                                        <div class="carton-pcs-container">
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][store_carton]" id="ctn_st_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">ctn</span>
                                            <input type="number" name="stocktake[<?= $stk['id'] ?>][store_pcs]" id="pcs_st_<?= $stk['id'] ?>" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungBarisPukal(<?= $stk['id'] ?>, <?= $stk['carton_size'] ?>)">
                                            <span class="carton-pcs-label">pcs</span>
                                        </div>
                                    </div>
                                    
                                    <!-- Live Total Pcs Display -->
                                    <div style="background-color: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); text-align: center;">
                                        <span style="font-size: 0.75rem; font-weight: bold; color: var(--text-muted); display:block;">Jumlah Kiraan</span>
                                        <span id="row_total_<?= $stk['id'] ?>" style="font-size: 1.1rem; font-weight: 800; color: var(--primary-color);">0 pcs</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- BUTANG SERAHKAN PENGIRAAN PUKAL -->
                    <button type="submit" class="btn" style="width: 100%; padding: 15px; font-size: 1.1rem; background-color: var(--warning); color: white;">💾 Serahkan Semua Pengiraan Stok Pukal</button>
                </form>
            </div>

            <!-- SUB-TAB 2: BORANG STOK ROSAK -->
            <div id="tab-outlet-damaged" class="tab-content">
                <div class="dashboard-grid">
                    <!-- Borang Laporkan Stok Rosak -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">💔 Lapor Stok Rosak (Factory Defect / Quality Issue)</span>
                        </div>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">
                            Sila isi borang ini secara lengkap bagi merekodkan stok defect kilang. **Semua ruangan (Tarikh Luput, Nombor Kelompok, dan Gambar Bukti) adalah wajib diisi.**
                        </p>
                        <!-- Pastikan enctype diletakkan untuk sokongan fail lampiran imej -->
                        <form action="index.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="outlet_add_damaged">
                            
                            <div class="form-group">
                                <label for="damaged_product_id">Pilih Produk Rosak</label>
                                <select name="product_id" id="damaged_product_id" class="form-control" onchange="hitungPecahanRosakOutlet()" required>
                                    <option value="">-- Pilih produk --</option>
                                    <?php foreach ($products_list as $prod): ?>
                                        <option value="<?= $prod['id'] ?>" data-carton="<?= $prod['carton_size'] ?>">[<?= htmlspecialchars($prod['sku']) ?>] <?= htmlspecialchars($prod['name']) ?> (1 ctn = <?= $prod['carton_size'] ?> pcs)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Kuantiti yang Rosak (Factory Defect / Quality Only)</label>
                                <div class="carton-pcs-container">
                                    <input type="number" name="damaged_carton" id="damaged_carton" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungPecahanRosakOutlet()" placeholder="0">
                                    <span class="carton-pcs-label">carton</span>
                                    <input type="number" name="damaged_pcs" id="damaged_pcs" class="form-control carton-pcs-input" min="0" value="0" oninput="hitungPecahanRosakOutlet()" placeholder="0">
                                    <span class="carton-pcs-label">pcs</span>
                                </div>
                                <span id="outlet_damaged_helper" class="helper-text" style="display: block; margin-top: 8px; font-weight: 600;">Jumlah Rosak: 0 pcs</span>
                            </div>

                            <!-- NOMBOR KELOMPOK (MANDATORI) -->
                            <div class="form-group">
                                <label for="dmg_batch_no">🏷️ Nombor Kelompok (Batch No) Terjejas</label>
                                <input type="text" name="batch_no" id="dmg_batch_no" class="form-control" placeholder="cth: BATCH-2026A" required>
                                <small class="text-muted" style="display: block; margin-top: 4px;">*Rujuk nombor kelompok pada bungkusan produk fizikal yang rosak.</small>
                            </div>

                            <!-- TARIKH LUPUT (MANDATORI) -->
                            <div class="form-group">
                                <label for="dmg_expiry_date">📅 Tarikh Luput Kelompok Terjejas (Expiry Date)</label>
                                <input type="date" name="expiry_date" id="dmg_expiry_date" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="issue_type">Jenis Isu Kerosakan</label>
                                <select name="issue_type" id="issue_type" class="form-control" required>
                                    <option value="Factory Defect">Kecacatan Kilang (Factory Defect)</option>
                                    <option value="Quality Issue">Isu Kualiti (Quality Issue)</option>
                                </select>
                            </div>

                            <!-- LAMPIRAN GAMBAR BUKTI KEROSAKAN - MANDATORI -->
                            <div class="form-group">
                                <label for="damaged_image">📸 Lampirkan Gambar Bukti Defect (Wajib)</label>
                                <input type="file" name="damaged_image" id="damaged_image" class="form-control" accept="image/*" onchange="previewImej(this)" required>
                                <small class="text-muted" style="display: block; margin-top: 4px;">*Sila ambil gambar defect yang jelas pada produk fizikal.</small>
                                
                                <!-- Preview container -->
                                <div id="image_preview_box" style="display:none; margin-top:12px;">
                                    <p style="font-size:0.8rem; font-weight:bold; color:var(--text-muted); margin-bottom:4px;">Pratonton Bukti:</p>
                                    <img id="image_preview_tag" src="" style="max-width: 150px; border-radius: 8px; border: 1px solid var(--border-color);" alt="Preview">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger" style="width: 100%;">Hantar Laporan Kerosakan</button>
                        </form>
                    </div>

                    <!-- Sejarah Stok Rosak Outlet Terkini -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Rekod Kerosakan Outlet Terkini</span>
                        </div>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tarikh Lapor</th>
                                        <th>Nama Produk</th>
                                        <th class="text-center">Kelompok</th>
                                        <th class="text-center">Kuantiti</th>
                                        <th class="text-center">Gambar</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($outlet_damaged_list)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center" style="color: var(--text-muted); padding: 15px 0;">Tiada rekod stok rosak didaftarkan baru-baru ini.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($outlet_damaged_list as $odmg): ?>
                                            <?php 
                                                $ctn = floor($odmg['quantity'] / $odmg['carton_size']);
                                                $pcs = $odmg['quantity'] % $odmg['carton_size'];
                                                $ctn_pcs_text = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
                                                if (empty($ctn_pcs_text)) $ctn_pcs_text = "0 pcs";

                                                $status_class = ($odmg['status'] === 'Dilaporkan') ? 'negative-variance' : 'positive-variance';
                                                $status_text = ($odmg['status'] === 'Dilaporkan') ? 'Dilaporkan' : 'Dibawa Balik';
                                            ?>
                                            <tr>
                                                <td><?= date('d/m/Y', strtotime($odmg['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($odmg['p_name']) ?></td>
                                                <td class="text-center"><code><?= htmlspecialchars($odmg['batch_no'] ?? 'N/A') ?></code></td>
                                                <td class="text-center" style="font-weight: bold;"><?= $ctn_pcs_text ?></td>
                                                <td class="text-center">
                                                    <?php if (!empty($odmg['image_data'])): ?>
                                                        <img src="<?= $odmg['image_data'] ?>" class="defect-thumbnail" onclick="paparLightbox('<?= $odmg['image_data'] ?>')" alt="Bukti">
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-style: italic;">Tiada</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><span class="<?= $status_class ?>" style="font-size: 0.75rem;"><?= $status_text ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SUB-TAB 3: SHARED EXPIRY MONITOR -->
            <div id="tab-outlet-expiry" class="tab-content">
                <div class="card full-width">
                    <div class="card-header" style="border-bottom: 2px solid var(--danger);">
                        <span class="card-title" style="color: var(--danger);">📅 Pemantauan Tarikh Luput Kelompok (Expiry Monitor)</span>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Nama Produk</th>
                                    <th>Kategori</th>
                                    <th class="text-center">Kelompok (Batch No)</th>
                                    <th class="text-center">Kuantiti Diterima (Pcs)</th>
                                    <th class="text-center">Tarikh Luput (Expiry Date)</th>
                                    <th class="text-center">Status Amaran Kelangsungan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_expiry_batches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center" style="color: var(--text-muted); padding: 20px 0;">Tiada rekod kelompok penghantaran stok aktif dijumpai.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_expiry_batches as $batch): ?>
                                        <?php 
                                            $badge_style = $batch['status_info']['warn'] ? 'expiry-warning-badge' : $batch['status_info']['class'];
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($batch['p_sku']) ?></strong></td>
                                            <td><?= htmlspecialchars($batch['p_name']) ?></td>
                                            <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;"><?= htmlspecialchars($batch['category']) ?></span></td>
                                            <td class="text-center"><code><?= htmlspecialchars($batch['batch_no'] ?? 'N/A') ?></code></td>
                                            <td class="text-center" style="font-weight: 600;"><?= $batch['quantity'] ?> pcs</td>
                                            <td class="text-center" style="font-weight: bold;"><?= date('d/m/Y', strtotime($batch['expiry_date'])) ?></td>
                                            <td class="text-center">
                                                <span class="badge <?= $badge_style ?>"><?= $batch['status_info']['label'] ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sejarah Jualan Terkini Outlet yang Terjana Secara Automatik -->
            <div class="card full-width">
                <div class="card-header">
                    <span class="card-title">Sejarah Jualan Terbina (Auto-Billed / Dituntut)</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Tarikh & Waktu</th>
                                <th>Produk</th>
                                <th class="text-center">Kuantiti Dituntut (Pcs)</th>
                                <th>Sebab Rekod</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $outlet_sales = $db->query("SELECT s.*, p.name as p_name, p.sku as p_sku FROM sales s JOIN products p ON s.product_id = p.id ORDER BY s.sale_date DESC LIMIT 10")->fetchAll();
                            if (empty($outlet_sales)): ?>
                                <tr>
                                    <td colspan="4" class="text-center" style="color: var(--text-muted);">Belum ada sebarang jualan direkodkan setakat ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($outlet_sales as $sale): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime($sale['sale_date'])) ?></td>
                                        <td>[<?= htmlspecialchars($sale['p_sku']) ?>] <?= htmlspecialchars($sale['p_name']) ?></td>
                                        <td class="text-center" style="font-weight: bold; color: var(--success);"><?= $sale['quantity'] ?> pcs</td>
                                        <td><span class="badge badge-admin" style="font-size: 0.7rem; padding: 2px 6px;">Sell-Through Auto-Bill</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- LIGHTBOX MODAL PREMIUM UNTUK MEMANTAL GAMBAR DEFECT (CSS + JS) -->
    <div id="lightboxModal" class="modal" onclick="tutupLightbox()" style="background-color: rgba(15, 23, 42, 0.85);">
        <div class="modal-content" style="background: none; border: none; box-shadow: none; display: flex; align-items: center; justify-content: center; position: relative;">
            <span class="close-btn" style="position: absolute; right: 10px; top: -35px; color: #fff; font-size: 2.5rem;" onclick="tutupLightbox()">&times;</span>
            <img id="lightboxImage" src="" style="max-width: 100%; max-height: 80vh; border-radius: 12px; border: 4px solid #fff; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);">
        </div>
    </div>

    <!-- CUSTOM HTML MODAL CONFIRMATION (Unobstructed, runs beautifully in iframe) -->
    <div id="customConfirmModal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center;">
            <div style="font-size: 2.5rem; color: var(--warning); margin-bottom: 12px;">💡</div>
            <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 8px;">Pengesahan Tindakan</h3>
            <p id="customConfirmText" style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 20px; line-height: 1.4;"></p>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="closeCustomConfirm(false)" style="flex: 1; padding: 8px;">Batal</button>
                <button type="button" class="btn" id="btnCustomConfirmSubmit" style="flex: 1; padding: 8px; background-color: var(--success);">Sahkan</button>
            </div>
        </div>
    </div>

    <script>
        // Pemboleh ubah global untuk penapisan produk secara dinamik
        let originalDeliveryOptions = [];
        let originalOutletOptions = [];

        window.onload = function() {
            // Ambil dan simpan pilihan produk asal pada dashboard Penghantar
            const selectDel = document.getElementById('product_id');
            if (selectDel) {
                originalDeliveryOptions = Array.from(selectDel.options).slice(1); // skip placeholder
            }
            // Ambil dan simpan pilihan produk asal pada dashboard Outlet
            const selectTake = document.getElementById('product_id_take');
            if (selectTake) {
                originalOutletOptions = Array.from(selectTake.options).slice(1); // skip placeholder
            }
        }

        // Fungsi carian/penapisan produk dinamik untuk Driver (Penghantar)
        function tapisProdukPenghantar() {
            const input = document.getElementById('product_search_delivery');
            const filter = input.value.toUpperCase();
            const select = document.getElementById('product_id');
            
            // Bersihkan pilihan kecuali placeholder
            select.innerHTML = '<option value="">-- Pilih satu produk --</option>';
            
            originalDeliveryOptions.forEach(opt => {
                if (opt.text.toUpperCase().includes(filter)) {
                    select.appendChild(opt.cloneNode(true));
                }
            });
            
            hitungPecahanPenghantar();
        }

        // BARU: Fungsi carian/penapisan produk dinamik untuk Staf Outlet (Stock Take Pukal)
        function tapisProdukOutletPukal() {
            const input = document.getElementById("product_search_take");
            const filter = input.value.toUpperCase();
            const rows = document.querySelectorAll(".stocktake-row");

            rows.forEach(row => {
                const sku = row.getAttribute("data-sku").toUpperCase();
                const name = row.getAttribute("data-name").toUpperCase();
                if (sku.includes(filter) || name.includes(filter)) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }

        // BARU: Validasi borang stock take pukal sebelum penghantaran
        function validasiStockTakePukal(event) {
            const rows = document.querySelectorAll('.stocktake-row[data-mandatory="true"]');
            let incomplete = false;
            let incompleteSku = '';
            let incompleteName = '';

            for (let row of rows) {
                const id = row.getAttribute('data-id');
                const cc = document.getElementById('ctn_c_' + id).value;
                const pc = document.getElementById('pcs_c_' + id).value;
                const cs = document.getElementById('ctn_s_' + id).value;
                const ps = document.getElementById('pcs_s_' + id).value;
                const cst = document.getElementById('ctn_st_' + id).value;
                const pst = document.getElementById('pcs_st_' + id).value;

                if (cc === '' || pc === '' || cs === '' || ps === '' || cst === '' || pst === '') {
                    incomplete = true;
                    incompleteSku = row.getAttribute('data-sku');
                    incompleteName = row.getAttribute('data-name');
                    break;
                }
            }

            if (incomplete) {
                event.preventDefault();
                const errBox = document.getElementById('stocktake_error_container');
                const errText = document.getElementById('stocktake_error_text');
                errText.innerHTML = `<strong>Ruangan Wajib Kosong!</strong> Produk <strong>[${incompleteSku}] ${incompleteName}</strong> wajib diisi kuantitinya kerana mempunyai baki jangkaan sistem. Masukkan '0' jika tiada baki fizikal.`;
                errBox.style.display = 'flex';
                errBox.scrollIntoView({ behavior: 'smooth' });
                return false;
            }

            // Sembunyikan ralat lama
            document.getElementById('stocktake_error_container').style.display = 'none';

            // Tunjukkan modal pengesahan kustom kustom
            confirmAction(event, 'Adakah anda pasti mahu menghantar pengiraan stok secara pukal ini? Sistem akan merekodkan jualan automatik sekiranya baki fizikal berkurang.');
            return false;
        }

        // BARU: Fungsi hitung baris stock take pukal secara langsung
        function hitungBarisPukal(id, cartonSize) {
            const chillerCarton = parseInt(document.getElementById('ctn_c_' + id).value) || 0;
            const chillerPcs = parseInt(document.getElementById('pcs_c_' + id).value) || 0;
            const shelfCarton = parseInt(document.getElementById('ctn_s_' + id).value) || 0;
            const shelfPcs = parseInt(document.getElementById('pcs_s_' + id).value) || 0;
            const storeCarton = parseInt(document.getElementById('ctn_st_' + id).value) || 0;
            const storePcs = parseInt(document.getElementById('pcs_st_' + id).value) || 0;

            const total = (chillerCarton * cartonSize) + chillerPcs + 
                          (shelfCarton * cartonSize) + shelfPcs + 
                          (storeCarton * cartonSize) + storePcs;

            document.getElementById('row_total_' + id).innerText = total + " pcs";
        }

        // Logik pertukaran tab di bahagian dashboard admin dan outlet
        function switchTab(tabId) {
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));

            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(button => button.classList.remove('active'));

            document.getElementById(tabId).classList.add('active');
            
            // Cari butang yang diklik berasaskan tabId
            const activeBtn = Array.from(buttons).find(btn => btn.getAttribute('onclick').includes(tabId));
            if (activeBtn) activeBtn.classList.add('active');
        }

        // Fungsi pembantu penukaran Unit Karton ke Pcs secara real-time
        function kemaskiniKiraanPendaraban(selectId, unitId, inputId, displayId) {
            const selectEl = document.getElementById(selectId);
            const unitEl = document.getElementById(unitId);
            const inputEl = document.getElementById(inputId);
            const displayEl = document.getElementById(displayId);

            if (!selectEl || !unitEl || !inputEl || !displayEl) return;

            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const cartonSize = parseInt(selectedOption.getAttribute('data-carton')) || 0;
            const unitVal = unitEl.value;
            const quantityVal = parseInt(inputEl.value) || 0;

            if (quantityVal > 0 && cartonSize > 0) {
                if (unitVal === 'carton') {
                    const totalPcs = quantityVal * cartonSize;
                    displayEl.innerText = `⚙️ Kiraan Aliran: ${quantityVal} Karton × ${cartonSize} pcs = ${totalPcs} pcs`;
                } else {
                    displayEl.innerText = `⚙️ Kiraan Aliran: ${quantityVal} pcs (Satu-satu)`;
                }
            } else {
                displayEl.innerText = '';
            }
        }

        // Fungsi Auto-Kira Pecahan Lokasi Fizikal (Chiller, Rak, Stor) secara langsung daripada Carton + Pcs (Masih disimpan sebagai fallback jika dipanggil)
        function hitungPecahanFizikalOutlet() {
            const selectEl = document.getElementById('product_id_take');
            if (!selectEl) return;

            if (selectEl.selectedIndex < 0) return;
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const cartonSize = parseInt(selectedOption.getAttribute('data-carton')) || 0;

            if (selectEl.value === "") {
                document.getElementById('chiller_qty').value = 0;
                document.getElementById('chiller_total_label').innerText = "Jumlah Chiller: Sila pilih produk terlebih dahulu";
                document.getElementById('shelf_qty').value = 0;
                document.getElementById('shelf_total_label').innerText = "Jumlah Rak: Sila pilih produk terlebih dahulu";
                document.getElementById('store_qty').value = 0;
                document.getElementById('store_total_label').innerText = "Jumlah Stor: Sila pilih produk terlebih dahulu";
                document.getElementById('total_physical_display').value = "0 pcs";
                return;
            }

            // 1. Kiraan Chiller
            const chillerCtn = parseInt(document.getElementById('chiller_carton').value) || 0;
            const chillerPcs = parseInt(document.getElementById('chiller_pcs').value) || 0;
            const totalChiller = (chillerCtn * cartonSize) + chillerPcs;
            document.getElementById('chiller_qty').value = totalChiller;
            document.getElementById('chiller_total_label').innerText = `Jumlah Chiller: ${totalChiller} pcs (${chillerCtn} ctn + ${chillerPcs} pcs)`;

            // 2. Kiraan Rak Display
            const shelfCtn = parseInt(document.getElementById('shelf_carton').value) || 0;
            const shelfPcs = parseInt(document.getElementById('shelf_pcs').value) || 0;
            const totalShelf = (shelfCtn * cartonSize) + shelfPcs;
            document.getElementById('shelf_qty').value = totalShelf;
            document.getElementById('shelf_total_label').innerText = `Jumlah Rak: ${totalShelf} pcs (${shelfCtn} ctn + ${shelfPcs} pcs)`;

            // 3. Kiraan Stor
            const storeCtn = parseInt(document.getElementById('store_carton').value) || 0;
            const storePcs = parseInt(document.getElementById('store_pcs').value) || 0;
            const totalStore = (storeCtn * cartonSize) + storePcs;
            document.getElementById('store_qty').value = totalStore;
            document.getElementById('store_total_label').innerText = `Jumlah Stor: ${totalStore} pcs (${storeCtn} ctn + ${storePcs} pcs)`;

            // 4. Jumlah Keseluruhan
            const totalGrand = totalChiller + totalShelf + totalStore;
            document.getElementById('total_physical_display').value = totalGrand + " pcs";
        }

        // Fungsi Auto-Kira bagi Penghantar (Carton + Pcs) secara langsung
        function hitungPecahanPenghantar() {
            const selectEl = document.getElementById('product_id');
            if (!selectEl) return;

            if (selectEl.selectedIndex < 0) return;
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const cartonSize = parseInt(selectedOption.getAttribute('data-carton')) || 0;
            const displayEl = document.getElementById('delivery_calc_helper');

            if (selectEl.value === "") {
                displayEl.innerText = "Jumlah Keseluruhan: Sila pilih produk terlebih dahulu";
                return;
            }

            const carton = parseInt(document.getElementById('delivery_carton').value) || 0;
            const pcs = parseInt(document.getElementById('delivery_pcs').value) || 0;
            const total = (carton * cartonSize) + pcs;

            displayEl.innerText = `⚙️ Kiraan Keseluruhan: ${total} pcs (${carton} ctn × ${cartonSize} + ${pcs} pcs)`;
        }

        // Fungsi Auto-Kira bagi Borang Stok Rosak Outlet (Carton + Pcs)
        function hitungPecahanRosakOutlet() {
            const selectEl = document.getElementById('damaged_product_id');
            if (!selectEl) return;

            if (selectEl.selectedIndex < 0) return;
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const cartonSize = parseInt(selectedOption.getAttribute('data-carton')) || 0;
            const displayEl = document.getElementById('outlet_damaged_helper');

            if (selectEl.value === "") {
                displayEl.innerText = "Jumlah Rosak: Sila pilih produk terlebih dahulu";
                return;
            }

            const carton = parseInt(document.getElementById('damaged_carton').value) || 0;
            const pcs = parseInt(document.getElementById('damaged_pcs').value) || 0;
            const total = (carton * cartonSize) + pcs;

            displayEl.innerText = `⚙️ Jumlah Rosak Keseluruhan: ${total} pcs (${carton} ctn × ${cartonSize} + ${pcs} pcs)`;
        }

        // GANTI CONFIRMATION BOX PENGHANTAR (Iframe Safe Modal Confirmation)
        let pendingFormToSubmit = null;
        function confirmAction(event, message) {
            event.preventDefault();
            pendingFormToSubmit = event.target;
            document.getElementById('customConfirmText').innerText = message;
            document.getElementById('customConfirmModal').style.display = 'flex';
            document.getElementById('btnCustomConfirmSubmit').onclick = function() {
                if (pendingFormToSubmit) {
                    pendingFormToSubmit.submit();
                }
            };
        }

        function closeCustomConfirm(status) {
            document.getElementById('customConfirmModal').style.display = 'none';
            pendingFormToSubmit = null;
        }

        // Pembaca Fail Excel Menggunakan SheetJS (XLSX Parser) di Browser (KALIS DATA KOSONG)
        function bacaFailExcel(event) {
            const file = event.target.files[0];
            const errorContainer = document.getElementById('import_error_container');
            const errorText = document.getElementById('import_error_text');
            const previewSection = document.getElementById('excel_preview_section');
            
            // Sembunyikan notifikasi ralat lama
            errorContainer.style.display = 'none';
            previewSection.style.display = 'none';

            if (!file) return;

            document.getElementById('file_name_display').innerText = "📂 " + file.name;

            const reader = new FileReader();
            reader.onload = function(e) {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                // Tukar sheet Excel kepada JSON array
                const jsonData = XLSX.utils.sheet_to_json(worksheet);
                
                if (jsonData.length === 0) {
                    errorText.innerText = "Gagal memproses fail Excel! Fail kosong atau format lembaran tidak disokong.";
                    errorContainer.style.display = 'flex';
                    return;
                }

                // Pengesahan nama lajur (Melihat padanan Melayu/Inggeris)
                const standardizedData = jsonData.map((row, index) => {
                    const rowNum = index + 2; // Baris Excel bermula dari 2 (Baris 1 ialah header)
                    
                    // Terima kolum Harga Kos atau Harga Kos Karton secara fleksibel
                    const rawCost = row['Harga Kos Karton'] !== undefined ? row['Harga Kos Karton'] : 
                                    (row['Harga Kos'] !== undefined ? row['Harga Kos'] : 
                                    (row['Cost Price'] !== undefined ? row['Cost Price'] : 
                                    (row['cost_price'] !== undefined ? row['cost_price'] : '')));

                    const rawRetail = row['Harga Jual'] !== undefined ? row['Harga Jual'] : 
                                      (row['Harga Jual (Pcs)'] !== undefined ? row['Harga Jual (Pcs)'] : 
                                      (row['Retail Price'] !== undefined ? row['Retail Price'] : 
                                      (row['retail_price'] !== undefined ? row['retail_price'] : '')));

                    const rawCartonSize = row['Saiz Karton'] !== undefined ? row['Saiz Karton'] : 
                                          (row['Saiz Carton'] !== undefined ? row['Saiz Carton'] : 
                                          (row['Carton Size'] !== undefined ? row['Carton Size'] : 
                                          (row['carton_size'] !== undefined ? row['carton_size'] : '')));

                    return {
                        rowNumber: rowNum,
                        sku: row['SKU'] || row['sku'] || '',
                        name: row['Nama'] || row['Nama Produk'] || row['Name'] || row['name'] || '',
                        category: row['Kategori'] || row['Category'] || row['category'] || 'UHT 125ml',
                        cost_price: rawCost !== '' ? parseFloat(rawCost) : NaN, 
                        retail_price: rawRetail !== '' ? parseFloat(rawRetail) : NaN,
                        carton_size: rawCartonSize !== '' ? parseInt(rawCartonSize) : 12
                    };
                });

                // Senaraikan semua baris data di dalam pratonton jadual
                const tbody = document.getElementById('excel_preview_tbody');
                tbody.innerHTML = '';
                
                let totalRowsWithFatalErrors = 0;
                let hasWarnings = false;
                const validatedItems = [];

                standardizedData.forEach(item => {
                    // Semak ralat kritikal (Missing SKU atau Nama)
                    const hasFatalError = (item.sku === '' || item.name === '');
                    
                    // Semak ralat amaran harga kosong/0
                    const isCostEmpty = isNaN(item.cost_price) || item.cost_price <= 0;
                    const isRetailEmpty = isNaN(item.retail_price) || item.retail_price <= 0;

                    if (hasFatalError) {
                        totalRowsWithFatalErrors++;
                    } else {
                        validatedItems.push({
                            sku: item.sku,
                            name: item.name,
                            category: item.category,
                            cost_price: isCostEmpty ? 0.00 : item.cost_price,
                            retail_price: isRetailEmpty ? 0.00 : item.retail_price,
                            carton_size: isNaN(item.carton_size) ? 12 : item.carton_size
                        });
                    }

                    if (isCostEmpty || isRetailEmpty) {
                        hasWarnings = true;
                    }

                    // Tentukan latar belakang khas bagi sel yang kosong
                    const costStyle = isCostEmpty ? "background-color: #fee2e2; color: #dc2626; font-weight: bold;" : "";
                    const retailStyle = isRetailEmpty ? "background-color: #fee2e2; color: #dc2626; font-weight: bold;" : "";
                    const rowStyle = hasFatalError ? "background-color: #fef2f2; opacity: 0.7;" : "";

                    const displayCost = isCostEmpty ? "Kosong (Diset RM0.00)" : `RM ${item.cost_price.toFixed(2)}`;
                    const displayRetail = isRetailEmpty ? "Kosong (Diset RM0.00)" : `RM ${item.retail_price.toFixed(2)}`;

                    const tr = document.createElement('tr');
                    tr.style = rowStyle;
                    tr.innerHTML = `
                        <td><strong>${item.sku || '<span style="color:red;font-style:italic;">SKU Hilang</span>'}</strong></td>
                        <td>${item.name || '<span style="color:red;font-style:italic;">Nama Hilang</span>'}</td>
                        <td><span class="badge badge-outlet" style="background-color:#f1f5f9; color:#1e293b;">${item.category}</span></td>
                        <td class="text-right" style="${costStyle}">${displayCost}</td>
                        <td class="text-right" style="${retailStyle}">${displayRetail}</td>
                        <td class="text-center">${isNaN(item.carton_size) ? '12 (Default)' : item.carton_size + ' pcs'}</td>
                    `;
                    tbody.appendChild(tr);
                });

                // Paparkan kotak amaran atau ralat sekiranya lajur lajur kosong dikesan
                if (totalRowsWithFatalErrors > 0) {
                    errorText.innerHTML = `Dikesan <strong>${totalRowsWithFatalErrors} baris beralat kritikal</strong> (Hilang SKU atau Nama). Baris ini telah diwarnakan dan dikecualikan daripada proses import.`;
                    errorContainer.className = "alert alert-error";
                    errorContainer.style.display = 'flex';
                } else if (hasWarnings) {
                    errorText.innerHTML = "<strong>Makluman:</strong> Beberapa produk mempunyai harga kos atau harga jual yang kosong/bernilai 0 (diwarnakan merah). Sistem membenarkan import ini tetapi harga kos produk tersebut akan didaftarkan sebagai <strong>RM0.00</strong> sementara waktu.";
                    errorContainer.className = "alert alert-warning";
                    errorContainer.style.display = 'flex';
                }

                if (validatedItems.length === 0) {
                    errorText.innerHTML = "<strong>Ralat Kritikal:</strong> Tiada baris data sah ditemui di dalam fail Excel anda. Sila semak semula fail anda.";
                    errorContainer.className = "alert alert-error";
                    errorContainer.style.display = 'flex';
                    document.getElementById('btn_import_submit').disabled = true;
                } else {
                    document.getElementById('btn_import_submit').disabled = false;
                }

                // Letakkan data JSON ke dalam hidden input untuk diserahkan ke PHP backend
                document.getElementById('import_data_field').value = JSON.stringify(validatedItems);
                previewSection.style.display = 'block';
            };
            reader.readAsArrayBuffer(file);
        }

        // Penjana Templat Excel .csv dinamik untuk dimuat turun oleh Admin
        function janaDanTurunTemplatCSV() {
            const headers = ["SKU", "Nama", "Kategori", "Harga Kos", "Harga Jual", "Saiz Karton"];
            const rows = [
                ["SKU-COF-125ML", "Minuman Kopi Premium 125ml", "UHT 125ml", "48.00", "2.50", "32"],
                ["SKU-COF-200ML", "Minuman Kopi Premium 200ml", "UHT 200ml", "48.00", "3.20", "24"],
                ["SKU-COF-1L", "Minuman Kopi Premium 1L", "UHT 1L", "102.00", "12.00", "12"]
            ];
            
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += headers.join(",") + "\r\n";
            rows.forEach(row => {
                csvContent += row.map(val => `"${val}"`).join(",") + "\r\n";
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "templat_import_produk_susumura.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Pratonton imej kerosakan sebelum dihantar oleh outlet
        function previewImej(input) {
            const previewBox = document.getElementById('image_preview_box');
            const previewTag = document.getElementById('image_preview_tag');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewTag.src = e.target.result;
                    previewBox.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                previewBox.style.display = 'none';
            }
        }

        // Paparkan Lightbox Modal untuk Imej Defect yang ditekan
        function paparLightbox(src) {
            const lightbox = document.getElementById('lightboxModal');
            const lightboxImg = document.getElementById('lightboxImage');
            lightboxImg.src = src;
            lightbox.style.display = 'flex';
        }

        // Tutup Lightbox Modal
        function tutupLightbox() {
            document.getElementById('lightboxModal').style.display = 'none';
        }

        // Modal Edit Produk Admin
        function openEditModal(id, sku, name, cost, retail, carton, category) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_sku').value = sku;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_cost_price').value = cost;
            document.getElementById('edit_retail_price').value = retail;
            document.getElementById('edit_carton_size').value = carton;
            document.getElementById('edit_category').value = category;
            document.getElementById('editModal').style.display = 'flex';
        }

        // Tutup Edit Modal
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Modal Pengesahan Padam Produk
        function openConfirmModal(productId) {
            document.getElementById('delete_product_id').value = productId;
            document.getElementById('confirmModal').style.display = 'flex';
        }

        // Tutup Pengesahan Modal
        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        // Tapis Jadual Produk Menggunakan JavaScript
        function filterAdminTable() {
            const input = document.getElementById("adminSearchInput");
            const filter = input.value.toUpperCase();
            const table = document.getElementById("adminProductTable");
            const tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) {
                const tdSku = tr[i].getElementsByTagName("td")[0];
                const tdName = tr[i].getElementsByTagName("td")[1];
                if (tdSku || tdName) {
                    const textSku = tdSku.textContent || tdSku.innerText;
                    const textName = tdName.textContent || tdName.innerText;
                    if (textSku.toUpperCase().indexOf(filter) > -1 || textName.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        // Tutup modal sekiranya pengguna klik di luar modal
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const confirmModal = document.getElementById('confirmModal');
            const customConfirmModal = document.getElementById('customConfirmModal');
            if (event.target == editModal) {
                editModal.style.display = "none";
            }
            if (event.target == confirmModal) {
                confirmModal.style.display = "none";
            }
            if (event.target == customConfirmModal) {
                customConfirmModal.style.display = "none";
            }
        }
    </script>
</body>
</html>