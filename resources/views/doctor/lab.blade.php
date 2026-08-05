<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Laboratorio — AURA Salud</title>
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
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }

        :root {
            --bg-color: #0B0F19;
            --primary-glow: rgba(13, 148, 136, 0.12);
            --secondary-glow: rgba(124, 58, 237, 0.12);
            --header-bg: rgba(11, 15, 25, 0.7);
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.06);
            --surface-raised: rgba(255, 255, 255, 0.04);
            --surface-sunken: rgba(255, 255, 255, 0.015);
            --input-bg: rgba(255, 255, 255, 0.04);
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --heading-grad-a: #FFFFFF;
            --heading-grad-b: #CBD5E1;
            --accent-teal: #0D9488;
            --accent-teal-light: #2DD4BF;
            --on-accent: #FFFFFF;
            --status-confirmed: #14B8A6;
            --status-pending: #F59E0B;
            --status-completed: #10B981;
            --status-cancelled: #EF4444;
        }

        :root[data-theme="light"] {
            --bg-color: #EEF2F6;
            --header-bg: rgba(255, 255, 255, 0.85);
            --card-bg: #FFFFFF;
            --card-border: #E2E8F0;
            --surface-raised: #F1F5F9;
            --surface-sunken: #F8FAFC;
            --input-bg: #FFFFFF;
            --text-primary: #0F172A;
            --text-secondary: #64748B;
            --heading-grad-a: #0F172A;
            --heading-grad-b: #334155;
            --accent-teal-light: #0F766E;
            --status-confirmed: #0D9488;
            --status-completed: #059669;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        .blob { position: absolute; border-radius: 50%; filter: blur(120px); z-index: 0; pointer-events: none; opacity: .6; }
        .blob-1 { top: -10%; left: 10%; width: 400px; height: 400px; background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%); }
        .blob-2 { bottom: 10%; right: 15%; width: 450px; height: 450px; background: radial-gradient(circle, var(--secondary-glow) 0%, transparent 70%); }

        header {
            height: 80px; border-bottom: 1px solid var(--card-border);
            backdrop-filter: blur(20px); background-color: var(--header-bg);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 40px; z-index: 10;
        }
        .logo-section { display: flex; align-items: center; gap: 12px; }
        .logo-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-teal-light));
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
        }
        .logo-icon svg { fill: var(--on-accent); width: 22px; height: 22px; }
        .logo-title {
            font-size: 20px; font-weight: 900; letter-spacing: -.5px;
            background: linear-gradient(135deg, var(--heading-grad-a), var(--heading-grad-b));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .logo-badge {
            font-size: 10px; font-weight: 700; background-color: rgba(13,148,136,.15);
            color: var(--accent-teal-light); border: 1px solid rgba(13,148,136,.3);
            padding: 3px 8px; border-radius: 8px; text-transform: uppercase; letter-spacing: .5px;
        }
        .doctor-profile { display: flex; align-items: center; gap: 16px; }
        .profile-info { text-align: right; }
        .profile-name { font-size: 14px; font-weight: 600; }
        .profile-role { font-size: 11px; color: var(--text-secondary); }
        .profile-avatar {
            width: 40px; height: 40px; border-radius: 50%; background-color: var(--accent-teal);
            border: 2px solid var(--accent-teal-light); display: flex; align-items: center;
            justify-content: center; font-weight: bold; color: var(--on-accent);
        }
        .btn-back { color: var(--accent-teal-light); text-decoration: none; font-size: 14px; font-weight: 600; }
        .theme-toggle {
            background: var(--surface-raised); border: 1px solid var(--card-border);
            border-radius: 10px; padding: 6px 10px; cursor: pointer; font-size: 14px;
        }

        main {
            flex: 1; width: 100%; max-width: 1400px; margin: 0 auto;
            padding: 36px 24px 80px; z-index: 10;
            display: flex; flex-direction: column; gap: 32px;
        }

        h2 { font-size: 18px; font-weight: 800; margin-bottom: 14px; letter-spacing: -.3px; }
        .hint { font-size: 12px; color: var(--text-secondary); margin-bottom: 14px; line-height: 1.6; }

        .card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: 18px; padding: 22px;
        }

        .controls { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .control-group { display: flex; flex-direction: column; gap: 6px; }
        .control-group label { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .4px; }
        input, select, textarea {
            background: var(--input-bg); border: 1px solid var(--card-border);
            border-radius: 10px; padding: 9px 12px; color: var(--text-primary);
            font-size: 13px; min-width: 130px;
        }
        select option {
            background-color: var(--card-bg, #1e293b);
            color: var(--text-primary, #f8fafc);
            padding: 8px 12px;
        }
        textarea { min-width: 260px; resize: vertical; }

        .btn {
            border: none; border-radius: 10px; padding: 10px 16px; cursor: pointer;
            font-size: 13px; font-weight: 700; background: var(--accent-teal); color: #fff;
        }
        .btn:hover { filter: brightness(1.1); }
        .btn-ghost { background: var(--surface-raised); color: var(--text-primary); border: 1px solid var(--card-border); }
        .btn-danger { background: var(--status-cancelled); }
        .btn-sm { padding: 6px 10px; font-size: 12px; }

        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { text-align: left; padding: 10px 12px; font-size: 13px; border-bottom: 1px solid var(--card-border); }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-secondary); }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }

        .collection {
            background: var(--surface-sunken); border: 1px solid var(--card-border);
            border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 10px;
        }
        .collection-when { font-size: 15px; font-weight: 800; color: var(--accent-teal-light); }
        .collection-who { font-size: 14px; font-weight: 600; }
        .collection-line { font-size: 12px; color: var(--text-secondary); line-height: 1.6; }
        .collection-line strong { color: var(--text-primary); font-weight: 600; }

        .notes {
            background: rgba(245, 158, 11, .10); border: 1px solid rgba(245, 158, 11, .35);
            border-radius: 10px; padding: 10px 12px; font-size: 12px; line-height: 1.6;
        }
        .notes-title { font-weight: 800; color: var(--status-pending); font-size: 11px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }

        .badge {
            display: inline-block; font-size: 10px; font-weight: 800; padding: 3px 8px;
            border-radius: 999px; text-transform: uppercase; letter-spacing: .4px;
        }
        .badge-scheduled { background: rgba(20,184,166,.15); color: var(--status-confirmed); }
        .badge-pending { background: rgba(245,158,11,.15); color: var(--status-pending); }
        .badge-completed { background: rgba(16,185,129,.15); color: var(--status-completed); }
        .badge-cancelled { background: rgba(239,68,68,.15); color: var(--status-cancelled); }

        .result-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; }
        .result-row a { color: var(--accent-teal-light); text-decoration: none; font-weight: 600; }

        .empty { color: var(--text-secondary); font-size: 13px; padding: 24px 0; text-align: center; }

        .earnings { display: flex; gap: 28px; flex-wrap: wrap; }
        .earning-item { display: flex; flex-direction: column; gap: 2px; }
        .earning-value { font-size: 22px; font-weight: 900; }
        .earning-label { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .4px; }

        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: var(--card-bg); border: 1px solid var(--card-border);
            backdrop-filter: blur(20px); padding: 12px 20px; border-radius: 12px;
            font-size: 13px; z-index: 50; display: none; max-width: 90vw;
        }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<header>
    <div class="logo-section">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm6 12H6v-1c0-2 4-3.1 6-3.1s6 1.1 6 3.1v1z"/></svg>
        </div>
        <div class="logo-title">AURA Salud</div>
        <div class="logo-badge">Laboratorio</div>
    </div>
    <div class="doctor-profile">
        <button type="button" class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Cambiar tema">☀️</button>
        <a href="/doctor" class="btn-back">← Volver al panel</a>
        <div class="profile-info">
            <div class="profile-name">{{ $staffName }}</div>
            <div class="profile-role">{{ $staffRole === 'admin' ? 'Administración' : 'Laboratorista' }}</div>
        </div>
        <div class="profile-avatar">{{ strtoupper(mb_substr($staffName, 0, 1)) }}</div>
        <form method="POST" action="/doctor/logout" style="margin-left:14px;">
            @csrf
            <button type="submit" class="btn btn-danger btn-sm">Salir</button>
        </form>
    </div>
