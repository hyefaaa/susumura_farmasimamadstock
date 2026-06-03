<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

if (!function_exists('dapatkan_status_expiry')) {
    die("<div style='padding:20px;color:red;background:#fee2e2;'><strong>Ralat Kritikal:</strong> Fungsi 'dapatkan_status_expiry' tiada dalam config.php!</div>");
}

$msg = $msg ?? '';
$msg_type = $msg_type ?? 'success';

if (($_SESSION['role'] ?? '') !== 'admin') {
    die("Akses dinafikan. Anda bukan admin.");
}

// ── POST HANDLERS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'admin_add_product') {
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $retail_price = floatval($_POST['retail_price'] ?? 0);
        $carton_size = intval($_POST['carton_size'] ?? 12);
        $category = trim($_POST['category'] ?? 'UHT 125ml');
        if ($sku !== '' && $name !== '') {
            try {
                $stmt = $db->prepare("INSERT INTO products (sku, name, cost_price, retail_price, carton_size, category) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$sku, $name, $cost_price, $retail_price, $carton_size, $category]);
                set_msg("Produk [$sku] $name berjaya didaftarkan!", "success");
            } catch (PDOException $e) {
                set_msg($e->getCode() == 23000 ? "Ralat: SKU telah wujud." : "Ralat: " . $e->getMessage(), "error");
            }
        }
        header("Location: admin.php"); exit;
    }

    if ($action === 'admin_edit_product') {
        $id = intval($_POST['id'] ?? 0);
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $retail_price = floatval($_POST['retail_price'] ?? 0);
        $carton_size = intval($_POST['carton_size'] ?? 12);
        $category = trim($_POST['category'] ?? 'UHT 125ml');
        if ($id > 0) {
            try {
                $stmt = $db->prepare("UPDATE products SET sku=?,name=?,cost_price=?,retail_price=?,carton_size=?,category=? WHERE id=?");
                $stmt->execute([$sku, $name, $cost_price, $retail_price, $carton_size, $category, $id]);
                set_msg("Produk [$sku] berjaya dikemaskini!", "success");
            } catch (PDOException $e) { set_msg("Gagal kemaskini.", "error"); }
        }
        header("Location: admin.php"); exit;
    }

    if ($action === 'admin_delete_product') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) { $db->prepare("DELETE FROM products WHERE id=?")->execute([$id]); set_msg("Produk dipadamkan.", "success"); }
        header("Location: admin.php"); exit;
    }

    if ($action === 'admin_import_excel') {
        $import_items = json_decode($_POST['import_data'] ?? '', true);
        if (is_array($import_items) && !empty($import_items)) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO products (sku,name,cost_price,retail_price,carton_size,category) VALUES (:sku,:name,:cost_price,:retail_price,:carton_size,:category) ON DUPLICATE KEY UPDATE name=VALUES(name),cost_price=VALUES(cost_price),retail_price=VALUES(retail_price),carton_size=VALUES(carton_size),category=VALUES(category)");
                $count = 0;
                foreach ($import_items as $item) {
                    if (!empty($item['sku']) && !empty($item['name'])) {
                        $stmt->execute([':sku'=>strtoupper($item['sku']),':name'=>$item['name'],':cost_price'=>floatval($item['cost_price']),':retail_price'=>floatval($item['retail_price']),':carton_size'=>intval($item['carton_size']),':category'=>$item['category']]);
                        $count++;
                    }
                }
                $db->commit();
                set_msg("Berjaya import $count produk!", "success");
            } catch (Exception $e) { $db->rollBack(); set_msg("Gagal import.", "error"); }
        }
        header("Location: admin.php"); exit;
    }
}

// ── DATA QUERIES ─────────────────────────────────────────────────────────────
$total_products_count = 0; $total_delivered_units = 0; $total_sold_units = 0;
$total_sales_revenue = 0; $total_wholesale_cost = 0; $total_expiry_warnings = 0;
$total_damaged_reported_pcs = 0;

