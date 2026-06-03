<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

if (!function_exists('dapatkan_status_expiry')) {
    die("<div style='padding:20px;color:red;background:#fee2e2;border:1px solid red;font-family:sans-serif;'><strong>Ralat Kritikal:</strong> Fungsi 'dapatkan_status_expiry' tidak dijumpai dalam config.php!</div>");
}

$msg = $msg ?? '';
$msg_type = $msg_type ?? 'success';

if (($_SESSION['role'] ?? '') !== 'penghantar') {
    die("Akses dinafikan. Anda bukan staf penghantaran.");
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delivery_add') {
        $product_id      = intval($_POST['product_id'] ?? 0);
        $delivery_carton = intval($_POST['delivery_carton'] ?? 0);
        $delivery_pcs    = intval($_POST['delivery_pcs'] ?? 0);
        $expiry_date     = trim($_POST['expiry_date'] ?? '');
        $batch_no        = strtoupper(trim($_POST['batch_no'] ?? ''));
        $delivered_by    = $_SESSION['fullname'] ?? 'Staf Logistik';

        if ($product_id > 0 && ($delivery_carton > 0 || $delivery_pcs > 0) && $expiry_date !== '' && $batch_no !== '') {
            $stmtProd = $db->prepare("SELECT carton_size, name FROM products WHERE id = ?");
            $stmtProd->execute([$product_id]);
            $prod_info = $stmtProd->fetch();
            if ($prod_info) {
                $cs        = intval($prod_info['carton_size']) > 0 ? intval($prod_info['carton_size']) : 12;
                $final_pcs = ($delivery_carton * $cs) + $delivery_pcs;
                $db->prepare("INSERT INTO deliveries (product_id, quantity, expiry_date, batch_no, delivered_by) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$product_id, $final_pcs, $expiry_date, $batch_no, $delivered_by]);
                set_msg("Berjaya merekod penghantaran $final_pcs pcs bagi " . htmlspecialchars($prod_info['name']) . ".", "success");
            }
        } else {
            set_msg("Sila masukkan kuantiti, tarikh luput, dan nombor kelompok yang sah!", "error");
        }
        header("Location: penghantar.php"); exit;
    }

    if ($action === 'delivery_return_damaged') {
        $damaged_id  = intval($_POST['damaged_id'] ?? 0);
        $returned_by = $_SESSION['fullname'] ?? 'Staf Logistik';
        if ($damaged_id > 0) {
            $db->prepare("UPDATE damaged_stock SET status='Dibawa Balik', returned_by=?, return_date=NOW() WHERE id=?")
               ->execute([$returned_by, $damaged_id]);
            set_msg("Stok rosak berjaya ditandakan 'Dibawa Balik' ke gudang.", "success");
        }
        header("Location: penghantar.php"); exit;
    }
}

// ── DATA ───────────────────────────────────────────────────────────────────────
$products_list   = $db->query("SELECT * FROM products ORDER BY name ASC")->fetchAll();
$user_dels       = $db->query("SELECT d.*, p.name as p_name, p.sku as p_sku FROM deliveries d JOIN products p ON d.product_id=p.id ORDER BY d.delivery_date DESC LIMIT 10")->fetchAll();
$pending_dmg     = $db->query("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size FROM damaged_stock ds JOIN products p ON ds.product_id=p.id WHERE ds.status='Dilaporkan' ORDER BY ds.created_at DESC")->fetchAll();
$returned_dmg    = $db->query("SELECT ds.*, p.name as p_name, p.sku as p_sku, p.carton_size FROM damaged_stock ds JOIN products p ON ds.product_id=p.id WHERE ds.status='Dibawa Balik' ORDER BY ds.return_date DESC LIMIT 10")->fetchAll();
$all_dels_do     = $db->query("SELECT d.*, p.name as p_name, p.sku as p_sku FROM deliveries d JOIN products p ON d.product_id=p.id ORDER BY d.delivery_date DESC LIMIT 100")->fetchAll();

// Date summary for DO history
$date_summary = $db->query("SELECT DATE(delivery_date) as tdate, COUNT(*) as bil FROM deliveries GROUP BY DATE(delivery_date) ORDER BY tdate DESC LIMIT 20")->fetchAll();

