<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

if (!function_exists('dapatkan_status_expiry')) {
    die("<div style='padding:20px;color:red;background:#fee2e2;border:1px solid red;font-family:sans-serif;'><strong>Ralat Kritikal:</strong> Fungsi 'dapatkan_status_expiry' tidak dijumpai dalam config.php!</div>");
}

$msg = $msg ?? '';
$msg_type = $msg_type ?? 'success';

if (($_SESSION['role'] ?? '') !== 'outlet') {
    die("Akses dinafikan. Anda bukan staf outlet.");
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'outlet_add_stocktake') {
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
                    $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
                    $stmtProd->execute([$product_id]);
                    $prod_info = $stmtProd->fetch();
                    if (!$prod_info) continue;
                    $cs = intval($prod_info['carton_size']);
                    $chiller_qty = (intval($counts['chiller_carton']??0)*$cs)+intval($counts['chiller_pcs']??0);
                    $shelf_qty   = (intval($counts['shelf_carton']??0)*$cs)+intval($counts['shelf_pcs']??0);
                    $store_qty   = (intval($counts['store_carton']??0)*$cs)+intval($counts['store_pcs']??0);
                    $physical_qty = $chiller_qty + $shelf_qty + $store_qty;
                    $stmtDel = $db->prepare("SELECT SUM(quantity) as total FROM deliveries WHERE product_id = ?");
                    $stmtDel->execute([$product_id]);
                    $total_delivered = intval($stmtDel->fetch()['total']??0);
                    $stmtSale = $db->prepare("SELECT SUM(quantity) as total FROM sales WHERE product_id = ?");
                    $stmtSale->execute([$product_id]);
                    $total_sold_before = intval($stmtSale->fetch()['total']??0);
                    $stmtDmg = $db->prepare("SELECT SUM(quantity) as total FROM damaged_stock WHERE product_id = ?");
                    $stmtDmg->execute([$product_id]);
                    $total_damaged_before = intval($stmtDmg->fetch()['total']??0);
                    $theoretical = $total_delivered - $total_sold_before - $total_damaged_before;
                    if ($theoretical <= 0 && $physical_qty == 0) continue;
                    $variance = $physical_qty - $theoretical;
                    $db->prepare("INSERT INTO stock_takes (product_id,chiller_qty,shelf_qty,store_qty,physical_qty,theoretical_qty,variance,taken_by) VALUES (?,?,?,?,?,?,?,?)")->execute([$product_id,$chiller_qty,$shelf_qty,$store_qty,$physical_qty,$theoretical,$variance,$taken_by]);
                    if ($variance < 0) {
                        $db->prepare("INSERT INTO sales (product_id,quantity) VALUES (?,?)")->execute([$product_id,abs($variance)]);
                        $total_auto_sales += abs($variance);
                    }
                    $success_count++;
                }
                $db->commit();
                set_msg($total_auto_sales > 0 ? "Berjaya! $success_count produk dikira. $total_auto_sales pcs auto-billed sebagai jualan." : "Berjaya! $success_count produk dikira — baki seimbang.", "success");
            } catch (PDOException $e) { $db->rollBack(); set_msg("Ralat DB: ".$e->getMessage(), "error"); }
        } else { set_msg("Tiada data dihantar.", "error"); }
        header("Location: outlet.php"); exit;
    }

    if ($action === 'outlet_add_damaged') {
        $product_id = intval($_POST['product_id']??0);
        $damaged_carton = intval($_POST['damaged_carton']??0);
        $damaged_pcs = intval($_POST['damaged_pcs']??0);
        $expiry_date = trim($_POST['expiry_date']??'');
        $batch_no = strtoupper(trim($_POST['batch_no']??''));
        $issue_type = $_POST['issue_type']??'Factory Defect';
        $image_base64 = null;
        if (isset($_FILES['damaged_image']) && $_FILES['damaged_image']['tmp_name'] != '') {
            $check = getimagesize($_FILES['damaged_image']['tmp_name']);
            if ($check !== false) {
                $ext = pathinfo($_FILES['damaged_image']['name'], PATHINFO_EXTENSION);
                $image_base64 = 'data:image/'.$ext.';base64,'.base64_encode(file_get_contents($_FILES['damaged_image']['tmp_name']));
            } else { set_msg("Fail bukan imej sah!", "error"); header("Location: outlet.php"); exit; }
        }
        if ($product_id > 0 && ($damaged_carton > 0 || $damaged_pcs > 0) && $expiry_date !== '' && $batch_no !== '' && $image_base64 !== null) {
            $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
            $stmtProd->execute([$product_id]);
            $prod_info = $stmtProd->fetch();
            $final_pcs = ($damaged_carton * $prod_info['carton_size']) + $damaged_pcs;
            $db->prepare("INSERT INTO damaged_stock (product_id,quantity,expiry_date,batch_no,image_data,reported_by,report_role,issue_type,status) VALUES (?,?,?,?,?,?,?,?,'Dilaporkan')")->execute([$product_id,$final_pcs,$expiry_date,$batch_no,$image_base64,$_SESSION['fullname'],$_SESSION['role'],$issue_type]);
            set_msg("Laporan rosak berjaya — $final_pcs pcs ".$prod_info['name']." dilaporkan.", "success");
        } else { set_msg("Sila lengkapkan semua ruangan termasuk gambar bukti!", "error"); }
        header("Location: outlet.php"); exit;
    }
}

// ── DATA ───────────────────────────────────────────────────────────────────────
$products_list = $db->query("SELECT * FROM products ORDER BY name ASC")->fetchAll();

// Compliance
$days_since_last_take = null;
$last_take_date_fmt = "Belum Pernah";
$lt = $db->query("SELECT MAX(take_date) as last_date FROM stock_takes")->fetch();
if ($lt && $lt['last_date']) {
    $last_take_date_fmt = date('d/m/Y', strtotime($lt['last_date']));
    $days_since_last_take = (new DateTime($lt['last_date']))->diff(new DateTime())->days;
}

