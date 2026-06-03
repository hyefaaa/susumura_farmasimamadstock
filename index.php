<?php
require_once 'config.php';

// ── DEBUG ─────────────────────────────────────────────────────────────────────
if (isset($_GET['debug'])) {
    echo "<pre style='background:#111;color:#0f0;padding:20px;font-family:monospace;font-size:13px;'>";
    echo "SESSION:\n"; print_r($_SESSION);
    echo "\nPOST:\n"; print_r($_POST);
    echo "\nMETHOD: " . $_SERVER['REQUEST_METHOD'];
    echo "\nSESSION ID: " . session_id();
    echo "\n\nUSERS IN DB:\n";
    $rows = $db->query("SELECT id, username, role, LEFT(password,20) as pw_preview FROM users")->fetchAll();
    print_r($rows);
    echo "</pre>";
    die();
}

// ── PROSES LOGIN ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        set_msg("Sila isi semua ruangan!", "error");
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['fullname'] = $user['fullname'];

            switch ($user['role']) {
                case 'admin':      header("Location: admin.php");      break;
                case 'penghantar': header("Location: penghantar.php"); break;
                case 'outlet':     header("Location: outlet.php");     break;
                default:           header("Location: index.php");      break;
            }
            exit;
        } else {
            set_msg(!$user ? "Username '$username' tidak dijumpai dalam sistem." : "Kata laluan salah untuk '$username'.", "error");
        }
    }
}

// ── REDIRECT KALAU DAH LOGIN ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':      header("Location: admin.php");      exit;
        case 'penghantar': header("Location: penghantar.php"); exit;
        case 'outlet':     header("Location: outlet.php");     exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log Masuk — Susumura Farmasi Mamad</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
body{font-family:'Plus Jakarta Sans',sans-serif;}
@keyframes float{0%,100%{transform:translateY(0px);}50%{transform:translateY(-12px);}}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.float{animation:float 4s ease-in-out infinite;}
.fade-up{animation:fadeUp 0.6s ease-out forwards;}
.delay-1{animation-delay:0.1s;opacity:0;}
.delay-2{animation-delay:0.2s;opacity:0;}
.delay-3{animation-delay:0.3s;opacity:0;}
.delay-4{animation-delay:0.4s;opacity:0;}
.delay-5{animation-delay:0.5s;opacity:0;}
input:focus{outline:none;}
</style>
<script>
tailwind.config={theme:{extend:{colors:{
  primary:'#2563eb',secondary:'#ec4899',
  'primary-light':'#eff6ff',
  'surface':'#f7f9fb','surface-low':'#f2f4f6',
  'on-surface':'#191c1e','on-surface-v':'#434655',
  'outline-v':'#c3c6d7'
}}}}
</script>
</head>
<body class="min-h-screen bg-surface flex">