// Expiry batches
$all_expiry_batches = [];
$expiry_expired = $expiry_warn = $expiry_safe = [];
foreach ($db->query("SELECT d.*, p.name as p_name, p.sku as p_sku, p.category FROM deliveries d JOIN products p ON d.product_id=p.id ORDER BY d.expiry_date ASC")->fetchAll() as $batch) {
    $batch['status_info'] = dapatkan_status_expiry($batch['expiry_date'], $batch['category'] ?? 'UHT');
    $all_expiry_batches[] = $batch;
    if ($batch['status_info']['days'] < 0) $expiry_expired[] = $batch;
    elseif ($batch['status_info']['warn']) $expiry_warn[] = $batch;
    else $expiry_safe[] = $batch;
}

// Stats
$total_del_today = $db->query("SELECT COUNT(*) FROM deliveries WHERE DATE(delivery_date)=CURDATE()")->fetchColumn();
$total_sku_today = $db->query("SELECT COUNT(DISTINCT product_id) FROM deliveries WHERE DATE(delivery_date)=CURDATE()")->fetchColumn();
$total_warn      = count($expiry_expired) + count($expiry_warn);
$total_pending   = count($pending_dmg);
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Penghantar — Susumura Farmasi Mamad</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f7f9fb;}
.nav-item.active{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb;font-weight:700;}
.tab-content{display:none;}
.tab-content.active{display:block;}
.glass{backdrop-filter:blur(12px);background:rgba(255,255,255,0.8);border:1px solid rgba(255,255,255,0.3);}
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:#f1f5f9;}
::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px;}
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
    <p class="text-xs text-on-surface-v mt-0.5">Delivery Portal</p>
  </div>
  <nav class="flex-1 space-y-1">
    <a onclick="switchTab('hantar')" id="nav-hantar" class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">local_shipping</span> Penghantaran Stok
    </a>
    <a onclick="switchTab('rosak')" id="nav-rosak" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">heart_broken</span> Ambil Stok Rosak
      <?php if ($total_pending > 0): ?>
      <span class="ml-auto bg-red-100 text-red-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?= $total_pending ?></span>
      <?php endif; ?>
    </a>
    <a onclick="switchTab('luput')" id="nav-luput" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">event_busy</span> Tarikh Luput
      <?php if ($total_warn > 0): ?>
      <span class="ml-auto bg-red-100 text-red-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?= $total_warn ?></span>
      <?php endif; ?>
    </a>
    <a onclick="switchTab('do')" id="nav-do" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer transition-all text-sm text-on-surface-v hover:bg-primary-light hover:text-primary">
      <span class="material-symbols-outlined text-[20px]">print</span> Cetak Delivery Order
    </a>
  </nav>
  <div class="mt-auto px-3 pt-4 border-t border-outline-v">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-9 h-9 rounded-full bg-gradient-to-br from-secondary to-primary flex items-center justify-center text-white font-bold text-sm">
        <?= strtoupper(substr($_SESSION['fullname']??'P',0,1)) ?>
      </div>
      <div>
        <p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($_SESSION['fullname']??'Penghantar') ?></p>
        <p class="text-[10px] text-on-surface-v">Staf Penghantaran</p>
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

  <!-- TOP HEADER -->
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
        <span class="text-white/80 text-sm hidden sm:block"><?= htmlspecialchars($_SESSION['fullname']??'') ?></span>
        <span class="px-3 py-1 rounded-full bg-white/20 text-white text-xs font-bold uppercase tracking-wide">Penghantar</span>
      </div>
    </div>
  </header>

  <?php if ($msg !== ''): ?>
  <div class="mx-6 mt-4 flex items-center gap-2 px-4 py-3 rounded-xl text-sm font-bold <?= $msg_type==='success' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
    <span class="material-symbols-outlined text-[18px]"><?= $msg_type==='success' ? 'check_circle' : 'error' ?></span>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <div class="flex-1 p-6 lg:p-8">

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: PENGHANTARAN STOK                    -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-hantar" class="tab-content active">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Penghantaran Stok</h2>
        <p class="text-on-surface-v text-sm mt-1">Rekod penghantaran baru ke outlet Farmasi Mamad.</p>
      </div>

      <!-- Stat strip -->
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold uppercase text-on-surface-v">Dihantar Hari Ini</span>
            <div class="bg-primary/10 p-1.5 rounded-full"><span class="material-symbols-outlined text-primary text-[18px]">local_shipping</span></div>
          </div>
          <span class="text-4xl font-extrabold text-on-surface"><?= $total_del_today ?></span>
          <p class="text-xs text-on-surface-v mt-1">rekod hari ini</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold uppercase text-on-surface-v">SKU Hari Ini</span>
            <div class="bg-secondary/10 p-1.5 rounded-full"><span class="material-symbols-outlined text-secondary text-[18px]">inventory_2</span></div>
          </div>
          <span class="text-4xl font-extrabold text-secondary"><?= $total_sku_today ?></span>
          <p class="text-xs text-on-surface-v mt-1">jenis produk</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 <?= $total_warn > 0 ? 'border-red-400' : 'border-emerald-400' ?>">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold uppercase text-on-surface-v">Amaran Luput</span>
            <div class="<?= $total_warn > 0 ? 'bg-red-100' : 'bg-emerald-100' ?> p-1.5 rounded-full">
              <span class="material-symbols-outlined <?= $total_warn > 0 ? 'text-red-500' : 'text-emerald-500' ?> text-[18px]">event_busy</span>
            </div>
          </div>
          <span class="text-4xl font-extrabold <?= $total_warn > 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= $total_warn ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch perlu tindakan</p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
        <!-- FORM -->
        <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
          <div class="bg-gradient-to-r from-secondary to-primary px-6 py-4">
            <h3 class="text-white font-bold text-lg">🚚 Rekod Hantar Baru</h3>
            <p class="text-white/70 text-xs mt-0.5">Lengkapkan semua maklumat penghantaran</p>
          </div>
          <form action="penghantar.php" method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="delivery_add">

            <!-- Search filter -->
            <div>
              <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">🔍 Cari Produk</label>
              <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-v text-[18px]">search</span>
                <input type="text" id="product_search_delivery" oninput="tapisProduk()" placeholder="SKU atau nama produk..."
                  class="w-full pl-10 pr-4 py-2.5 border border-outline-v rounded-xl text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
              </div>
            </div>

            <div>
              <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Produk *</label>
              <select name="product_id" id="product_id" onchange="hitungPecahan()" required
                class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                <option value="">-- Pilih produk --</option>
                <?php foreach ($products_list as $p): ?>
                <option value="<?= $p['id'] ?>" data-carton="<?= $p['carton_size'] ?>">[<?= htmlspecialchars($p['sku']) ?>] <?= htmlspecialchars($p['name']) ?> (1 ctn = <?= $p['carton_size'] ?> pcs)</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Kuantiti Dihantar *</label>
              <div class="flex gap-3">
                <div class="flex-1">
                  <input type="number" name="delivery_carton" id="delivery_carton" min="0" value="0" oninput="hitungPecahan()"
                    class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm text-center focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                  <p class="text-[11px] text-center text-on-surface-v mt-1">karton</p>
                </div>
                <div class="flex-1">
                  <input type="number" name="delivery_pcs" id="delivery_pcs" min="0" value="0" oninput="hitungPecahan()"
                    class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm text-center focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
                  <p class="text-[11px] text-center text-on-surface-v mt-1">pcs</p>
                </div>
              </div>
              <!-- Auto-calc pill -->
              <div id="calc_result" class="mt-2 px-4 py-2 bg-primary-light rounded-xl flex items-center justify-between">
                <span class="text-xs font-bold text-primary uppercase tracking-wide">Jumlah Keseluruhan</span>
                <span id="calc_total" class="text-lg font-extrabold text-primary">0 pcs</span>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">No. Batch *</label>
                <input type="text" name="batch_no" placeholder="cth: BATCH-2026A" required
                  class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none uppercase">
              </div>
              <div>
                <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Tarikh Luput *</label>
                <input type="date" name="expiry_date" required
                  class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
              </div>
            </div>

            <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-secondary to-primary text-white font-bold rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
              <span class="material-symbols-outlined">check_circle</span> Sahkan & Rekod Penghantaran
            </button>
          </form>
        </div>

        <!-- HISTORY TABLE -->
        <div class="xl:col-span-3 bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden flex flex-col">
          <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
            <div>
              <h3 class="font-bold text-on-surface">📋 Sejarah Penghantaran Terkini</h3>
              <p class="text-xs text-on-surface-v mt-0.5">10 rekod terbaharu</p>
            </div>
            <span class="material-symbols-outlined text-on-surface-v">history</span>
          </div>
          <div class="overflow-x-auto flex-1">
            <table class="w-full text-left">
              <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
                <tr>
                  <th class="px-5 py-3">Tarikh</th>
                  <th class="px-5 py-3">Produk</th>
                  <th class="px-5 py-3 text-center">Batch</th>
                  <th class="px-5 py-3 text-center">Qty</th>
                  <th class="px-5 py-3 text-center">Exp</th>
                  <th class="px-5 py-3 text-center">DO</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-outline-v/30">
                <?php if (empty($user_dels)): ?>
                <tr><td colspan="6" class="px-5 py-8 text-center text-on-surface-v text-sm">Belum ada rekod penghantaran.</td></tr>
                <?php else: foreach ($user_dels as $del): ?>
                <tr class="hover:bg-blue-50/30 transition-colors">
                  <td class="px-5 py-4 text-xs text-on-surface-v whitespace-nowrap"><?= date('d/m/Y H:i', strtotime($del['delivery_date'])) ?></td>
                  <td class="px-5 py-4">
                    <p class="font-medium text-sm text-on-surface"><?= htmlspecialchars($del['p_name']) ?></p>
                    <code class="text-[11px] text-on-surface-v"><?= htmlspecialchars($del['p_sku']) ?></code>
                  </td>
                  <td class="px-5 py-4 text-center"><code class="text-xs bg-surface-low px-2 py-0.5 rounded text-on-surface-v"><?= htmlspecialchars($del['batch_no']??'N/A') ?></code></td>
                  <td class="px-5 py-4 text-center font-bold text-sm"><?= number_format($del['quantity']) ?> pcs</td>
                  <td class="px-5 py-4 text-center text-xs font-bold <?= strtotime($del['expiry_date']??'') < time() ? 'text-red-600' : 'text-on-surface-v' ?>"><?= $del['expiry_date'] ? date('d/m/Y', strtotime($del['expiry_date'])) : '-' ?></td>
                  <td class="px-5 py-4 text-center">
                    <a href="cetak_do.php?tarikh=<?= date('Y-m-d', strtotime($del['delivery_date'])) ?>" target="_blank"
                      class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-outline-v text-xs font-bold text-primary hover:bg-primary-light transition-all">
                      <span class="material-symbols-outlined text-[14px]">print</span> DO
                    </a>
                  </td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: STOK ROSAK                           -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-rosak" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Ambil Stok Rosak</h2>
        <p class="text-on-surface-v text-sm mt-1">Kutip stok rosak dari outlet untuk proses Return Note (RN).</p>
      </div>

      <!-- Pending pickup -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
          <div>
            <h3 class="font-bold text-on-surface">⏳ Menunggu Kutipan</h3>
            <p class="text-xs text-on-surface-v mt-0.5">Stok rosak yang dilaporkan outlet — perlu diambil balik</p>
          </div>
          <?php if ($total_pending > 0): ?>
          <span class="px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-bold"><?= $total_pending ?> tertunggak</span>
          <?php endif; ?>
        </div>

        <?php if (empty($pending_dmg)): ?>
        <div class="flex flex-col items-center py-12 text-on-surface-v">
          <span class="material-symbols-outlined text-[48px] opacity-30 mb-2">check_circle</span>
          <p class="text-sm font-medium">Tiada stok rosak tertunggak.</p>
          <p class="text-xs mt-1">Semua stok rosak telah diambil.</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-6">
          <?php foreach ($pending_dmg as $d):
            $cs = intval($d['carton_size']) > 0 ? intval($d['carton_size']) : 12;
            $ctn = floor($d['quantity']/$cs); $pcs = $d['quantity']%$cs;
            $qty = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
          ?>
          <div class="bg-red-50 border border-red-200 rounded-2xl p-5 border-l-4 border-l-red-500">
            <div class="flex items-start justify-between mb-3">
              <div>
                <code class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($d['p_sku']) ?></code>
                <p class="font-bold text-sm text-on-surface mt-1"><?= htmlspecialchars($d['p_name']) ?></p>
              </div>
              <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase">Dilaporkan</span>
            </div>
            <div class="space-y-1 mb-4 text-xs text-on-surface-v">
              <p><strong>Batch:</strong> <?= htmlspecialchars($d['batch_no']??'-') ?></p>
              <p><strong>Kuantiti:</strong> <span class="font-bold text-red-700"><?= $qty ?></span></p>
              <p><strong>Isu:</strong> <?= htmlspecialchars($d['issue_type']??'-') ?></p>
              <p><strong>Dilaporkan:</strong> <?= date('d/m/Y', strtotime($d['created_at'])) ?></p>
            </div>
            <form action="penghantar.php" method="POST">
              <input type="hidden" name="action" value="delivery_return_damaged">
              <input type="hidden" name="damaged_id" value="<?= $d['id'] ?>">
              <button type="submit" onclick="return confirm('Sahkan kutipan stok rosak ini?')"
                class="w-full py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-400 text-white font-bold text-sm rounded-xl shadow hover:shadow-md hover:-translate-y-0.5 transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-outlined text-[18px]">check_circle</span> Tandakan Dibawa Balik
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Collected history -->
      <?php if (!empty($returned_dmg)): ?>
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v">
          <h3 class="font-bold text-on-surface">✅ Sejarah Kutipan</h3>
          <p class="text-xs text-on-surface-v mt-0.5">Stok rosak yang telah berjaya diambil balik</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">Tarikh Ambil</th>
                <th class="px-6 py-3">Produk</th>
                <th class="px-6 py-3 text-center">Batch</th>
                <th class="px-6 py-3 text-center">Kuantiti</th>
                <th class="px-6 py-3 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($returned_dmg as $r):
                $cs = intval($r['carton_size']) > 0 ? intval($r['carton_size']) : 12;
                $ctn = floor($r['quantity']/$cs); $pcs = $r['quantity']%$cs;
                $qty = ($ctn > 0 ? "$ctn ctn " : "") . ($pcs > 0 ? "$pcs pcs" : "");
              ?>
              <tr class="hover:bg-blue-50/20 transition-colors">
                <td class="px-6 py-4 text-xs text-on-surface-v"><?= $r['return_date'] ? date('d/m/Y H:i', strtotime($r['return_date'])) : '-' ?></td>
                <td class="px-6 py-4">
                  <p class="font-medium"><?= htmlspecialchars($r['p_name']) ?></p>
                  <code class="text-[11px] text-on-surface-v"><?= htmlspecialchars($r['p_sku']) ?></code>
                </td>
                <td class="px-6 py-4 text-center"><code class="text-xs bg-surface-low px-2 py-0.5 rounded"><?= htmlspecialchars($r['batch_no']??'-') ?></code></td>
                <td class="px-6 py-4 text-center font-bold"><?= $qty ?></td>
                <td class="px-6 py-4 text-center"><span class="px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-[11px] font-bold uppercase">Dibawa Balik</span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════ -->
    <!-- TAB: TARIKH LUPUT                         -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-luput" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Pemantauan Tarikh Luput</h2>
        <p class="text-on-surface-v text-sm mt-1">Pantau semua batch stok mengikut status hayat produk.</p>
      </div>

      <!-- Summary pills -->
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-red-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-red-600 opacity-80">Luput</p>
            <div class="bg-red-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-red-600 text-[18px]">dangerous</span></div>
          </div>
          <span class="text-5xl font-extrabold text-red-600"><?= count($expiry_expired) ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch telah luput</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-amber-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-amber-600 opacity-80">Amaran</p>
            <div class="bg-amber-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-amber-600 text-[18px]">warning</span></div>
          </div>
          <span class="text-5xl font-extrabold text-amber-600"><?= count($expiry_warn) ?></span>
          <p class="text-xs text-on-surface-v mt-1">dalam had amaran</p>
        </div>
        <div class="glass rounded-2xl p-5 shadow-sm border-l-4 border-emerald-500">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[11px] font-bold uppercase text-emerald-600 opacity-80">Selamat</p>
            <div class="bg-emerald-100 p-1.5 rounded-full"><span class="material-symbols-outlined text-emerald-600 text-[18px]">check_circle</span></div>
          </div>
          <span class="text-5xl font-extrabold text-emerald-600"><?= count($expiry_safe) ?></span>
          <p class="text-xs text-on-surface-v mt-1">batch selamat</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
        <div class="px-6 py-4 border-b border-outline-v flex items-center justify-between">
          <h3 class="font-bold text-on-surface">Senarai Semua Batch</h3>
          <span class="text-xs text-on-surface-v"><?= count($all_expiry_batches) ?> batch</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider">
              <tr>
                <th class="px-6 py-3">SKU & Produk</th>
                <th class="px-6 py-3 text-center">Batch</th>
                <th class="px-6 py-3 text-center">Tarikh Luput</th>
                <th class="px-6 py-3 text-right">Hari Berbaki</th>
                <th class="px-6 py-3 text-center">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-v/30">
              <?php foreach ($all_expiry_batches as $b):
                $s = $b['status_info'];
                $rowbg = $s['days'] < 0 ? 'bg-red-50/50 border-l-4 border-l-red-400' : ($s['warn'] ? 'bg-amber-50/30 border-l-4 border-l-amber-400' : 'border-l-4 border-l-emerald-300');
                $daycol = $s['days'] < 0 ? 'text-red-600' : ($s['warn'] ? 'text-amber-600' : 'text-emerald-600');
                $badgecol = $s['days'] < 0 ? 'bg-red-100 text-red-700' : ($s['warn'] ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
              ?>
              <tr class="<?= $rowbg ?> hover:bg-blue-50/10 transition-colors">
                <td class="px-6 py-4">
                  <code class="text-xs bg-blue-50 text-primary px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($b['p_sku']) ?></code>
                  <p class="font-medium text-sm text-on-surface mt-0.5"><?= htmlspecialchars($b['p_name']) ?></p>
                </td>
                <td class="px-6 py-4 text-center"><code class="text-xs text-on-surface-v"><?= htmlspecialchars($b['batch_no']??'N/A') ?></code></td>
                <td class="px-6 py-4 text-center font-bold text-sm <?= $daycol ?>"><?= $b['expiry_date'] ? date('d/m/Y', strtotime($b['expiry_date'])) : '-' ?></td>
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
    <!-- TAB: CETAK DO                             -->
    <!-- ══════════════════════════════════════════ -->
    <div id="tab-do" class="tab-content">
      <div class="mb-6">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Cetak Delivery Order</h2>
        <p class="text-on-surface-v text-sm mt-1">Jana DO mengikut tarikh — semua produk pada tarikh sama digabung dalam satu DO.</p>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Jana DO by date -->
        <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
          <div class="bg-gradient-to-r from-primary to-blue-400 px-6 py-4">
            <h3 class="text-white font-bold">🖨️ Jana DO Mengikut Tarikh</h3>
            <p class="text-white/70 text-xs mt-0.5">Semua produk pada tarikh yang sama dalam satu DO</p>
          </div>
          <div class="p-6">
            <div class="mb-5">
              <label class="text-xs font-bold text-on-surface-v uppercase block mb-1.5">Pilih Tarikh Penghantaran</label>
              <input type="date" id="do_tarikh_input" value="<?= date('Y-m-d') ?>"
                class="w-full border border-outline-v rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary outline-none">
            </div>
            <button onclick="bukaDO()" class="w-full py-3.5 bg-gradient-to-r from-primary to-blue-400 text-white font-bold rounded-2xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
              <span class="material-symbols-outlined">print</span> Jana & Pratonton DO
            </button>
            <p class="text-xs text-on-surface-v mt-3 text-center">Dalam tab baru, klik <strong>Cetak / Simpan PDF</strong></p>
          </div>

          <!-- Date history -->
          <div class="border-t border-outline-v">
            <div class="px-6 py-3 bg-surface-low">
              <p class="text-xs font-bold text-on-surface-v uppercase tracking-wider">Sejarah DO Lepas</p>
            </div>
            <div class="divide-y divide-outline-v/30">
              <?php if (empty($date_summary)): ?>
              <div class="px-6 py-6 text-center text-sm text-on-surface-v">Tiada rekod.</div>
              <?php else: foreach ($date_summary as $ds): ?>
              <div class="px-6 py-3.5 flex items-center justify-between hover:bg-blue-50/30 transition-colors">
                <div>
                  <p class="font-bold text-sm text-on-surface"><?= date('d/m/Y', strtotime($ds['tdate'])) ?></p>
                  <p class="text-xs text-on-surface-v"><?= $ds['bil'] ?> produk</p>
                </div>
                <a href="cetak_do.php?tarikh=<?= $ds['tdate'] ?>" target="_blank"
                  class="flex items-center gap-1 px-3 py-1.5 rounded-xl border border-outline-v text-xs font-bold text-primary hover:bg-primary-light transition-all">
                  <span class="material-symbols-outlined text-[14px]">print</span> Cetak DO
                </a>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

        <!-- Jana DO custom checkbox -->
        <div class="bg-white rounded-2xl shadow-sm border border-outline-v overflow-hidden">
          <div class="bg-gradient-to-r from-secondary to-pink-400 px-6 py-4">
            <h3 class="text-white font-bold">📋 Jana DO Pilihan</h3>
            <p class="text-white/70 text-xs mt-0.5">Tanda rekod tertentu untuk digabung dalam satu DO</p>
          </div>
          <form action="cetak_do.php?mod=custom" method="POST" target="_blank">
            <div class="overflow-x-auto" style="max-height:420px;overflow-y:auto;">
              <table class="w-full text-left">
                <thead class="bg-surface-low text-on-surface-v text-[11px] uppercase tracking-wider sticky top-0">
                  <tr>
                    <th class="px-4 py-3 w-10"><input type="checkbox" id="checkAll" onclick="toggleAll(this)" class="rounded border-outline-v text-primary focus:ring-primary"></th>
                    <th class="px-4 py-3">Tarikh</th>
                    <th class="px-4 py-3">Produk</th>
                    <th class="px-4 py-3 text-center">Qty</th>
                    <th class="px-4 py-3 text-center">Exp</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-outline-v/30">
                  <?php foreach ($all_dels_do as $ad): ?>
                  <tr class="hover:bg-blue-50/20 transition-colors">
                    <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?= $ad['id'] ?>" class="rounded border-outline-v text-primary focus:ring-primary"></td>
                    <td class="px-4 py-3 text-xs text-on-surface-v whitespace-nowrap"><?= date('d/m/Y', strtotime($ad['delivery_date'])) ?></td>
                    <td class="px-4 py-3">
                      <p class="font-medium text-xs text-on-surface"><?= htmlspecialchars($ad['p_name']) ?></p>
                      <code class="text-[10px] text-on-surface-v"><?= htmlspecialchars($ad['p_sku']) ?></code>
                    </td>
                    <td class="px-4 py-3 text-center text-xs font-bold"><?= number_format($ad['quantity']) ?></td>
                    <td class="px-4 py-3 text-center text-xs text-red-600 font-bold"><?= $ad['expiry_date'] ? date('d/m/Y', strtotime($ad['expiry_date'])) : '-' ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="p-4 border-t border-outline-v">
              <button type="submit" class="w-full py-3 bg-gradient-to-r from-secondary to-pink-400 text-white font-bold rounded-2xl shadow hover:shadow-lg transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">print</span> Jana DO Rekod Dipilih
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div><!-- end flex-1 p-8 -->
</main><!-- end main -->

<script>
// Sidebar nav
function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  const el = document.getElementById('nav-' + name);
  if (el) el.classList.add('active');
  window.scrollTo({top:0, behavior:'smooth'});
}

