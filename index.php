<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');

/*
 * Presensi Anggota IPM — PHP + MySQL dalam satu file.
 * Sesuaikan kredensial MySQL jika berbeda.
 */
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'db_absensidigital';

try {
    // Membuat database jika akun MySQL memiliki izin CREATE DATABASE.
    $pdoServer = new PDO(
        "mysql:host={$dbHost};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_state (
        state_key VARCHAR(100) NOT NULL PRIMARY KEY,
        state_value LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Endpoint internal untuk menyimpan/memuat semua data aplikasi yang sebelumnya memakai localStorage.
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($_GET['api'] === 'load' && $method === 'GET') {
            $rows = $pdo->query('SELECT state_key, state_value FROM app_state')->fetchAll();
            $state = [];
            foreach ($rows as $row) {
                $decoded = json_decode($row['state_value'], true);
                $state[$row['state_key']] = $decoded;
            }
            echo json_encode(['ok' => true, 'state' => $state], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($_GET['api'] === 'save' && $method === 'POST') {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw ?: '', true);
            if (!is_array($payload) || !isset($payload['key']) || !is_string($payload['key'])
                || !preg_match('/^ipm_[a-zA-Z0-9_]{1,90}$/', $payload['key'])
                || !array_key_exists('value', $payload)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Data tidak valid.']);
                exit;
            }
            $value = json_encode($payload['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($value === false) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Format data tidak valid.']);
                exit;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO app_state (state_key, state_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)'
            );
            $stmt->execute([':k' => $payload['key'], ':v' => $value]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($_GET['api'] === 'delete' && $method === 'POST') {
            $payload = json_decode(file_get_contents('php://input') ?: '', true);
            $key = is_array($payload) ? ($payload['key'] ?? '') : '';
            if (!is_string($key) || !preg_match('/^ipm_[a-zA-Z0-9_]{1,90}$/', $key)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Kunci tidak valid.']);
                exit;
            }
            $stmt = $pdo->prepare('DELETE FROM app_state WHERE state_key = ?');
            $stmt->execute([$key]);
            echo json_encode(['ok' => true]);
            exit;
        }

        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Endpoint tidak ditemukan.']);
        exit;
    }
} catch (Throwable $e) {
    if (isset($_GET['api'])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        // Detail teknis tidak ditampilkan ke browser untuk menghindari kebocoran konfigurasi.
        echo json_encode(['ok' => false, 'message' => 'Koneksi database gagal. Pastikan MySQL aktif dan konfigurasi database benar.']);
        exit;
    }
    $dbError = 'Koneksi database gagal. Pastikan MySQL aktif dan akun memiliki izin membuat/mengakses database db_absensidigital.';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presensi Anggota IPM Pro - Dashboard & Google Maps Integration</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- HTML5 QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <!-- QRCode.js Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @media print {
            header, nav, button, form, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #f59e0b; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #d97706; }
        
        #qr-reader { width: 100% !important; height: 100% !important; border: none !important; }
        #qr-reader video { width: 100% !important; height: 100% !important; object-fit: cover !important; border-radius: 0.75rem; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 font-sans antialiased">
    <?php if (isset($dbError)): ?>
    <div style="background:#fee2e2;color:#991b1b;padding:12px 18px;font:14px sans-serif;text-align:center">
        <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Header Navigation -->
    <header class="bg-amber-500 text-slate-900 shadow-lg sticky top-0 z-40 border-b-2 border-amber-600">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <div class="bg-slate-900 text-amber-400 p-2 rounded-xl shadow-md">
                    <i class="fa-solid fa-id-card text-2xl"></i>
                </div>
                <div>
                    <h1 id="brandOrgName" class="text-xl font-black tracking-tight flex items-center">
                        Presensi Anggota IPM 
                        <span class="ml-2 text-xs bg-slate-900 text-amber-300 px-2.5 py-0.5 rounded-full font-extrabold">Pro v8.5</span>
                    </h1>
                    <p class="text-xs font-bold text-slate-800">Ikatan Pelajar Muhammadiyah &bull; Live Google Maps GPS</p>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <button onclick="openViewerModal()" class="bg-slate-900 hover:bg-slate-800 text-amber-300 px-3 py-2 rounded-xl text-xs font-bold transition flex items-center shadow">
                    <i class="fa-solid fa-desktop mr-1.5"></i> Mode Kiosk
                </button>
                <div id="adminBadge" class="hidden md:flex items-center bg-slate-900/80 text-emerald-400 text-xs px-3 py-1.5 rounded-full border border-emerald-500/30">
                    <span class="w-2 h-2 bg-emerald-400 rounded-full mr-2 animate-pulse"></span>
                    Admin Active
                </div>
                <button id="btnSwitchView" onclick="toggleMainView()" class="bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center shadow">
                    <i class="fa-solid fa-user-shield mr-1.5 text-amber-400" id="switchIcon"></i>
                    <span id="switchText">Portal Admin</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- ================= PORTAL ANGGOTA ================= -->
        <section id="karyawanView" class="space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Absensi Card -->
                <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <div class="text-center bg-slate-900 text-white p-4 rounded-xl mb-4 shadow-inner">
                        <div id="liveTime" class="text-3xl font-extrabold tracking-wider text-amber-400">00:00:00 WIB</div>
                        <div id="liveDate" class="text-xs text-slate-400 mt-1 uppercase tracking-widest font-medium">Loading Date...</div>
                    </div>

                    <!-- Live GPS Address Status Box -->
                    <div class="bg-slate-50 border border-slate-200 p-3 rounded-xl mb-4 text-xs font-semibold text-slate-700 flex items-center space-x-2">
                        <i class="fa-solid fa-location-dot text-red-500 text-base animate-bounce"></i>
                        <div class="overflow-hidden flex-1">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block">Lokasi Live Client Auto-GPS:</span>
                            <span id="liveGpsAddress" class="text-slate-800 font-bold truncate block">Mendeteksi lokasi...</span>
                        </div>
                    </div>

                    <!-- Mode Selector Tab -->
                    <div class="grid grid-cols-2 gap-1 bg-slate-100 p-1 rounded-xl mb-5">
                        <button type="button" onclick="switchCheckinMode('instant')" id="modeInstantBtn" class="py-2 text-xs font-bold rounded-lg bg-amber-500 text-slate-900 shadow-sm transition">
                            <i class="fa-solid fa-bolt mr-1"></i> Scan Instan
                        </button>
                        <button type="button" onclick="switchCheckinMode('manual')" id="modeManualBtn" class="py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900 transition">
                            <i class="fa-solid fa-pen-to-square mr-1"></i> Input Manual
                        </button>
                    </div>

                    <!-- MENU 1: ABSENSI INSTAN (SCANNER QR) -->
                    <div id="instantScanSection" class="space-y-4">
                        <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl text-amber-900 text-xs flex items-center space-x-2">
                            <i class="fa-solid fa-camera text-base text-amber-600"></i>
                            <div>
                                <strong class="block">Auto-Verify QR Code</strong>
                                Arahkan Kartu Anggota ke kamera. Terverifikasi otomatis.
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Sesi Kegiatan <span class="text-red-500">*</span></label>
                            <select id="instantShiftSelect" class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-lg p-2.5 font-semibold"></select>
                        </div>

                        <!-- Frame Kamera Persegi Tinggi -->
                        <div class="relative bg-slate-900 rounded-2xl overflow-hidden border-2 border-dashed border-amber-500 w-full aspect-square flex items-center justify-center shadow-md max-w-[320px] mx-auto">
                            <div id="qr-reader" class="w-full h-full"></div>
                        </div>

                        <div id="instantScanStatus" class="p-3 text-center rounded-xl text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200 transition-all">
                            Menunggu pindaian Kartu Anggota IPM...
                        </div>

                        <button type="button" onclick="initInstantScanner()" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-2.5 rounded-xl transition flex items-center justify-center shadow">
                            <i class="fa-solid fa-rotate mr-2 text-amber-400"></i> Restart Kamera Scanner
                        </button>
                    </div>

                    <!-- MENU 2: FORM MANUAL -->
                    <form id="attendanceForm" onsubmit="handleAttendanceSubmit(event)" class="space-y-4 hidden">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Pilih Anggota <span class="text-red-500">*</span></label>
                            <select id="empSelect" required class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-lg p-2.5 font-medium">
                                <option value="">-- Pilih Nama Anda --</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Tipe Presensi <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="flex items-center p-2.5 border rounded-lg cursor-pointer hover:bg-slate-50 border-slate-200 text-xs font-semibold">
                                    <input type="radio" name="attendanceType" value="Masuk" checked class="w-4 h-4 text-amber-500 focus:ring-amber-400 mr-2">
                                    <span>Absen Masuk</span>
                                </label>
                                <label class="flex items-center p-2.5 border rounded-lg cursor-pointer hover:bg-slate-50 border-slate-200 text-xs font-semibold">
                                    <input type="radio" name="attendanceType" value="Keluar" class="w-4 h-4 text-amber-500 focus:ring-amber-400 mr-2">
                                    <span>Absen Keluar</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Sesi / Shift Kegiatan <span class="text-red-500">*</span></label>
                            <select id="shiftSelect" required class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-lg p-2.5">
                                <option value="">-- Pilih Sesi --</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Foto Selfie <span class="text-emerald-600">(Tidak wajib diisi)</span></label>
                            <div class="relative bg-slate-100 rounded-xl overflow-hidden border border-slate-300 aspect-video flex items-center justify-center">
                                <video id="webcam" autoplay playsinline class="w-full h-full object-cover hidden"></video>
                                <img id="photoPreview" class="w-full h-full object-cover hidden" alt="Foto Absen">
                                <div id="cameraPlaceholder" class="text-center p-4">
                                    <i class="fa-solid fa-camera text-3xl text-slate-400 mb-2"></i>
                                    <p class="text-xs text-slate-500">Foto selfie tidak wajib. Kamu bisa langsung mengirim presensi tanpa mengambil foto.</p>
                                </div>
                            </div>
                            <div class="flex gap-2 mt-2">
                                <button type="button" onclick="startCamera()" id="btnStartCam" class="flex-1 bg-slate-800 hover:bg-slate-900 text-white text-xs py-2 px-3 rounded-lg font-medium transition flex items-center justify-center">
                                    <i class="fa-solid fa-video mr-1.5 text-amber-400"></i> Buka Kamera
                                </button>
                                <button type="button" onclick="capturePhoto()" id="btnCapture" disabled class="flex-1 bg-amber-500 disabled:opacity-50 hover:bg-amber-600 text-slate-900 text-xs py-2 px-3 rounded-lg font-bold transition flex items-center justify-center">
                                    <i class="fa-solid fa-circle-dot mr-1.5"></i> Ambil Foto
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Lokasi Auto GPS Client</label>
                            <input type="text" id="locationInput" readonly required class="bg-slate-100 border border-slate-300 text-slate-700 text-xs rounded-lg block w-full p-2.5 font-semibold">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Catatan (Opsional)</label>
                            <textarea id="attendanceNotes" rows="2" placeholder="Catatan kegiatan..." class="w-full bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-lg p-2.5"></textarea>
                        </div>

                        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-amber-400 font-bold py-3 px-4 rounded-xl shadow-md transition text-sm flex items-center justify-center">
                            <i class="fa-solid fa-paper-plane mr-2"></i> Kirim Presensi Manual
                        </button>
                    </form>
                </div>

                <!-- Personal History Log Card -->
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-base font-bold text-slate-800 flex items-center">
                            <i class="fa-solid fa-clock-rotate-left mr-2 text-amber-500"></i> Riwayat Presensi Anggota Hari Ini
                        </h2>
                        <span id="todayCount" class="text-xs bg-amber-50 text-amber-800 font-bold px-2.5 py-1 rounded-full border border-amber-200">0 Data</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left text-slate-600">
                            <thead class="text-xs text-slate-700 uppercase bg-slate-100">
                                <tr>
                                    <th class="px-3 py-3 rounded-l-lg">Waktu</th>
                                    <th class="px-3 py-3">Anggota IPM</th>
                                    <th class="px-3 py-3">Sesi</th>
                                    <th class="px-3 py-3">Lokasi / Maps</th>
                                    <th class="px-3 py-3">Status</th>
                                    <th class="px-3 py-3 text-center rounded-r-lg">Detail</th>
                                </tr>
                            </thead>
                            <tbody id="todayAttendanceTable" class="divide-y divide-slate-100">
                                <tr><td colspan="6" class="text-center py-8 text-slate-400">Belum ada data presensi hari ini.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= DASHBOARD ADMIN ================= -->
        <section id="adminView" class="hidden space-y-6">
            <div class="flex flex-wrap border-b border-slate-200 gap-2">
                <button onclick="switchAdminTab('dashboard')" id="tabBtnDashboard" class="py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-amber-500 text-amber-600 bg-white">
                    <i class="fa-solid fa-chart-pie mr-1.5"></i> Dashboard & Pengaturan
                </button>
                <button onclick="switchAdminTab('rekap')" id="tabBtnRekap" class="py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-transparent text-slate-500 hover:text-slate-700">
                    <i class="fa-solid fa-map-location-dot mr-1.5"></i> Rekap Absensi & Google Maps
                </button>
                <button onclick="switchAdminTab('karyawan')" id="tabBtnKaryawan" class="py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-transparent text-slate-500 hover:text-slate-700">
                    <i class="fa-solid fa-id-card mr-1.5"></i> Data Anggota & QR
                </button>
                <button onclick="switchAdminTab('master')" id="tabBtnMaster" class="py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-transparent text-slate-500 hover:text-slate-700">
                    <i class="fa-solid fa-layer-group mr-1.5"></i> Master Sesi & Bidang
                </button>
                <button onclick="logoutAdmin()" class="ml-auto py-2 px-3 text-xs font-bold text-red-600 hover:bg-red-50 rounded-lg transition flex items-center">
                    <i class="fa-solid fa-right-from-bracket mr-1"></i> Logout
                </button>
            </div>

            <!-- TAB 0: DASHBOARD & PENGATURAN SISTEM -->
            <div id="adminTabDashboard" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-4">
                        <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center text-xl font-bold"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <div class="text-xs text-slate-500 font-semibold uppercase">Total Anggota</div>
                            <div id="dashTotalEmp" class="text-2xl font-black text-slate-800">0</div>
                        </div>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-4">
                        <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center text-xl font-bold"><i class="fa-solid fa-clipboard-check"></i></div>
                        <div>
                            <div class="text-xs text-slate-500 font-semibold uppercase">Hadir Hari Ini</div>
                            <div id="dashTotalHadir" class="text-2xl font-black text-emerald-600">0</div>
                        </div>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-4">
                        <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-xl flex items-center justify-center text-xl font-bold"><i class="fa-solid fa-bolt"></i></div>
                        <div>
                            <div class="text-xs text-slate-500 font-semibold uppercase">Scan Instan</div>
                            <div id="dashTotalScan" class="text-2xl font-black text-indigo-600">0</div>
                        </div>
                    </div>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-4">
                        <div class="w-12 h-12 bg-sky-100 text-sky-600 rounded-xl flex items-center justify-center text-xl font-bold"><i class="fa-solid fa-map"></i></div>
                        <div>
                            <div class="text-xs text-slate-500 font-semibold uppercase">GPS Aktif</div>
                            <div class="text-xs font-bold text-sky-600 mt-1"><i class="fa-solid fa-circle-check"></i> Realtime API</div>
                        </div>
                    </div>
                </div>

                <!-- Form Pengaturan Organisasi -->
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="text-sm font-bold text-slate-800 border-b pb-3 flex items-center">
                        <i class="fa-solid fa-gears text-amber-600 mr-2"></i> Pengaturan Konfigurasi Organisasi & Kantor Pusat
                    </h3>
                    <form onsubmit="handleSaveSettings(event)" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Nama Organisasi / Cabang</label>
                            <input type="text" id="settingOrgName" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5 font-semibold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Alamat / Lokasi Kantor Pusat</label>
                            <input type="text" id="settingOfficeLocation" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5 font-semibold">
                        </div>
                        <div class="sm:col-span-2 pt-2">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs font-bold px-5 py-2.5 rounded-xl shadow transition">
                                <i class="fa-solid fa-floppy-disk mr-1.5"></i> Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 1: REKAP ABSENSI & GOOGLE MAPS -->
            <div id="adminTabRekap" class="hidden space-y-6">
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-wrap gap-3 items-center justify-between">
                    <div class="flex flex-wrap gap-2 items-center flex-1">
                        <input type="text" id="searchFilter" oninput="renderAdminAttendanceTable()" placeholder="Cari NBA / Nama..." class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2 w-44">
                        <select id="deptFilter" onchange="renderAdminAttendanceTable()" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2">
                            <option value="">Semua Bidang/Ranting</option>
                        </select>
                        <input type="date" id="dateFilter" onchange="renderAdminAttendanceTable()" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2">
                    </div>
                    <div class="flex gap-2">
                        <button onclick="exportToCSV()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-3 py-2 rounded-lg font-semibold transition flex items-center">
                            <i class="fa-solid fa-file-excel mr-1.5"></i> Ekspor CSV
                        </button>
                        <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-900 text-white text-xs px-3 py-2 rounded-lg font-semibold transition flex items-center">
                            <i class="fa-solid fa-print mr-1.5"></i> Cetak Laporan
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left text-slate-600">
                            <thead class="text-xs text-slate-700 uppercase bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3">Tanggal & Waktu</th>
                                    <th class="px-4 py-3">Nama Anggota</th>
                                    <th class="px-4 py-3">Bidang / Ranting</th>
                                    <th class="px-4 py-3">Lokasi Absen (Google Maps)</th>
                                    <th class="px-4 py-3">Metode</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="adminAttendanceTable" class="divide-y divide-slate-100">
                                <tr><td colspan="7" class="text-center py-6">Memuat data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: DATA ANGGOTA & QR -->
            <div id="adminTabKaryawan" class="hidden space-y-6">
                <div class="flex justify-between items-center">
                    <h2 class="text-base font-bold text-slate-800">Data Anggota IPM & Cetak QR Code</h2>
                    <button onclick="openEmployeeModal()" class="bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs px-3.5 py-2 rounded-lg font-bold transition flex items-center">
                        <i class="fa-solid fa-user-plus mr-1.5"></i> Tambah Anggota
                    </button>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left text-slate-600">
                            <thead class="text-xs text-slate-700 uppercase bg-slate-100">
                                <tr>
                                    <th class="px-4 py-3">NBA</th>
                                    <th class="px-4 py-3">Nama Lengkap</th>
                                    <th class="px-4 py-3">Bidang / Ranting</th>
                                    <th class="px-4 py-3">Jabatan</th>
                                    <th class="px-4 py-3 text-center">Barcode / QR</th>
                                    <th class="px-4 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="adminEmployeeTable" class="divide-y divide-slate-100">
                                <tr><td colspan="6" class="text-center py-6">Belum ada data anggota.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: MASTER DATA & SHIFT -->
            <div id="adminTabMaster" class="hidden space-y-6">
                <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="font-bold text-sm text-slate-800 border-b pb-2 flex justify-between items-center">
                        <span><i class="fa-solid fa-clock text-amber-600 mr-2"></i> Master Sesi Kegiatan</span>
                    </h3>
                    <form onsubmit="handleAddShift(event)" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <input type="text" id="newShiftNameInput" required placeholder="Sesi (Contoh: Sesi Pagi)" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                        <input type="text" id="newShiftTimeInput" required placeholder="Jam (Contoh: 08:00 - 12:00)" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs px-4 py-2.5 rounded-lg font-bold">
                            <i class="fa-solid fa-plus mr-1"></i> Tambah Sesi
                        </button>
                    </form>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left text-slate-600 mt-2">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="p-2.5">Sesi</th>
                                    <th class="p-2.5">Waktu</th>
                                    <th class="p-2.5 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="shiftList" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
                        <h3 class="font-bold text-sm text-slate-800 border-b pb-2">Master Bidang / Ranting</h3>
                        <form onsubmit="handleAddDepartment(event)" class="flex gap-2">
                            <input type="text" id="newDeptInput" required placeholder="Bidang Baru" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2 flex-1">
                            <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-amber-400 text-xs px-3 py-2 rounded-lg font-bold">Tambah</button>
                        </form>
                        <ul id="departmentList" class="divide-y divide-slate-100 text-xs"></ul>
                    </div>

                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
                        <h3 class="font-bold text-sm text-slate-800 border-b pb-2">Master Jabatan</h3>
                        <form onsubmit="handleAddPosition(event)" class="flex gap-2">
                            <input type="text" id="newPosInput" required placeholder="Jabatan Baru" class="bg-slate-50 border border-slate-300 text-xs rounded-lg p-2 flex-1">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs px-3 py-2 rounded-lg font-bold">Tambah</button>
                        </form>
                        <ul id="positionList" class="divide-y divide-slate-100 text-xs"></ul>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- MODAL: LOGIN ADMIN -->
    <div id="loginModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-5">
            <div class="text-center">
                <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fa-solid fa-lock text-xl"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Autentikasi Admin IPM</h3>
            </div>
            <form onsubmit="handleAdminLogin(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Username</label>
                    <input type="text" id="adminUser" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Password</label>
                    <input type="password" id="adminPass" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="closeLoginModal()" class="w-1/2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold py-2.5 rounded-lg">Batal</button>
                    <button type="submit" class="w-1/2 bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs font-bold py-2.5 rounded-lg shadow">Login</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: ANGGOTA CRUD -->
    <div id="employeeModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
            <h3 id="empModalTitle" class="text-base font-bold text-slate-800 border-b pb-2">Tambah Anggota Baru</h3>
            <form onsubmit="handleEmployeeSubmit(event)" class="space-y-3">
                <input type="hidden" id="empIdHidden">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">NBA (Nomor Baku Anggota)</label>
                    <input type="text" id="empNip" required placeholder="IPM001" class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap</label>
                    <input type="text" id="empName" required placeholder="Nama lengkap..." class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Bidang / Ranting</label>
                    <select id="empDeptSelect" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5"></select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jabatan</label>
                    <select id="empPosSelect" required class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5"></select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email</label>
                    <input type="email" id="empEmail" required placeholder="email@ipm.or.id" class="w-full bg-slate-50 border border-slate-300 text-xs rounded-lg p-2.5">
                </div>
                <div class="flex gap-2 pt-3">
                    <button type="button" onclick="closeEmployeeModal()" class="w-1/2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold py-2.5 rounded-lg">Batal</button>
                    <button type="submit" class="w-1/2 bg-amber-500 hover:bg-amber-600 text-slate-900 text-xs font-bold py-2.5 rounded-lg shadow">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: DISPLAY QR CODE -->
    <div id="qrModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4 text-center">
            <h3 class="text-base font-bold text-slate-800">Kartu Anggota QR Code IPM</h3>
            <p id="qrModalSub" class="text-xs text-slate-500">Scan QR Code ini pada menu Absen Instan.</p>
            <div id="qrcodeContainer" class="bg-white p-4 rounded-xl inline-block border border-slate-200 shadow-inner"></div>
            <div>
                <button type="button" onclick="closeQrModal()" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold py-2.5 rounded-lg">Tutup</button>
            </div>
        </div>
    </div>

    <!-- MODAL: VIEWER KIOSK -->
    <div id="viewerModal" class="fixed inset-0 bg-slate-900 z-50 flex flex-col hidden p-6 text-white overflow-y-auto">
        <div class="flex justify-between items-center border-b border-slate-800 pb-4 mb-6">
            <div class="flex items-center space-x-3">
                <div class="bg-amber-500 text-slate-900 p-2.5 rounded-xl"><i class="fa-solid fa-desktop text-2xl"></i></div>
                <div>
                    <h2 class="text-xl font-black">IPM Presensi Monitor Kiosk</h2>
                    <p class="text-xs text-slate-400">Live Stream Absensi & Google Maps GPS</p>
                </div>
            </div>
            <button onclick="closeViewerModal()" class="bg-slate-800 hover:bg-slate-700 px-4 py-2 rounded-xl text-xs font-bold transition">Tutup Monitor</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="md:col-span-1 bg-slate-800 p-6 rounded-2xl border border-slate-700 text-center flex flex-col justify-center">
                <div id="viewerClock" class="text-4xl font-black text-amber-400 mb-2">00:00:00</div>
                <div id="viewerDate" class="text-sm text-slate-400 uppercase tracking-widest">Loading Date...</div>
                <div class="mt-6 pt-6 border-t border-slate-700">
                    <div class="text-xs text-slate-400 uppercase">Total Anggota Hadir Hari Ini</div>
                    <div id="viewerTotalHadir" class="text-5xl font-black text-emerald-400 mt-2">0</div>
                </div>
            </div>

            <div class="md:col-span-2 bg-slate-800 p-6 rounded-2xl border border-slate-700 flex flex-col">
                <h3 class="text-sm font-bold text-slate-300 mb-4 flex items-center">
                    <i class="fa-solid fa-list-check mr-2 text-amber-400"></i> Aktivitas Absensi Terbaru
                </h3>
                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-xs text-left text-slate-300">
                        <thead class="bg-slate-700 text-slate-200 uppercase">
                            <tr>
                                <th class="p-3 rounded-l-lg">Waktu</th>
                                <th class="p-3">Nama Anggota</th>
                                <th class="p-3">Sesi</th>
                                <th class="p-3">Lokasi / Maps</th>
                                <th class="p-3 rounded-r-lg">Status</th>
                            </tr>
                        </thead>
                        <tbody id="viewerTableBody" class="divide-y divide-slate-700/50">
                            <tr><td colspan="5" class="text-center py-6 text-slate-500">Menunggu aktivitas presensi...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- JEMBATAN DATABASE: memuat data MySQL sebelum logika aplikasi berjalan -->
    <script>
    (function () {
        const nativeSetItem = Storage.prototype.setItem;
        const nativeRemoveItem = Storage.prototype.removeItem;
        const nativeClear = Storage.prototype.clear;

        // XHR sinkron hanya untuk bootstrap awal agar data dari database tersedia sebelum aplikasi diinisialisasi.
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', window.location.pathname + '?api=load', false);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(null);
            if (xhr.status >= 200 && xhr.status < 300) {
                const result = JSON.parse(xhr.responseText);
                if (result && result.ok && result.state) {
                    Object.keys(result.state).forEach(function (key) {
                        nativeSetItem.call(localStorage, key, JSON.stringify(result.state[key]));
                    });
                }
            } else {
                console.error('Data database tidak berhasil dimuat.');
            }
        } catch (error) {
            console.error('Tidak dapat memuat data database:', error);
        }

        // Tetap mendukung kode aplikasi lama, tetapi setiap perubahan ipm_* juga dikirim ke PHP/MySQL.
        Storage.prototype.setItem = function (key, value) {
            nativeSetItem.call(this, key, value);
            if (this !== localStorage || !/^ipm_[a-zA-Z0-9_]{1,90}$/.test(String(key))) return;
            let parsedValue;
            try { parsedValue = JSON.parse(String(value)); }
            catch (_) { parsedValue = String(value); }
            fetch(window.location.pathname + '?api=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: String(key), value: parsedValue })
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            }).then(function (result) {
                if (!result.ok) console.error('Data belum tersimpan ke database.');
            }).catch(function (error) {
                console.error('Gagal menyimpan data ke database:', error);
            });
        };

        Storage.prototype.removeItem = function (key) {
            nativeRemoveItem.call(this, key);
            if (this !== localStorage || !/^ipm_[a-zA-Z0-9_]{1,90}$/.test(String(key))) return;
            fetch(window.location.pathname + '?api=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: String(key) })
            }).catch(function (error) { console.error('Gagal menghapus data database:', error); });
        };

        Storage.prototype.clear = function () {
            const keys = [];
            if (this === localStorage) {
                for (let i = 0; i < this.length; i++) {
                    const key = this.key(i);
                    if (key && /^ipm_[a-zA-Z0-9_]{1,90}$/.test(key)) keys.push(key);
                }
            }
            nativeClear.call(this);
            if (this === localStorage) {
                keys.forEach(function (key) {
                    fetch(window.location.pathname + '?api=delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ key: key })
                    }).catch(function (error) { console.error(error); });
                });
            }
        };
    })();
    </script>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        // --- SETTINGS STORE ---
        let appSettings = JSON.parse(localStorage.getItem('ipm_settings')) || {
            orgName: 'Presensi Anggota IPM',
            officeLocation: 'Gedung PDM / Sekretariat IPM'
        };

        let employees = JSON.parse(localStorage.getItem('ipm_employees')) || [
            { id: 1, nip: 'IPM001', name: 'Ahmad Dahlan', dept: 'Kaderisasi', pos: 'Ketua Umum', email: 'dahlan@ipm.or.id' },
            { id: 2, nip: 'IPM002', name: 'Siti Walidah', dept: 'PIP (Pengkajian Ilmu)', pos: 'Sekretaris Umum', email: 'walidah@ipm.or.id' },
            { id: 3, nip: 'IPM003', name: 'KHA Badawi', dept: 'KDI (Kajian Dakwah)', pos: 'Kabid KDI', email: 'badawi@ipm.or.id' }
        ];

        let shifts = JSON.parse(localStorage.getItem('ipm_shifts')) || [
            { name: 'Sesi Pagi / Rapat Utama', time: '08:00 - 12:00' },
            { name: 'Sesi Siang / Pleno', time: '13:00 - 17:00' },
            { name: 'Sesi Malam / Diskusi', time: '19:00 - 22:00' }
        ];

        let departments = JSON.parse(localStorage.getItem('ipm_departments')) || [
            'Kaderisasi', 'PIP (Pengkajian Ilmu)', 'KDI (Kajian Dakwah)', 'ASBO (Seni & Olahraga)', 'Advokasi'
        ];

        let positions = JSON.parse(localStorage.getItem('ipm_positions')) || [
            'Ketua Umum', 'Sekretaris Umum', 'Bendahara Umum', 'Kabid', 'Anggota Bidang'
        ];

        let attendanceLogs = JSON.parse(localStorage.getItem('ipm_attendance')) || [];

        let isAdminLoggedIn = localStorage.getItem('ipm_admin_logged') === 'true';
        let currentCheckinMode = 'instant';
        let html5QrCode = null;
        let mediaStream = null;
        let capturedPhotoData = null;
        let isProcessingScan = false;
        let currentLiveGpsAddress = "Mendeteksi lokasi Auto-GPS...";
        let currentCoords = "-6.2088,106.8456";

        // --- APPLICATION INIT ---
        window.addEventListener('DOMContentLoaded', () => {
            updateLiveClock();
            setInterval(updateLiveClock, 1000);

            applySettings();
            initAutoGps();

            populateDropdowns();
            renderTodayAttendance();
            renderAdminAttendanceTable();
            renderAdminEmployeeTable();
            renderMasterLists();
            updateAdminStats();

            if (isAdminLoggedIn) {
                document.getElementById('adminBadge').classList.remove('hidden');
                document.getElementById('switchText').innerText = 'Portal Anggota';
            }

            initInstantScanner();
        });

        function applySettings() {
            document.getElementById('brandOrgName').innerHTML = `${appSettings.orgName} <span class="ml-2 text-xs bg-slate-900 text-amber-300 px-2.5 py-0.5 rounded-full font-extrabold">Pro v8.5</span>`;
            if(document.getElementById('settingOrgName')) document.getElementById('settingOrgName').value = appSettings.orgName;
            if(document.getElementById('settingOfficeLocation')) document.getElementById('settingOfficeLocation').value = appSettings.officeLocation;
        }

        function handleSaveSettings(event) {
            event.preventDefault();
            appSettings.orgName = document.getElementById('settingOrgName').value;
            appSettings.officeLocation = document.getElementById('settingOfficeLocation').value;
            localStorage.setItem('ipm_settings', JSON.stringify(appSettings));
            applySettings();
            alert('Pengaturan organisasi berhasil disimpan!');
        }

        // --- CLOCK ---
        function updateLiveClock() {
            const now = new Date();
            const timeString = now.toTimeString().split(' ')[0] + ' WIB';
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateString = now.toLocaleDateString('id-ID', options);

            if (document.getElementById('liveTime')) {
                document.getElementById('liveTime').innerText = timeString;
                document.getElementById('liveDate').innerText = dateString;
            }
            if (document.getElementById('viewerClock')) {
                document.getElementById('viewerClock').innerText = timeString;
                document.getElementById('viewerDate').innerText = dateString;
            }
        }

        // --- LIVE AUTO GPS & REVERSE GEOCODING ---
        function initAutoGps() {
            const gpsLabel = document.getElementById('liveGpsAddress');
            const locationInput = document.getElementById('locationInput');

            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        currentCoords = `${lat},${lng}`;
                        
                        try {
                            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                            const data = await response.json();
                            
                            if (data && data.display_name) {
                                const addr = data.address;
                                const road = addr.road || addr.suburb || addr.village || '';
                                const city = addr.city || addr.town || addr.regency || addr.county || '';
                                const state = addr.state || '';
                                
                                let formattedAddress = [road, city, state].filter(Boolean).join(', ');
                                if (!formattedAddress) formattedAddress = data.display_name;

                                currentLiveGpsAddress = `${formattedAddress}`;
                            } else {
                                currentLiveGpsAddress = `Lat: ${lat.toFixed(4)}, Lng: ${lng.toFixed(4)}`;
                            }
                        } catch (err) {
                            currentLiveGpsAddress = `Lat: ${lat.toFixed(4)}, Lng: ${lng.toFixed(4)} (GPS Active)`;
                        }

                        if (gpsLabel) gpsLabel.innerText = currentLiveGpsAddress;
                        if (locationInput) locationInput.value = currentLiveGpsAddress;
                    },
                    (error) => {
                        currentLiveGpsAddress = appSettings.officeLocation;
                        currentCoords = "-6.2088,106.8456";
                        if (gpsLabel) gpsLabel.innerText = currentLiveGpsAddress;
                        if (locationInput) locationInput.value = currentLiveGpsAddress;
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            } else {
                currentLiveGpsAddress = appSettings.officeLocation;
                if (gpsLabel) gpsLabel.innerText = currentLiveGpsAddress;
                if (locationInput) locationInput.value = currentLiveGpsAddress;
            }
        }

        function populateDropdowns() {
            const instantShiftSelect = document.getElementById('instantShiftSelect');
            if(instantShiftSelect) {
                instantShiftSelect.innerHTML = '';
                shifts.forEach(s => {
                    instantShiftSelect.innerHTML += `<option value="${s.name} (${s.time})">${s.name} - ${s.time}</option>`;
                });
            }

            const shiftSelect = document.getElementById('shiftSelect');
            if(shiftSelect) {
                shiftSelect.innerHTML = '<option value="">-- Pilih Sesi --</option>';
                shifts.forEach(s => {
                    shiftSelect.innerHTML += `<option value="${s.name} (${s.time})">${s.name} - ${s.time}</option>`;
                });
            }

            const empSelect = document.getElementById('empSelect');
            if(empSelect) {
                empSelect.innerHTML = '<option value="">-- Pilih Nama Anda --</option>';
                employees.forEach(e => {
                    empSelect.innerHTML += `<option value="${e.nip}">${e.nip} - ${e.name} (${e.dept})</option>`;
                });
            }

            const empDeptSelect = document.getElementById('empDeptSelect');
            if(empDeptSelect) {
                empDeptSelect.innerHTML = '';
                departments.forEach(d => {
                    empDeptSelect.innerHTML += `<option value="${d}">${d}</option>`;
                });
            }

            const empPosSelect = document.getElementById('empPosSelect');
            if(empPosSelect) {
                empPosSelect.innerHTML = '';
                positions.forEach(p => {
                    empPosSelect.innerHTML += `<option value="${p}">${p}</option>`;
                });
            }

            const deptFilter = document.getElementById('deptFilter');
            if(deptFilter) {
                deptFilter.innerHTML = '<option value="">Semua Bidang/Ranting</option>';
                departments.forEach(d => {
                    deptFilter.innerHTML += `<option value="${d}">${d}</option>`;
                });
            }
        }

        function switchCheckinMode(mode) {
            currentCheckinMode = mode;
            const instantSec = document.getElementById('instantScanSection');
            const manualForm = document.getElementById('attendanceForm');
            const btnInstant = document.getElementById('modeInstantBtn');
            const btnManual = document.getElementById('modeManualBtn');

            if (mode === 'instant') {
                instantSec.classList.remove('hidden');
                manualForm.classList.add('hidden');
                btnInstant.className = "py-2 text-xs font-bold rounded-lg bg-amber-500 text-slate-900 shadow-sm transition";
                btnManual.className = "py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900 transition";
                initInstantScanner();
            } else {
                instantSec.classList.add('hidden');
                manualForm.classList.remove('hidden');
                btnManual.className = "py-2 text-xs font-bold rounded-lg bg-amber-500 text-slate-900 shadow-sm transition";
                btnInstant.className = "py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900 transition";
                stopInstantScanner();
            }
        }

        // --- SCANNER ENGINE ---
        function initInstantScanner() {
            if (currentCheckinMode !== 'instant') return;
            if (html5QrCode) {
                html5QrCode.stop().then(() => startScannerEngine()).catch(() => startScannerEngine());
            } else {
                startScannerEngine();
            }
        }

        function startScannerEngine() {
            const statusBox = document.getElementById('instantScanStatus');
            if(!statusBox) return;
            statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200";
            statusBox.innerText = "Mengaktifkan kamera pemindai...";

            html5QrCode = new Html5Qrcode("qr-reader");
            const config = { fps: 10, qrbox: { width: 220, height: 220 } };

            html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure).catch(err => {
                statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-red-50 text-red-700 border border-red-200";
                statusBox.innerText = "Gagal mengakses kamera. Gunakan Input Manual.";
            });
        }

        function stopInstantScanner() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => html5QrCode.clear()).catch(err => {});
            }
        }

        function playBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine'; osc.frequency.value = 800;
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                osc.connect(gain); gain.connect(ctx.destination);
                osc.start(); osc.stop(ctx.currentTime + 0.15);
            } catch(e) {}
        }

        function onScanSuccess(decodedText) {
            if (isProcessingScan) return;
            isProcessingScan = true;

            let nip = decodedText.trim();
            try {
                const parsed = JSON.parse(decodedText);
                if (parsed.nip) nip = parsed.nip;
            } catch(e) {}

            const statusBox = document.getElementById('instantScanStatus');
            const employee = employees.find(e => e.nip === nip);

            if (!employee) {
                statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-red-100 text-red-700 border border-red-300 animate-bounce";
                statusBox.innerHTML = `<i class="fa-solid fa-triangle-exclamation mr-1"></i> GAGAL: NBA "${nip}" Tidak Terdaftar!`;
                setTimeout(() => {
                    statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200";
                    statusBox.innerText = "Menunggu pindaian Kartu Anggota IPM...";
                    isProcessingScan = false;
                }, 2000);
                return;
            }

            const todayStr = new Date().toISOString().split('T')[0];
            const alreadyCheckedIn = attendanceLogs.find(log => log.nip === nip && log.date.startsWith(todayStr) && log.type === 'Masuk');

            const shiftVal = document.getElementById('instantShiftSelect').value;
            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];
            const fullDateStr = `${todayStr} ${timeStr}`;
            const attendanceType = alreadyCheckedIn ? 'Keluar' : 'Masuk';

            const newLog = {
                id: Date.now(),
                nip: employee.nip,
                name: employee.name,
                dept: employee.dept,
                shift: shiftVal,
                method: 'Scan Instan',
                type: attendanceType,
                status: 'Hadir',
                location: currentLiveGpsAddress,
                coords: currentCoords,
                notes: 'Terverifikasi Otomatis',
                date: fullDateStr
            };

            attendanceLogs.unshift(newLog);
            localStorage.setItem('ipm_attendance', JSON.stringify(attendanceLogs));
            playBeep();

            statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-emerald-500 text-white border border-emerald-600 shadow-md animate-pulse";
            statusBox.innerHTML = `
                <div class="text-sm"><i class="fa-solid fa-circle-check mr-1"></i> TERVERIFIKASI!</div>
                <div class="text-xs font-normal mt-0.5">${employee.name} (${employee.nip}) &bull; <strong>${attendanceType.toUpperCase()}</strong></div>
            `;

            renderTodayAttendance();
            renderAdminAttendanceTable();
            updateAdminStats();
            renderViewerTable();

            if (html5QrCode) {
                html5QrCode.pause();
                setTimeout(() => {
                    statusBox.className = "p-3 text-center rounded-xl text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200";
                    statusBox.innerText = "Menunggu pindaian Kartu Anggota IPM...";
                    isProcessingScan = false;
                    if (currentCheckinMode === 'instant') html5QrCode.resume();
                }, 3000);
            }
        }

        function onScanFailure(error) {}

        // --- MANUAL FORM & WEBCAM ---
        function startCamera() {
            const video = document.getElementById('webcam');
            const placeholder = document.getElementById('cameraPlaceholder');
            const btnCapture = document.getElementById('btnCapture');

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
                .then(stream => {
                    mediaStream = stream;
                    video.srcObject = stream;
                    video.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                    btnCapture.removeAttribute('disabled');
                })
                .catch(err => alert('Tidak dapat mengakses kamera depan.'));
        }

        function capturePhoto() {
            const video = document.getElementById('webcam');
            const photoPreview = document.getElementById('photoPreview');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth || 320;
            canvas.height = video.videoHeight || 240;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            capturedPhotoData = canvas.toDataURL('image/png');
            photoPreview.src = capturedPhotoData;
            photoPreview.classList.remove('hidden');
            video.classList.add('hidden');

            if (mediaStream) mediaStream.getTracks().forEach(track => track.stop());
        }

        function handleAttendanceSubmit(event) {
            event.preventDefault();
            const nip = document.getElementById('empSelect').value;
            const employee = employees.find(e => e.nip === nip);
            const type = document.querySelector('input[name="attendanceType"]:checked').value;
            const shift = document.getElementById('shiftSelect').value;
            const notes = document.getElementById('attendanceNotes').value;

            const now = new Date();
            const fullDateStr = now.toISOString().split('T')[0] + ' ' + now.toTimeString().split(' ')[0];

            const newLog = {
                id: Date.now(),
                nip: employee.nip,
                name: employee.name,
                dept: employee.dept,
                shift: shift,
                method: 'Input Manual',
                type: type,
                status: 'Hadir',
                location: currentLiveGpsAddress,
                coords: currentCoords,
                notes: notes || '-',
                date: fullDateStr
            };

            attendanceLogs.unshift(newLog);
            localStorage.setItem('ipm_attendance', JSON.stringify(attendanceLogs));

            alert(`Presensi ${type} atas nama ${employee.name} berhasil disimpan!`);
            document.getElementById('attendanceForm').reset();
            document.getElementById('photoPreview').classList.add('hidden');
            document.getElementById('cameraPlaceholder').classList.remove('hidden');
            document.getElementById('btnCapture').setAttribute('disabled', 'true');
            capturedPhotoData = null;

            renderTodayAttendance();
            renderAdminAttendanceTable();
            updateAdminStats();
            renderViewerTable();
        }

        // --- RENDERING ---
        function renderTodayAttendance() {
            const tbody = document.getElementById('todayAttendanceTable');
            const countBadge = document.getElementById('todayCount');
            const todayStr = new Date().toISOString().split('T')[0];

            const todayLogs = attendanceLogs.filter(log => log.date.startsWith(todayStr));
            countBadge.innerText = `${todayLogs.length} Data`;

            if (todayLogs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-8 text-slate-400">Belum ada data presensi hari ini.</td></tr>`;
                return;
            }

            tbody.innerHTML = '';
            todayLogs.forEach(log => {
                const timeOnly = log.date.split(' ')[1];
                const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(log.coords || log.location)}`;
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-3 py-3 font-semibold text-slate-700">${timeOnly}</td>
                        <td class="px-3 py-3 font-bold text-slate-800">${log.name}<br><span class="text-[10px] text-slate-400 font-normal">${log.nip}</span></td>
                        <td class="px-3 py-3">${log.shift}</td>
                        <td class="px-3 py-3">
                            <a href="${mapsUrl}" target="_blank" class="text-indigo-600 hover:text-indigo-800 font-semibold underline flex items-center">
                                <i class="fa-solid fa-map-location-dot mr-1 text-red-500"></i>${log.location}
                            </a>
                        </td>
                        <td class="px-3 py-3"><span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-bold text-[10px]">${log.type}</span></td>
                        <td class="px-3 py-3 text-center">
                            <button onclick="viewLogDetail(${log.id})" class="bg-amber-50 hover:bg-amber-100 text-amber-600 p-1.5 rounded-lg transition"><i class="fa-solid fa-eye"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function renderAdminAttendanceTable() {
            const tbody = document.getElementById('adminAttendanceTable');
            const search = document.getElementById('searchFilter').value.toLowerCase();
            const dept = document.getElementById('deptFilter').value;
            const date = document.getElementById('dateFilter').value;

            let filtered = attendanceLogs.filter(log => {
                const matchSearch = log.name.toLowerCase().includes(search) || log.nip.toLowerCase().includes(search);
                const matchDept = dept ? log.dept === dept : true;
                const matchDate = date ? log.date.startsWith(date) : true;
                return matchSearch && matchDept && matchDate;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-slate-400">Tidak ada data yang sesuai filter.</td></tr>`;
                return;
            }

            tbody.innerHTML = '';
            filtered.forEach(log => {
                const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(log.coords || log.location)}`;
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-4 py-3 font-medium text-slate-700">${log.date}</td>
                        <td class="px-4 py-3 font-bold text-slate-900">${log.name}<br><span class="text-[10px] text-slate-400 font-normal">${log.nip}</span></td>
                        <td class="px-4 py-3">${log.dept}</td>
                        <td class="px-4 py-3">
                            <a href="${mapsUrl}" target="_blank" class="bg-sky-50 text-sky-700 hover:bg-sky-100 px-2.5 py-1 rounded-lg font-bold text-[11px] inline-flex items-center transition border border-sky-200" title="Buka di Google Maps">
                                <i class="fa-solid fa-map-pin text-red-500 mr-1.5"></i> ${log.location}
                            </a>
                        </td>
                        <td class="px-4 py-3"><span class="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-semibold">${log.method}</span></td>
                        <td class="px-4 py-3"><span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-full font-bold text-[10px]">${log.type}</span></td>
                        <td class="px-4 py-3 text-center space-x-1">
                            <button onclick="viewLogDetail(${log.id})" class="bg-amber-50 hover:bg-amber-100 text-amber-600 p-1.5 rounded-lg transition" title="Detail"><i class="fa-solid fa-eye"></i></button>
                            <button onclick="deleteLog(${log.id})" class="bg-red-50 hover:bg-red-100 text-red-600 p-1.5 rounded-lg transition" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function renderAdminEmployeeTable() {
            const tbody = document.getElementById('adminEmployeeTable');
            if (employees.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-6 text-slate-400">Belum ada data anggota.</td></tr>`;
                return;
            }

            tbody.innerHTML = '';
            employees.forEach(e => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-4 py-3 font-bold text-slate-700">${e.nip}</td>
                        <td class="px-4 py-3 font-semibold text-slate-900">${e.name}<br><span class="text-[10px] text-slate-400">${e.email}</span></td>
                        <td class="px-4 py-3">${e.dept}</td>
                        <td class="px-4 py-3">${e.pos}</td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="generateQrCodeCard('${e.nip}', '${e.name}')" class="bg-amber-50 hover:bg-amber-100 text-amber-800 px-2.5 py-1 rounded-lg text-xs font-bold transition flex items-center justify-center mx-auto">
                                <i class="fa-solid fa-qrcode mr-1"></i> QR Code
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center space-x-1">
                            <button onclick="openEmployeeModal(${e.id})" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 p-1.5 rounded-lg transition"><i class="fa-solid fa-pen"></i></button>
                            <button onclick="deleteEmployee(${e.id})" class="bg-red-50 hover:bg-red-100 text-red-600 p-1.5 rounded-lg transition"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function renderMasterLists() {
            const shiftList = document.getElementById('shiftList');
            if(shiftList) {
                shiftList.innerHTML = '';
                shifts.forEach((s, idx) => {
                    shiftList.innerHTML += `
                        <tr>
                            <td class="p-2.5 font-bold text-slate-700">${s.name}</td>
                            <td class="p-2.5">${s.time}</td>
                            <td class="p-2.5 text-center"><button onclick="deleteShift(${idx})" class="text-red-600 hover:text-red-800"><i class="fa-solid fa-trash"></i></button></td>
                        </tr>
                    `;
                });
            }

            const deptList = document.getElementById('departmentList');
            if(deptList) {
                deptList.innerHTML = '';
                departments.forEach((d, idx) => {
                    deptList.innerHTML += `
                        <li class="py-2 px-1 flex justify-between items-center text-slate-700 font-medium">
                            <span>${d}</span>
                            <button onclick="deleteDepartment(${idx})" class="text-red-600 hover:text-red-800"><i class="fa-solid fa-trash"></i></button>
                        </li>
                    `;
                });
            }

            const posList = document.getElementById('positionList');
            if(posList) {
                posList.innerHTML = '';
                positions.forEach((p, idx) => {
                    posList.innerHTML += `
                        <li class="py-2 px-1 flex justify-between items-center text-slate-700 font-medium">
                            <span>${p}</span>
                            <button onclick="deletePosition(${idx})" class="text-red-600 hover:text-red-800"><i class="fa-solid fa-trash"></i></button>
                        </li>
                    `;
                });
            }
        }

        function updateAdminStats() {
            const todayStr = new Date().toISOString().split('T')[0];
            const todayLogs = attendanceLogs.filter(log => log.date.startsWith(todayStr));

            if(document.getElementById('statHadir')) document.getElementById('statHadir').innerText = todayLogs.length;
            if(document.getElementById('dashTotalHadir')) document.getElementById('dashTotalHadir').innerText = todayLogs.length;
            if(document.getElementById('dashTotalEmp')) document.getElementById('dashTotalEmp').innerText = employees.length;
            if(document.getElementById('dashTotalScan')) document.getElementById('dashTotalScan').innerText = todayLogs.filter(l => l.method === 'Scan Instan').length;
        }

        function renderViewerTable() {
            const tbody = document.getElementById('viewerTableBody');
            if(!tbody) return;
            const todayStr = new Date().toISOString().split('T')[0];
            const todayLogs = attendanceLogs.filter(log => log.date.startsWith(todayStr)).slice(0, 5);
            const totalHadir = attendanceLogs.filter(log => log.date.startsWith(todayStr)).length;

            if(document.getElementById('viewerTotalHadir')) document.getElementById('viewerTotalHadir').innerText = totalHadir;

            if (todayLogs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center py-6 text-slate-500">Menunggu aktivitas presensi...</td></tr>`;
                return;
            }

            tbody.innerHTML = '';
            todayLogs.forEach(log => {
                const timeOnly = log.date.split(' ')[1];
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-700/50 transition">
                        <td class="p-3 font-semibold text-amber-400">${timeOnly}</td>
                        <td class="p-3 font-bold text-white">${log.name}<br><span class="text-[10px] text-slate-400">${log.dept}</span></td>
                        <td class="p-3">${log.shift}</td>
                        <td class="p-3 text-slate-300"><i class="fa-solid fa-location-dot text-red-400 mr-1"></i>${log.location}</td>
                        <td class="p-3"><span class="bg-emerald-900/50 text-emerald-300 px-2.5 py-0.5 rounded-full font-bold text-[10px]">${log.type}</span></td>
                    </tr>
                `;
            });
        }

        // --- ADMIN LOGIN & TABS ---
        function toggleMainView() {
            if (!isAdminLoggedIn) document.getElementById('loginModal').classList.remove('hidden');
            else logoutAdmin();
        }

        function handleAdminLogin(event) {
            event.preventDefault();
            const user = document.getElementById('adminUser').value;
            const pass = document.getElementById('adminPass').value;

            if (user === 'admin' && pass === 'admin123') {
                isAdminLoggedIn = true;
                localStorage.setItem('ipm_admin_logged', 'true');
                document.getElementById('loginModal').classList.add('hidden');
                document.getElementById('adminBadge').classList.remove('hidden');
                document.getElementById('switchText').innerText = 'Portal Anggota';
                
                document.getElementById('karyawanView').classList.add('hidden');
                document.getElementById('adminView').classList.remove('hidden');
                stopInstantScanner();
                updateAdminStats();
            } else {
                alert('Username / Password Admin salah! (Gunakan admin / admin123)');
            }
        }

        function logoutAdmin() {
            isAdminLoggedIn = false;
            localStorage.removeItem('ipm_admin_logged');
            document.getElementById('adminBadge').classList.add('hidden');
            document.getElementById('switchText').innerText = 'Portal Admin';
            document.getElementById('adminView').classList.add('hidden');
            document.getElementById('karyawanView').classList.remove('hidden');
            initInstantScanner();
        }

        function closeLoginModal() { document.getElementById('loginModal').classList.add('hidden'); }

        function switchAdminTab(tabName) {
            ['Dashboard', 'Rekap', 'Karyawan', 'Master'].forEach(t => {
                document.getElementById(`adminTab${t}`).classList.add('hidden');
                document.getElementById(`tabBtn${t}`).className = "py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-transparent text-slate-500 hover:text-slate-700 bg-transparent";
            });

            document.getElementById(`adminTab${tabName.charAt(0).toUpperCase() + tabName.slice(1)}`).classList.remove('hidden');
            document.getElementById(`tabBtn${tabName.charAt(0).toUpperCase() + tabName.slice(1)}`).className = "py-2.5 px-4 text-xs font-bold rounded-t-lg border-b-2 border-amber-500 text-amber-600 bg-white";

            if (tabName === 'rekap') renderAdminAttendanceTable();
            else if (tabName === 'karyawan') renderAdminEmployeeTable();
            else if (tabName === 'master') renderMasterLists();
        }

        // --- EMPLOYEE CRUD ---
        function openEmployeeModal(id = null) {
            const modal = document.getElementById('employeeModal');
            const title = document.getElementById('empModalTitle');
            modal.classList.remove('hidden');

            if (id) {
                title.innerText = "Edit Data Anggota";
                const emp = employees.find(e => e.id === id);
                document.getElementById('empIdHidden').value = emp.id;
                document.getElementById('empNip').value = emp.nip;
                document.getElementById('empName').value = emp.name;
                document.getElementById('empDeptSelect').value = emp.dept;
                document.getElementById('empPosSelect').value = emp.pos;
                document.getElementById('empEmail').value = emp.email;
            } else {
                title.innerText = "Tambah Anggota Baru";
                document.getElementById('empIdHidden').value = '';
                document.getElementById('empNip').value = `IPM00${employees.length + 1}`;
                document.getElementById('empName').value = '';
                document.getElementById('empEmail').value = '';
            }
        }

        function closeEmployeeModal() { document.getElementById('employeeModal').classList.add('hidden'); }

        function handleEmployeeSubmit(event) {
            event.preventDefault();
            const id = document.getElementById('empIdHidden').value;
            const nip = document.getElementById('empNip').value;
            const name = document.getElementById('empName').value;
            const dept = document.getElementById('empDeptSelect').value;
            const pos = document.getElementById('empPosSelect').value;
            const email = document.getElementById('empEmail').value;

            if (id) {
                const index = employees.findIndex(e => e.id == id);
                employees[index] = { id: Number(id), nip, name, dept, pos, email };
            } else {
                employees.push({ id: Date.now(), nip, name, dept, pos, email });
            }

            localStorage.setItem('ipm_employees', JSON.stringify(employees));
            closeEmployeeModal();
            populateDropdowns();
            renderAdminEmployeeTable();
            updateAdminStats();
        }

        function deleteEmployee(id) {
            if (confirm('Yakin menghapus data anggota ini?')) {
                employees = employees.filter(e => e.id !== id);
                localStorage.setItem('ipm_employees', JSON.stringify(employees));
                populateDropdowns();
                renderAdminEmployeeTable();
                updateAdminStats();
            }
        }

        // --- MASTER DATA ---
        function handleAddShift(event) {
            event.preventDefault();
            shifts.push({ name: document.getElementById('newShiftNameInput').value, time: document.getElementById('newShiftTimeInput').value });
            localStorage.setItem('ipm_shifts', JSON.stringify(shifts));
            document.getElementById('newShiftNameInput').value = ''; document.getElementById('newShiftTimeInput').value = '';
            populateDropdowns(); renderMasterLists();
        }
        function deleteShift(i) { shifts.splice(i, 1); localStorage.setItem('ipm_shifts', JSON.stringify(shifts)); populateDropdowns(); renderMasterLists(); }

        function handleAddDepartment(event) {
            event.preventDefault();
            departments.push(document.getElementById('newDeptInput').value);
            localStorage.setItem('ipm_departments', JSON.stringify(departments));
            document.getElementById('newDeptInput').value = '';
            populateDropdowns(); renderMasterLists();
        }
        function deleteDepartment(i) { departments.splice(i, 1); localStorage.setItem('ipm_departments', JSON.stringify(departments)); populateDropdowns(); renderMasterLists(); }

        function handleAddPosition(event) {
            event.preventDefault();
            positions.push(document.getElementById('newPosInput').value);
            localStorage.setItem('ipm_positions', JSON.stringify(positions));
            document.getElementById('newPosInput').value = '';
            populateDropdowns(); renderMasterLists();
        }
        function deletePosition(i) { positions.splice(i, 1); localStorage.setItem('ipm_positions', JSON.stringify(positions)); populateDropdowns(); renderMasterLists(); }

        // --- QR CODE MODAL ---
        function generateQrCodeCard(nip, name) {
            const modal = document.getElementById('qrModal');
            const container = document.getElementById('qrcodeContainer');
            document.getElementById('qrModalSub').innerText = `Kartu Anggota: ${name} (${nip})`;
            container.innerHTML = ''; modal.classList.remove('hidden');
            new QRCode(container, { text: nip, width: 180, height: 180, colorDark: "#0f172a", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H });
        }
        function closeQrModal() { document.getElementById('qrModal').classList.add('hidden'); }

        // --- VIEWER MODAL ---
        function openViewerModal() { document.getElementById('viewerModal').classList.remove('hidden'); renderViewerTable(); }
        function closeViewerModal() { document.getElementById('viewerModal').classList.add('hidden'); }

        function viewLogDetail(id) {
            const log = attendanceLogs.find(l => l.id === id);
            if (!log) return;
            alert(`Detail Presensi:\n\nNama: ${log.name} (${log.nip})\nBidang: ${log.dept}\nSesi: ${log.shift}\nTipe: ${log.type} (${log.method})\nWaktu: ${log.date}\nLokasi GPS: ${log.location}\nCatatan: ${log.notes}`);
        }

        function deleteLog(id) {
            if (confirm('Hapus riwayat presensi ini?')) {
                attendanceLogs = attendanceLogs.filter(l => l.id !== id);
                localStorage.setItem('ipm_attendance', JSON.stringify(attendanceLogs));
                renderTodayAttendance(); renderAdminAttendanceTable(); updateAdminStats();
            }
        }

        function exportToCSV() {
            let csvContent = "data:text/csv;charset=utf-8,ID,NBA,Nama,Bidang,Sesi,Metode,Tipe,Status,Lokasi_Client,Waktu\n";
            attendanceLogs.forEach(log => {
                csvContent += `"${log.id}","${log.nip}","${log.name}","${log.dept}","${log.shift}","${log.method}","${log.type}","${log.status}","${log.location}","${log.date}"\n`;
            });
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `Rekap_Presensi_IPM_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link); link.click(); document.body.removeChild(link);
        }
    </script>
</body>
</html>