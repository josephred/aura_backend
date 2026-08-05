<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Operaciones · Aura Salud</title>
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-2: #273549;
            --border: #334155;
            --text: #f1f5f9;
            --text-secondary: #94a3b8;
            --accent: #0d9488;
            --accent-soft: rgba(13, 148, 136, 0.15);
            --warn: #f59e0b;
            --danger: #ef4444;
            --ok: #10b981;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 24px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-title { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; }
        .brand-badge {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--accent);
            background: var(--accent-soft);
            border-radius: 999px;
            padding: 4px 10px;
        }

        .header-actions { display: flex; align-items: center; gap: 12px; }
        .staff-name { font-size: 13px; color: var(--text-secondary); }

        .btn {
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }

        main { padding: 24px; max-width: 1200px; margin: 0 auto; }

        h2 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            margin: 32px 0 12px;
        }
        h2:first-child { margin-top: 0; }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
        }

        .metric-label { font-size: 11px; color: var(--text-secondary); font-weight: 600; }
        .metric-value { font-size: 26px; font-weight: 800; margin-top: 6px; }
        .metric-hint { font-size: 11px; color: var(--text-secondary); margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-secondary);
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        input {
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 12px;
            width: 100%;
        }

        select {
            color-scheme: dark;
            background: #111827;
            border: 1px solid var(--border);
            color: #F8FAFC;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 12px;
            width: 100%;
        }

        select option {
            background-color: #111827 !important;
            color: #F8FAFC !important;
            padding: 8px 12px;
        }

        .pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .pill-ok { background: rgba(16, 185, 129, 0.15); color: var(--ok); }
        .pill-warn { background: rgba(245, 158, 11, 0.15); color: var(--warn); }
        .pill-off { background: rgba(148, 163, 184, 0.15); color: var(--text-secondary); }

        .muted { color: var(--text-secondary); font-size: 12px; }
        .note {
            background: var(--accent-soft);
            border: 1px solid rgba(13, 148, 136, 0.35);
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <div class="brand-title">AURA Salud</div>
            <span class="brand-badge">Panel de Operaciones</span>
        </div>
        <div class="header-actions">
            <span class="staff-name">{{ $staffName }}</span>
            <form method="POST" action="/doctor/logout" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-danger">Salir</button>
            </form>
        </div>
    </header>

    <main>
        <div class="note">
            Este panel es exclusivamente administrativo. La atención de pacientes, agendas y
            videoconsultas viven en el <strong>portal clínico</strong>, que funciona de forma independiente.
        </div>

        <h2>Estado general</h2>
        <div class="metrics" id="metrics">
            <div class="card"><div class="metric-label">Cargando…</div></div>
        </div>

        <h2>Demanda por zona</h2>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Zona</th>
                        <th>Solicitudes abiertas</th>
                        <th>Profesionales en turno</th>
                        <th>Cobertura</th>
                    </tr>
                </thead>
                <tbody id="zones">
                    <tr><td colspan="4" class="muted">Cargando zonas…</td></tr>
                </tbody>
            </table>
        </div>

        <h2>Prestadores, turnos y cobertura</h2>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th style="min-width: 180px;">Profesional</th>
                        <th style="width: 150px;">Turno</th>
                        <th style="min-width: 220px;">Zonas que cubre</th>
                        <th style="min-width: 200px;">Correo de acceso</th>
                        <th style="width: 210px; text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="professionals">
                    <tr><td colspan="5" class="muted">Cargando prestadores…</td></tr>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        const csrf = '{{ csrf_token() }}';

        async function api(url, options = {}) {
            const response = await fetch(url, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                ...options,
            });
            return response.json();
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
        }

        async function loadMetrics() {
            const m = await api('/admin/api/metrics');
            document.getElementById('metrics').innerHTML = `
                <div class="card">
                    <div class="metric-label">Prestadores en turno</div>
                    <div class="metric-value">${m.professionals_on_duty} / ${m.professionals_total}</div>
                    <div class="metric-hint">Disponibles ahora</div>
                </div>
                <div class="card">
                    <div class="metric-label">Solicitudes abiertas</div>
                    <div class="metric-value">${m.open_requests}</div>
                    <div class="metric-hint">En cola o en curso</div>
                </div>
                <div class="card">
                    <div class="metric-label">Demora promedio</div>
                    <div class="metric-value">${m.average_eta_minutes} min</div>
                    <div class="metric-hint">Sobre solicitudes activas</div>
                </div>
                <div class="card">
                    <div class="metric-label">Completadas hoy</div>
                    <div class="metric-value">${m.completed_today}</div>
                    <div class="metric-hint">Atenciones cerradas</div>
                </div>`;
        }

        async function loadZones() {
            const zones = await api('/admin/api/zones');
            const body = document.getElementById('zones');

            if (!zones.length) {
                body.innerHTML = '<tr><td colspan="4" class="muted">No hay solicitudes abiertas en este momento.</td></tr>';
                return;
            }

            body.innerHTML = zones.map((z) => {
                const covered = z.professionals_on_duty > 0;
                const saturated = covered && z.open_requests > z.professionals_on_duty;
                const pill = !covered
                    ? '<span class="pill pill-off">Sin cobertura</span>'
                    : saturated
                        ? '<span class="pill pill-warn">Saturada</span>'
                        : '<span class="pill pill-ok">Al día</span>';

                return `<tr>
                    <td><strong>${escapeHtml(z.zone)}</strong></td>
                    <td>${z.open_requests}</td>
                    <td>${z.professionals_on_duty}</td>
                    <td>${pill}</td>
                </tr>`;
            }).join('');
        }

        async function loadProfessionals() {
            const rows = await api('/admin/api/professionals');
            document.getElementById('professionals').innerHTML = rows.map((p) => {
                const last = p.last_login_at
                    ? new Date(p.last_login_at).toLocaleString('es-CL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
                    : 'Nunca';

                return `<tr>
                    <td>
                        <strong>${escapeHtml(p.name)}</strong><br>
                        <span class="muted">${escapeHtml(p.specialty)}${p.role === 'admin' ? ' · ⭐ Admin' : ''}</span>
                    </td>
                    <td>
                        <select id="duty_${p.id}" onchange="saveDuty('${p.id}')">
                            <option value="disponible" ${p.duty_status === 'disponible' ? 'selected' : ''}>Disponible</option>
                            <option value="ocupado" ${p.duty_status === 'ocupado' ? 'selected' : ''}>Ocupado</option>
                            <option value="desconectado" ${p.duty_status === 'desconectado' ? 'selected' : ''}>Desconectado</option>
                        </select>
                    </td>
                    <td><input id="zones_${p.id}" value="${escapeHtml(p.coverage_zones ?? '')}" placeholder="Providencia, Ñuñoa…"></td>
                    <td>
                        <input type="email" id="email_${p.id}" value="${escapeHtml(p.email ?? '')}" placeholder="correo@aura.cl">
                        <span class="muted">${p.has_password ? 'Último acceso: ' + last : 'Sin cuenta'}</span>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button class="btn" onclick="saveZones('${p.id}')">Guardar zonas</button>
                        <button class="btn btn-primary" onclick="saveAccount('${p.id}')">${p.has_password ? 'Resetear clave' : 'Crear cuenta'}</button>
                    </td>
                </tr>`;
            }).join('');
        }

        async function saveDuty(id) {
            await api(`/admin/api/professionals/${id}`, {
                method: 'POST',
                body: JSON.stringify({ duty_status: document.getElementById(`duty_${id}`).value }),
            });
            loadMetrics();
            loadZones();
        }

        async function saveZones(id) {
            await api(`/admin/api/professionals/${id}`, {
                method: 'POST',
                body: JSON.stringify({ coverage_zones: document.getElementById(`zones_${id}`).value }),
            });
            loadZones();
        }

        async function saveAccount(id) {
            const email = document.getElementById(`email_${id}`).value.trim();
            if (!email) { alert('Ingresa un correo primero.'); return; }

            const res = await api(`/admin/api/professionals/${id}/account`, {
                method: 'POST',
                body: JSON.stringify({ email }),
            });

            if (res.generated_password) {
                prompt('Cuenta lista. Copia y entrega esta contraseña (no se volverá a mostrar):', res.generated_password);
            } else if (res.error || !res.success) {
                alert(res.error || 'No se pudo guardar la cuenta.');
            }
            loadProfessionals();
        }

        loadMetrics();
        loadZones();
        loadProfessionals();
        setInterval(() => { loadMetrics(); loadZones(); }, 30000);
    </script>
</body>
</html>
