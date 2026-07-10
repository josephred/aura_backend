<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda — AURA Salud</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        (function () {
            try {
                if ((localStorage.getItem('aura_portal_theme') || 'dark') === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch (e) {}
        })();
    </script>
    <style>
        /* CSS Reset & Variables */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        :root {
            --bg-color: #0B0F19;
            --primary-glow: rgba(13, 148, 136, 0.12);
            --secondary-glow: rgba(124, 58, 237, 0.12);
            --header-bg: rgba(11, 15, 25, 0.7);
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.06);
            --card-border-hover: rgba(13, 148, 136, 0.4);
            --surface-raised: rgba(255, 255, 255, 0.04);
            --surface-sunken: rgba(255, 255, 255, 0.015);
            --surface-hover: rgba(255, 255, 255, 0.06);
            --input-bg: rgba(255, 255, 255, 0.04);
            --chip-bg: rgba(255, 255, 255, 0.03);
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --heading-grad-a: #FFFFFF;
            --heading-grad-b: #CBD5E1;
            --accent-teal: #0D9488;
            --accent-teal-glow: #0F766E;
            --accent-teal-light: #2DD4BF;
            --on-accent: #FFFFFF;
            --status-confirmed: #14B8A6;
            --status-pending: #F59E0B;
            --status-completed: #10B981;
            --status-cancelled: #EF4444;
            --type-video-text: #C084FC;
        }

        :root[data-theme="light"] {
            --bg-color: #EEF2F6;
            --primary-glow: rgba(13, 148, 136, 0.10);
            --secondary-glow: rgba(124, 58, 237, 0.07);
            --header-bg: rgba(255, 255, 255, 0.85);
            --card-bg: #FFFFFF;
            --card-border: #E2E8F0;
            --card-border-hover: rgba(13, 148, 136, 0.45);
            --surface-raised: #F1F5F9;
            --surface-sunken: #F8FAFC;
            --surface-hover: #F1F5F9;
            --input-bg: #FFFFFF;
            --chip-bg: #F8FAFC;
            --text-primary: #0F172A;
            --text-secondary: #64748B;
            --heading-grad-a: #0F172A;
            --heading-grad-b: #334155;
            --accent-teal-light: #0F766E;
            --status-confirmed: #0D9488;
            --status-completed: #059669;
            --type-video-text: #6D28D9;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Glowing Background Blobs */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            z-index: 0;
            pointer-events: none;
            opacity: 0.6;
        }

        .blob-1 {
            top: -10%;
            left: 10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%);
            animation: pulse-glow 8s infinite alternate;
        }

        .blob-2 {
            bottom: 10%;
            right: 15%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, var(--secondary-glow) 0%, transparent 70%);
            animation: pulse-glow 10s infinite alternate-reverse;
        }

        @keyframes pulse-glow {
            0% { transform: scale(1) translate(0, 0); opacity: 0.4; }
            100% { transform: scale(1.15) translate(20px, -20px); opacity: 0.7; }
        }

        /* Header matching main dashboard */
        header {
            height: 80px;
            border-bottom: 1px solid var(--card-border);
            backdrop-filter: blur(20px);
            background-color: var(--header-bg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            z-index: 10;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-teal-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 20px rgba(13, 148, 136, 0.4);
        }

        .logo-icon svg {
            fill: var(--on-accent);
            width: 22px;
            height: 22px;
        }

        .logo-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--heading-grad-a), var(--heading-grad-b));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-badge {
            font-size: 10px;
            font-weight: 700;
            background-color: rgba(13, 148, 136, 0.15);
            color: var(--accent-teal-light);
            border: 1px solid rgba(13, 148, 136, 0.3);
            padding: 3px 8px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doctor-profile {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .profile-info {
            text-align: right;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .profile-role {
            font-size: 11px;
            color: var(--text-secondary);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--accent-teal);
            border: 2px solid var(--accent-teal-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--on-accent);
        }

        .btn-back {
            color: var(--accent-teal-light);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s ease;
        }

        .btn-back:hover {
            color: var(--text-primary);
        }

        /* Layout Main Workspace */
        main {
            flex: 1;
            max-width: 100%;
            width: 100%;
            margin: 0 auto;
            padding: 40px 24px 80px;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 36px;
        }

        h2 {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Section Container */
        .section-block {
            display: flex;
            flex-direction: column;
        }

        /* Cards Grid for Appointments */
        .appointments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
            margin-top: 16px;
        }

        .appointment-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            backdrop-filter: blur(16px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .appointment-card:hover {
            border-color: var(--card-border-hover);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(13, 148, 136, 0.1);
        }

        .appointment-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background-color: transparent;
        }

        .appointment-card.confirmed::before { background-color: var(--status-confirmed); }
        .appointment-card.pending_payment::before { background-color: var(--status-pending); }
        .appointment-card.completed::before { background-color: var(--status-completed); }
        .appointment-card.cancelled::before, .appointment-card.no_show::before { background-color: var(--status-cancelled); }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .appointment-type {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .appointment-type.type-video {
            background-color: rgba(124, 58, 237, 0.12);
            color: var(--type-video-text);
            border: 1px solid rgba(124, 58, 237, 0.3);
        }

        .appointment-type.type-in-person {
            background-color: rgba(13, 148, 136, 0.12);
            color: var(--accent-teal-light);
            border: 1px solid rgba(13, 148, 136, 0.3);
        }

        .status {
            font-size: 9px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status.confirmed { background-color: rgba(20, 184, 166, 0.12); color: var(--status-confirmed); }
        .status.pending_payment { background-color: rgba(245, 158, 11, 0.12); color: var(--status-pending); }
        .status.completed { background-color: rgba(16, 185, 129, 0.12); color: var(--status-completed); }
        .status.cancelled, .status.no_show { background-color: rgba(239, 68, 68, 0.12); color: var(--status-cancelled); }

        .card-body {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .appointment-time {
            font-size: 13px;
            color: var(--accent-teal-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .patient-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 4px;
        }

        .patient-icon {
            font-size: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--surface-raised);
            border: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .patient-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .professional-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .reason-box {
            background-color: var(--surface-sunken);
            border-left: 3px solid var(--accent-teal);
            padding: 10px 14px;
            border-radius: 0 12px 12px 0;
            font-size: 13px;
            color: var(--text-secondary);
            font-style: italic;
            line-height: 1.4;
        }

        .card-footer {
            border-top: 1px solid var(--card-border);
            padding-top: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .price-info {
            display: flex;
            flex-direction: column;
        }

        .price-label {
            font-size: 9px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .price-value {
            font-size: 16px;
            font-weight: 800;
            color: var(--status-completed);
        }

        /* Buttons Styling */
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid transparent;
            outline: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-teal-light));
            color: var(--on-accent);
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--status-completed);
            border-color: rgba(16, 185, 129, 0.25);
        }

        .btn-success:hover {
            background-color: var(--status-completed);
            color: white;
        }

        .btn-danger {
            background-color: rgba(239, 68, 68, 0.08);
            color: var(--status-cancelled);
            border-color: rgba(239, 68, 68, 0.25);
        }

        .btn-danger:hover {
            background-color: var(--status-cancelled);
            color: white;
        }

        .btn-warning {
            background-color: rgba(245, 158, 11, 0.08);
            color: var(--status-pending);
            border-color: rgba(245, 158, 11, 0.25);
        }

        .btn-warning:hover {
            background-color: var(--status-pending);
            color: white;
        }

        .btn-purple {
            background: linear-gradient(135deg, #7C3AED, #9333EA);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
            animation: pulse-purple 2s infinite;
        }

        .btn-purple:hover {
            opacity: 0.9;
        }

        @keyframes pulse-purple {
            0% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(124, 58, 237, 0); }
            100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); }
        }

        .empty-state {
            grid-column: 1 / -1;
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 50px 30px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            color: var(--text-secondary);
            backdrop-filter: blur(16px);
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            stroke: var(--text-secondary);
            opacity: 0.35;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* Schedule Controls Card */
        .schedule-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 24px;
            backdrop-filter: blur(16px);
            margin-top: 12px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .controls {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 180px;
        }

        .control-group label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            text-transform: uppercase;
        }

        select, input[type="time"], input[type="email"] {
            background-color: var(--input-bg);
            color: var(--text-primary);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
            transition: all 0.25s ease;
            width: 100%;
        }

        select:focus, input[type="time"]:focus, input[type="email"]:focus {
            border-color: var(--accent-teal);
            box-shadow: 0 0 8px rgba(13, 148, 136, 0.2);
        }

        /* Schedule Chips */
        .schedule-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background-color: var(--chip-bg);
            border: 1px solid var(--card-border);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            transition: all 0.2s ease;
        }

        .chip:hover {
            border-color: var(--accent-teal);
            background-color: rgba(13, 148, 136, 0.05);
        }

        .chip button {
            background: none;
            border: none;
            color: var(--status-cancelled);
            cursor: pointer;
            font-size: 15px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s ease;
        }

        .chip button:hover {
            transform: scale(1.25);
        }

        /* Accounts administration list (for admin) */
        .table-container {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            overflow: hidden;
            backdrop-filter: blur(16px);
            margin-top: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 16px 20px;
            font-size: 14px;
            border-bottom: 1px solid var(--card-border);
        }

        th {
            background-color: var(--surface-sunken);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: var(--surface-hover);
        }

        .admin-star {
            color: var(--status-pending);
            font-weight: 700;
        }

        /* Theme toggle */
        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--surface-raised);
            color: var(--text-primary);
            font-size: 17px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .theme-toggle:hover { border-color: var(--accent-teal); }
    </style>
