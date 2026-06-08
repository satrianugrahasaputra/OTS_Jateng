<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - OTS Premium Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.5);
            --bg-dark: #0f172a;
            --glass: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            height: 100vh;
            background-color: var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* Animated Background Gradient */
        .bg-gradient {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 30%, #4338ca 0%, transparent 40%),
                        radial-gradient(circle at 80% 70%, #db2777 0%, transparent 40%),
                        radial-gradient(circle at 50% 50%, #1e1b4b 0%, #0f172a 100%);
            z-index: 1;
        }

        /* Moving Blobs */
        .blob {
            position: absolute;
            width: 500px;
            height: 500px;
            background: var(--primary);
            filter: blur(120px);
            opacity: 0.2;
            border-radius: 50%;
            z-index: 2;
            animation: float 20s infinite alternate;
        }

        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(100px, 50px) scale(1.2); }
        }

        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .login-card {
            background: var(--glass);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            border-radius: 32px;
            padding: 48px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header h1 {
            color: white;
            font-size: 32px;
            font-weight: 600;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }

        .login-header p {
            color: #94a3b8;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            color: #e2e8f0;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
            margin-left: 4px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 14px 20px;
            color: white;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
        }

        .form-group input:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-glow);
        }

        .btn-login {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login:hover {
            background: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px var(--primary-glow);
        }

        .btn-login:active { transform: translateY(0); }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .error-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 14px;
            border-radius: 14px;
            font-size: 13px;
            margin-bottom: 24px;
            display: none;
            text-align: center;
        }

        .footer-text {
            text-align: center;
            margin-top: 32px;
            color: #475569;
            font-size: 12px;
            font-weight: 500;
        }

        /* Sparkle animation */
        .sparkle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: white;
            border-radius: 50%;
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>

<body>
    <div class="bg-gradient"></div>
    <div class="blob"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>Selamat Datang</h1>
                <p>Silakan masuk ke Panel Admin OTS</p>
            </div>

            <div id="errorBox" class="error-box"></div>

            <form id="loginForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Masukkan username" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-login" id="submitBtn">
                    <span>Masuk ke Panel</span>
                    <span id="spinner" style="display: none;">⏳</span>
                </button>
            </form>

            <div class="footer-text">
                &copy; 2026 OTS Jateng • Secured and Restricted
            </div>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const errorBox = document.getElementById('errorBox');
        const submitBtn = document.getElementById('submitBtn');
        const spinner = document.getElementById('spinner');

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            errorBox.style.display = 'none';
            submitBtn.disabled = true;
            spinner.style.display = 'inline-block';

            const formData = new FormData(loginForm);
            const data = Object.fromEntries(formData);

            try {
                const response = await fetch('/admin/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (response.ok && result.status === 'success') {
                    window.location.href = '/admin/dashboard';
                } else {
                    errorBox.textContent = result.message || 'Username atau password salah.';
                    errorBox.style.display = 'block';
                    submitBtn.disabled = false;
                    spinner.style.display = 'none';
                }
            } catch (error) {
                errorBox.textContent = 'Terjadi kesalahan sistem. Coba lagi nanti.';
                errorBox.style.display = 'block';
                submitBtn.disabled = false;
                spinner.style.display = 'none';
            }
        });
    </script>
</body>

</html>