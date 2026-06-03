<?php
require_once 'config.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['penghantar', 'admin'])) {
    die("<p style='padding:20px;color:red;font-family:sans-serif;'>Akses dinafikan.</p>");
}

$deliveries = [];

// ── Tentukan tarikh — ambil dari URL atau guna tarikh delivery terbaharu ──
if (isset($_GET['tarikh']) && $_GET['tarikh'] !== '') {
    $do_tarikh_raw = $_GET['tarikh'];
} else {
    $stmt_latest = $db->query("SELECT DATE(MAX(delivery_date)) as latest FROM deliveries");
    $latest = $stmt_latest->fetch();
    $do_tarikh_raw = $latest['latest'] ?? date('Y-m-d');
}

$do_date_fmt = date('d/m/Y', strtotime($do_tarikh_raw));

// ── Ambil rekod delivery ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    // Mod pilihan checkbox
    $ids = array_map('intval', $_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT d.*, p.name as p_name, p.sku as p_sku, p.carton_size, p.category
        FROM deliveries d JOIN products p ON d.product_id = p.id
        WHERE d.id IN ($placeholders)
        ORDER BY p.category ASC, p.name ASC
    ");
    $stmt->execute($ids);
    $deliveries = $stmt->fetchAll();
    if (!empty($deliveries)) {
        $do_date_fmt = date('d/m/Y', strtotime($deliveries[0]['delivery_date']));
    }
} else {
    // Mod tarikh — semua produk pada hari yang sama dalam SATU DO
    $stmt = $db->prepare("
        SELECT d.*, p.name as p_name, p.sku as p_sku, p.carton_size, p.category
        FROM deliveries d JOIN products p ON d.product_id = p.id
        WHERE DATE(d.delivery_date) = ?
        ORDER BY p.category ASC, p.name ASC
    ");
    $stmt->execute([$do_tarikh_raw]);
    $deliveries = $stmt->fetchAll();
}

// ── Jana nombor DO ──
$stmt_count = $db->prepare("SELECT COUNT(DISTINCT DATE(delivery_date)) FROM deliveries WHERE DATE(delivery_date) <= ?");
$stmt_count->execute([$do_tarikh_raw]);
$day_seq = intval($stmt_count->fetchColumn());
$no_do = 'DOTME-' . date('Y-m', strtotime($do_tarikh_raw)) . '-' . str_pad($day_seq, 5, '0', STR_PAD_LEFT);

// ── Hitung jumlah ──
$total_pcs = 0;
$total_ctn = 0;
foreach ($deliveries as $d) {
    $cs = max(1, intval($d['carton_size']));
    $total_pcs += intval($d['quantity']);
    $total_ctn += intdiv(intval($d['quantity']), $cs);
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DO <?= htmlspecialchars($no_do) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'IBM Plex Sans',sans-serif;background:#e8eaed;color:#111;font-size:13px}
.toolbar{background:#1c2333;color:#fff;padding:10px 28px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:99;border-bottom:2px solid #2d3a55}
.toolbar-title{font-size:12px;color:#8892aa;flex:1}
.btn-back{color:#8892aa;border:1px solid #3a4a6b;background:none;padding:7px 16px;border-radius:5px;font-size:12px;cursor:pointer;text-decoration:none}
.btn-back:hover{color:#fff;border-color:#6b7db8}
.btn-print{background:#2563eb;color:#fff;border:none;padding:8px 22px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer}
.btn-print:hover{background:#1d4ed8}
.page-wrap{max-width:860px;margin:28px auto 60px;padding:0 16px}
.paper{background:#fff;padding:42px 48px;border:1px solid #cdd3df}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:18px;border-bottom:2.5px solid #111;margin-bottom:22px}
.co-name{font-size:19px;font-weight:700;letter-spacing:-.02em;margin-bottom:1px}
.co-reg{font-size:10.5px;color:#666;margin-bottom:8px}
.co-addr{font-size:11px;color:#555;line-height:1.75}
.do-label{font-size:22px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;text-align:right;margin-bottom:14px}
.do-meta{border-collapse:collapse;margin-left:auto}
.do-meta td{padding:3px 8px;font-size:12px}
.do-meta td:first-child{color:#777;font-weight:500}
.do-meta td:last-child{font-weight:700;font-family:'IBM Plex Mono',monospace;font-size:11.5px}
.bill-row{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}
.bill-box label{display:block;font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#999;margin-bottom:5px}
.bill-name{font-size:12.5px;font-weight:700;line-height:1.55}
.bill-addr{font-size:11px;color:#666;line-height:1.7;margin-top:3px}
.items-tbl{width:100%;border-collapse:collapse;margin-bottom:0;font-size:12px}
.items-tbl thead tr{background:#111;color:#fff}
.items-tbl thead th{padding:9px 12px;font-size:10px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}
.items-tbl thead th.c{text-align:center}
.items-tbl tbody tr{border-bottom:1px solid #e5e7eb}
.items-tbl tbody tr:nth-child(even){background:#f9fafb}
.items-tbl tbody tr:last-child{border-bottom:2px solid #111}
.items-tbl td{padding:8px 12px;vertical-align:middle}
.items-tbl td.c{text-align:center}
.sku{font-family:'IBM Plex Mono',monospace;font-size:10.5px;background:#eef2ff;color:#3b5bdb;padding:2px 6px;border-radius:3px;font-weight:600;white-space:nowrap}
.qty-val{font-weight:700;font-family:'IBM Plex Mono',monospace;font-size:12.5px}
.uom-val{font-size:11px;color:#777}
.exp-val{font-size:11px;font-weight:600;color:#374151}
.batch-val{font-family:'IBM Plex Mono',monospace;font-size:10.5px;color:#555}
.summary-bar{display:flex;gap:0;margin-bottom:28px;border:1px solid #e5e7eb;border-top:none}
.sum-cell{flex:1;padding:10px 16px;text-align:center;border-right:1px solid #e5e7eb}
.sum-cell:last-child{border-right:none}
.sum-lbl{font-size:9.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#999;margin-bottom:3px}
.sum-num{font-size:20px;font-weight:700;color:#111;font-family:'IBM Plex Mono',monospace}
.remarks{border-top:1px solid #e5e7eb;padding-top:18px;margin-bottom:36px}
.remarks h4{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#999;margin-bottom:10px}
.remarks ul{list-style:none;font-size:11px;color:#666;line-height:2}
.remarks ul li::before{content:'• ';color:#111}
.payment{margin-top:12px;font-size:12px;color:#333}
.payment strong{color:#111}
.sig-row{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:8px}
.sig-box{text-align:center}
.sig-line{border-top:1px solid #111;padding-top:44px;margin-bottom:6px}
.sig-lbl{font-size:11px;color:#777}
.doc-footer{border-top:1px solid #e5e7eb;padding-top:12px;margin-top:16px;display:flex;justify-content:space-between;font-size:9.5px;color:#bbb}
.no-data{text-align:center;padding:80px 20px;color:#888}
.no-data strong{display:block;font-size:16px;margin-bottom:8px;color:#444}
.no-data a{display:inline-block;margin-top:16px;background:#2563eb;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600}
@media print{
    body{background:#fff}
    .toolbar{display:none!important}
    .page-wrap{margin:0;padding:0;max-width:100%}
    .paper{border:none;padding:15mm 18mm}
    @page{size:A4 portrait;margin:0}
    .items-tbl{font-size:11px}
    .items-tbl thead th{padding:7px 10px}
    .items-tbl td{padding:6px 10px}
    .summary-bar{page-break-inside:avoid}
    .sig-row{page-break-inside:avoid}
}
</style>
</head>
<body>

<div class="toolbar">
    <a href="penghantar.php" class="btn-back">← Kembali</a>
    <span class="toolbar-title">
        Delivery Order &nbsp;·&nbsp; <?= htmlspecialchars($no_do) ?> &nbsp;·&nbsp;
        <?= $do_date_fmt ?> &nbsp;·&nbsp;
        <?= count($deliveries) ?> produk
    </span>
    <button class="btn-print" onclick="window.print()">🖨&nbsp; Cetak / Simpan PDF</button>
</div>

<div class="page-wrap">
<div class="paper">

<?php if (empty($deliveries)): ?>
    <div class="no-data">
        <strong>Tiada rekod penghantaran pada tarikh ini.</strong>
        Tarikh dipilih: <strong><?= $do_date_fmt ?></strong><br><br>
        Sila pilih tarikh yang ada rekod penghantaran dari jadual di bawah.
        <br>
        <a href="penghantar.php">← Kembali ke Penghantar</a>
    </div>
<?php else: ?>

<!-- HEADER -->
<div class="hdr">
    <div>
        <div class="co-name">Twin Matrix Enterprise</div>
        <div class="co-reg">(201903151694 / TR0205380-W)</div>
        <div class="co-addr">
            NO. 7A Ground Floor, Taman Tanjong Permata,<br>
            Jalan Gong Badak, 21300 Kuala Nerus, Terengganu, Malaysia<br>
            Tel: +60 11-2062 1990 &nbsp;|&nbsp; +60 10-540 6620<br>
            twinmatrixenterprise@gmail.com &nbsp;|&nbsp; www.moomoostation.com
        </div>
    </div>
    <div>
        <div class="do-label">Delivery Order</div>
        <table class="do-meta">
            <tr><td>NO.</td><td><?= htmlspecialchars($no_do) ?></td></tr>
            <tr><td>DATE</td><td><?= $do_date_fmt ?></td></tr>
            <tr><td>DELIVERED BY</td><td><?= htmlspecialchars($_SESSION['fullname'] ?? '-') ?></td></tr>
        </table>
    </div>
</div>

<!-- BILL TO -->
<div class="bill-row">
    <div class="bill-box">
        <label>Bill To</label>
        <div class="bill-name">Farmasi Mamad Wholly Owned<br>by Klinik Perubatan Raudhah Sdn Bhd</div>
        <div class="bill-addr">
            6994 Rumah Kedai 2, Jln Kuala Berang, Tmn,<br>
            21700 Kuala Berang, Terengganu, Malaysia<br>
            REG NO. 201501040239
        </div>
    </div>
    <div class="bill-box">
        <label>Deliver To</label>
        <div class="bill-name">Farmasi Mamad</div>
        <div class="bill-addr">
            6994 Rumah Kedai 2, Jln Kuala Berang,<br>
            21700 Kuala Berang, Terengganu
        </div>
    </div>
</div>

<!-- ITEMS TABLE -->
<table class="items-tbl">
    <thead>
        <tr>
            <th style="width:36px">NO.</th>
            <th style="width:110px">SKU</th>
            <th>DESCRIPTION</th>
            <th class="c" style="width:75px">BATCH</th>
            <th class="c" style="width:82px">EXPIRY</th>
            <th class="c" style="width:70px">QTY</th>
            <th class="c" style="width:55px">UOM</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $row = 1;
    foreach ($deliveries as $d):
        $cs  = max(1, intval($d['carton_size']));
        $qty = intval($d['quantity']);
        $ctn = intdiv($qty, $cs);
        $pcs = $qty % $cs;
        if ($ctn > 0 && $pcs > 0) { $qty_disp = "{$ctn} ctn + {$pcs}"; $uom = 'pcs'; }
        elseif ($ctn > 0)          { $qty_disp = $ctn;                   $uom = 'ctn'; }
        else                       { $qty_disp = $pcs;                   $uom = 'pcs'; }
    ?>
        <tr>
            <td class="c" style="color:#999"><?= $row++ ?></td>
            <td><span class="sku"><?= htmlspecialchars($d['p_sku']) ?></span></td>
            <td><?= htmlspecialchars($d['p_name']) ?></td>
            <td class="c"><span class="batch-val"><?= htmlspecialchars($d['batch_no'] ?? '-') ?></span></td>
            <td class="c"><span class="exp-val"><?= $d['expiry_date'] ? date('d/m/Y', strtotime($d['expiry_date'])) : '-' ?></span></td>
            <td class="c"><span class="qty-val"><?= $qty_disp ?></span></td>
            <td class="c"><span class="uom-val"><?= $uom ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- SUMMARY BAR -->
<div class="summary-bar">
    <div class="sum-cell">
        <div class="sum-lbl">Jumlah SKU</div>
        <div class="sum-num"><?= count($deliveries) ?></div>
    </div>
    <div class="sum-cell">
        <div class="sum-lbl">Jumlah Karton</div>
        <div class="sum-num"><?= $total_ctn ?> ctn</div>
    </div>
    <div class="sum-cell">
        <div class="sum-lbl">Jumlah Keseluruhan</div>
        <div class="sum-num"><?= number_format($total_pcs) ?> pcs</div>
    </div>
</div>

<!-- REMARKS -->
<div class="remarks">
    <h4>Remarks / Important</h4>
    <ul>
        <li>Store pasteurized milk in chilled storage ≤ 4°C immediately upon receiving.</li>
        <li>UHT stock replacement valid only 14 days before expiry date.</li>
        <li>Pasteurized stock replacement valid only 7 days before expiry date.</li>
        <li>Quality claims must include photo of product with clear expiry date.</li>
        <li>All goods sold are not returnable.</li>
    </ul>
    <div class="payment">
        <strong>Payment:</strong> &nbsp;CIMB &nbsp;·&nbsp; Twin Matrix Enterprise &nbsp;·&nbsp; <strong>8603665421</strong>
    </div>
</div>

<!-- SIGNATURE -->
<div class="sig-row">
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Authorised By</div></div>
    <div class="sig-box"><div class="sig-line"></div><div class="sig-lbl">Accepted By</div></div>
</div>

<!-- FOOTER -->
<div class="doc-footer">
    <span>Susumura · Farmasi Mamad Stock System</span>
    <span>Dicetak: <?= date('d/m/Y H:i') ?> &nbsp;·&nbsp; <?= htmlspecialchars($_SESSION['fullname'] ?? '-') ?></span>
    <span><?= htmlspecialchars($no_do) ?></span>
</div>

<?php endif; ?>
</div>
</div>
</body>
</html>