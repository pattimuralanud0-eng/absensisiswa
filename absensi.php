<?php
// ===== Absensi QR Code Sederhana (MySQL) =====
// Taruh file ini di C:\xampp\htdocs\absensi\absensi.php
// Nyalakan Apache + MySQL di XAMPP, lalu buka: http://localhost/absensi/absensi.php
// Database & tabel dibuat otomatis (atau import database.sql lewat phpMyAdmin).
date_default_timezone_set('Asia/Jayapura');
const JAM_MASUK_STANDAR = '07:00';   // format HH:MM

// ---- Pengaturan database (default XAMPP) ----
const DB_HOST = 'localhost';
const DB_NAME = 'absensi_qr';
const DB_USER = 'root';
const DB_PASS = '';

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        $pdo->exec('CREATE TABLE IF NOT EXISTS siswa (
            nis VARCHAR(30) NOT NULL PRIMARY KEY,
            nama VARCHAR(100) NOT NULL,
            kelas VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS absensi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nis VARCHAR(30) NOT NULL,
            tanggal DATE NOT NULL,
            jam_masuk TIME NULL,
            jam_pulang TIME NULL,
            UNIQUE KEY uq_nis_tgl (nis, tanggal),
            CONSTRAINT fk_absensi_siswa FOREIGN KEY (nis) REFERENCES siswa(nis) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (PDOException $e) {
        die('<h3>Koneksi database gagal</h3><p>Pastikan <b>MySQL</b> di XAMPP sudah Start, dan cek DB_USER / DB_PASS di bagian atas file.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
    }
    return $pdo;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function jam5($t) { return $t ? substr($t, 0, 5) : ''; }
function fmt($t) { return $t ? str_replace(':', '.', $t) : '-'; }
function ketMasuk($t) { return !$t ? '-' : ($t <= JAM_MASUK_STANDAR ? 'Tepat Waktu' : 'Terlambat'); }

function daftarSiswa() {
    return db()->query('SELECT nis, nama, kelas FROM siswa ORDER BY created_at, nis')->fetchAll();
}
function cariSiswa($nis) {
    $st = db()->prepare('SELECT nis, nama, kelas FROM siswa WHERE nis = ?');
    $st->execute([$nis]);
    return $st->fetch() ?: null;
}
function tambahSiswa($nis, $nama, $kelas) {
    db()->prepare('INSERT INTO siswa (nis, nama, kelas) VALUES (?, ?, ?)')->execute([$nis, $nama, $kelas]);
}
function hapusSiswa($nis) {
    db()->prepare('DELETE FROM siswa WHERE nis = ?')->execute([$nis]);
}
function ambilAbsen($nis, $tgl) {
    $st = db()->prepare('SELECT jam_masuk, jam_pulang FROM absensi WHERE nis = ? AND tanggal = ?');
    $st->execute([$nis, $tgl]);
    $r = $st->fetch();
    return $r ? ['masuk' => jam5($r['jam_masuk']), 'pulang' => jam5($r['jam_pulang'])] : ['masuk' => '', 'pulang' => ''];
}
function catat($nis, $tgl, $jenis, $jam) {
    $kol = $jenis === 'pulang' ? 'jam_pulang' : 'jam_masuk';
    db()->prepare("INSERT INTO absensi (nis, tanggal, $kol) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE $kol = VALUES($kol)")->execute([$nis, $tgl, $jam . ':00']);
}
function hapusAbsen($nis, $tgl) {
    db()->prepare('DELETE FROM absensi WHERE nis = ? AND tanggal = ?')->execute([$nis, $tgl]);
}
function rekap($tgl) {
    $st = db()->prepare('SELECT s.nis, s.nama, s.kelas, a.jam_masuk, a.jam_pulang
                         FROM siswa s LEFT JOIN absensi a ON a.nis = s.nis AND a.tanggal = ?
                         ORDER BY s.created_at, s.nis');
    $st->execute([$tgl]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $masuk = jam5($r['jam_masuk']); $pulang = jam5($r['jam_pulang']);
        $rows[] = [
            'nis' => $r['nis'], 'nama' => $r['nama'], 'kelas' => $r['kelas'],
            'masuk' => $masuk, 'ketMasuk' => ketMasuk($masuk),
            'pulang' => $pulang, 'hadir' => $masuk ? 'Hadir' : 'Tidak Hadir',
        ];
    }
    return $rows;
}

// ---------- API scan (AJAX) ----------
if (isset($_GET['api']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $nis = trim($_POST['nis'] ?? '');
    $s = cariSiswa($nis);
    if (!$s) { echo json_encode(['ok' => false, 'pesan' => 'QR tidak dikenali: ' . $nis]); exit; }
    $tgl = date('Y-m-d'); $jam = date('H:i');
    $r = ambilAbsen($nis, $tgl);
    if (empty($r['masuk'])) {
        catat($nis, $tgl, 'masuk', $jam);
        echo json_encode(['ok' => true, 'pesan' => "{$s['nama']} ({$s['kelas']}) — MASUK " . fmt($jam) . ' — ' . ketMasuk($jam)]);
    } elseif (empty($r['pulang'])) {
        catat($nis, $tgl, 'pulang', $jam);
        echo json_encode(['ok' => true, 'pesan' => "{$s['nama']} ({$s['kelas']}) — PULANG " . fmt($jam)]);
    } else {
        echo json_encode(['ok' => false, 'pesan' => "{$s['nama']} sudah scan masuk & pulang hari ini."]);
    }
    exit;
}

// ---------- Aksi form ----------
$page = $_GET['page'] ?? 'dashboard';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    $msg = ''; $tipe = 'ok'; $to = $page;

    if ($aksi === 'tambah_siswa') {
        $nis = trim($_POST['nis'] ?? ''); $nama = trim($_POST['nama'] ?? ''); $kelas = trim($_POST['kelas'] ?? '');
        if ($nis === '' || $nama === '' || $kelas === '') { $msg = 'Semua kolom wajib diisi.'; $tipe = 'err'; }
        elseif (cariSiswa($nis)) { $msg = 'NIS sudah terdaftar.'; $tipe = 'err'; }
        else { tambahSiswa($nis, $nama, $kelas); $msg = 'Siswa berhasil ditambahkan'; }
        $to = 'siswa';
    } elseif ($aksi === 'hapus_siswa') {
        hapusSiswa($_POST['nis'] ?? '');
        $msg = 'Siswa dihapus'; $to = 'siswa';
    } elseif ($aksi === 'manual') {
        $nis = $_POST['nis'] ?? ''; $tgl = $_POST['tanggal'] ?? date('Y-m-d');
        $jenis = ($_POST['jenis'] ?? '') === 'pulang' ? 'pulang' : 'masuk'; $jam = $_POST['jam'] ?? '';
        if (!cariSiswa($nis) || !preg_match('/^\d{2}:\d{2}$/', $jam) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            $msg = 'Data manual tidak valid.'; $tipe = 'err';
        } else { catat($nis, $tgl, $jenis, $jam); $msg = 'Absensi manual tersimpan'; }
        $to = 'absensi&tgl=' . urlencode($tgl);
    } elseif ($aksi === 'hapus_absen') {
        hapusAbsen($_POST['nis'] ?? '', $_POST['tanggal'] ?? '');
        $msg = 'Rekaman absensi dihapus'; $to = 'absensi&tgl=' . urlencode($_POST['tanggal'] ?? '');
    }
    header("Location: absensi.php?page=$to&msg=" . urlencode($msg) . "&t=$tipe");
    exit;
}

$tgl = $_GET['tgl'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = date('Y-m-d');
$flash = $_GET['msg'] ?? ''; $flashT = $_GET['t'] ?? 'ok';
$siswa = daftarSiswa();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Absensi QR</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#eef0f7;color:#333}
  nav{background:linear-gradient(90deg,#4a6fdc,#6a4fd8);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;color:#fff}
  nav b{font-size:18px}
  nav a{color:#fff;text-decoration:none;margin-left:18px;font-weight:600;opacity:.85}
  nav a.on,nav a:hover{opacity:1;text-decoration:underline}
  .wrap{max-width:1000px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.08);margin-bottom:20px}
  h2{margin:0 0 16px}
  .btn{display:inline-block;border:0;border-radius:8px;padding:9px 16px;font-weight:600;color:#fff;background:#4a7bd0;cursor:pointer;text-decoration:none;font-size:14px}
  .btn.red{background:#d9534f}.btn.green{background:#3aa66b}.btn.gray{background:#888}
  .btn.sm{padding:6px 11px;font-size:13px}
  table{width:100%;border-collapse:collapse;margin-top:14px}
  th{background:#4a7bd0;color:#fff;padding:11px;text-align:left}
  td{padding:10px 11px;border-bottom:1px solid #eee}
  .tw{background:#e3f6ea;color:#1e7a45}.tl{background:#fdecd2;color:#a56200}.th{background:#fbe0e0;color:#b02a2a}
  .bd{padding:3px 10px;border-radius:12px;font-size:13px;font-weight:600;display:inline-block}
  input,select{padding:9px;border:1px solid #ccc;border-radius:8px;font-size:14px;width:100%}
  label{font-size:13px;font-weight:600;display:block;margin:10px 0 4px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
  .stat{background:#fff;border-radius:12px;padding:18px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.08)}
  .stat b{font-size:30px;display:block;color:#4a6fdc}
  .flash{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600}
  .flash.ok{background:#e3f6ea;color:#1e7a45}.flash.err{background:#fbe0e0;color:#b02a2a}
  .center{text-align:center}
  #reader{width:100%;max-width:420px;margin:12px auto}
  #hasil{margin-top:14px;padding:12px;border-radius:8px;display:none;font-weight:600}
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:10}
  .modal.show{display:flex}
  .box{background:#fff;border-radius:16px;padding:24px;text-align:center;min-width:280px}
  .box #qrcode{display:flex;justify-content:center;margin:14px 0}
  .box small{display:block;color:#666}
  .row{display:flex;gap:10px;flex-wrap:wrap}.row>div{flex:1;min-width:140px}
  @media print{
    body *{visibility:hidden}
    .box,.box *{visibility:visible}
    .box .noprint{display:none}
    .modal{background:#fff;position:absolute}
  }
</style>
</head>
<body>
<nav>
  <b>📋 Absensi QR</b>
  <div>
    <?php foreach (['dashboard'=>'Dashboard','siswa'=>'Siswa','scan'=>'Scan','absensi'=>'Absensi'] as $k=>$v): ?>
      <a href="absensi.php?page=<?= $k ?>" class="<?= $page===$k?'on':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
</nav>
<div class="wrap">
<?php if ($flash): ?><div class="flash <?= h($flashT) ?>"><?= h($flash) ?></div><?php endif; ?>

<?php if ($page === 'dashboard'):
    $rk = rekap(date('Y-m-d'));
    $hadir = count(array_filter($rk, fn($r)=>$r['masuk']));
    $telat = count(array_filter($rk, fn($r)=>$r['ketMasuk']==='Terlambat'));
?>
  <div class="card"><h2>Dashboard — <?= date('d/m/Y') ?></h2>
    <p>Jam masuk standar: <b><?= fmt(JAM_MASUK_STANDAR) ?></b></p>
    <div class="grid">
      <div class="stat"><b><?= count($rk) ?></b>Total Siswa</div>
      <div class="stat"><b><?= $hadir ?></b>Hadir</div>
      <div class="stat"><b><?= $telat ?></b>Terlambat</div>
      <div class="stat"><b><?= count($rk)-$hadir ?></b>Tidak Hadir</div>
    </div>
  </div>

<?php elseif ($page === 'siswa'): ?>
  <div class="card">
    <h2>🎓 Data Siswa</h2>
    <button class="btn" onclick="document.getElementById('formTambah').style.display='block';this.style.display='none'">+ Tambah Siswa</button>
    <form id="formTambah" method="post" style="display:none">
      <input type="hidden" name="aksi" value="tambah_siswa">
      <div class="row">
        <div><label>NIS</label><input name="nis" required></div>
        <div><label>Nama</label><input name="nama" required></div>
        <div><label>Kelas</label><input name="kelas" placeholder="contoh: 12.6" required></div>
      </div><br><button class="btn green">Simpan</button>
    </form>
    <table>
      <tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>QR</th><th>Aksi</th></tr>
      <?php foreach ($siswa as $i=>$s): ?>
      <tr>
        <td><?= $i+1 ?></td><td><?= h($s['nis']) ?></td><td><?= h($s['nama']) ?></td><td><?= h($s['kelas']) ?></td>
        <td><button class="btn sm" onclick="lihatQR(<?= h(json_encode($s['nis'])) ?>,<?= h(json_encode($s['nama'])) ?>)">▣ QR</button></td>
        <td><form method="post" onsubmit="return confirm('Hapus siswa ini?')" style="margin:0">
          <input type="hidden" name="aksi" value="hapus_siswa"><input type="hidden" name="nis" value="<?= h($s['nis']) ?>">
          <button class="btn red sm">🗑</button></form></td>
      </tr>
      <?php endforeach; if (!$siswa): ?><tr><td colspan="6" class="center">Belum ada siswa.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="modal" id="modalQR">
    <div class="box">
      <h3 id="qrNama"></h3><div id="qrcode"></div><small id="qrNis"></small><br>
      <div class="noprint"><button class="btn" onclick="window.print()">🖨 Cetak</button>
      <button class="btn red" onclick="document.getElementById('modalQR').classList.remove('show')">Tutup</button></div>
    </div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
  function lihatQR(nis, nama){
    document.getElementById('qrNama').textContent = nama;
    document.getElementById('qrNis').textContent = 'NIS: ' + nis;
    const el = document.getElementById('qrcode'); el.innerHTML = '';
    new QRCode(el, {text: nis, width: 220, height: 220});
    document.getElementById('modalQR').classList.add('show');
  }
  </script>

<?php elseif ($page === 'scan'): ?>
  <div class="card center">
    <h2>🎥 Scan QR Absensi</h2>
    <p>Arahkan kamera ke QR Code siswa.<br><small>Scan pertama = Jam Masuk, scan kedua = Jam Pulang.</small></p>
    <div id="reader"></div>
    <button class="btn green" id="btnStart" onclick="mulai()">Mulai Scan</button>
    <button class="btn gray" id="btnStop" onclick="berhenti()" style="display:none">Stop Scanning</button>
    <div id="hasil"></div>
  </div>
  <script src="https://unpkg.com/html5-qrcode"></script>
  <script>
  let scanner = null, last = '', lastT = 0;
  function tampil(ok, teks){
    const d = document.getElementById('hasil');
    d.style.display = 'block'; d.textContent = teks;
    d.style.background = ok ? '#e3f6ea' : '#fbe0e0'; d.style.color = ok ? '#1e7a45' : '#b02a2a';
  }
  function onScan(text){
    if (text === last && Date.now() - lastT < 4000) return;
    last = text; lastT = Date.now();
    fetch('absensi.php?page=scan&api=1', {method:'POST', body:new URLSearchParams({nis:text})})
      .then(r => r.json()).then(j => tampil(j.ok, j.pesan))
      .catch(() => tampil(false, 'Gagal menghubungi server.'));
  }
  async function mulai(){
    scanner = new Html5Qrcode('reader');
    const cfg = {fps:10, qrbox:220};
    try { await scanner.start({facingMode:'environment'}, cfg, onScan); }
    catch(e){
      try { await scanner.start({facingMode:'user'}, cfg, onScan); }
      catch(e2){ tampil(false, 'Kamera tidak bisa dibuka: ' + e2); return; }
    }
    document.getElementById('btnStart').style.display='none';
    document.getElementById('btnStop').style.display='inline-block';
  }
  async function berhenti(){
    if (scanner) { await scanner.stop(); scanner.clear(); }
    document.getElementById('btnStart').style.display='inline-block';
    document.getElementById('btnStop').style.display='none';
  }
  </script>

<?php elseif ($page === 'absensi'): $rk = rekap($tgl); ?>
  <div class="card">
    <h2>🗒 Tambah Absensi Manual</h2>
    <form method="post">
      <input type="hidden" name="aksi" value="manual">
      <div class="row">
        <div><label>Siswa</label>
          <select name="nis" required>
            <option value="">-- pilih siswa --</option>
            <?php foreach ($siswa as $s): ?><option value="<?= h($s['nis']) ?>"><?= h($s['nama']) ?> (<?= h($s['kelas']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div><label>Tanggal</label><input type="date" name="tanggal" value="<?= h($tgl) ?>" required></div>
        <div><label>Jenis</label><select name="jenis"><option value="masuk">Jam Masuk</option><option value="pulang">Jam Pulang</option></select></div>
        <div><label>Jam</label><input type="time" name="jam" value="<?= date('H:i') ?>" required></div>
      </div><br><button class="btn green">Simpan</button>
    </form>
  </div>

  <div class="card">
    <h2>Rekapitulasi Absensi</h2>
    <form method="get" class="row" style="align-items:end">
      <input type="hidden" name="page" value="absensi">
      <div style="max-width:200px"><label>Tanggal</label><input type="date" name="tgl" value="<?= h($tgl) ?>"></div>
      <div style="flex:0"><button class="btn">Tampilkan</button></div>
      <div style="flex:0"><button type="button" class="btn gray" onclick="salinMD()" style="white-space:nowrap">Salin Markdown</button></div>
    </form>
    <p>Jam masuk standar: <b><?= fmt(JAM_MASUK_STANDAR) ?></b></p>
    <table>
      <tr><th>Nama Siswa</th><th>Kelas</th><th>Jam Masuk</th><th>Keterangan Masuk</th><th>Jam Pulang</th><th>Keterangan Kehadiran</th><th></th></tr>
      <?php foreach ($rk as $r):
        $c1 = $r['ketMasuk']==='Tepat Waktu' ? 'tw' : ($r['ketMasuk']==='Terlambat' ? 'tl' : '');
        $c2 = $r['hadir']==='Hadir' ? 'tw' : 'th'; ?>
      <tr>
        <td><?= h($r['nama']) ?></td><td><?= h($r['kelas']) ?></td><td><?= fmt($r['masuk']) ?></td>
        <td><?= $c1 ? "<span class='bd $c1'>".h($r['ketMasuk'])."</span>" : '-' ?></td>
        <td><?= fmt($r['pulang']) ?></td>
        <td><span class="bd <?= $c2 ?>"><?= $r['hadir'] ?></span></td>
        <td><?php if ($r['masuk'] || $r['pulang']): ?>
          <form method="post" onsubmit="return confirm('Hapus rekaman hari ini?')" style="margin:0">
            <input type="hidden" name="aksi" value="hapus_absen"><input type="hidden" name="nis" value="<?= h($r['nis']) ?>"><input type="hidden" name="tanggal" value="<?= h($tgl) ?>">
            <button class="btn red sm">🗑</button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; if (!$rk): ?><tr><td colspan="7" class="center">Belum ada siswa.</td></tr><?php endif; ?>
    </table>
  </div>
  <?php
    $md = "| Nama Siswa | Kelas | Jam Masuk | Keterangan Masuk | Jam Pulang | Keterangan Kehadiran |\n|---|---|---|---|---|---|\n";
    foreach ($rk as $r) $md .= "| {$r['nama']} | {$r['kelas']} | " . fmt($r['masuk']) . " | {$r['ketMasuk']} | " . fmt($r['pulang']) . " | {$r['hadir']} |\n";
  ?>
  <script>
  function salinMD(){ navigator.clipboard.writeText(<?= json_encode($md) ?>).then(()=>alert('Tabel Markdown disalin')); }
  </script>
<?php endif; ?>
</div>
</body>
</html>