</header>

<main>

    <section>
        <h2>🧪 Tomas de muestra agendadas</h2>
        <p class="hint">
            Lea las indicaciones antes de salir: ahí van las condiciones previas del paciente
            (ayuno, orden médica física, para cuándo se necesita el resultado).
        </p>
        <div class="controls" style="margin-bottom:18px;">
            <div class="control-group">
                <label for="filterDate">Ver un día en particular</label>
                <input type="date" id="filterDate">
            </div>
            <button class="btn btn-ghost" onclick="loadCollections()">Aplicar</button>
            <button class="btn btn-ghost" onclick="document.getElementById('filterDate').value=''; loadCollections();">Desde hoy</button>
        </div>
        <div class="grid" id="collections">
            <div class="empty">Cargando tomas agendadas…</div>
        </div>
    </section>

    <section>
        <h2>📅 Publicar disponibilidad</h2>
        <p class="hint">
            El paciente solo puede elegir dentro de lo que usted publique. Cada bloque se divide
            en cupos de la duración indicada; la capacidad es cuántas tomas admite en paralelo.
        </p>
        <div class="card">
            <div class="controls">
                @if($staffRole === 'admin')
                    <div class="control-group">
                        <label for="professionalId">ID del prestador</label>
                        <input type="text" id="professionalId" placeholder="prof_...">
                    </div>
                @endif
                <div class="control-group">
                    <label for="blockDate">Fecha</label>
                    <input type="date" id="blockDate">
                </div>
                <div class="control-group">
                    <label for="startTime">Desde</label>
                    <input type="time" id="startTime" value="08:00" step="900">
                </div>
                <div class="control-group">
                    <label for="endTime">Hasta</label>
                    <input type="time" id="endTime" value="12:00" step="900">
                </div>
                <div class="control-group">
                    <label for="slotMinutes">Duración del cupo</label>
                    <select id="slotMinutes">
                        <option value="20">20 min</option>
                        <option value="30" selected>30 min</option>
                        <option value="45">45 min</option>
                        <option value="60">60 min</option>
                    </select>
                </div>
                <div class="control-group">
                    <label for="capacity">Cupos en paralelo</label>
                    <input type="number" id="capacity" value="1" min="1" max="20">
                </div>
                <div class="control-group">
                    <label for="zone">Sector (opcional)</label>
                    <input type="text" id="zone" placeholder="Providencia">
                </div>
                <button class="btn" onclick="publishBlock()">Publicar bloque</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Fecha</th><th>Horario</th><th>Cupo</th><th>Paralelo</th>
                        <th>Sector</th><th>Agendados</th><th>Estado</th><th></th>
                    </tr>
                </thead>
                <tbody id="blocks">
                    <tr><td colspan="8" class="empty">Cargando bloques…</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2>💰 Saldo por dispersar</h2>
        <p class="hint">
            El paciente paga siempre a la plataforma. Esto es lo devengado a su favor,
            ya descontada la retención, pendiente de transferencia.
        </p>
        <div class="card earnings" id="earnings">
            <div class="empty">Cargando saldo…</div>
        </div>
    </section>

