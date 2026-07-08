<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Staff — Aura Salud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0B0F19;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #E2E8F0;
        }
        .card {
            width: 100%;
            max-width: 380px;
            margin: 16px;
            padding: 40px 32px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
        }
        .logo {
            width: 64px; height: 64px;
            margin: 0 auto 20px;
            border-radius: 18px;
            background: linear-gradient(135deg, #0D9488, #2DD4BF);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            box-shadow: 0 8px 24px rgba(13, 148, 136, 0.35);
        }
        h1 { font-size: 20px; font-weight: 800; text-align: center; letter-spacing: -0.4px; }
        p.sub { font-size: 12px; color: #64748B; text-align: center; margin: 6px 0 28px; }
        label { display: block; font-size: 10px; font-weight: 700; letter-spacing: 1px; color: #94A3B8; text-transform: uppercase; margin-bottom: 8px; }
        input[type="password"], input[type="email"] {
            width: 100%;
            padding: 13px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #F1F5F9;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        input[type="password"]:focus, input[type="email"]:focus { border-color: #2DD4BF; }
        .field { margin-bottom: 18px; }
        .error {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            background: rgba(220, 38, 38, 0.12);
            border: 1px solid rgba(220, 38, 38, 0.3);
            color: #FCA5A5;
            font-size: 12px;
        }
        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #0D9488, #14B8A6);
            color: white;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        button:hover { opacity: 0.9; }
    </style>
</head>
<body>
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
            <button type="submit">Ingresar al panel</button>
        </form>
    </div>
</body>
</html>
