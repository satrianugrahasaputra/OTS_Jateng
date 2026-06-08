<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Panel</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header h2 {
            font-size: 28px;
            font-weight: 600;
            color: #0f172a;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.4);
        }

        .btn-primary:hover {
            background: var(--primary-light);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            padding: 8px 16px;
            font-size: 12px;
        }

        /* Components */
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 16px 24px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        td {
            padding: 16px 24px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #1e293b;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            width: 100%;
            max-width: 450px;
            padding: 32px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 15px;
            outline: none;
            transition: all 0.2s;
        }

        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }

        .btn-ghost {
            background: #f1f5f9;
            color: #475569;
        }

        .status-pill {
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }

        /* Sync Cron Section */
        .sync-section { margin-top: 40px; }
        .sync-section h2 { font-size: 22px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
        .sync-section .subtitle { color: #64748b; font-size: 14px; margin-bottom: 20px; }

        .sync-card {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
            border-radius: 20px;
            padding: 32px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .sync-card::before {
            content: '';
            position: absolute; top: -50%; right: -30%; width: 300px; height: 300px;
            background: rgba(255,255,255,0.08); border-radius: 50%;
        }

        .sync-controls { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; position: relative; z-index: 1; flex-wrap: wrap; }
        .sync-controls select {
            padding: 12px 16px; border-radius: 12px; border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15); color: white; font-size: 14px; font-weight: 600;
            outline: none; cursor: pointer; backdrop-filter: blur(4px);
        }
        .sync-controls select option { background: #1e293b; color: white; }

        .btn-sync {
            padding: 14px 28px; border-radius: 14px; border: none;
            background: white; color: #6366f1; font-weight: 700; font-size: 15px;
            cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .btn-sync:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
        .btn-sync:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .btn-sync .spinner {
            width: 18px; height: 18px; border: 3px solid rgba(99,102,241,0.3);
            border-top-color: #6366f1; border-radius: 50%;
            animation: spin 0.8s linear infinite; display: none;
        }
        .btn-sync.loading .spinner { display: block; }
        .btn-sync.loading .btn-icon { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .sync-info { display: flex; gap: 24px; position: relative; z-index: 1; flex-wrap: wrap; }
        .sync-info-item { background: rgba(255,255,255,0.12); backdrop-filter: blur(8px); border-radius: 14px; padding: 16px 20px; flex: 1; min-width: 160px; }
        .sync-info-item .label { font-size: 12px; opacity: 0.8; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        .sync-info-item .value { font-size: 18px; font-weight: 700; }

        .sync-result {
            margin-top: 16px; background: white; border-radius: 16px; padding: 24px;
            color: #1e293b; display: none; position: relative; z-index: 1;
        }
        .sync-result.visible { display: block; animation: slideUp 0.4s ease-out; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .sync-result h4 { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
        .result-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
        .result-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 10px; background: #f8fafc; font-size: 13px; }
        .result-item .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .result-item .dot.ok { background: #22c55e; }
        .result-item .dot.err { background: #ef4444; }
        .result-item .name { font-weight: 600; flex: 1; }
        .result-item .dur { color: #94a3b8; font-size: 12px; }
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
                <a href="/admin/pdrb" class="nav-link">PDRB Sync</a>
            </li>
            <li class="nav-item">
                <a href="/admin/users" class="nav-link active">User Management</a>
            </li>
        </ul>
        <button class="logout-btn" onclick="logout()">
            Log Out
        </button>
    </div>

    <div class="main-content">
        <div class="header">
            <div>
                <h2>Manajemen User</h2>
                <p style="color: #64748b; font-size: 14px;">Kelola akun administrator yang memiliki akses ke panel ini.</p>
            </div>
            <button class="btn btn-primary" onclick="openModal()">
                <span>➕</span> Tambah Admin
            </button>
        </div>

        <div class="card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Status</th>
                            <th>Dibuat Pada</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user): ?>
                        <tr>
                            <td style="font-weight: 600;"><?= htmlspecialchars($user['username']) ?></td>
                            <td><span class="status-pill">Aktif</span></td>
                            <td><?= $user['created_at'] ?></td>
                            <td>
                                <button class="btn btn-danger" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sync Cron Section -->
        <div class="sync-section">
            <h2>⚡ Sinkronisasi Data (Sync Cron)</h2>
            <p class="subtitle">Tarik data terbaru dari BPS secara manual tanpa menunggu jadwal Task Scheduler.</p>

            <div class="sync-card">
                <div class="sync-controls">
                    <select id="syncYear">
                        <?php for($y = (int)date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <button class="btn-sync" id="btnRunSync" onclick="runSync()">
                        <span class="btn-icon">🚀</span>
                        <span class="spinner"></span>
                        <span class="btn-text">Sync Sekarang</span>
                    </button>
                </div>

                <div class="sync-info">
                    <div class="sync-info-item">
                        <div class="label">Status</div>
                        <div class="value" id="syncStatus">Menunggu</div>
                    </div>
                    <div class="sync-info-item">
                        <div class="label">Terakhir Dijalankan</div>
                        <div class="value" id="syncLastRun">-</div>
                    </div>
                    <div class="sync-info-item">
                        <div class="label">Durasi</div>
                        <div class="value" id="syncDuration">-</div>
                    </div>
                </div>

                <div class="sync-result" id="syncResult">
                    <h4>📋 Detail Hasil Sync</h4>
                    <div class="result-grid" id="syncResultGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Add User -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Tambah Admin Baru</h3>
            </div>
            <form id="addUserForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="admin_baru" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal()" style="flex:1">Batal</button>
                    <button type="submit" class="btn btn-primary" style="flex:2">Simpan User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }

        document.getElementById('addUserForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData);

            try {
                const res = await fetch('/api/admin/users', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                if (result.status === 'success') {
                    alert('User berhasil ditambahkan!');
                    window.location.reload();
                } else {
                    alert(result.message);
                }
            } catch (e) {
                alert('Terjadi kesalahan sistem.');
            }
        });

        async function deleteUser(id, username) {
            if (!confirm(`Hapus admin "${username}"?`)) return;

            try {
                const res = await fetch(`/api/admin/users/${id}`, { method: 'DELETE' });
                const result = await res.json();
                if (result.status === 'success') {
                    alert(result.message);
                    window.location.reload();
                } else {
                    alert(result.message);
                }
            } catch (e) {
                alert('Gagal menghapus user.');
            }
        }

        async function logout() {
            await fetch('/admin/logout');
            window.location.href = '/admin/login';
        }

        // === Sync Cron Functions ===
        async function runSync() {
            const btn = document.getElementById('btnRunSync');
            const year = document.getElementById('syncYear').value;
            const statusEl = document.getElementById('syncStatus');
            const lastRunEl = document.getElementById('syncLastRun');
            const durationEl = document.getElementById('syncDuration');
            const resultEl = document.getElementById('syncResult');
            const gridEl = document.getElementById('syncResultGrid');

            btn.classList.add('loading');
            btn.disabled = true;
            btn.querySelector('.btn-text').textContent = 'Sedang sync...';
            statusEl.textContent = '⏳ Proses...';
            durationEl.textContent = '...';
            resultEl.classList.remove('visible');

            const startTime = Date.now();

            try {
                const res = await fetch(`/api/admin/run-sync?year=${year}`);
                if (res.status === 401 || res.redirected) {
                    window.location.href = '/admin/login';
                    return;
                }
                const data = await res.json();
                const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);

                lastRunEl.textContent = data.triggered_at || new Date().toLocaleString('id-ID');
                durationEl.textContent = data.summary ? data.summary.duration : elapsed + 's';

                if (data.status === 'success') {
                    statusEl.textContent = '✅ Berhasil';
                } else if (data.status === 'partial') {
                    statusEl.textContent = '⚠️ Sebagian Gagal';
                } else {
                    statusEl.textContent = '❌ Gagal';
                }

                // Render details
                if (data.details) {
                    gridEl.innerHTML = '';
                    for (const [name, info] of Object.entries(data.details)) {
                        const isOk = info.status === 'success';
                        gridEl.innerHTML += `<div class="result-item">
                            <span class="dot ${isOk ? 'ok' : 'err'}"></span>
                            <span class="name">${name}</span>
                            <span class="dur">${info.duration || '-'}</span>
                        </div>`;
                    }
                    resultEl.classList.add('visible');
                }
            } catch (err) {
                statusEl.textContent = '❌ Error';
                durationEl.textContent = '-';
                alert('Gagal menjalankan sync: ' + err.message);
            } finally {
                btn.classList.remove('loading');
                btn.disabled = false;
                btn.querySelector('.btn-text').textContent = 'Sync Sekarang';
            }
        }
    </script>
</body>

</html>