// Product search filter
let origOpts = [];
window.onload = () => {
  const sel = document.getElementById('product_id');
  if (sel) origOpts = Array.from(sel.options).slice(1);
};
function tapisProduk() {
  const f = document.getElementById('product_search_delivery').value.toUpperCase();
  const sel = document.getElementById('product_id');
  sel.innerHTML = '<option value="">-- Pilih produk --</option>';
  origOpts.forEach(o => { if (o.text.toUpperCase().includes(f)) sel.appendChild(o.cloneNode(true)); });
  hitungPecahan();
}

// Qty calculator
function hitungPecahan() {
  const sel = document.getElementById('product_id');
  if (!sel || sel.selectedIndex <= 0) { document.getElementById('calc_total').textContent = '0 pcs'; return; }
  const cs  = parseInt(sel.options[sel.selectedIndex].getAttribute('data-carton')) || 0;
  const ctn = parseInt(document.getElementById('delivery_carton').value) || 0;
  const pcs = parseInt(document.getElementById('delivery_pcs').value) || 0;
  document.getElementById('calc_total').textContent = ((ctn * cs) + pcs) + ' pcs';
}

// DO functions
function bukaDO() {
  const t = document.getElementById('do_tarikh_input').value;
  if (!t) { alert('Sila pilih tarikh.'); return; }
  window.open('cetak_do.php?tarikh=' + t, '_blank');
}
function toggleAll(src) {
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = src.checked);
}
</script>
</body>
</html>