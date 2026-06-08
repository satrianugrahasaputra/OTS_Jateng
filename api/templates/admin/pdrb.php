<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDRB Synchronization - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --secondary: #ec4899;
            --bg-dark: #0f172a;
            --bg-page: #f8fafc;
            --sidebar-width: 280px;
            --glass-bg: rgba(255, 255, 255, 0.8);
            --glass-border: rgba(255, 255, 255, 0.4);
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-page);
            color: #1e293b;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Premium Dark */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-dark);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            height: 100vh;
            position: fixed;
            padding: 32px 24px;
            display: flex;
            flex-direction: column;
            z-index: 100;
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
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 40px;
        }

        .header {
            margin-bottom: 32px;
        }

        .header h2 {
            font-size: 28px;
            font-weight: 600;
            color: #0f172a;
        }

        /* PDRB Specific Cards */
        .sync-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .sync-card {
            background: white;
            padding: 32px;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            border: 1px solid #f1f5f9;
            display: flex;
            flex-direction: column;
            gap: 20px;
            transition: all 0.3s;
        }

        .sync-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .sync-card h3 {
            font-size: 18px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sync-card p {
            font-size: 14px;
            color: #64748b;
            line-height: 1.6;
        }

        .btn {
            padding: 14px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .form-select {
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            outline: none;
            width: 100%;
            margin-top: 10px;
        }

        .status-indicator {
            padding: 16px;
            border-radius: 12px;
            margin-top: 24px;
            font-size: 13px;
            display: none;
        }

        .status-loading { background: #eff6ff; color: #1e40af; }
        .status-done { background: #f0fdf4; color: #166534; }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="logo">
            <span>✨</span> OTS Admin
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="/admin/dashboard" class="nav-link">Dashboard Sync</a>
            </li>
            <li class="nav-item">
                <a href="/admin/pdrb" class="nav-link active">PDRB Sync</a>
            </li>
            <li class="nav-item">
                <a href="/admin/users" class="nav-link">User Management</a>
            </li>
        </ul>
        <button class="logout-btn" onclick="logout()">
            Log Out
        </button>
    </div>

    <div class="main-content">
        <div class="header">
            <h2>Sinkronisasi PDRB</h2>
            <p style="color: #64748b; font-size: 14px; margin-top: 5px;">Halaman khusus untuk update data PDRB Provinsi & Kabupaten/Kota (ADHK/ADHB).</p>
        </div>

        <div class="sync-grid">
            <!-- PDRB Provinsi -->
            <div class="sync-card">
                <h3><span>📊</span> PDRB Provinsi</h3>
                <p>Sinkronkan data PDRB level Provinsi Jawa Tengah. Mencakup data lapangan usaha dan pengeluaran.</p>
                <div>
                    <label style="font-size: 12px; font-weight: 600; color: #475569;">Pilih Tahun</label>
                    <select class="form-select" id="yearProv">
                        <?php for($i=date('Y'); $i>=2020; $i--) echo "<option value='$i'>$i</option>"; ?>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="runSync('prov')">Mulai Sinkronisasi Sektoral</button>
                <div id="statusProv" class="status-indicator"></div>
            </div>

            <!-- PDRB Kabupaten/Kota -->
            <div class="sync-card">
                <h3><span>🏙️</span> PDRB Kab/Kota</h3>
                <p>Sinkronkan data PDRB untuk seluruh 35 Kabupaten/Kota di Jawa Tengah secara massal.</p>
                <div>
                    <label style="font-size: 12px; font-weight: 600; color: #475569;">Pilih Tahun</label>
                    <select class="form-select" id="yearKab">
                        <?php for($i=date('Y'); $i>=2020; $i--) echo "<option value='$i'>$i</option>"; ?>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="runSync('kab')">Mulai Sinkronisasi Wilayah</button>
                <div id="statusKab" class="status-indicator"></div>
            </div>
        </div>

        <div class="sync-card" style="max-width: 600px;">
            <h3><span>⚠️</span> Catatan Penting</h3>
            <p style="font-size: 13px;">Sinkronisasi PDRB membutuhkan waktu lebih lama dibandingkan data lainnya karena volume data yang besar. Pastikan koneksi internet stabil saat proses berjalan.</p>
        </div>
    </div>

    <script>
        async function runSync(type) {
            const statusDiv = document.getElementById(type === 'prov' ? 'statusProv' : 'statusKab');
            const year = document.getElementById(type === 'prov' ? 'yearProv' : 'yearKab').value;
            const endpoint = type === 'prov' ? '/api/preview-sync?table=t_pdrb_prov' : '/api/preview-sync?table=t_pdrb_kab';

            statusDiv.style.display = 'block';
            statusDiv.className = 'status-indicator status-loading';
            statusDiv.innerHTML = `⏳ Sedang mengambil preview data tahun ${year}...`;

            try {
                const res = await fetch(`${endpoint}&year=${year}`);
                const result = await res.json();
                
                if (result.status === 'success') {
                    statusDiv.innerHTML = `✅ Data ${year} ditemukan (${result.data.length} baris). Memulai penyimpanan...`;
                    
                    // Run Save
                    const saveRes = await fetch('/api/save-sync', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            table: type === 'prov' ? 't_pdrb_prov' : 't_pdrb_kab',
                            year: year,
                            data: result.data
                        })
                    });
                    
                    const saveResult = await saveRes.json();
                    if (saveResult.status === 'success') {
                        statusDiv.className = 'status-indicator status-done';
                        statusDiv.innerHTML = `🎉 Sinkronisasi PDRB ${type.toUpperCase()} Tahun ${year} Berhasil!`;
                    } else {
                        throw new Error(saveResult.message);
                    }
                } else {
                    throw new Error(result.message);
                }
            } catch (e) {
                statusDiv.className = 'status-indicator';
                statusDiv.style.background = '#fef2f2';
                statusDiv.style.color = '#991b1b';
                statusDiv.innerHTML = `❌ Error: ${e.message}`;
            }
        }

        async function logout() {
            await fetch('/admin/logout');
            window.location.href = '/admin/login';
        }
    </script>
</body>

</html>