</head>
<body>

    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <header>
        <div class="logo-section">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm6 12H6v-1c0-2 4-3.1 6-3.1s6 1.1 6 3.1v1z"/>
                </svg>
            </div>
            <div class="logo-title">AURA Salud</div>
            <div class="logo-badge">Portal Médico</div>
        </div>
        <div class="doctor-profile">
            <button type="button" class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Cambiar tema">☀️</button>
            <a href="/doctor" class="btn-back">← Volver al panel</a>
            <div class="profile-info">
                <div class="profile-name">{{ $staffName }}</div>
                <div class="profile-role">{{ $staffRole === 'admin' ? 'Administración' : 'Profesional clínico' }}</div>
            </div>
            <div class="profile-avatar">
                {{ strtoupper(mb_substr($staffName, 0, 1)) }}{{ strtoupper(mb_substr(strrchr($staffName, ' ') ?: ' A', 1, 1)) }}
            </div>
            <form method="POST" action="/doctor/logout" style="margin-left:14px;">
                @csrf
                <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size:12px;">Salir</button>
            </form>
        </div>
    </header>

    <main>
        <!-- Section Citas -->
        <div class="section-block">
            <h2>🗓️ Próximas Citas Agendadas</h2>
            <div class="appointments-grid" id="appointments">
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p>Cargando citas…</p>
                </div>
            </div>
        </div>

        <!-- Section Horarios -->
        <div class="section-block">
            <h2>📅 Horarios de Atención Semanal</h2>
            <div class="schedule-card">
                <div class="controls">
                    <div class="control-group">
                        <label for="professional">Profesional Médico</label>
                        <select id="professional"></select>
                    </div>
                    <div class="control-group">
                        <label for="day">Día de la semana</label>
                        <select id="day">
                            <option value="1">Lunes</option>
                            <option value="2">Martes</option>
                            <option value="3">Miércoles</option>
                            <option value="4">Jueves</option>
                            <option value="5">Viernes</option>
                            <option value="6">Sábado</option>
                            <option value="7">Domingo</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label for="start">Hora Inicio</label>
                        <input type="time" id="start" value="09:00">
                    </div>
                    <div class="control-group">
                        <label for="end">Hora Fin</label>
                        <input type="time" id="end" value="13:00">
                    </div>
                    <button class="btn btn-primary" style="height: 40px; min-width: 140px;" onclick="addBlock()">Agregar bloque</button>
                </div>
                <div id="schedule" class="schedule-chips">
                    <span class="empty" style="color:var(--text-secondary);font-size:13px;">Cargando horarios...</span>
                </div>
            </div>
        </div>

        <!-- Section Admin Cuentas -->
        @if (($staffRole ?? '') === 'admin')
        <div class="section-block">
            <h2>🔑 Cuentas de Acceso del Portal</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Profesional</th>
                            <th>Correo de Acceso</th>
                            <th>Rol</th>
                            <th>Último Acceso</th>
                            <th style="width: 160px; text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="accounts">
                        <tr>
                            <td colspan="5" class="empty" style="text-align: center; color: var(--text-secondary);">Cargando cuentas…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </main>

    <script>
        const csrf = '{{ csrf_token() }}';
        const IS_ADMIN = @json(($staffRole ?? '') === 'admin');
        const MY_PROFESSIONAL_ID = @json($staffProfessionalId ?? null);

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
        const DAYS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        const STATUS_ES = {
            confirmed: 'Confirmada',
            pending_payment: 'Pago Pendiente',
            completed: 'Completada',
            cancelled: 'Cancelada',
            no_show: 'No Asistió'
        };

        async function api(path, options = {}) {
            const res = await fetch(path, {
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                ...options,
            });
            return res.json();
        }

        async function loadAppointments() {
            const rows = await api('/doctor/api/appointments');
            const container = document.getElementById('appointments');
            
            if (!rows.length) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p>No tienes citas agendadas en este momento.</p>
                    </div>`;
                return;
            }

            container.innerHTML = rows.map(a => {
                const dt = new Date(a.scheduled_at);
                const when = dt.toLocaleString('es-CL', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
                const canAct = ['confirmed', 'pending_payment'].includes(a.status);
                
                const joinBtn = a.joinable ? `
                    <button class="btn btn-purple" onclick="joinVideo('${a.id}')">🎥 Unirse</button>` : '';
                
                const actions = (a.joinable ? joinBtn : '') + (canAct ? `
                    <button class="btn btn-success" onclick="setStatus('${a.id}','completed')">Completada</button>
                    <button class="btn btn-warning" onclick="setStatus('${a.id}','no_show')">No asistió</button>
                    <button class="btn btn-danger" onclick="setStatus('${a.id}','cancelled')">Cancelar</button>` : '');

                const typeIcon = a.type === 'video' ? '🎥 Videoconsulta' : '🏠 Presencial';
                const typeClass = a.type === 'video' ? 'type-video' : 'type-in-person';
                const statusLabel = STATUS_ES[a.status] ?? a.status;

                return `
                    <div class="appointment-card ${a.status}">
                        <div class="card-header">
                            <span class="appointment-type ${typeClass}">${typeIcon}</span>
                            <span class="status ${a.status}">${statusLabel}</span>
                        </div>
                        <div class="card-body">
                            <div class="appointment-time">
                                ⏰ ${when}
                            </div>
                            <div class="patient-info">
                                <span class="patient-icon">👤</span>
                                <div>
                                    <div class="patient-name">${a.patient_name ?? '—'}</div>
                                    <div class="professional-label">Especialista: ${a.professional_name ?? '—'}</div>
                                </div>
                            </div>
                            ${a.reason ? `
                            <div class="reason-box">
                                "${a.reason}"
                            </div>` : ''}
                        </div>
                        <div class="card-footer">
                            <div class="price-info">
                                <span class="price-label">Valor</span>
                                <span class="price-value">$${a.price.toLocaleString('es-CL')}</span>
                            </div>
                            <div class="actions">
                                ${actions}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function joinVideo(id) {
            window.open(`/doctor/agenda/call/${id}`, '_blank');
        }

        async function setStatus(id, status) {
            await api(`/doctor/api/appointments/${id}/status`, { method: 'POST', body: JSON.stringify({ status }) });
            loadAppointments();
        }

        async function loadProfessionals() {
            let pros = await api('/api/professionals');
            const select = document.getElementById('professional');
            
            if (!IS_ADMIN && MY_PROFESSIONAL_ID) {
                pros = pros.filter(p => p.id === MY_PROFESSIONAL_ID);
                select.disabled = true;
            }
            select.innerHTML = pros.map(p => `<option value="${p.id}">${p.name} — ${p.specialty}</option>`).join('');
            select.onchange = loadSchedule;
            if (pros.length) loadSchedule();
        }

        async function loadAccounts() {
            if (!IS_ADMIN) return;
            const rows = await api('/doctor/api/accounts');
            document.getElementById('accounts').innerHTML = rows.map(a => {
                const last = a.last_login_at
                    ? new Date(a.last_login_at).toLocaleString('es-CL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
                    : 'Nunca';
                return `<tr>
                    <td><strong>${a.name}</strong><br><span style="color:var(--text-secondary);font-size:12px">${a.specialty}</span></td>
                    <td><input type="email" id="email_${a.id}" value="${a.email ?? ''}" placeholder="correo@aura.cl"></td>
                    <td><span class="admin-star">${a.role === 'admin' ? '⭐ Admin' : 'Profesional'}</span></td>
                    <td>${a.has_password ? last : '<span style="color:var(--status-pending);font-weight:600;">Sin cuenta</span>'}</td>
                    <td style="text-align: right;"><button class="btn btn-primary" style="font-size:12px;padding:6px 12px;" onclick="saveAccount('${a.id}')">
                        ${a.has_password ? 'Resetear clave' : 'Crear cuenta'}</button></td>
                </tr>`;
            }).join('');
        }

        async function saveAccount(id) {
            const email = document.getElementById(`email_${id}`).value.trim();
            if (!email) { alert('Ingresa un correo primero.'); return; }
            const res = await api(`/doctor/api/professionals/${id}/account`, {
                method: 'POST',
                body: JSON.stringify({ email }),
            });
            if (res.generated_password) {
                prompt('Cuenta lista. Copia y entrega esta contraseña (no se volverá a mostrar):', res.generated_password);
            } else if (res.error ?? !res.success) {
                alert(res.error ?? 'No se pudo guardar la cuenta.');
            }
            loadAccounts();
        }

        async function loadSchedule() {
            const id = document.getElementById('professional').value;
            const blocks = await api(`/doctor/api/professionals/${id}/schedules`);
            document.getElementById('schedule').innerHTML = blocks.map(b => `
                <span class="chip">${DAYS[b.day_of_week]} ${b.start_time}–${b.end_time}
                    <button title="Eliminar" onclick="removeBlock(${b.id})">✕</button>
                </span>`).join('') || '<span class="empty" style="color:var(--text-secondary);font-size:13px;padding:8px 0;">Sin horarios de atención definidos en este profesional.</span>';
        }

        async function addBlock() {
            const id = document.getElementById('professional').value;
            await api(`/doctor/api/professionals/${id}/schedules`, {
                method: 'POST',
                body: JSON.stringify({
                    day_of_week: parseInt(document.getElementById('day').value),
                    start_time: document.getElementById('start').value,
                    end_time: document.getElementById('end').value,
                }),
            });
            loadSchedule();
        }

        async function removeBlock(blockId) {
            const id = document.getElementById('professional').value;
            await api(`/doctor/api/professionals/${id}/schedules/${blockId}`, { method: 'DELETE' });
            loadSchedule();
        }

        loadAppointments();
        loadProfessionals();
        loadAccounts();
        setInterval(loadAppointments, 30000);
    </script>
</body>
</html>