$admin_summary = $db->query("
    SELECT p.*,
        (SELECT COALESCE(SUM(quantity),0) FROM deliveries WHERE product_id=p.id) as total_delivered,
        (SELECT COALESCE(SUM(quantity),0) FROM sales WHERE product_id=p.id) as total_sold,
        (SELECT COALESCE(SUM(quantity),0) FROM damaged_stock WHERE product_id=p.id) as total_damaged,
        (SELECT variance FROM stock_takes WHERE product_id=p.id ORDER BY take_date DESC LIMIT 1) as last_variance
    FROM products p ORDER BY p.sku ASC
")->fetchAll();
$total_products_count = count($admin_summary);
$processed_restock_list = [];

foreach ($admin_summary as $item) {
    $total_delivered_units += $item['total_delivered'];
    $total_sold_units += $item['total_sold'];
    $total_sales_revenue += ($item['total_sold'] * floatval($item['retail_price']));
    $total_wholesale_cost += ($item['total_sold'] * floatval($item['cost_price'] ?? 0));
    $current_stock = $item['total_delivered'] - $item['total_sold'] - $item['total_damaged'];
    $stmt_r = $db->prepare("SELECT COALESCE(SUM(quantity),0) as qty FROM sales WHERE product_id=? AND sale_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt_r->execute([$item['id']]);
    $recent_sales = intval($stmt_r->fetch()['qty'] ?? 0);
    $target_stock = max($recent_sales * 2, $item['carton_size']);
    $item['current_stock_pcs'] = $current_stock;
    $item['suggested_restock_pcs'] = $target_stock - $current_stock;
    $item['priority_score'] = ($current_stock <= ($recent_sales * 0.5) || $current_stock <= 0) ? 1 : (($current_stock < $target_stock) ? 2 : 3);
    $item['recent_sales_7d'] = $recent_sales;
    $processed_restock_list[] = $item;
}
usort($processed_restock_list, function($a, $b) {
    return $a['priority_score'] === $b['priority_score'] ? strcmp($a['name'], $b['name']) : $a['priority_score'] <=> $b['priority_score'];
});

$gross_profit = $total_sales_revenue - $total_wholesale_cost;
$margin_pct = $total_sales_revenue > 0 ? round(($gross_profit / $total_sales_revenue) * 100, 1) : 0;

$all_damaged_logs = $db->query("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size FROM damaged_stock ds JOIN products p ON ds.product_id=p.id ORDER BY ds.created_at DESC")->fetchAll();
foreach ($all_damaged_logs as $dmg) {
    if ($dmg['status'] === 'Dilaporkan') $total_damaged_reported_pcs += $dmg['quantity'];
}

$takes = $db->query("
    SELECT s.*, p.name as p_name, p.sku as p_sku
    FROM stock_takes s
    JOIN products p ON s.product_id = p.id
    ORDER BY s.take_date DESC, s.id DESC
    LIMIT 100
")->fetchAll();

$all_expiry_batches = [];
$raw_batches = $db->query("SELECT d.*, p.name as p_name, p.sku as p_sku, p.category, p.carton_size FROM deliveries d JOIN products p ON d.product_id=p.id ORDER BY d.expiry_date ASC")->fetchAll();
foreach ($raw_batches as $batch) {
    $status_data = dapatkan_status_expiry($batch['expiry_date'], $batch['category']);
    if ($status_data['warn']) $total_expiry_warnings++;
    $batch['status_info'] = $status_data;
    $all_expiry_batches[] = $batch;
}
$expiry_expired = array_filter($all_expiry_batches, fn($b) => $b['status_info']['days'] < 0);
$expiry_warn    = array_filter($all_expiry_batches, fn($b) => $b['status_info']['warn'] && $b['status_info']['days'] >= 0);
$expiry_safe    = array_filter($all_expiry_batches, fn($b) => !$b['status_info']['warn'] && $b['status_info']['days'] >= 0);

$categories_list = ['UHT 100ml','UHT 115ml','UHT 125ml','UHT 180ml','UHT 200ml','UHT 1L','IC 55ml','IC 75ml','IC 109ml','PST 120g','PST 100ml','PST 200ml','PST 568ml','PST 700ml','PST 1L','PST 1.4kg','PST 2L','Butter','Merchandise','POWDER','YOGURT','GROW CULTURE'];
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Susumura Farmasi Mamad</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
.material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.fill-icon { font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
body { font-family:'Plus Jakarta Sans',sans-serif; background:#f7f9fb; }
.tab-content { display:none; }
.tab-content.active { display:block; }
.nav-item.active { background:#eff6ff; color:#2563eb; border-left:4px solid #2563eb; font-weight:700; }
::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:#f1f5f9; }
::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; }
.glass { backdrop-filter:blur(12px); background:rgba(255,255,255,0.8); border:1px solid rgba(255,255,255,0.3); }
</style>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        primary:'#2563eb', secondary:'#ec4899',
        'primary-light':'#eff6ff', 'secondary-light':'#fdf2f8',
        'surface':'#f7f9fb', 'surface-low':'#f2f4f6',
        'on-surface':'#191c1e', 'on-surface-v':'#434655',
        'outline':'#737686', 'outline-v':'#c3c6d7'
      }
    }
  }
}
</script>
</head>
<body class="text-on-surface min-h-screen flex">

<!-- ── SIDEBAR ── -->
<aside class="hidden lg:flex flex-col h-screen w-64 bg-white border-r border-outline-v fixed left-0 top-0 z-50 py-6 px-4">
  <div class="px-3 mb-8">
    <h1 class="text-xl font-extrabold text-primary tracking-tight">Susumura</h1>
    <p class="text-xs text-on-surface-v mt-0.5">Stock Management</p>
  </div>
  <nav class="flex-1 space-y-1" id="sideNav">
    <a onclick="switchTab('dashboard')" class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">dashboard</span> Dashboard
    </a>
    <a onclick="switchTab('katalog')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">inventory_2</span> Katalog Produk
    </a>
    <a onclick="switchTab('penghantaran')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">local_shipping</span> Penghantaran
    </a>
    <a onclick="switchTab('jualan')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">analytics</span> Jualan & Stok Take
    </a>
    <a onclick="switchTab('rosak')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">heart_broken</span> Stok Rosak
    </a>
    <a onclick="switchTab('luput')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">event_busy</span> Tarikh Luput
    </a>
    <a onclick="switchTab('audit')" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">history</span> Audit Log
    </a>
  </nav>
  <div class="mt-auto px-3 pt-4 border-t border-outline-v">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-9 h-9 rounded-full bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white font-bold text-sm">
        <?= strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)) ?>
      </div>
      <div>
        <p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?></p>
        <p class="text-[10px] text-on-surface-v">Administrator</p>
      </div>
    </div>
    <form action="logout.php" method="POST">
      <button type="submit" class="w-full py-2 text-xs font-bold text-on-surface-v border border-outline-v rounded-lg hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all">Log Keluar</button>
    </form>
  </div>
</aside>