<!-- ── LEFT PANEL — Branding ── -->
<div class="hidden lg:flex lg:w-1/2 xl:w-3/5 bg-gradient-to-br from-secondary via-purple-500 to-primary relative overflow-hidden flex-col items-center justify-center p-16">

  <!-- Decorative circles -->
  <div class="absolute top-[-80px] left-[-80px] w-96 h-96 bg-white/5 rounded-full"></div>
  <div class="absolute bottom-[-60px] right-[-60px] w-72 h-72 bg-white/5 rounded-full"></div>
  <div class="absolute top-1/2 left-1/4 w-32 h-32 bg-white/5 rounded-full"></div>

  <!-- Content -->
  <div class="relative z-10 text-center max-w-lg">

    <!-- Logo icon -->
    <div class="float mb-8 inline-flex">
      <div class="w-24 h-24 bg-white/15 backdrop-blur-sm rounded-3xl flex items-center justify-center border border-white/20 shadow-2xl">
        <span class="material-symbols-outlined text-white text-[52px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 48;">inventory_2</span>
      </div>
    </div>

    <h1 class="text-5xl font-extrabold text-white tracking-tight mb-3 leading-tight">
      Susumurah<br>
      <span class="text-white/80 text-3xl font-bold">Farmasi Mamad</span>
    </h1>
    <p class="text-white/70 text-lg mt-4 leading-relaxed">
      Sistem Pengurusan Inventori Konsignasi<br>
      <span class="text-white/50 text-base">Stok · Penghantaran · Jualan · Laporan</span>
    </p>

    <!-- Feature pills -->
    <div class="flex flex-wrap justify-center gap-2 mt-10">
      <?php
      $features = [
        ['inventory_2', 'Katalog Produk'],
        ['local_shipping', 'Penghantaran'],
        ['bar_chart', 'Laporan Jualan'],
        ['event_busy', 'Tarikh Luput'],
        ['heart_broken', 'Stok Rosak'],
        ['print', 'Delivery Order'],
      ];
      foreach ($features as $f):
      ?>
      <div class="flex items-center gap-1.5 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full border border-white/20 text-white text-sm font-medium">
        <span class="material-symbols-outlined text-[16px]"><?= $f[0] ?></span>
        <?= $f[1] ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-3 gap-4 mt-10">
      <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/15">
        <p class="text-3xl font-extrabold text-white">3</p>
        <p class="text-white/60 text-xs mt-0.5">Peranan Pengguna</p>
      </div>
      <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/15">
        <p class="text-3xl font-extrabold text-white">84+</p>
        <p class="text-white/60 text-xs mt-0.5">SKU Produk</p>
      </div>
      <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/15">
        <p class="text-3xl font-extrabold text-white">Real</p>
        <p class="text-white/60 text-xs mt-0.5">Time Tracking</p>
      </div>
    </div>
  </div>

  <!-- Bottom credit -->
  <div class="absolute bottom-6 text-white/30 text-xs">
    Twin Matrix Enterprise · Farmasi Mamad · Kuala Berang, Terengganu
  </div>
</div>

