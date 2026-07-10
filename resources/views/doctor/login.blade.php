<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Staff — Aura Salud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script>
        // Apply saved theme before first paint to avoid a flash
        (function () {
            try {
                if ((localStorage.getItem('aura_portal_theme') || 'dark') === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch (e) {}
        })();
    </script>
    <style>
        :root {
            --bg: #0B0F19;
            --surface: rgba(255, 255, 255, 0.04);
            --border: rgba(255, 255, 255, 0.08);
            --border-strong: rgba(255, 255, 255, 0.1);
            --input-bg: rgba(255, 255, 255, 0.05);
            --text-primary: #E2E8F0;
            --text-input: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --accent: #0D9488;
            --accent-2: #14B8A6;
            --accent-light: #2DD4BF;
            --on-accent: #FFFFFF;
            --shadow: rgba(0, 0, 0, 0.5);
            --accent-glow: rgba(13, 148, 136, 0.35);
            --danger-bg: rgba(220, 38, 38, 0.12);
            --danger-border: rgba(220, 38, 38, 0.3);
            --danger-text: #FCA5A5;
        }
        :root[data-theme="light"] {
            --bg: #EEF2F6;
            --surface: #FFFFFF;
            --border: #E2E8F0;
            --border-strong: #CBD5E1;
            --input-bg: #FFFFFF;
            --text-primary: #0F172A;
            --text-input: #0F172A;
            --text-secondary: #475569;
            --text-muted: #94A3B8;
            --accent-light: #0F766E;
            --shadow: rgba(15, 23, 42, 0.12);
            --accent-glow: rgba(13, 148, 136, 0.25);
            --danger-bg: rgba(220, 38, 38, 0.08);
            --danger-border: rgba(220, 38, 38, 0.25);
            --danger-text: #DC2626;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            transition: background 0.3s ease, color 0.3s ease;
        }
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-primary);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .theme-toggle:hover { border-color: var(--accent); }
        .card {
            width: 100%;
            max-width: 380px;
            margin: 16px;
            padding: 40px 32px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 24px 60px var(--shadow);
        }
        .logo {
            width: 64px; height: 64px;
            margin: 0 auto 20px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            box-shadow: 0 8px 24px var(--accent-glow);
        }
        h1 { font-size: 20px; font-weight: 800; text-align: center; letter-spacing: -0.4px; color: var(--text-primary); }
        p.sub { font-size: 12px; color: var(--text-muted); text-align: center; margin: 6px 0 28px; }
        label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px; }
        input[type="password"], input[type="email"] {
            width: 100%;
            padding: 13px 16px;
            border-radius: 14px;
            border: 1px solid var(--border-strong);
            background: var(--input-bg);
            color: var(--text-input);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input[type="password"]:focus, input[type="email"]:focus { border-color: var(--accent-light); }
        .field { margin-bottom: 18px; }
        .error {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            font-size: 12px;
        }
        button.submit {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: var(--on-accent);
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        button.submit:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <button type="button" class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Cambiar tema">☀️</button>
    <div class="card">
        <div class="logo">🩺</div>
        <h1>Portal Clínico Aura</h1>
        <p class="sub">Acceso exclusivo para personal de salud autorizado</p>
        <form method="POST" action="/doctor/login">
            @csrf
            <div class="field">
                <label for="email">Correo electrónico</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" autofocus required autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            @error('email')
                <div class="error">{{ $message }}</div>
            @enderror
            <button type="submit" class="submit">Ingresar al panel</button>
        </form>
    </div>
    <script>
        function updateThemeIcon() {
            var light = document.documentElement.getAttribute('data-theme') === 'light';
            document.getElementById('themeToggle').textContent = light ? '🌙' : '☀️';
        }
        function toggleTheme() {
            var root = document.documentElement;
            if (root.getAttribute('data-theme') === 'light') {
                root.removeAttribute('data-theme');
                try { localStorage.setItem('aura_portal_theme', 'dark'); } catch (e) {}
            } else {
                root.setAttribute('data-theme', 'light');
                try { localStorage.setItem('aura_portal_theme', 'light'); } catch (e) {}
            }
            updateThemeIcon();
        }
        updateThemeIcon();
    </script>
</body>
</html>
