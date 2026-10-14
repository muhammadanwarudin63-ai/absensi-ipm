// --- SETTINGS & DATA STORE ---
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
    document.getElementById('brandOrgName').innerHTML = `${appSettings.orgName} <span class="ml-2 text-xs bg-slate-900 text-amber-300 px-2.5 py-0.5 rounded-full font-extrabold">Pro v9.0</span>`;
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
    const config = { fps: 10, qrbox: { width: 200, height: 200 } };

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
        photo: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
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

    if (!capturedPhotoData) {
        alert('Silakan ambil foto selfie terlebih dahulu!');
        return;
    }

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
        photo: capturedPhotoData,
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
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-slate-400">Tidak ada data yang sesuai filter.</td></tr>`;
        return;
    }

    tbody.innerHTML = '';
    filtered.forEach(log => {
        const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(log.coords || log.location)}`;
        tbody.innerHTML += `
            <tr class="hover:bg-slate-50 transition">
                <td class="px-4 py-3 font-medium text-slate-700">${log.date}</td>
                <td class="px-4 py-3">
                    <img src="${log.photo}" class="w-10 h-10 object-cover rounded-lg border border-slate-200 shadow-sm" alt="Selfie">
                </td>
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
    if (!isAdminLoggedIn) {
        document.getElementById('adminUser').value = '';
        document.getElementById('adminPass').value = '';
        document.getElementById('loginModal').classList.remove('hidden');
        document.getElementById('adminUser').focus();
    } else {
        logoutAdmin();
    }
}

function handleAdminLogin(event) {
    event.preventDefault();
    const user = document.getElementById('adminUser').value.trim();
    const pass = document.getElementById('adminPass').value.trim();

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
        alert('Username atau Password Admin salah!\n\nGunakan:\nUsername: admin\nPassword: admin123');
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