<!-- ── RIGHT PANEL — Login Form ── -->
<div class="w-full lg:w-1/2 xl:w-2/5 flex items-center justify-center p-6 lg:p-16 bg-white">
  <div class="w-full max-w-md">

    <!-- Mobile logo -->
    <div class="lg:hidden flex items-center gap-3 mb-8 fade-up">
      <div class="w-12 h-12 bg-gradient-to-br from-secondary to-primary rounded-2xl flex items-center justify-center">
        <span class="material-symbols-outlined text-white text-[24px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">inventory_2</span>
      </div>
      <div>
        <h1 class="text-xl font-extrabold text-on-surface">Susumura</h1>
        <p class="text-xs text-on-surface-v">Farmasi Mamad Stock</p>
      </div>
    </div>

    <!-- Heading -->
    <div class="mb-8 fade-up delay-1">
      <h2 class="text-3xl font-extrabold text-on-surface tracking-tight">Selamat Datang 👋</h2>
      <p class="text-on-surface-v mt-1.5 text-sm">Log masuk untuk akses sistem inventori konsignasi.</p>
    </div>

    <!-- Flash message -->
    <?php if ($msg !== ''): ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium mb-6 fade-up <?= $msg_type==='success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
      <span class="material-symbols-outlined text-[18px] flex-shrink-0"><?= $msg_type==='success' ? 'check_circle' : 'error' ?></span>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form action="index.php" method="POST" class="space-y-5">
      <input type="hidden" name="action" value="login">

      <div class="fade-up delay-2">
        <label for="username" class="block text-xs font-bold text-on-surface-v uppercase tracking-wider mb-2">Nama Pengguna</label>
        <div class="relative">
          <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-v text-[20px]">person</span>
          <input type="text" name="username" id="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required autofocus autocomplete="username"
            placeholder="Masukkan username anda"
            class="w-full pl-12 pr-4 py-3.5 border-2 border-outline-v rounded-2xl text-sm transition-all focus:border-primary focus:ring-4 focus:ring-primary/10">
        </div>
      </div>

      <div class="fade-up delay-3">
        <label for="password" class="block text-xs font-bold text-on-surface-v uppercase tracking-wider mb-2">Kata Laluan</label>
        <div class="relative">
          <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-v text-[20px]">lock</span>
          <input type="password" name="password" id="password"
            required autocomplete="current-password"
            placeholder="Masukkan kata laluan"
            class="w-full pl-12 pr-12 py-3.5 border-2 border-outline-v rounded-2xl text-sm transition-all focus:border-primary focus:ring-4 focus:ring-primary/10">
          <button type="button" onclick="togglePwd()" class="absolute right-4 top-1/2 -translate-y-1/2 text-on-surface-v hover:text-primary transition-colors">
            <span id="pwdIcon" class="material-symbols-outlined text-[20px]">visibility_off</span>
          </button>
        </div>
      </div>

      <div class="fade-up delay-4 pt-1">
        <button type="submit"
          class="w-full py-4 bg-gradient-to-r from-secondary to-primary text-white font-bold text-base rounded-2xl shadow-lg shadow-primary/20 hover:shadow-xl hover:shadow-primary/30 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
          <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">login</span>
          Log Masuk
        </button>
      </div>
    </form>

    <!-- Role cards -->
    <div class="mt-8 fade-up delay-5">
      <p class="text-xs font-bold text-on-surface-v uppercase tracking-wider mb-3">Kredensial Ujian</p>
      <div class="space-y-2">

        <div onclick="fillCreds('admin','admin123')" class="flex items-center gap-3 px-4 py-3 rounded-2xl border-2 border-outline-v hover:border-primary hover:bg-primary-light cursor-pointer transition-all group">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-secondary to-primary flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-white text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">admin_panel_settings</span>
          </div>
          <div class="flex-1">
            <p class="font-bold text-sm text-on-surface group-hover:text-primary transition-colors">Admin</p>
            <p class="text-xs text-on-surface-v"><code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">admin</code> / <code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">admin123</code></p>
          </div>
          <span class="material-symbols-outlined text-on-surface-v group-hover:text-primary text-[18px] transition-colors">arrow_forward</span>
        </div>

        <div onclick="fillCreds('penghantar','hantar123')" class="flex items-center gap-3 px-4 py-3 rounded-2xl border-2 border-outline-v hover:border-primary hover:bg-primary-light cursor-pointer transition-all group">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-primary to-blue-400 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-white text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">local_shipping</span>
          </div>
          <div class="flex-1">
            <p class="font-bold text-sm text-on-surface group-hover:text-primary transition-colors">Penghantar</p>
            <p class="text-xs text-on-surface-v"><code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">penghantar</code> / <code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">hantar123</code></p>
          </div>
          <span class="material-symbols-outlined text-on-surface-v group-hover:text-primary text-[18px] transition-colors">arrow_forward</span>
        </div>

        <div onclick="fillCreds('outlet','outlet123')" class="flex items-center gap-3 px-4 py-3 rounded-2xl border-2 border-outline-v hover:border-primary hover:bg-primary-light cursor-pointer transition-all group">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-400 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-white text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">store</span>
          </div>
          <div class="flex-1">
            <p class="font-bold text-sm text-on-surface group-hover:text-primary transition-colors">Outlet</p>
            <p class="text-xs text-on-surface-v"><code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">outlet</code> / <code class="bg-surface-low px-1.5 py-0.5 rounded text-[11px]">outlet123</code></p>
          </div>
          <span class="material-symbols-outlined text-on-surface-v group-hover:text-primary text-[18px] transition-colors">arrow_forward</span>
        </div>

      </div>
      <p class="text-[11px] text-on-surface-v text-center mt-3">💡 Klik mana-mana kad untuk auto-isi kelayakan</p>
    </div>

    <!-- Footer -->
    <div class="mt-8 pt-6 border-t border-outline-v text-center fade-up delay-5">
      <p class="text-xs text-on-surface-v">
        © 2026 Twin Matrix Enterprise ·
        <span class="text-primary font-medium">Susumura Stock System</span>
      </p>
    </div>

  </div>
</div>

<script>
function fillCreds(user, pass) {
  document.getElementById('username').value = user;
  document.getElementById('password').value = pass;
  // Auto-submit after short delay
  setTimeout(() => document.querySelector('form').submit(), 300);
}

function togglePwd() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('pwdIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.textContent = 'visibility';
  } else {
    input.type = 'password';
    icon.textContent = 'visibility_off';
  }
}
</script>
</body>
</html>