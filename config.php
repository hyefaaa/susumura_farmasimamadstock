<?php
// ══════════════════════════════════════════════════════════════════
//  config.php — Susumura Farmasi Mamad Stock System
//  PENTING: session_start() MESTI dipanggil sebelum sebarang output
// ══════════════════════════════════════════════════════════════════

// 1. Tetapan session (MESTI sebelum session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// 2. Error reporting (selepas session_start)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 3. Konfigurasi database — tukar ikut persekitaran anda
// ── Localhost (XAMPP/WAMP/Laragon) ──
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';          // Kosong untuk XAMPP/WAMP/Laragon
$db_name = 'susumurah_farmasimamadstock';

// ── Hosting server (uncomment baris bawah & comment atas bila deploy) ──
// $db_host = 'localhost';
// $db_user = 'susumura_admin_farmasimamad';
// $db_pass = 'tMe620mAmad';
// $db_name = 'susumura_farmasimamadstock';

// 4. Sambung ke database
try {
    $db = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Gagal menyambung ke pangkalan data MySQL: " . $e->getMessage());
}

// 5. Flash message (notifikasi sesi)
$msg      = '';
$msg_type = '';

function set_msg($text, $type = 'success') {
    $_SESSION['flash_msg']  = $text;
    $_SESSION['flash_type'] = $type;
}

if (isset($_SESSION['flash_msg'])) {
    $msg      = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// 6. Fungsi amaran tarikh luput
function dapatkan_status_expiry($expiry_date, $category) {
    if (!$expiry_date) {
        return [
            'label' => 'Tiada Rekod',
            'class' => 'neutral-variance',
            'warn'  => false,
            'days'  => 999
        ];
    }

    $now      = new DateTime(date('Y-m-d'));
    $exp      = new DateTime($expiry_date);
    $diff     = $now->diff($exp);
    $days_left = (int)$diff->format("%r%a");

    if ($days_left < 0) {
        return [
            'label' => 'TELAH LUPUT (' . abs($days_left) . ' hari lepas)',
            'class' => 'negative-variance',
            'warn'  => true,
            'days'  => $days_left
        ];
    }

    $is_uht_or_ic = (stripos($category, 'UHT') !== false || stripos($category, 'IC') !== false);
    $is_pst       = (stripos($category, 'PST') !== false  || stripos($category, 'Butter') !== false);

    $threshold = 0;
    if ($is_uht_or_ic) $threshold = 20;
    elseif ($is_pst)   $threshold = 10;

    if ($threshold > 0 && $days_left <= $threshold) {
        return [
            'label' => 'AMARAN (' . $days_left . ' hari berbaki)',
            'class' => 'negative-variance',
            'warn'  => true,
            'days'  => $days_left
        ];
    }

    return [
        'label' => 'Selamat (' . $days_left . ' hari berbaki)',
        'class' => 'positive-variance',
        'warn'  => false,
        'days'  => $days_left
    ];
}

// 7. Nama bulan dalam Bahasa Melayu
$bulan_melayu = [
    1  => 'Januari',   2  => 'Februari',  3  => 'Mac',
    4  => 'April',     5  => 'Mei',       6  => 'Jun',
    7  => 'Julai',     8  => 'Ogos',      9  => 'September',
    10 => 'Oktober',   11 => 'November',  12 => 'Disember'
];