<!-- ── MAIN ── -->
<main class="flex-1 lg:ml-64 flex flex-col min-h-screen">

  <!-- TOP HEADER -->
  <header class="sticky top-0 z-40 bg-gradient-to-r from-secondary to-primary px-8 py-4 shadow-lg">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-4">
        <span class="text-2xl font-extrabold text-white tracking-tight">Farmasi Mamad</span>
        <?php if ($msg !== ''): ?>
        <div class="hidden md:flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold <?= $msg_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
          <span class="material-symbols-outlined text-[14px]"><?= $msg_type === 'success' ? 'check_circle' : 'error' ?></span>
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-3">
        <span class="text-white/80 text-sm hidden sm:block"><?= htmlspecialchars($_SESSION['fullname'] ?? '') ?></span>
        <span class="px-3 py-1 rounded-full bg-white/20 text-white text-xs font-bold uppercase tracking-wide">Admin</span>
      </div>
    </div>
  </header>

  <?php if ($msg !== ''): ?>
  <div class="md:hidden mx-6 mt-4 flex items-center gap-2 px-4 py-3 rounded-xl text-sm font-bold <?= $msg_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
    <span class="material-symbols-outlined text-[18px]"><?= $msg_type === 'success' ? 'check_circle' : 'error' ?></span>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <div class="flex-1 p-6 lg:p-8">

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: DASHBOARD                            -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-dashboard" class="tab-content active">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Dashboard</h2>
        <p class="text-on-surface-v text-sm mt-1">Ringkasan keseluruhan inventori konsignasi Susumura.</p>
      </div>

      <!-- STAT CARDS -->
      <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-8">
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">SKU Aktif</span>
            <div class="bg-primary/10 p-1.5 rounded-full"><span class="material-symbols-outlined text-primary text-[18px]">inventory_2</span></div>
          </div>
          <span class="text-4xl font-extrabold text-on-surface"><?= $total_products_count ?></span>
          <p class="text-xs text-on-surface-v mt-1">produk aktif</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">Dihantar</span>
            <div class="bg-blue-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-blue-600 text-[18px]">local_shipping</span></div>
          </div>
          <span class="text-4xl font-extrabold text-on-surface"><?= number_format($total_delivered_units) ?></span>
          <p class="text-xs text-on-surface-v mt-1">pcs keseluruhan</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">Terjual</span>
            <div class="bg-emerald-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-emerald-600 text-[18px]">trending_up</span></div>
          </div>
          <span class="text-4xl font-extrabold text-emerald-600"><?= number_format($total_sold_units) ?></span>
          <p class="text-xs text-on-surface-v mt-1">pcs terjual</p>
        </div>
        <div class="rounded-2xl p-5 shadow-sm bg-gradient-to-br from-secondary to-pink-400 text-white">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-white/80">Invois Outlet</span>
            <div class="bg-white/20 p-1.5 rounded-full"><span class="material-symbols-outlined text-white text-[18px]">receipt</span></div>
          </div>
          <span class="text-3xl font-extrabold">RM <?= number_format($total_wholesale_cost, 2) ?></span>
          <p class="text-xs text-white/80 mt-1">kos sebenar</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">Jualan RSP</span>
            <div class="bg-emerald-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-emerald-600 text-[18px]">payments</span></div>
          </div>
          <span class="text-3xl font-extrabold text-emerald-600">RM <?= number_format($total_sales_revenue, 2) ?></span>
          <p class="text-xs text-on-surface-v mt-1">nilai runcit</p>
        </div>
        <div class="rounded-2xl p-5 shadow-sm bg-gradient-to-br from-primary to-blue-400 text-white">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-white/80">Untung Kasar</span>
            <div class="bg-white/20 p-1.5 rounded-full"><span class="material-symbols-outlined text-white text-[18px]">show_chart</span></div>
          </div>
          <span class="text-3xl font-extrabold">RM <?= number_format($gross_profit, 2) ?></span>
          <p class="text-xs text-white/80 mt-1"><?= $margin_pct ?>% margin</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-red-400">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">Amaran Luput</span>
            <div class="bg-red-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-red-600 text-[18px]">event_busy</span></div>
          </div>
          <span class="text-4xl font-extrabold text-red-600"><?= $total_expiry_warnings ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch perlu tindakan</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-amber-400">
          <div class="flex items-center justify-between mb-3">
            <span class="text-[11px] font-bold uppercase tracking-wider text-on-surface-v">Stok Rosak</span>
            <div class="bg-amber-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-amber-600 text-[18px]">heart_broken</span></div>
          </div>
          <span class="text-4xl font-extrabold text-amber-600"><?= $total_damaged_reported_pcs ?></span>
          <p class="text-xs text-on-surface-v mt-1">pcs dilaporkan</p>
        </div>
      </div>

      <!-- RESTOCK TABLE -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-on-surface">🔁 Cadangan Restok Pintar</h3>
            <p class="text-xs text-on-surface-v mt-0.5">Disusun mengikut keutamaan berdasarkan jualan 7 hari</p>
          </div>
          <div class="flex gap-2">
            <button onclick="filterRestock('all')" class="restock-filter active px-3 py-1.5 rounded-full text-xs font-bold bg-primary text-white transition-all">Semua</button>
            <button onclick="filterRestock('1')" class="restock-filter px-3 py-1.5 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-all">🔴 Kritikal</button>
            <button onclick="filterRestock('2')" class="restock-filter px-3 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition-all">🟡 Sederhana</button>
            <button onclick="filterRestock('3')" class="restock-filter px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-all">🟢 Mencukupi</button>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">SKU</th>
                <th class="px-6 py-3">Produk</th>
                <th class="px-6 py-3">Kategori</th>
                <th class="px-6 py-3 text-center">Baki Stok</th>
                <th class="px-6 py-3 text-center">Jualan 7 Hari</th>
                <th class="px-6 py-3 text-center">Cadangan Restok</th>
                <th class="px-6 py-3 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($processed_restock_list as $item):
                $priority = $item['priority_score'];
                $rowbg = $priority === 1 ? 'bg-red-50/50 hover:bg-red-50' : ($priority === 2 ? 'bg-amber-50/30 hover:bg-amber-50/50' : 'hover:bg-blue-50/30');
                $badge = $priority === 1 ? 'bg-red-100 text-red-700' : ($priority === 2 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
                $label = $priority === 1 ? 'Kritikal' : ($priority === 2 ? 'Sederhana' : 'Mencukupi');
              ?>
              <tr class="<?= $rowbg ?> transition-colors cursor-pointer" data-priority="<?= $priority ?>">
                <td class="px-6 py-4"><code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($item['sku']) ?></code></td>
                <td class="px-6 py-4 font-medium text-sm text-on-surface"><?= htmlspecialchars($item['name']) ?></td>
                <td class="px-6 py-4 text-xs text-on-surface-v"><?= htmlspecialchars($item['category']) ?></td>
                <td class="px-6 py-4 text-center font-bold <?= $item['current_stock_pcs'] <= 0 ? 'text-red-600' : 'text-on-surface' ?>"><?= number_format($item['current_stock_pcs']) ?></td>
                <td class="px-6 py-4 text-center text-sm text-on-surface-v"><?= $item['recent_sales_7d'] ?></td>
                <td class="px-6 py-4 text-center text-sm <?= $item['suggested_restock_pcs'] > 0 ? 'text-secondary font-bold' : 'text-emerald-600' ?>"><?= $item['suggested_restock_pcs'] > 0 ? '+' . number_format($item['suggested_restock_pcs']) . ' pcs' : 'Mencukupi' ?></td>
                <td class="px-6 py-4 text-center"><span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= $badge ?>"><?= $label ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: KATALOG PRODUK                       -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-katalog" class="tab-content">
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
        <div>
          <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Katalog Produk</h2>
          <p class="text-on-surface-v text-sm mt-1"><?= $total_products_count ?> produk berdaftar dalam sistem.</p>
        </div>
        <div class="flex gap-2">
          <button onclick="document.getElementById('importModal').style.display='flex'" class="flex items-center gap-2 px-4 py-2 rounded-xl border border-outline-v text-primary font-bold text-sm hover:bg-primary-light transition-all">
            <span class="material-symbols-outlined text-[18px]">upload_file</span> Import Excel
          </button>
          <button onclick="document.getElementById('addModal').style.display='flex'" class="flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-secondary to-primary text-white font-bold text-sm shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all">
            <span class="material-symbols-outlined text-[18px]">add</span> Tambah Produk
          </button>
        </div>
      </div>

      <!-- Search + Filter -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v flex flex-wrap gap-3 items-center">
          <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-v text-[18px]">search</span>
            <input type="text" id="searchProd" oninput="filterProducts()" placeholder="Cari SKU atau nama produk..." class="w-full pl-10 pr-4 py-2 text-sm border border-outline-v rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
          </div>
          <select id="catFilter" onchange="filterProducts()" class="px-3 py-2 text-sm border border-outline-v rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            <option value="">Semua Kategori</option>
            <?php foreach ($categories_list as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">SKU</th>
                <th class="px-6 py-3">Nama Produk</th>
                <th class="px-6 py-3">Kategori</th>
                <th class="px-6 py-3 text-right">Harga Kos</th>
                <th class="px-6 py-3 text-right">Harga RSP</th>
                <th class="px-6 py-3 text-center">Saiz Karton</th>
                <th class="px-6 py-3 text-center">Tindakan</th>
              </tr>
            </thead>
            <tbody id="productTableBody" class="divide-y divide-outline-v/30">
              <?php foreach ($admin_summary as $prod): ?>
              <tr class="hover:bg-blue-50/30 transition-colors prod-row" data-sku="<?= strtolower($prod['sku']) ?>" data-name="<?= strtolower($prod['name']) ?>" data-cat="<?= htmlspecialchars($prod['category']) ?>">
                <td class="px-6 py-4"><code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($prod['sku']) ?></code></td>
                <td class="px-6 py-4 font-medium text-sm"><?= htmlspecialchars($prod['name']) ?></td>
                <td class="px-6 py-4"><span class="px-2 py-0.5 rounded-full bg-surface-low text-on-surface-v text-xs"><?= htmlspecialchars($prod['category']) ?></span></td>
                <td class="px-6 py-4 text-right text-sm">RM <?= number_format(floatval($prod['cost_price']), 2) ?></td>
                <td class="px-6 py-4 text-right text-sm font-bold text-primary">RM <?= number_format(floatval($prod['retail_price']), 2) ?></td>
                <td class="px-6 py-4 text-center text-sm"><?= $prod['carton_size'] ?> pcs</td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button onclick="openEditModal(<?= $prod['id'] ?>,'<?= addslashes($prod['sku']) ?>','<?= addslashes($prod['name']) ?>',<?= $prod['cost_price'] ?>,<?= $prod['retail_price'] ?>,<?= $prod['carton_size'] ?>,'<?= addslashes($prod['category']) ?>')" class="p-1.5 rounded-lg hover:bg-blue-50 text-primary transition-all"><span class="material-symbols-outlined text-[18px]">edit</span></button>
                    <button onclick="openConfirmModal(<?= $prod['id'] ?>)" class="p-1.5 rounded-lg hover:bg-red-50 text-red-500 transition-all"><span class="material-symbols-outlined text-[18px]">delete</span></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: PENGHANTARAN                         -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-penghantaran" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Rekod Penghantaran</h2>
        <p class="text-on-surface-v text-sm mt-1">Sejarah lengkap semua penghantaran stok ke outlet.</p>
      </div>
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">Tarikh</th>
                <th class="px-6 py-3">SKU</th>
                <th class="px-6 py-3">Produk</th>
                <th class="px-6 py-3 text-center">Batch</th>
                <th class="px-6 py-3 text-center">Exp. Date</th>
                <th class="px-6 py-3 text-center">Kuantiti</th>
                <th class="px-6 py-3 text-center">Status Luput</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($all_expiry_batches as $b):
                $s = $b['status_info'];
                $rowcol = $s['days'] < 0 ? 'bg-red-50/50' : ($s['warn'] ? 'bg-amber-50/30' : '');
                $badgecol = $s['days'] < 0 ? 'bg-red-100 text-red-700' : ($s['warn'] ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
              ?>
              <tr class="<?= $rowcol ?> hover:bg-blue-50/20 transition-colors">
                <td class="px-6 py-4 text-sm"><?= $b['delivery_date'] ? date('d/m/Y', strtotime($b['delivery_date'])) : '-' ?></td>
                <td class="px-6 py-4"><code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code></td>
                <td class="px-6 py-4 font-medium text-sm"><?= htmlspecialchars($b['p_name']) ?></td>
                <td class="px-6 py-4 text-center text-xs font-mono text-on-surface-v"><?= htmlspecialchars($b['batch_no'] ?? '-') ?></td>
                <td class="px-6 py-4 text-center text-sm font-bold <?= $s['days'] < 0 ? 'text-red-600' : ($s['warn'] ? 'text-amber-600' : 'text-on-surface') ?>"><?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></td>
                <td class="px-6 py-4 text-center font-bold"><?= number_format($b['quantity']) ?> pcs</td>
                <td class="px-6 py-4 text-center"><span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= $badgecol ?>"><?= htmlspecialchars($s['label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: JUALAN & STOK TAKE                   -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-jualan" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Jualan & Stok Take</h2>
        <p class="text-on-surface-v text-sm mt-1">Rekod audit fizikal dan ringkasan jualan.</p>
      </div>
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <!-- Stok Take History -->
        <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
          <div class="px-6 py-4 border-b border-outline-v">
            <h3 class="font-bold text-on-surface">Sejarah Stok Take</h3>
            <p class="text-xs text-on-surface-v mt-0.5">100 rekod terkini · <?= count($takes) ?> rekod dijumpai</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
                <tr>
                  <th class="px-4 py-3">Tarikh</th>
                  <th class="px-4 py-3">Produk</th>
                  <th class="px-4 py-3 text-center">❄️</th>
                  <th class="px-4 py-3 text-center">🪵</th>
                  <th class="px-4 py-3 text-center">📦</th>
                  <th class="px-4 py-3 text-center">Fizikal</th>
                  <th class="px-4 py-3 text-center">Jangkaan</th>
                  <th class="px-4 py-3">Variance</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-outline-v/30">
                <?php foreach ($takes as $t):
                  $var = intval($t['variance']);
                  $varcol = $var > 0 ? 'text-emerald-700 bg-emerald-100' : ($var < 0 ? 'text-red-700 bg-red-100' : 'text-on-surface-v bg-surface-low');
                ?>
                <tr class="hover:bg-blue-50/20 transition-colors">
                  <td class="px-4 py-3 whitespace-nowrap text-xs text-on-surface-v"><?= date('d/m/Y', strtotime($t['take_date'])) ?></td>
                  <td class="px-4 py-3 font-medium"><?= htmlspecialchars($t['p_name']) ?></td>
                  <td class="px-4 py-3 text-center text-xs"><?= $t['chiller_qty'] ?></td>
                  <td class="px-4 py-3 text-center text-xs"><?= $t['shelf_qty'] ?></td>
                  <td class="px-4 py-3 text-center text-xs"><?= $t['store_qty'] ?></td>
                  <td class="px-4 py-3 text-center font-bold"><?= $t['physical_qty'] ?></td>
                  <td class="px-4 py-3 text-center text-on-surface-v"><?= $t['theoretical_qty'] ?></td>
                  <td class="px-4 py-3">
                    <span class="px-2 py-0.5 rounded text-[11px] font-bold <?= $varcol ?>">
                      <?= $var > 0 ? '+' : '' ?><?= $var ?><?= $var < 0 ? ' (Auto-Billed)' : '' ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <!-- Sales Summary -->
        <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden flex flex-col">
          <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
            <div>
              <h3 class="font-bold text-on-surface">Ringkasan Jualan</h3>
              <p class="text-xs text-on-surface-v mt-0.5">Top 5 produk</p>
            </div>
            <span class="material-symbols-outlined text-secondary">monetization_on</span>
          </div>
          <div class="flex-1 overflow-y-auto divide-y divide-outline-v/30">
            <?php
            // Sort by total_sold descending
            $sold_sorted = $admin_summary;
            usort($sold_sorted, fn($a,$b) => floatval($b['total_sold']) <=> floatval($a['total_sold']));
            $sold_top = array_filter(array_slice($sold_sorted, 0, 10), fn($s) => floatval($s['total_sold']) > 0);
            $maxSold = !empty($sold_sorted) ? (max(array_column($sold_sorted, 'total_sold')) ?: 1) : 1;

            if (empty($sold_top)): ?>
            <div class="flex flex-col items-center justify-center py-12 text-on-surface-v">
              <span class="material-symbols-outlined text-[40px] opacity-30 mb-2">bar_chart</span>
              <p class="text-sm">Belum ada rekod jualan.</p>
              <p class="text-xs mt-1 text-center px-4">Jualan akan direkod selepas staf outlet buat kiraan stok pertama.</p>
            </div>
            <?php else: foreach ($sold_top as $s):
              $pct = round((floatval($s['total_sold']) / $maxSold) * 100);
            ?>
            <div class="px-4 py-4">
              <div class="flex justify-between mb-1">
                <span class="font-bold text-sm text-on-surface"><?= htmlspecialchars($s['name']) ?></span>
                <span class="text-lg font-extrabold text-on-surface"><?= number_format($s['total_sold']) ?></span>
              </div>
              <div class="flex justify-between text-xs text-on-surface-v mb-2">
                <code class="bg-blue-50 text-primary px-1.5 py-0.5 rounded text-[10px] font-bold"><?= htmlspecialchars($s['sku']) ?></code>
                <span>RM <?= number_format(floatval($s['total_sold']) * floatval($s['retail_price']), 2) ?></span>
              </div>
              <div class="w-full bg-surface-low h-1.5 rounded-full">
                <div class="bg-gradient-to-r from-primary to-secondary h-full rounded-full transition-all" style="width:<?= $pct ?>%"></div>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
          <div class="bg-gradient-to-r from-primary to-secondary p-4 flex justify-between items-center text-white">
            <div>
              <p class="text-[11px] font-bold uppercase tracking-wider opacity-80">Total Jualan</p>
              <p class="text-xl font-extrabold">RM <?= number_format($total_sales_revenue, 2) ?></p>
            </div>
            <div class="text-right">
              <p class="text-[11px] font-bold uppercase tracking-wider opacity-80">Total Pcs</p>
              <p class="text-xl font-extrabold"><?= number_format($total_sold_units) ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: STOK ROSAK                           -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-rosak" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Stok Rosak</h2>
        <p class="text-on-surface-v text-sm mt-1">Semua laporan kerosakan dari outlet.</p>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <?php
        $d_counts = ['Dilaporkan'=>0,'Dibawa Balik'=>0,'Selesai'=>0,'total'=>count($all_damaged_logs)];
        foreach ($all_damaged_logs as $d) { if (isset($d_counts[$d['status']])) $d_counts[$d['status']]++; }
        ?>
        <div class="glass rounded-2xl p-5 shadow-sm"><p class="text-[11px] font-bold uppercase text-on-surface-v mb-2">Jumlah Kes</p><span class="text-4xl font-extrabold text-on-surface"><?= $d_counts['total'] ?></span></div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-amber-400"><p class="text-[11px] font-bold uppercase text-on-surface-v mb-2">Dilaporkan</p><span class="text-4xl font-extrabold text-amber-600"><?= $d_counts['Dilaporkan'] ?></span></div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-blue-400"><p class="text-[11px] font-bold uppercase text-on-surface-v mb-2">Dibawa Balik</p><span class="text-4xl font-extrabold text-blue-600"><?= $d_counts['Dibawa Balik'] ?></span></div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-emerald-400"><p class="text-[11px] font-bold uppercase text-on-surface-v mb-2">Selesai</p><span class="text-4xl font-extrabold text-emerald-600"><?= $d_counts['Selesai'] ?></span></div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v flex gap-2 flex-wrap">
          <button onclick="filterDamaged('all')" class="dmg-filter active px-3 py-1.5 rounded-full text-xs font-bold bg-primary text-white">Semua</button>
          <button onclick="filterDamaged('Dilaporkan')" class="dmg-filter px-3 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">Dilaporkan</button>
          <button onclick="filterDamaged('Dibawa Balik')" class="dmg-filter px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">Dibawa Balik</button>
          <button onclick="filterDamaged('Selesai')" class="dmg-filter px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Selesai</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">Tarikh Lapor</th>
                <th class="px-6 py-3">SKU / Produk</th>
                <th class="px-6 py-3 text-center">Batch / Luput</th>
                <th class="px-6 py-3 text-center">Kuantiti</th>
                <th class="px-6 py-3">Jenis Isu</th>
                <th class="px-6 py-3 text-center">Gambar</th>
                <th class="px-6 py-3 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30" id="damagedTableBody">
              <?php foreach ($all_damaged_logs as $dmg):
                $sbg = $dmg['status'] === 'Dilaporkan' ? 'bg-amber-100 text-amber-700' : ($dmg['status'] === 'Dibawa Balik' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700');
              ?>
              <tr class="hover:bg-blue-50/20 transition-colors dmg-row" data-status="<?= htmlspecialchars($dmg['status']) ?>">
                <td class="px-6 py-4">
                  <p class="font-bold text-sm"><?= date('d/m/Y', strtotime($dmg['created_at'])) ?></p>
                  <p class="text-xs text-on-surface-v"><?= date('H:i', strtotime($dmg['created_at'])) ?></p>
                </td>
                <td class="px-6 py-4">
                  <p class="font-bold text-primary text-sm"><?= htmlspecialchars($dmg['p_sku']) ?></p>
                  <p class="text-sm"><?= htmlspecialchars($dmg['p_name']) ?></p>
                </td>
                <td class="px-6 py-4 text-center">
                  <p class="text-xs font-mono"><?= htmlspecialchars($dmg['batch_no'] ?? '-') ?></p>
                  <p class="text-xs font-bold text-red-600"><?= $dmg['expiry_date'] ? date('d/m/Y', strtotime($dmg['expiry_date'])) : '-' ?></p>
                </td>
                <td class="px-6 py-4 text-center font-bold"><?= $dmg['quantity'] ?> pcs</td>
                <td class="px-6 py-4 text-sm"><span class="px-2 py-0.5 rounded bg-surface-low text-on-surface-v text-xs"><?= htmlspecialchars($dmg['issue_type'] ?? '-') ?></span></td>
                <td class="px-6 py-4 text-center">
                  <?php if (!empty($dmg['image_data'])): ?>
                    <img src="<?= $dmg['image_data'] ?>" class="w-12 h-12 object-cover rounded-lg border border-outline-v cursor-pointer hover:scale-110 transition-transform mx-auto" onclick="document.getElementById('lb').style.display='flex';document.getElementById('lbImg').src='<?= $dmg['image_data'] ?>'">
                  <?php else: ?>
                    <span class="material-symbols-outlined text-on-surface-v text-[20px]">image_not_supported</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-center"><span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= $sbg ?>"><?= htmlspecialchars($dmg['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: TARIKH LUPUT                         -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-luput" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Tarikh Luput</h2>
        <p class="text-on-surface-v text-sm mt-1">Pantau kitaran hayat semua batch stok.</p>
      </div>
      <!-- Summary pills -->
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-red-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-red-600 opacity-80">Status: Luput</p>
            <div class="bg-red-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-red-600 text-[18px]">dangerous</span></div>
          </div>
          <span class="text-5xl font-extrabold text-red-600"><?= count($expiry_expired) ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch perlu dilupuskan</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-amber-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-amber-600 opacity-80">Status: Amaran</p>
            <div class="bg-amber-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-amber-600 text-[18px]">warning</span></div>
          </div>
          <span class="text-5xl font-extrabold text-amber-600"><?= count($expiry_warn) ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch dalam had amaran</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-emerald-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-emerald-600 opacity-80">Status: Selamat</p>
            <div class="bg-emerald-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-emerald-600 text-[18px]">check_circle</span></div>
          </div>
          <span class="text-5xl font-extrabold text-emerald-600"><?= count($expiry_safe) ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch dalam keadaan baik</p>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
          <h3 class="font-bold text-on-surface">Senarai Inventori Mengikut Tarikh Luput</h3>
          <span class="text-xs text-on-surface-v"><?= count($all_expiry_batches) ?> batch</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">SKU</th>
                <th class="px-6 py-3">Produk</th>
                <th class="px-6 py-3 text-center">No. Kelompok</th>
                <th class="px-6 py-3">Tarikh Luput</th>
                <th class="px-6 py-3 text-right">Hari Berbaki</th>
                <th class="px-6 py-3 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($all_expiry_batches as $b):
                $s = $b['status_info'];
                $rowbg = $s['days'] < 0 ? 'bg-red-50/50 border-l-4 border-red-400' : ($s['warn'] ? 'bg-amber-50/30 border-l-4 border-amber-400' : 'border-l-4 border-emerald-400');
                $daycol = $s['days'] < 0 ? 'text-red-600' : ($s['warn'] ? 'text-amber-600' : 'text-emerald-600');
                $badgecol = $s['days'] < 0 ? 'bg-red-100 text-red-700' : ($s['warn'] ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
              ?>
              <tr class="<?= $rowbg ?> hover:bg-blue-50/20 transition-colors">
                <td class="px-6 py-4"><code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code></td>
                <td class="px-6 py-4 font-medium text-sm"><?= htmlspecialchars($b['p_name']) ?></td>
                <td class="px-6 py-4 text-center text-xs font-mono text-on-surface-v"><?= htmlspecialchars($b['batch_no'] ?? 'N/A') ?></td>
                <td class="px-6 py-4 font-bold text-sm <?= $daycol ?>"><?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></td>
                <td class="px-6 py-4 text-right text-2xl font-extrabold <?= $daycol ?>"><?= $s['days'] ?></td>
                <td class="px-6 py-4 text-center"><span class="px-3 py-1 rounded-full text-[11px] font-bold uppercase <?= $badgecol ?>"><?= htmlspecialchars($s['label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: AUDIT LOG                            -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-audit" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Audit Log</h2>
        <p class="text-on-surface-v text-sm mt-1">Sejarah lengkap kiraan stok fizikal — <?= count($takes) ?> rekod.</p>
      </div>
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">Tarikh</th>
                <th class="px-6 py-3">Produk</th>
                <th class="px-6 py-3 text-center">Chiller</th>
                <th class="px-6 py-3 text-center">Rak</th>
                <th class="px-6 py-3 text-center">Stor</th>
                <th class="px-6 py-3 text-center">Fizikal</th>
                <th class="px-6 py-3 text-center">Jangkaan</th>
                <th class="px-6 py-3">Variance</th>
                <th class="px-6 py-3">Dikira Oleh</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($takes as $t):
                $var = intval($t['variance']);
                $varcol = $var > 0 ? 'text-emerald-700 bg-emerald-100' : ($var < 0 ? 'text-red-700 bg-red-100' : 'text-on-surface-v bg-surface-low');
              ?>
              <tr class="hover:bg-blue-50/20 transition-colors">
                <td class="px-6 py-3 whitespace-nowrap text-xs text-on-surface-v"><?= date('d/m/Y H:i', strtotime($t['take_date'])) ?></td>
                <td class="px-6 py-3">
                  <p class="font-bold text-sm"><?= htmlspecialchars($t['p_name']) ?></p>
                  <p class="text-xs text-on-surface-v"><?= htmlspecialchars($t['p_sku']) ?></p>
                </td>
                <td class="px-6 py-3 text-center text-xs"><?= $t['chiller_qty'] ?></td>
                <td class="px-6 py-3 text-center text-xs"><?= $t['shelf_qty'] ?></td>
                <td class="px-6 py-3 text-center text-xs"><?= $t['store_qty'] ?></td>
                <td class="px-6 py-3 text-center font-bold"><?= $t['physical_qty'] ?></td>
                <td class="px-6 py-3 text-center text-on-surface-v"><?= $t['theoretical_qty'] ?></td>
                <td class="px-6 py-3"><span class="px-2 py-0.5 rounded text-[11px] font-bold <?= $varcol ?>"><?= $var > 0 ? '+' : '' ?><?= $var ?><?= $var < 0 ? ' (Billed)' : '' ?></span></td>
                <td class="px-6 py-3 text-xs text-on-surface-v"><?= htmlspecialchars($t['taken_by']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- end p-8 -->
</main>

<!-- ══════════════════════════════════════════════ -->
<!-- MODALS                                         -->
<!-- ══════════════════════════════════════════════ -->

<!-- Add Product Modal -->
<div id="addModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
    <div class="bg-gradient-to-r from-secondary to-primary px-6 py-4 flex items-center justify-between">
      <h3 class="text-white font-bold text-lg">+ Tambah Produk Baru</h3>
      <button onclick="document.getElementById('addModal').style.display='none'" class="text-white/80 hover:text-white"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form action="admin.php" method="POST" class="p-6 space-y-4">
      <input type="hidden" name="action" value="admin_add_product">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">SKU *</label><input type="text" name="sku" required class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Kategori *</label>
          <select name="category" required class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            <?php foreach ($categories_list as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Nama Produk *</label><input type="text" name="name" required class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
      <div class="grid grid-cols-3 gap-4">
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Harga Kos (RM)</label><input type="number" name="cost_price" step="0.01" min="0" value="0" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Harga RSP (RM)</label><input type="number" name="retail_price" step="0.01" min="0" value="0" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Saiz Karton</label><input type="number" name="carton_size" min="1" value="12" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="flex-1 py-2.5 border border-outline-v rounded-xl text-sm font-bold text-on-surface-v hover:bg-surface-low transition-all">Batal</button>
        <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-secondary to-primary text-white rounded-xl text-sm font-bold shadow-lg hover:shadow-xl transition-all">Simpan Produk</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
    <div class="bg-gradient-to-r from-primary to-blue-400 px-6 py-4 flex items-center justify-between">
      <h3 class="text-white font-bold text-lg">✏️ Edit Produk</h3>
      <button onclick="closeEditModal()" class="text-white/80 hover:text-white"><span class="material-symbols-outlined">close</span></button>
    </div>
    <form action="admin.php" method="POST" class="p-6 space-y-4">
      <input type="hidden" name="action" value="admin_edit_product">
      <input type="hidden" name="id" id="edit_id">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">SKU</label><input type="text" name="sku" id="edit_sku" required class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Kategori</label>
          <select name="category" id="edit_category" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            <?php foreach ($categories_list as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Nama Produk</label><input type="text" name="name" id="edit_name" required class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
      <div class="grid grid-cols-3 gap-4">
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Harga Kos</label><input type="number" name="cost_price" id="edit_cost" step="0.01" min="0" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Harga RSP</label><input type="number" name="retail_price" id="edit_retail" step="0.01" min="0" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
        <div><label class="text-xs font-bold text-on-surface-v uppercase mb-1 block">Saiz Karton</label><input type="number" name="carton_size" id="edit_carton" min="1" class="w-full border border-outline-v rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none"></div>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="button" onclick="closeEditModal()" class="flex-1 py-2.5 border border-outline-v rounded-xl text-sm font-bold text-on-surface-v hover:bg-surface-low transition-all">Batal</button>
        <button type="submit" class="flex-1 py-2.5 bg-gradient-to-r from-primary to-blue-400 text-white rounded-xl text-sm font-bold shadow-lg hover:shadow-xl transition-all">Kemaskini</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div id="confirmModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
      <span class="material-symbols-outlined text-red-600 text-[32px]">delete</span>
    </div>
    <h3 class="text-lg font-bold mb-2">Padamkan Produk?</h3>
    <p class="text-sm text-on-surface-v mb-6">Tindakan ini tidak boleh dibatalkan. Data berkaitan akan terjejas.</p>
    <form action="admin.php" method="POST" class="flex gap-3">
      <input type="hidden" name="action" value="admin_delete_product">
      <input type="hidden" name="id" id="delete_product_id">
      <button type="button" onclick="document.getElementById('confirmModal').style.display='none'" class="flex-1 py-2.5 border border-outline-v rounded-xl text-sm font-bold hover:bg-surface-low transition-all">Batal</button>
      <button type="submit" class="flex-1 py-2.5 bg-red-500 text-white rounded-xl text-sm font-bold hover:bg-red-600 transition-all">Ya, Padamkan</button>
    </form>
  </div>
</div>

<!-- Import Excel Modal -->
<div id="importModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
    <div class="bg-gradient-to-r from-emerald-500 to-primary px-6 py-4 flex items-center justify-between">
      <h3 class="text-white font-bold text-lg">📥 Import Produk dari Excel</h3>
      <button onclick="document.getElementById('importModal').style.display='none'" class="text-white/80 hover:text-white"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="p-6">
      <p class="text-xs text-on-surface-v mb-4">Format Excel: <code class="bg-surface-low px-1 rounded">SKU | Nama | Harga Kos | Harga RSP | Saiz Karton | Kategori</code></p>
      <input type="file" id="excelFileInput" accept=".xlsx,.xls" class="w-full border-2 border-dashed border-outline-v rounded-xl p-4 text-sm text-on-surface-v cursor-pointer hover:border-primary transition-all" onchange="handleExcelFile(this)">
      <div id="importPreview" class="hidden mt-4">
        <p class="text-xs font-bold text-emerald-600 mb-2" id="importCount"></p>
        <form action="admin.php" method="POST">
          <input type="hidden" name="action" value="admin_import_excel">
          <input type="hidden" name="import_data" id="importDataInput">
          <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-emerald-500 to-primary text-white rounded-xl text-sm font-bold shadow-lg hover:shadow-xl transition-all">Import Sekarang</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div id="lb" onclick="this.style.display='none'" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.85);">
  <img id="lbImg" src="" class="max-w-full max-h-[85vh] rounded-2xl border-4 border-white shadow-2xl">
</div>

<script>
// Tab navigation
function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => {
    if (n.getAttribute('onclick') && n.getAttribute('onclick').includes("'" + name + "'")) n.classList.add('active');
  });
}

// Restock filter
function filterRestock(val) {
  document.querySelectorAll('[data-priority]').forEach(r => {
    r.style.display = (val === 'all' || r.dataset.priority === val) ? '' : 'none';
  });
  document.querySelectorAll('.restock-filter').forEach(b => {
    b.classList.remove('bg-primary','text-white');
    b.classList.add('bg-opacity-0');
  });
  event.currentTarget.classList.add('bg-primary','text-white');
}

// Damaged filter
function filterDamaged(status) {
  document.querySelectorAll('.dmg-row').forEach(r => {
    r.style.display = (status === 'all' || r.dataset.status === status) ? '' : 'none';
  });
}

// Product search
function filterProducts() {
  const q = document.getElementById('searchProd').value.toLowerCase();
  const cat = document.getElementById('catFilter').value;
  document.querySelectorAll('.prod-row').forEach(r => {
    const matchQ = r.dataset.sku.includes(q) || r.dataset.name.includes(q);
    const matchC = !cat || r.dataset.cat === cat;
    r.style.display = (matchQ && matchC) ? '' : 'none';
  });
}

// Edit modal
function openEditModal(id, sku, name, cost, retail, carton, category) {
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_sku').value = sku;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_cost').value = cost;
  document.getElementById('edit_retail').value = retail;
  document.getElementById('edit_carton').value = carton;
  document.getElementById('edit_category').value = category;
  document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
function openConfirmModal(id) { document.getElementById('delete_product_id').value = id; document.getElementById('confirmModal').style.display = 'flex'; }

// Excel import
function handleExcelFile(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const wb = XLSX.read(e.target.result, {type:'binary'});
    const data = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header:1});
    const items = [];
    for (let i = 1; i < data.length; i++) {
      const r = data[i];
      if (r[0] && r[1]) items.push({sku:r[0],name:r[1],cost_price:r[2]||0,retail_price:r[3]||0,carton_size:r[4]||12,category:r[5]||'UHT 125ml'});
    }
    document.getElementById('importDataInput').value = JSON.stringify(items);
    document.getElementById('importCount').textContent = items.length + ' produk bersedia untuk diimport';
    document.getElementById('importPreview').classList.remove('hidden');
  };
  reader.readAsBinaryString(input.files[0]);
}

// Close modals on backdrop click
['addModal','editModal','confirmModal','importModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});
</script>
</body>
</html>