</main>

<div class="toast" id="toast"></div>

<script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const IS_ADMIN = @json($staffRole === 'admin');

    function toggleTheme() {
        const root = document.documentElement;
        const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        if (next === 'light') root.setAttribute('data-theme', 'light');
        else root.removeAttribute('data-theme');
        try { localStorage.setItem('aura_portal_theme', next); } catch (e) {}
    }

    let toastTimer = null;
    function toast(message, isError) {
        const el = document.getElementById('toast');
        el.textContent = message;
        el.style.display = 'block';
        el.style.borderColor = isError ? 'var(--status-cancelled)' : 'var(--card-border)';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.style.display = 'none'; }, 4500);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    async function api(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', ...(options.headers || {}) },
            ...options,
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.error || body.message || 'Error inesperado');
        return body;
    }

    const money = n => '$' + Number(n || 0).toLocaleString('es-CL');

    function whenLabel(iso) {
        if (!iso) return 'Sin fecha';
        const d = new Date(iso);
        return d.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
            + ' · ' + d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
    }

    const BADGES = {
        pending_payment: ['badge-pending', 'Pago pendiente'],
        scheduled: ['badge-scheduled', 'Agendada'],
        accepted: ['badge-scheduled', 'Confirmada'],
        en_camino: ['badge-scheduled', 'En camino'],
        en_atencion: ['badge-scheduled', 'En atención'],
        completed: ['badge-completed', 'Completada'],
        cancelled: ['badge-cancelled', 'Cancelada'],
    };

    // ---------- Tomas agendadas ----------

    async function loadCollections() {
        const date = document.getElementById('filterDate').value;
        const container = document.getElementById('collections');
        try {
            const rows = await api('/doctor/api/lab/collections' + (date ? '?date=' + date : ''));
            if (!rows.length) {
                container.innerHTML = '<div class="empty">No hay tomas de muestra agendadas para este periodo.</div>';
                return;
            }
            container.innerHTML = rows.map(renderCollection).join('');
        } catch (e) {
            container.innerHTML = '<div class="empty">No se pudieron cargar las tomas: ' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderCollection(row) {
        const [badgeClass, badgeText] = BADGES[row.status] || ['badge-pending', row.status];
        const results = (row.results || []).map(r => `
            <div class="result-row">
                <span>📄 ${escapeHtml(r.title)}${r.emailed_at ? ' · enviado por correo' : (r.email_error ? ' · <span style="color:var(--status-cancelled)">correo falló</span>' : '')}</span>
                <a href="${escapeHtml(r.download_url)}" target="_blank" rel="noopener">Ver</a>
            </div>`).join('');

        return `
        <article class="collection">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                <div class="collection-when">${escapeHtml(whenLabel(row.scheduled_at))}</div>
                <span class="badge ${badgeClass}">${escapeHtml(badgeText)}</span>
            </div>
            <div class="collection-who">${escapeHtml(row.patient_name)}</div>
            <div class="collection-line">📍 ${escapeHtml(row.address_text)}${row.zone ? ' · ' + escapeHtml(row.zone) : ''}</div>
            <div class="collection-line"><strong>Exámenes:</strong> ${escapeHtml(row.exam_required || 'No especificado')}</div>
            ${row.clinical_notes ? `<div class="notes"><div class="notes-title">Indicaciones del paciente</div>${escapeHtml(row.clinical_notes)}</div>` : ''}
            ${row.prescription_file ? `<div class="collection-line">🧾 <a href="${escapeHtml(row.prescription_file)}" target="_blank" rel="noopener" style="color:var(--accent-teal-light)">Ver orden médica adjunta</a></div>` : ''}
            <div class="collection-line">${money(row.final_price)} · pago ${escapeHtml(row.payment_status || 'sin registrar')}</div>
            ${results ? `<div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">${results}</div>` : ''}
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                <button class="btn btn-ghost btn-sm" onclick="setStatus('${row.id}','en_camino')">En camino</button>
                <button class="btn btn-ghost btn-sm" onclick="setStatus('${row.id}','en_atencion')">En domicilio</button>
                <button class="btn btn-sm" onclick="setStatus('${row.id}','completed')">Completar</button>
                <button class="btn btn-ghost btn-sm" onclick="promptUpload('${row.id}')">Subir resultado</button>
            </div>
        </article>`;
    }

    async function setStatus(id, status) {
        try {
            await api('/doctor/api/bookings/' + id + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status }),
            });
            toast('Estado actualizado.');
            loadCollections();
            loadEarnings();
        } catch (e) {
            toast(e.message, true);
        }
    }

    // ---------- Carga de resultados ----------

    function promptUpload(id) {
        const title = prompt('Título del informe (ej. "Hemograma completo")');
        if (!title) return;
        const notes = prompt('Observaciones para el paciente (opcional)') || '';

        const picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = 'application/pdf';
        picker.onchange = async () => {
            const file = picker.files[0];
            if (!file) return;
            const form = new FormData();
            form.append('title', title);
            if (notes) form.append('notes', notes);
            form.append('file', file);
            try {
                toast('Subiendo informe…');
                await api('/doctor/api/lab/collections/' + id + '/results', { method: 'POST', body: form });
                toast('Informe cargado y enviado al correo del paciente.');
                loadCollections();
            } catch (e) {
                toast(e.message, true);
            }
        };
        picker.click();
    }

    // ---------- Bloques de disponibilidad ----------

    async function loadBlocks() {
        const tbody = document.getElementById('blocks');
        try {
            const blocks = await api('/doctor/api/lab/schedules');
            if (!blocks.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty">Aún no ha publicado bloques de disponibilidad.</td></tr>';
                return;
            }
            tbody.innerHTML = blocks.map(b => `
                <tr>
                    <td>${escapeHtml(b.date)}</td>
                    <td>${escapeHtml(b.start_time)} – ${escapeHtml(b.end_time)}</td>
                    <td>${b.slot_minutes} min</td>
                    <td>${b.capacity}</td>
                    <td>${escapeHtml(b.zone || 'Todo el sector')}</td>
                    <td>${b.slots_booked} / ${b.slots_total}</td>
                    <td><span class="badge ${b.active ? 'badge-scheduled' : 'badge-cancelled'}">${b.active ? 'Publicado' : 'Retirado'}</span></td>
                    <td><button class="btn btn-danger btn-sm" onclick="removeBlock(${b.id})">Quitar</button></td>
                </tr>`).join('');
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty">No se pudieron cargar los bloques: ' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    async function publishBlock() {
        const payload = {
            date: document.getElementById('blockDate').value,
            start_time: document.getElementById('startTime').value,
            end_time: document.getElementById('endTime').value,
            slot_minutes: Number(document.getElementById('slotMinutes').value),
            capacity: Number(document.getElementById('capacity').value),
            zone: document.getElementById('zone').value || null,
        };
        if (IS_ADMIN) {
            payload.professional_id = document.getElementById('professionalId').value || null;
        }
        if (!payload.date) { toast('Elija una fecha.', true); return; }

        try {
            await api('/doctor/api/lab/schedules', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            toast('Bloque publicado.');
            loadBlocks();
        } catch (e) {
            toast(e.message, true);
        }
    }

    async function removeBlock(id) {
        if (!confirm('¿Quitar este bloque de la oferta?')) return;
        try {
            const result = await api('/doctor/api/lab/schedules/' + id, { method: 'DELETE' });
            toast(result.message || 'Bloque eliminado.');
            loadBlocks();
        } catch (e) {
            toast(e.message, true);
        }
    }

    // ---------- Saldo ----------

    async function loadEarnings() {
        const container = document.getElementById('earnings');
        try {
            const data = await api('/doctor/api/lab/earnings');
            const b = data.balance;
            container.innerHTML = `
                <div class="earning-item"><span class="earning-value">${money(b.pending_net)}</span><span class="earning-label">Por transferirle</span></div>
                <div class="earning-item"><span class="earning-value">${money(b.gross)}</span><span class="earning-label">Cobrado por la plataforma</span></div>
                <div class="earning-item"><span class="earning-value">${money(b.retained)}</span><span class="earning-label">Retención (${(data.commission_bps / 100).toFixed(2).replace('.', ',')} %)</span></div>
                <div class="earning-item"><span class="earning-value">${b.pending_count}</span><span class="earning-label">Atenciones pendientes de pago</span></div>`;
        } catch (e) {
            container.innerHTML = '<div class="empty">' + escapeHtml(e.message) + '</div>';
        }
    }

    document.getElementById('themeToggle').textContent =
        document.documentElement.getAttribute('data-theme') === 'light' ? '🌙' : '☀️';

    loadCollections();
    loadBlocks();
    loadEarnings();
    setInterval(loadCollections, 60000);
</script>

</body>
</html>
