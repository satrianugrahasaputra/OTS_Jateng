<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Dashboard - OTS Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --bg-dark: #0f172a;
            --sidebar-bg: #1e293b;
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-secondary: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Premium Dark */
        .sidebar {
            width: 280px;
            background: var(--bg-dark);
            height: 100vh;
            position: fixed;
            padding: 32px 24px;
            display: flex;
            flex-direction: column;
            z-index: 100;
            border-right: 1px solid rgba(255, 255, 255, 0.05);
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin-bottom: 48px;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.02em;
        }

        .logo span {
            background: linear-gradient(135deg, #6366f1, #a855f7);
            padding: 8px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-menu {
            list-style: none;
            flex: 1;
        }

        .nav-item {
            margin-bottom: 12px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            text-decoration: none;
            color: #94a3b8;
            border-radius: 16px;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 15px;
        }

        .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);
        }

        .logout-btn {
            margin-top: auto;
            width: 100%;
            padding: 14px;
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .logout-btn:hover {
            background: #ef4444;
            color: white;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 48px;
        }

        .header {
            margin-bottom: 40px;
        }

        .header h2 {
            font-size: 32px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        .header p {
            color: var(--text-secondary);
            margin-top: 8px;
            font-size: 15px;
        }

        /* Modern Controls */
        .controls-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            padding: 24px 32px;
            border-radius: 24px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04);
            margin-bottom: 32px;
            display: flex;
            gap: 20px;
            align-items: flex-end;
            border: 1px solid #f1f5f9;
            position: sticky;
            top: 24px;
            z-index: 90;
        }

        .form-control {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .form-control label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-left: 4px;
        }

        .form-control select,
        .form-control input {
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
            width: 100%;
        }

        .form-control select:focus,
        .form-control input:focus {
            background: white;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .btn {
            padding: 14px 28px;
            border-radius: 14px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 15px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(99, 102, 241, 0.3);
        }

        .btn-success {
            background: #10b981;
            color: white;
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
        }

        /* Table Area */
        .table-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #f1f5f9;
            overflow: hidden;
        }

        .table-header {
            padding: 24px 32px;
            background: #f8fafc;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-scroll {
            overflow-x: auto;
            max-height: 550px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 18px 24px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: sticky;
            top: 0;
            border-bottom: 1px solid #f1f5f9;
        }

        td {
            padding: 14px 24px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-api {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .badge-manual {
            background: #fffbeb;
            color: #b45309;
        }

        .empty-state {
            padding: 100px;
            text-align: center;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 54px;
            display: block;
            margin-bottom: 24px;
        }

        /* Transitions */
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="logo">
            <span>🚀</span> OTS Admin
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="/admin/dashboard" class="nav-link active">
                    Dashboard Sync
                </a>
            </li>
            <li class="nav-item">
                <a href="/admin/pdrb" class="nav-link">
                    PDRB Sync
                </a>
            </li>
            <li class="nav-item">
                <a href="/admin/users" class="nav-link">
                    User Management
                </a>
            </li>
        </ul>
        <button class="logout-btn" onclick="logout()">
            Log Out
        </button>
    </div>

    <div class="main-content fade-in">
        <div class="header">
            <h2>Sinkronisasi Data</h2>
            <p>Kelola data dari BPS ke database lokal dengan kontrol penuh.</p>
        </div>

        <div class="controls-card">
            <div class="form-control">
                <label>Pilih Indikator</label>
                <select id="tableSelect">
                    <option value="">-- Pilih Tabel --</option>
                    <option value="ots_inflasi">Inflasi (ots_inflasi)</option>
                    <option value="t_ntp">NTP (t_ntp)</option>
                    <option value="ots_ekspor">Ekspor (ots_ekspor)</option>
                    <option value="ots_impor">Impor (ots_impor)</option>
                    <option value="t_neraca">Neraca Perdagangan (t_neraca)</option>
                    <option value="t_ipm_prov">IPM Provinsi (t_ipm_prov)</option>
                    <option value="t_ipm_kab">IPM Kab/Kota (t_ipm_kab)</option>
                    <option value="t_miskin_prov">Kemiskinan Prov (t_miskin_prov)</option>
                    <option value="t_miskin_kab">Kemiskinan Kab (t_miskin_kab)</option>
                    <option value="t_giniratio">Gini Ratio (t_giniratio)</option>
                    <option value="t_naker_prov">Naker Prov (t_naker_prov)</option>
                    <option value="t_naker_kab">Naker Kab (t_naker_kab)</option>
                    <option value="t_pdrb_prov">PDRB Provinsi (t_pdrb_prov)</option>
                    <option value="t_pdrb_kab">PDRB Kab/Kota (t_pdrb_kab)</option>
                    <option value="ots_ind_rpjpn">Indikator RPJPN (ots_ind_rpjpn)</option>
                    <option value="ots_skd">SKD (ots_skd)</option>
                    <option value="ots_skd_tahunan">SKD Tahunan (ots_skd_tahunan)</option>
                    <option value="t_ekspor">Ekspor Lama (t_ekspor)</option>
                    <option value="t_impor">Impor Lama (t_impor)</option>
                    <option value="t_inflasi">Inflasi Lama (t_inflasi)</option>
                    <option value="t_pdrb_tahunan">PDRB Tahunan (t_pdrb_tahunan)</option>
                    <option value="tabel_webapi">Web API (tabel_webapi)</option>
                </select>
            </div>
            <div class="form-control" style="max-width: 150px;">
                <label>Tahun</label>
                <input type="number" id="yearInput" value="<?php echo date('Y'); ?>" min="2019" max="<?php echo (int) date('Y') + 1; ?>">
            </div>
            <button class="btn btn-primary" onclick="fetchPreview()" id="fetchBtn">
                Ambil Data
            </button>
            <button class="btn btn-success" id="saveBtn" onclick="saveData()" style="display:none">
                Simpan Perubahan
            </button>
        </div>

        <div id="statusMsg"
            style="display:none; margin-bottom: 24px; padding: 16px; border-radius: 16px; font-weight: 600; font-size: 14px;">
        </div>

        <div class="table-card">
            <div id="tableContainer">
                <div class="empty-state">
                    <i>📋</i>
                    <p>Silakan pilih indikator dan tahun untuk melihat pratinjau data sinkronisasi.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const MANUAL_COLS = ['tanda', 'sign', 'poin', 'sebelumnya', 'month_before'];
        let currentData = [];
        let currentTable = '';

        async function fetchPreview() {
            const table = document.getElementById('tableSelect').value;
            const year = document.getElementById('yearInput').value;
            if (!table) return alert('Pilih indikator terlebih dahulu!');

            const btn = document.getElementById('fetchBtn');
            btn.disabled = true;
            btn.innerText = 'Memuat...';

            try {
                const res = await fetch(`/api/preview-sync?table=${table}&year=${year}`);

                if (res.status === 401 || res.redirected) {
                    window.location.href = '/admin/login';
                    return;
                }

                const result = await res.json();

                if (result.status === 'success') {
                    currentData = result.data;
                    currentTable = table;
                    renderTable(result.columns, result.data);
                    document.getElementById('saveBtn').style.display = 'flex';
                    showStatus('success', 'Data berhasil ditarik dari ' + result.source);
                } else {
                    showStatus('error', result.message);
                }
            } catch (e) {
                showStatus('error', 'Gagal terhubung ke server API.');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Ambil Data';
            }
        }

        const WIDE_COLS = ['nama', 'label', 'wilayah', 'kecamatan', 'kabupaten', 'indikator', 'keterangan', 'sebelumnya', 'month_before', 'bulan', 'month'];

        function renderTable(cols, data) {
            // Calculate optimal width per column based on longest content
            // Overhead: td padding (24*2=48) + input padding (14*2=28) + border (4) = 80px
            const colWidths = {};
            cols.forEach(c => {
                let maxLen = c.length; // start with header length
                data.forEach(row => {
                    const val = row[c] === null ? '' : String(row[c]);
                    if (val.length > maxLen) maxLen = val.length;
                });
                colWidths[c] = Math.min(400, Math.max(80, maxLen * 10 + 80));
            });

            let html = '<div class="table-scroll"><table><thead><tr>';
            cols.forEach(c => {
                const isManual = MANUAL_COLS.includes(c);
                html += `<th class="${isManual ? 'col-manual' : ''}" style="min-width: ${colWidths[c]}px; white-space: nowrap;">${c}</th>`;
            });
            html += '</tr></thead><tbody>';

            data.forEach((row, rowIndex) => {
                html += '<tr>';
                cols.forEach(col => {
                    const isManual = MANUAL_COLS.includes(col);
                    const val = row[col] === null ? '' : row[col];
                    const disabled = col === 'id' ? 'disabled' : '';
                    html += `<td><input type="text" style="width:100%; padding:10px 14px; border:1px solid #e2e8f0; border-radius:10px; background:${isManual ? '#fffbeb' : '#fff'};" 
                                value="${val}" ${disabled} 
                                onchange="updateLocalData(${rowIndex}, '${col}', this.value)"></td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('tableContainer').innerHTML = html;
        }

        function updateLocalData(idx, col, val) {
            currentData[idx][col] = val;
        }

        async function saveData() {
            if (!confirm('Simpan data ke sistem?')) return;

            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';

            try {
                const res = await fetch('/api/save-sync', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        table: currentTable,
                        year: document.getElementById('yearInput').value,
                        data: currentData
                    })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    showStatus('success', '✅ ' + result.message);
                } else {
                    showStatus('error', '❌ ' + result.message);
                }
            } catch (e) {
                showStatus('error', 'Terjadi gangguan koneksi.');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Simpan Perubahan';
            }
        }

        function showStatus(type, msg) {
            const bar = document.getElementById('statusMsg');
            bar.style.display = 'block';
            bar.innerText = msg;
            if (type === 'success') {
                bar.style.background = '#ecfdf5';
                bar.style.color = '#065f46';
                bar.style.border = '1px solid #a7f3d0';
            } else {
                bar.style.background = '#fef2f2';
                bar.style.color = '#991b1b';
                bar.style.border = '1px solid #fecaca';
            }
            setTimeout(() => bar.style.display = 'none', 5000);
        }

        async function logout() {
            await fetch('/admin/logout');
            window.location.href = '/admin/login';
        }
    </script>
</body>

</html>