// Current stock
$outlet_stocks = $db->query("
    SELECT p.id, p.name, p.sku, p.carton_size, p.category,
        ((SELECT COALESCE(SUM(quantity),0) FROM deliveries WHERE product_id=p.id) -
         (SELECT COALESCE(SUM(quantity),0) FROM sales WHERE product_id=p.id) -
         (SELECT COALESCE(SUM(quantity),0) FROM damaged_stock WHERE product_id=p.id)) as baki
    FROM products p ORDER BY baki DESC, p.name ASC
")->fetchAll();

// Damaged
$stmt_dmg = $db->prepare("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size FROM damaged_stock ds JOIN products p ON ds.product_id=p.id ORDER BY ds.created_at DESC LIMIT 10");
$stmt_dmg->execute();
$outlet_damaged_list = $stmt_dmg->fetchAll();

// Expiry
$all_expiry_batches = [];
$expiry_expired = $expiry_warn = $expiry_safe = [];
foreach ($db->query("SELECT d.*, p.name as p_name, p.sku as p_sku, p.category FROM deliveries d JOIN products p ON d.product_id=p.id ORDER BY d.expiry_date ASC")->fetchAll() as $batch) {
    $batch['status_info'] = dapatkan_status_expiry($batch['expiry_date'], $batch['category']);
    $all_expiry_batches[] = $batch;
    if ($batch['status_info']['days'] < 0) $expiry_expired[] = $batch;
    elseif ($batch['status_info']['warn']) $expiry_warn[] = $batch;
    else $expiry_safe[] = $batch;
}

// Weekly sales
$weekly_sales = $db->query("
    SELECT YEAR(take_date) as tahun, WEEK(take_date,1) as minggu,
        MIN(DATE(take_date)) as tarikh_mula, MAX(DATE(take_date)) as tarikh_akhir,
        SUM(ABS(CASE WHEN variance<0 THEN variance ELSE 0 END)) as jumlah_terjual,
        COUNT(DISTINCT product_id) as bil_sku
    FROM stock_takes WHERE variance<0 AND take_date>=DATE_SUB(NOW(),INTERVAL 8 WEEK)
    GROUP BY YEAR(take_date),WEEK(take_date,1) ORDER BY tahun DESC,minggu DESC
")->fetchAll();

$minggu_ini = $db->query("
    SELECT p.sku as p_sku, p.name as p_name, p.category, p.retail_price, p.cost_price,
        SUM(ABS(st.variance)) as jumlah_terjual,
        SUM(ABS(st.variance))*p.retail_price as nilai_rsp,
        SUM(ABS(st.variance))*p.cost_price as nilai_kos
    FROM stock_takes st JOIN products p ON st.product_id=p.id
    WHERE st.variance<0 AND WEEK(st.take_date,1)=WEEK(NOW(),1) AND YEAR(st.take_date)=YEAR(NOW())
    GROUP BY p.id ORDER BY jumlah_terjual DESC
")->fetchAll();

$bulan_ini = (int)date('n'); $tahun_ini = (int)date('Y');
$monthly_sales = $db->query("
    SELECT YEAR(take_date) as tahun, MONTH(take_date) as bulan,
        SUM(ABS(CASE WHEN variance<0 THEN variance ELSE 0 END)) as jumlah_terjual,
        SUM(ABS(CASE WHEN variance<0 THEN variance ELSE 0 END)*p.retail_price) as nilai_rsp,
        SUM(ABS(CASE WHEN variance<0 THEN variance ELSE 0 END)*p.cost_price) as nilai_kos,
        COUNT(DISTINCT st.product_id) as bil_sku
    FROM stock_takes st JOIN products p ON st.product_id=p.id
    WHERE st.variance<0 AND take_date>=DATE_SUB(NOW(),INTERVAL 6 MONTH)
    GROUP BY YEAR(take_date),MONTH(take_date) ORDER BY tahun DESC,bulan DESC
")->fetchAll();

$bulan_breakdown = $db->query("
    SELECT p.sku as p_sku, p.name as p_name, p.category, p.retail_price, p.cost_price,
        SUM(ABS(st.variance)) as jumlah_terjual,
        SUM(ABS(st.variance))*p.retail_price as nilai_rsp,
        SUM(ABS(st.variance))*p.cost_price as nilai_kos
    FROM stock_takes st JOIN products p ON st.product_id=p.id
    WHERE st.variance<0 AND MONTH(st.take_date)=$bulan_ini AND YEAR(st.take_date)=$tahun_ini
    GROUP BY p.id ORDER BY jumlah_terjual DESC
")->fetchAll();

$total_sold_minggu = array_sum(array_column($minggu_ini,'jumlah_terjual'));
$total_rsp_minggu  = array_sum(array_column($minggu_ini,'nilai_rsp'));
$total_sold_bulan  = array_sum(array_column($bulan_breakdown,'jumlah_terjual'));
$total_rsp_bulan   = array_sum(array_column($bulan_breakdown,'nilai_rsp'));
$total_kos_bulan   = array_sum(array_column($bulan_breakdown,'nilai_kos'));
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Outlet — Susumura Farmasi Mamad</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f7f9fb;}
/* Sidebar nav */
.nav-item.active{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb;font-weight:700;}
/* Tabs */
.tab-content{display:none;}
.tab-content.active{display:block;}
.glass{backdrop-filter:blur(12px);background:rgba(255,255,255,0.8);border:1px solid rgba(255,255,255,0.3);}
/* scrollbar */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:#f1f5f9;}
::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px;}
/* Card product stocktake */
.stk-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:16px 20px;margin-bottom:14px;}
.stk-card.mandatory{border-left:4px solid #ef4444;}
.stk-card.optional{border-left:4px solid #c3c6d7;}
</style>
<script>
tailwind.config={theme:{extend:{colors:{
  primary:'#2563eb',secondary:'#ec4899',
  'primary-light':'#eff6ff','secondary-light':'#fdf2f8',
  'surface':'#f7f9fb','surface-low':'#f2f4f6',
  'on-surface':'#191c1e','on-surface-v':'#434655',
  'outline':'#737686','outline-v':'#c3c6d7'
}}}}
</script>
</head>
<body class="text-on-surface min-h-screen flex">

<!-- ── SIDEBAR ── -->
<aside class="hidden lg:flex flex-col h-screen w-64 bg-white border-r border-outline-v fixed left-0 top-0 z-50 py-6 px-4">
  <div class="px-3 mb-8">
    <h1 class="text-xl font-extrabold text-primary tracking-tight">Susumura</h1>
    <p class="text-xs text-on-surface-v mt-0.5">Outlet Portal</p>
  </div>
  <nav class="flex-1 space-y-1">
    <a onclick="switchNav('stok')" id="nav-stok" class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">inventory_2</span> Kiraan Stok
    </a>
    <a onclick="switchNav('rosak')" id="nav-rosak" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">heart_broken</span> Stok Rosak
      <?php if (count($outlet_damaged_list) > 0): ?>
      <span class="ml-auto bg-red-100 text-red-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?= count($outlet_damaged_list) ?></span>
      <?php endif; ?>
    </a>
    <a onclick="switchNav('luput')" id="nav-luput" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">event_busy</span> Tarikh Luput
      <?php if (count($expiry_expired)+count($expiry_warn) > 0): ?>
      <span class="ml-auto bg-red-100 text-red-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?= count($expiry_expired)+count($expiry_warn) ?></span>
      <?php endif; ?>
    </a>
    <a onclick="switchNav('jualan')" id="nav-jualan" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">bar_chart</span> Laporan Jualan
    </a>
  </nav>
  <div class="mt-auto px-3 pt-4 border-t border-outline-v">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary to-primary flex items-center justify-center text-white font-bold text-sm">
        <?= strtoupper(substr($_SESSION['fullname']??'O',0,1)) ?>
      </div>
      <div>
        <p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($_SESSION['fullname']??'Outlet') ?></p>
        <p class="text-[10px] text-on-surface-v">Staf Outlet</p>
      </div>
    </div>
    <form action="logout.php" method="POST">
      <button type="submit" class="w-full py-2 text-xs font-bold text-on-surface-v border border-outline-v rounded-lg hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all flex items-center justify-center gap-1.5">
        <span class="material-symbols-outlined text-[16px]">logout</span> Log Keluar
      </button>
    </form>
  </div>
</aside>

<!-- ── MAIN ── -->
<main class="flex-1 lg:ml-64 flex flex-col min-h-screen">

<!-- ── TOP HEADER ── -->
<header class="sticky top-0 z-40 bg-gradient-to-r from-secondary to-primary px-6 py-4 shadow-lg">
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-4">
      <span class="text-2xl font-extrabold text-white tracking-tight">Farmasi Mamad</span>
      <?php if ($msg !== ''): ?>
      <div class="hidden md:flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold <?= $msg_type==='success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
        <span class="material-symbols-outlined text-[14px]"><?= $msg_type==='success' ? 'check_circle' : 'error' ?></span>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="flex items-center gap-3">
      <?php if ($days_since_last_take === null || $days_since_last_take > 7): ?>
        <span class="flex items-center gap-1 px-3 py-1 rounded-full bg-red-500 text-white text-xs font-bold">
          <span class="material-symbols-outlined text-[14px]">warning</span>
          <?= $days_since_last_take === null ? 'Belum Stock Take' : 'Overdue '.$days_since_last_take.' hari' ?>
        </span>
      <?php else: ?>
        <span class="flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-400 text-white text-xs font-bold">
          <span class="material-symbols-outlined text-[14px]">check_circle</span> Tally OK
        </span>
      <?php endif; ?>
      <span class="text-white/80 text-sm hidden sm:block"><?= htmlspecialchars($_SESSION['fullname']??'') ?></span>
      <span class="px-3 py-1 rounded-full bg-white/20 text-white text-xs font-bold uppercase tracking-wide">Outlet</span>
    </div>
  </div>
</header>

<!-- Flash message -->
<?php if ($msg !== ''): ?>
<div class="mx-6 mt-4 flex items-center gap-2 px-4 py-3 rounded-xl text-sm font-bold <?= $msg_type==='success' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
  <span class="material-symbols-outlined text-[18px]"><?= $msg_type==='success' ? 'check_circle' : 'error' ?></span>
  <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ── CONTENT ── -->
<div class="flex-1 p-6 lg:p-8">

  <!-- ════════════════════════════════════════ -->
  <!-- TAB: STOK TAKE                          -->
  <!-- ════════════════════════════════════════ -->
  <div id="tab-stok" class="tab-content active">
    <div class="mb-5">
      <h2 class="text-2xl font-extrabold text-on-surface">Kiraan Stok</h2>
      <p class="text-sm text-on-surface-v mt-1">Masukkan kuantiti fizikal mengikut lokasi penyimpanan.</p>
    </div>

    <!-- Compliance alert -->
    <?php if ($days_since_last_take === null): ?>
    <div class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-2xl p-4 mb-5">
      <span class="material-symbols-outlined text-red-500 text-[22px] mt-0.5">warning</span>
      <div><p class="font-bold text-red-700 text-sm">Belum pernah hantar kiraan stok!</p><p class="text-xs text-red-600 mt-0.5">Sila jalankan kiraan mingguan hari ini.</p></div>
    </div>
    <?php elseif ($days_since_last_take > 7): ?>
    <div class="flex items-start gap-3 bg-red-50 border border-red-200 rounded-2xl p-4 mb-5">
      <span class="material-symbols-outlined text-red-500 text-[22px] mt-0.5">schedule</span>
      <div><p class="font-bold text-red-700 text-sm">Amaran Kelewatan — <?= $days_since_last_take ?> hari!</p><p class="text-xs text-red-600 mt-0.5">Kiraan terakhir: <?= $last_take_date_fmt ?>. Wajib seminggu sekali.</p></div>
    </div>
    <?php else: ?>
    <div class="flex items-start gap-3 bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-5">
      <span class="material-symbols-outlined text-emerald-500 text-[22px] mt-0.5">check_circle</span>
      <div><p class="font-bold text-emerald-700 text-sm">Status Pematuhan OK</p><p class="text-xs text-emerald-600 mt-0.5">Kiraan terakhir: <?= $last_take_date_fmt ?></p></div>
    </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="relative mb-4">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-v text-[20px]">search</span>
      <input type="text" id="searchStk" oninput="filterStk()" placeholder="Cari SKU atau nama produk..." class="w-full pl-10 pr-4 py-3 rounded-2xl border border-outline-v text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none bg-white">
    </div>

    <!-- Error box -->
    <div id="stkError" class="hidden flex items-start gap-3 bg-red-50 border border-red-200 rounded-2xl p-4 mb-4">
      <span class="material-symbols-outlined text-red-500 text-[20px] mt-0.5">error</span>
      <p id="stkErrorText" class="text-sm text-red-700"></p>
    </div>

    <form action="outlet.php" method="POST" onsubmit="return validateStk(event)">
      <input type="hidden" name="action" value="outlet_add_stocktake">
      <div id="stkContainer">
        <?php foreach ($outlet_stocks as $stk):
          $mandatory = $stk['baki'] > 0;
        ?>
        <div class="stk-card <?= $mandatory ? 'mandatory' : 'optional' ?> stk-row"
             data-id="<?= $stk['id'] ?>"
             data-sku="<?= strtolower($stk['sku']) ?>"
             data-name="<?= strtolower($stk['name']) ?>"
             data-mandatory="<?= $mandatory ? 'true' : 'false' ?>">

          <!-- Product header -->
          <div class="flex items-start justify-between mb-3">
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($stk['sku']) ?></code>
                <span class="text-xs text-on-surface-v bg-surface-low px-2 py-0.5 rounded"><?= $stk['carton_size'] ?> pcs/ctn</span>
              </div>
              <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($stk['name']) ?></p>
            </div>
            <?php if ($mandatory): ?>
            <span class="flex items-center gap-1 px-2 py-1 rounded-full bg-red-100 text-red-700 text-[10px] font-bold uppercase ml-2 whitespace-nowrap">
              <span class="material-symbols-outlined text-[12px]">warning</span> WAJIB · <?= $stk['baki'] ?> pcs
            </span>
            <?php else: ?>
            <span class="px-2 py-1 rounded-full bg-surface-low text-on-surface-v text-[10px] font-bold uppercase ml-2">PILIHAN</span>
            <?php endif; ?>
          </div>

          <!-- 3-location inputs -->
          <div class="grid grid-cols-3 gap-3 mb-3">
            <?php
            $locs = [['❄️','Chiller','chiller'],['🪵','Rak','shelf'],['📦','Stor','store']];
            foreach ($locs as [$icon,$label,$key]):
            ?>
            <div>
              <label class="text-[11px] font-bold text-on-surface-v uppercase block mb-1"><?= $icon ?> <?= $label ?></label>
              <div class="flex gap-1">
                <div class="flex-1">
                  <input type="number" name="stocktake[<?= $stk['id'] ?>][<?= $key ?>_carton]" id="ctn_<?= $key[0] ?>_<?= $stk['id'] ?>" min="0" value="0"
                    oninput="calcRow(<?= $stk['id'] ?>,<?= $stk['carton_size'] ?>)"
                    class="w-full border border-outline-v rounded-lg px-2 py-1.5 text-sm text-center focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                  <p class="text-[10px] text-center text-on-surface-v mt-0.5">ctn</p>
                </div>
                <div class="flex-1">
                  <input type="number" name="stocktake[<?= $stk['id'] ?>][<?= $key ?>_pcs]" id="pcs_<?= $key[0] ?>_<?= $stk['id'] ?>" min="0" value="0"
                    oninput="calcRow(<?= $stk['id'] ?>,<?= $stk['carton_size'] ?>)"
                    class="w-full border border-outline-v rounded-lg px-2 py-1.5 text-sm text-center focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                  <p class="text-[10px] text-center text-on-surface-v mt-0.5">pcs</p>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Total -->
          <div class="flex items-center justify-between bg-primary-light rounded-xl px-4 py-2.5">
            <span class="text-xs font-bold text-primary uppercase tracking-wider">Jumlah Kiraan</span>
            <span id="row_total_<?= $stk['id'] ?>" class="text-lg font-extrabold text-primary">0 pcs</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Submit -->
      <div class="pt-2 pb-4">
        <button type="submit" class="w-full py-4 bg-gradient-to-r from-secondary to-primary text-white font-bold text-base rounded-2xl shadow-xl hover:shadow-2xl hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
          <span class="material-symbols-outlined">save</span>
          Serah Semua Kiraan Stok
        </button>
      </div>
    </form>
  </div>

  <!-- ════════════════════════════════════════ -->
  <!-- TAB: STOK ROSAK                         -->
  <!-- ════════════════════════════════════════ -->
  <div id="tab-rosak" class="tab-content">
    <div class="mb-5">
      <h2 class="text-2xl font-extrabold text-on-surface">Laporan Stok Rosak</h2>
      <p class="text-sm text-on-surface-v mt-1">Rekod kerosakan dengan bukti gambar wajib.</p>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden mb-6">
      <div class="bg-gradient-to-r from-red-500 to-secondary px-5 py-4">
        <h3 class="text-white font-bold">💔 Hantar Laporan Baru</h3>
        <p class="text-white/70 text-xs mt-0.5">Semua ruangan wajib dilengkapkan</p>
      </div>
      <form action="outlet.php" method="POST" enctype="multipart/form-data" class="p-5 space-y-4">
        <input type="hidden" name="action" value="outlet_add_damaged">

        <div>
          <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Produk Rosak *</label>
          <select name="product_id" id="dmg_prod" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" onchange="calcDmg()" required>
            <option value="">-- Pilih produk --</option>
            <?php foreach ($products_list as $p): ?>
            <option value="<?= $p['id'] ?>" data-carton="<?= $p['carton_size'] ?>">[<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Kuantiti Rosak *</label>
          <div class="flex gap-3">
            <div class="flex-1">
              <input type="number" name="damaged_carton" id="dmg_ctn" min="0" value="0" oninput="calcDmg()" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
              <p class="text-[11px] text-on-surface-v mt-1 text-center">karton</p>
            </div>
            <div class="flex-1">
              <input type="number" name="damaged_pcs" id="dmg_pcs" min="0" value="0" oninput="calcDmg()" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
              <p class="text-[11px] text-on-surface-v mt-1 text-center">pcs</p>
            </div>
          </div>
          <p id="dmg_total" class="text-xs font-bold text-primary mt-2">⚙️ Jumlah: 0 pcs</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">No. Batch *</label>
            <input type="text" name="batch_no" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none uppercase" required>
          </div>
          <div>
            <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Tarikh Luput *</label>
            <input type="date" name="expiry_date" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" required>
          </div>
        </div>

        <div>
          <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Jenis Isu *</label>
          <select name="issue_type" class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none" required>
            <option value="Factory Defect">Kecacatan Kilang (Factory Defect)</option>
            <option value="Quality Issue">Isu Kualiti (Quality Issue)</option>
          </select>
        </div>

        <div>
          <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">
            📸 Gambar Bukti * <span class="text-red-500">(Wajib)</span>
          </label>
          <label for="dmg_img" class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-outline-v rounded-xl p-6 cursor-pointer hover:border-primary hover:bg-primary-light transition-all">
            <span class="material-symbols-outlined text-on-surface-v text-[36px]">add_a_photo</span>
            <span class="text-sm font-bold text-on-surface-v">Ambil Gambar atau Pilih Fail</span>
            <span class="text-xs text-on-surface-v">JPG, PNG, WEBP</span>
          </label>
          <input type="file" id="dmg_img" name="damaged_image" accept="image/*" class="hidden" onchange="previewImg(this)" required>
          <div id="img_preview" class="hidden mt-3">
            <img id="img_preview_tag" src="" class="w-full max-h-48 object-cover rounded-xl border border-outline-v">
          </div>
        </div>

        <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-red-500 to-secondary text-white font-bold rounded-2xl shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2">
          <span class="material-symbols-outlined">report</span>
          Hantar Laporan Kerosakan
        </button>
      </form>
    </div>

    <!-- Recent damage records -->
    <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
      <div class="px-5 py-4 border-b border-outline-v">
        <h3 class="font-bold text-on-surface">Rekod Terkini</h3>
      </div>
      <?php if (empty($outlet_damaged_list)): ?>
      <div class="flex flex-col items-center justify-center py-10 text-on-surface-v">
        <span class="material-symbols-outlined text-[48px] mb-2 opacity-30">heart_broken</span>
        <p class="text-sm">Tiada rekod kerosakan.</p>
      </div>
      <?php else: ?>
      <div class="divide-y divide-outline-v/30">
        <?php foreach ($outlet_damaged_list as $d):
          $cs = intval($d['carton_size']);
          $ctn = floor($d['quantity']/$cs); $pcs = $d['quantity']%$cs;
          $qty_txt = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
          $sbg = $d['status']==='Dilaporkan' ? 'bg-amber-100 text-amber-700' : ($d['status']==='Dibawa Balik' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700');
        ?>
        <div class="flex items-center gap-3 px-5 py-4">
          <?php if (!empty($d['image_data'])): ?>
          <img src="<?= $d['image_data'] ?>" class="w-14 h-14 object-cover rounded-xl border border-outline-v flex-shrink-0 cursor-pointer hover:scale-105 transition-transform"
               onclick="document.getElementById('lb').style.display='flex';document.getElementById('lbImg').src='<?= $d['image_data'] ?>'">
          <?php else: ?>
          <div class="w-14 h-14 rounded-xl bg-surface-low flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-on-surface-v text-[24px]">image_not_supported</span>
          </div>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <p class="font-bold text-sm text-on-surface truncate"><?= htmlspecialchars($d['p_name']) ?></p>
            <p class="text-xs text-on-surface-v"><?= date('d/m/Y', strtotime($d['created_at'])) ?> · <?= $qty_txt ?></p>
            <p class="text-xs text-on-surface-v"><?= htmlspecialchars($d['issue_type']??'-') ?></p>
          </div>
          <span class="px-2.5 py-1 rounded-full text-[11px] font-bold uppercase flex-shrink-0 <?= $sbg ?>"><?= htmlspecialchars($d['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ════════════════════════════════════════ -->
  <!-- TAB: TARIKH LUPUT                       -->
  <!-- ════════════════════════════════════════ -->
  <div id="tab-luput" class="tab-content">
    <div class="mb-5">
      <h2 class="text-2xl font-extrabold text-on-surface">Tarikh Luput</h2>
      <p class="text-sm text-on-surface-v mt-1">Semak status hayat produk di outlet anda.</p>
    </div>

    <!-- Summary pills -->
    <div class="grid grid-cols-3 gap-3 mb-5">
      <div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-center">
        <span class="material-symbols-outlined text-red-500 text-[24px]">dangerous</span>
        <p class="text-3xl font-extrabold text-red-600 mt-1"><?= count($expiry_expired) ?></p>
        <p class="text-[11px] font-bold text-red-500 uppercase mt-0.5">Luput</p>
      </div>
      <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-center">
        <span class="material-symbols-outlined text-amber-500 text-[24px]">warning</span>
        <p class="text-3xl font-extrabold text-amber-600 mt-1"><?= count($expiry_warn) ?></p>
        <p class="text-[11px] font-bold text-amber-500 uppercase mt-0.5">Amaran</p>
      </div>
      <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-center">
        <span class="material-symbols-outlined text-emerald-500 text-[24px]">check_circle</span>
        <p class="text-3xl font-extrabold text-emerald-600 mt-1"><?= count($expiry_safe) ?></p>
        <p class="text-[11px] font-bold text-emerald-500 uppercase mt-0.5">Selamat</p>
      </div>
    </div>

    <!-- Expired cards -->
    <?php if (!empty($expiry_expired)): ?>
    <h4 class="text-xs font-bold uppercase text-red-600 tracking-wider mb-3 flex items-center gap-1">
      <span class="material-symbols-outlined text-[16px]">dangerous</span> Telah Luput
    </h4>
    <?php foreach ($expiry_expired as $b): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-3 border-l-4 border-l-red-500">
      <div class="flex items-start justify-between mb-2">
        <div>
          <code class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code>
          <p class="font-bold text-sm text-on-surface mt-1"><?= htmlspecialchars($b['p_name']) ?></p>
        </div>
        <span class="px-2.5 py-1 rounded-full bg-red-500 text-white text-[11px] font-bold uppercase">Luput</span>
      </div>
      <div class="flex items-center justify-between">
        <p class="text-xs text-red-600">Batch: <?= htmlspecialchars($b['batch_no']??'-') ?> · <?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></p>
        <p class="text-2xl font-extrabold text-red-600"><?= $b['status_info']['days'] ?> hari</p>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Warning cards -->
    <?php if (!empty($expiry_warn)): ?>
    <h4 class="text-xs font-bold uppercase text-amber-600 tracking-wider mb-3 mt-4 flex items-center gap-1">
      <span class="material-symbols-outlined text-[16px]">warning</span> Dalam Had Amaran
    </h4>
    <?php foreach ($expiry_warn as $b): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-3 border-l-4 border-l-amber-500">
      <div class="flex items-start justify-between mb-2">
        <div>
          <code class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code>
          <p class="font-bold text-sm text-on-surface mt-1"><?= htmlspecialchars($b['p_name']) ?></p>
        </div>
        <span class="px-2.5 py-1 rounded-full bg-amber-400 text-white text-[11px] font-bold uppercase">Amaran</span>
      </div>
      <div class="flex items-center justify-between">
        <p class="text-xs text-amber-700">Batch: <?= htmlspecialchars($b['batch_no']??'-') ?> · <?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></p>
        <p class="text-2xl font-extrabold text-amber-600"><?= $b['status_info']['days'] ?> hari</p>
      </div>
      <!-- Days bar -->
      <div class="mt-2 bg-amber-100 rounded-full h-1.5">
        <div class="bg-amber-400 h-full rounded-full" style="width:<?= max(5, min(100, round(($b['status_info']['days']/30)*100))) ?>%"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Safe (collapsed) -->
    <?php if (!empty($expiry_safe)): ?>
    <button onclick="toggleSafe()" class="w-full flex items-center justify-between py-3 px-4 rounded-2xl bg-surface-low border border-outline-v text-sm font-bold text-on-surface-v hover:bg-emerald-50 transition-all mt-4 mb-2">
      <span class="flex items-center gap-2"><span class="material-symbols-outlined text-emerald-500 text-[18px]">check_circle</span>Lihat <?= count($expiry_safe) ?> produk selamat</span>
      <span id="safeChevron" class="material-symbols-outlined text-[18px]">expand_more</span>
    </button>
    <div id="safeList" class="hidden space-y-2 mb-4">
      <?php foreach ($expiry_safe as $b): ?>
      <div class="bg-white border border-outline-v rounded-xl p-3 flex items-center justify-between border-l-4 border-l-emerald-400">
        <div>
          <code class="text-[11px] bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code>
          <p class="text-sm font-medium text-on-surface mt-0.5"><?= htmlspecialchars($b['p_name']) ?></p>
          <p class="text-xs text-on-surface-v"><?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></p>
        </div>
        <span class="text-xl font-extrabold text-emerald-500"><?= $b['status_info']['days'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ════════════════════════════════════════ -->
  <!-- TAB: LAPORAN JUALAN                     -->
  <!-- ════════════════════════════════════════ -->
  <div id="tab-jualan" class="tab-content">
    <div class="mb-5">
      <h2 class="text-2xl font-extrabold text-on-surface">Laporan Jualan</h2>
      <p class="text-sm text-on-surface-v mt-1">Prestasi jualan mingguan dan bulanan.</p>
    </div>

    <!-- Toggle -->
    <div class="flex gap-2 mb-5 bg-surface-low p-1 rounded-2xl">
      <button id="btn-minggu" onclick="tukarsales('minggu')" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-gradient-to-r from-secondary to-primary text-white shadow-md">📅 Mingguan</button>
      <button id="btn-bulan" onclick="tukarsales('bulan')" class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-on-surface-v hover:bg-white">📆 Bulanan</button>
    </div>

    <!-- WEEKLY VIEW -->
    <div id="view-minggu">
      <!-- Stat cards -->
      <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="glass rounded-2xl p-4 shadow-sm text-center">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">Jualan</p>
          <p class="text-2xl font-extrabold text-emerald-600"><?= number_format($total_sold_minggu) ?></p>
          <p class="text-[11px] text-on-surface-v">pcs</p>
        </div>
        <div class="glass rounded-2xl p-4 shadow-sm text-center">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">RSP</p>
          <p class="text-xl font-extrabold text-primary">RM</p>
          <p class="text-base font-extrabold text-primary"><?= number_format($total_rsp_minggu, 0) ?></p>
        </div>
        <div class="glass rounded-2xl p-4 shadow-sm text-center">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">SKU</p>
          <p class="text-2xl font-extrabold text-on-surface"><?= count($minggu_ini) ?></p>
          <p class="text-[11px] text-on-surface-v">produk</p>
        </div>
      </div>

      <!-- Top products this week -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden mb-5">
        <div class="px-5 py-4 border-b border-outline-v flex items-center justify-between">
          <h3 class="font-bold text-on-surface">🏆 Produk Minggu Ini</h3>
          <span class="text-xs text-on-surface-v"><?= date('d/m', strtotime('monday this week')) ?> – <?= date('d/m/Y', strtotime('sunday this week')) ?></span>
        </div>
        <?php if (empty($minggu_ini)): ?>
        <div class="flex flex-col items-center py-10 text-on-surface-v">
          <span class="material-symbols-outlined text-[40px] opacity-30 mb-2">bar_chart</span>
          <p class="text-sm">Tiada jualan minggu ini.</p>
        </div>
        <?php else:
          $max_m = max(array_column($minggu_ini,'jumlah_terjual'));
          foreach ($minggu_ini as $i => $s):
            $bp = $max_m > 0 ? round(($s['jumlah_terjual']/$max_m)*100) : 0;
        ?>
        <div class="px-5 py-4 <?= $i > 0 ? 'border-t border-outline-v/30' : '' ?>">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
              <span class="text-lg font-extrabold text-on-surface-v w-6 text-center"><?= $i+1 ?></span>
              <div>
                <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($s['p_name']) ?></p>
                <code class="text-[11px] text-on-surface-v"><?= htmlspecialchars($s['p_sku']) ?></code>
              </div>
            </div>
            <div class="text-right">
              <p class="text-xl font-extrabold text-emerald-600"><?= number_format($s['jumlah_terjual']) ?></p>
              <p class="text-xs text-on-surface-v">RM <?= number_format($s['nilai_rsp'],2) ?></p>
            </div>
          </div>
          <div class="w-full bg-surface-low rounded-full h-2">
            <div class="h-full rounded-full bg-gradient-to-r from-secondary to-primary transition-all" style="width:<?= $bp ?>%"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
        <?php if (!empty($minggu_ini)): ?>
        <div class="bg-gradient-to-r from-secondary to-primary px-5 py-3 flex justify-between text-white">
          <div><p class="text-[11px] font-bold uppercase opacity-80">Jumlah</p><p class="text-lg font-extrabold"><?= number_format($total_sold_minggu) ?> pcs</p></div>
          <div class="text-right"><p class="text-[11px] font-bold uppercase opacity-80">RSP</p><p class="text-lg font-extrabold">RM <?= number_format($total_rsp_minggu,2) ?></p></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- 8-week history -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-5 py-4 border-b border-outline-v"><h3 class="font-bold text-on-surface">📊 Sejarah 8 Minggu</h3></div>
        <?php if (empty($weekly_sales)): ?>
        <div class="flex flex-col items-center py-10 text-on-surface-v"><span class="material-symbols-outlined text-[40px] opacity-30 mb-2">history</span><p class="text-sm">Belum ada data.</p></div>
        <?php else:
          $max_w = max(array_column($weekly_sales,'jumlah_terjual'));
          foreach ($weekly_sales as $w):
            $is_now = ($w['tahun']==date('Y') && $w['minggu']==date('W'));
            $bw = $max_w > 0 ? round(($w['jumlah_terjual']/$max_w)*100) : 0;
        ?>
        <div class="px-5 py-4 <?= $is_now ? 'bg-primary-light' : '' ?> border-b border-outline-v/30 last:border-0">
          <div class="flex items-center justify-between mb-2">
            <div>
              <?php if ($is_now): ?><span class="px-2 py-0.5 rounded-full bg-primary text-white text-[10px] font-bold uppercase mb-1 inline-block">Minggu Ini</span><br><?php endif; ?>
              <span class="text-xs text-on-surface-v"><?= date('d/m', strtotime($w['tarikh_mula'])) ?> – <?= date('d/m/Y', strtotime($w['tarikh_akhir'])) ?></span>
            </div>
            <div class="text-right">
              <p class="font-extrabold text-on-surface"><?= number_format($w['jumlah_terjual']) ?> pcs</p>
              <p class="text-xs text-on-surface-v"><?= $w['bil_sku'] ?> SKU</p>
            </div>
          </div>
          <div class="w-full bg-surface-low rounded-full h-1.5">
            <div class="h-full rounded-full transition-all <?= $is_now ? 'bg-gradient-to-r from-secondary to-primary' : 'bg-outline-v' ?>" style="width:<?= $bw ?>%"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div><!-- end view-minggu -->

    <!-- MONTHLY VIEW -->
    <div id="view-bulan" class="hidden">
      <!-- Stat cards -->
      <div class="grid grid-cols-2 gap-3 mb-5">
        <div class="glass rounded-2xl p-4 shadow-sm">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">Jualan Bulan Ini</p>
          <p class="text-3xl font-extrabold text-emerald-600"><?= number_format($total_sold_bulan) ?></p>
          <p class="text-xs text-on-surface-v">pcs</p>
        </div>
        <div class="glass rounded-2xl p-4 shadow-sm">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">Nilai RSP</p>
          <p class="text-2xl font-extrabold text-primary">RM <?= number_format($total_rsp_bulan,0) ?></p>
        </div>
        <div class="glass rounded-2xl p-4 shadow-sm">
          <p class="text-[10px] font-bold uppercase text-on-surface-v mb-1">Kos</p>
          <p class="text-2xl font-extrabold text-purple-600">RM <?= number_format($total_kos_bulan,0) ?></p>
        </div>
        <div class="rounded-2xl p-4 shadow-sm bg-gradient-to-br from-primary to-secondary text-white">
          <p class="text-[10px] font-bold uppercase text-white/80 mb-1">Untung Kasar</p>
          <p class="text-2xl font-extrabold">RM <?= number_format($total_rsp_bulan-$total_kos_bulan,0) ?></p>
          <p class="text-xs text-white/70"><?= count($bulan_breakdown) ?> SKU terjual</p>
        </div>
      </div>

      <!-- Top products this month -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden mb-5">
        <div class="px-5 py-4 border-b border-outline-v">
          <h3 class="font-bold text-on-surface">🏆 Produk — <?= $bulan_melayu[$bulan_ini].' '.$tahun_ini ?></h3>
        </div>
        <?php if (empty($bulan_breakdown)): ?>
        <div class="flex flex-col items-center py-10 text-on-surface-v"><span class="material-symbols-outlined text-[40px] opacity-30 mb-2">bar_chart</span><p class="text-sm">Tiada jualan bulan ini.</p></div>
        <?php else:
          $max_b = max(array_column($bulan_breakdown,'jumlah_terjual'));
          foreach ($bulan_breakdown as $i => $b):
            $bb = $max_b > 0 ? round(($b['jumlah_terjual']/$max_b)*100) : 0;
        ?>
        <div class="px-5 py-4 <?= $i > 0 ? 'border-t border-outline-v/30' : '' ?>">
          <div class="flex items-center justify-between mb-1">
            <div class="flex items-center gap-2 flex-1 min-w-0">
              <span class="text-lg font-extrabold text-on-surface-v w-6 flex-shrink-0"><?= $i+1 ?></span>
              <div class="min-w-0">
                <p class="font-bold text-sm text-on-surface truncate"><?= htmlspecialchars($b['p_name']) ?></p>
                <div class="flex gap-3 text-xs text-on-surface-v mt-0.5">
                  <span>RSP: RM <?= number_format($b['nilai_rsp'],2) ?></span>
                  <span>Kos: RM <?= number_format($b['nilai_kos'],2) ?></span>
                </div>
              </div>
            </div>
            <p class="text-xl font-extrabold text-emerald-600 flex-shrink-0 ml-2"><?= number_format($b['jumlah_terjual']) ?></p>
          </div>
          <div class="w-full bg-surface-low rounded-full h-2 mt-2">
            <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary" style="width:<?= $bb ?>%"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
        <?php if (!empty($bulan_breakdown)): ?>
        <div class="bg-gradient-to-r from-primary to-secondary px-5 py-3 grid grid-cols-3 text-white text-center">
          <div><p class="text-[10px] uppercase opacity-80">Terjual</p><p class="font-extrabold"><?= number_format($total_sold_bulan) ?> pcs</p></div>
          <div><p class="text-[10px] uppercase opacity-80">RSP</p><p class="font-extrabold">RM <?= number_format($total_rsp_bulan,0) ?></p></div>
          <div><p class="text-[10px] uppercase opacity-80">Untung</p><p class="font-extrabold">RM <?= number_format($total_rsp_bulan-$total_kos_bulan,0) ?></p></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- 6-month history -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-5 py-4 border-b border-outline-v"><h3 class="font-bold text-on-surface">📊 Sejarah 6 Bulan</h3></div>
        <?php if (empty($monthly_sales)): ?>
        <div class="flex flex-col items-center py-10 text-on-surface-v"><span class="material-symbols-outlined text-[40px] opacity-30 mb-2">history</span><p class="text-sm">Belum ada data.</p></div>
        <?php else:
          $max_mb = max(array_column($monthly_sales,'jumlah_terjual'));
          foreach ($monthly_sales as $m):
            $is_now = ($m['tahun']==$tahun_ini && (int)$m['bulan']==$bulan_ini);
            $bm = $max_mb > 0 ? round(($m['jumlah_terjual']/$max_mb)*100) : 0;
            $untung = floatval($m['nilai_rsp']) - floatval($m['nilai_kos']);
        ?>
        <div class="px-5 py-4 <?= $is_now ? 'bg-primary-light' : '' ?> border-b border-outline-v/30 last:border-0">
          <div class="flex items-center justify-between mb-2">
            <div>
              <p class="font-bold text-sm text-on-surface <?= $is_now ? 'text-primary' : '' ?>">
                <?= $bulan_melayu[(int)$m['bulan']].' '.$m['tahun'] ?>
                <?php if ($is_now): ?><span class="ml-1 text-[10px] bg-primary text-white px-1.5 py-0.5 rounded-full font-bold">Semasa</span><?php endif; ?>
              </p>
              <p class="text-xs text-on-surface-v"><?= $m['bil_sku'] ?> SKU · Untung: <span class="font-bold <?= $untung >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">RM <?= number_format($untung,2) ?></span></p>
            </div>
            <div class="text-right">
              <p class="font-extrabold text-on-surface"><?= number_format($m['jumlah_terjual']) ?> pcs</p>
              <p class="text-xs text-on-surface-v">RM <?= number_format($m['nilai_rsp'],2) ?></p>
            </div>
          </div>
          <div class="w-full bg-surface-low rounded-full h-1.5">
            <div class="h-full rounded-full transition-all <?= $is_now ? 'bg-gradient-to-r from-secondary to-primary' : 'bg-outline-v' ?>" style="width:<?= $bm ?>%"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div><!-- end view-bulan -->
  </div>

</div><!-- end container -->

</div><!-- end flex-1 p-8 -->
</main><!-- end main -->

<!-- Lightbox -->
<div id="lb" onclick="this.style.display='none'" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,0.85);">
  <img id="lbImg" src="" class="max-w-full max-h-[85vh] rounded-2xl border-4 border-white shadow-2xl">
</div>

<script>
// Sidebar nav
function switchNav(name) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  const navEl = document.getElementById('nav-' + name);
  if (navEl) navEl.classList.add('active');
  window.scrollTo({top:0, behavior:'smooth'});
}

// Sales toggle
function tukarsales(view) {
  document.getElementById('view-minggu').style.display = view === 'minggu' ? 'block' : 'none';
  document.getElementById('view-bulan').style.display  = view === 'bulan'  ? 'block' : 'none';
  const active = 'flex-1 py-2.5 rounded-xl text-sm font-bold transition-all bg-gradient-to-r from-secondary to-primary text-white shadow-md';
  const inactive = 'flex-1 py-2.5 rounded-xl text-sm font-bold transition-all text-on-surface-v hover:bg-white';
  document.getElementById('btn-minggu').className = view === 'minggu' ? active : inactive;
  document.getElementById('btn-bulan').className  = view === 'bulan'  ? active : inactive;
}

// Safe toggle
function toggleSafe() {
  const list = document.getElementById('safeList');
  const chev = document.getElementById('safeChevron');
  list.classList.toggle('hidden');
  chev.textContent = list.classList.contains('hidden') ? 'expand_more' : 'expand_less';
}

// Stock take search
function filterStk() {
  const q = document.getElementById('searchStk').value.toLowerCase();
  document.querySelectorAll('.stk-row').forEach(r => {
    r.style.display = (r.dataset.sku.includes(q) || r.dataset.name.includes(q)) ? '' : 'none';
  });
}

// Stock take row calc
function calcRow(id, cs) {
  const v = (p) => parseInt(document.getElementById(p+'_'+id)?.value)||0;
  const total = (v('ctn_c')*cs)+v('pcs_c_')+(v('ctn_s')*cs)+v('pcs_s_')+(v('ctn_st')*cs)+v('pcs_st_');
  // fix id pattern
  const cc = parseInt(document.getElementById('ctn_c_'+id)?.value)||0;
  const pc = parseInt(document.getElementById('pcs_c_'+id)?.value)||0;
  const cs2 = parseInt(document.getElementById('ctn_s_'+id)?.value)||0;
  const ps = parseInt(document.getElementById('pcs_s_'+id)?.value)||0;
  const cst = parseInt(document.getElementById('ctn_st_'+id)?.value)||0;
  const pst = parseInt(document.getElementById('pcs_st_'+id)?.value)||0;
  const tot = (cc*cs)+pc+(cs2*cs)+ps+(cst*cs)+pst;
  const el = document.getElementById('row_total_'+id);
  if (el) el.textContent = tot + ' pcs';
}

// Damaged calc
function calcDmg() {
  const sel = document.getElementById('dmg_prod');
  if (!sel || sel.selectedIndex <= 0) { document.getElementById('dmg_total').textContent='⚙️ Jumlah: 0 pcs'; return; }
  const cs = parseInt(sel.options[sel.selectedIndex].getAttribute('data-carton'))||0;
  const ctn = parseInt(document.getElementById('dmg_ctn')?.value)||0;
  const pcs = parseInt(document.getElementById('dmg_pcs')?.value)||0;
  document.getElementById('dmg_total').textContent = '⚙️ Jumlah: '+((ctn*cs)+pcs)+' pcs';
}

// Image preview
function previewImg(input) {
  if (input.files && input.files[0]) {
    const r = new FileReader();
    r.onload = e => { document.getElementById('img_preview_tag').src=e.target.result; document.getElementById('img_preview').classList.remove('hidden'); };
    r.readAsDataURL(input.files[0]);
  }
}

// Stock take validation
function validateStk(event) {
  const rows = document.querySelectorAll('.stk-row[data-mandatory="true"]');
  for (let row of rows) {
    const id = row.dataset.id;
    const fields = ['ctn_c_','pcs_c_','ctn_s_','pcs_s_','ctn_st_','pcs_st_'];
    for (let f of fields) {
      const el = document.getElementById(f+id);
      if (!el || el.value === '') {
        event.preventDefault();
        const box = document.getElementById('stkError');
        document.getElementById('stkErrorText').innerHTML = '<strong>Ruangan Wajib Kosong!</strong> ['+row.dataset.sku+'] '+row.dataset.name+' — masukkan 0 jika tiada stok.';
        box.classList.remove('hidden');
        box.scrollIntoView({behavior:'smooth'});
        return false;
      }
    }
  }
  document.getElementById('stkError').classList.add('hidden');
  if (!confirm('Pasti mahu hantar kiraan stok? Sistem akan auto-bill jualan jika ada kekurangan.')) { event.preventDefault(); return false; }
  return true;
}
</script>
</body>
</html>