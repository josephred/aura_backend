<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda — AURA Salud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        :root {
            --bg-color: #0B0F19;
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.06);
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --accent-teal: #0D9488;
            --accent-teal-light: #2DD4BF;
            --status-pending: #F59E0B;
            --status-completed: #10B981;
            --status-cancelled: #EF4444;
        }
        body { background: var(--bg-color); color: var(--text-primary); min-height: 100vh; }
        header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 32px; border-bottom: 1px solid var(--card-border);
        }
        .title { font-size: 20px; font-weight: 700; }
        .title span { color: var(--accent-teal-light); }
        nav a {
            color: var(--text-secondary); text-decoration: none; margin-left: 20px;
            font-size: 14px; font-weight: 500;
        }
        nav a:hover { color: var(--accent-teal-light); }
        main { max-width: 1100px; margin: 0 auto; padding: 28px 24px 60px; }
        h2 { font-size: 16px; font-weight: 600; margin: 28px 0 14px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.08em; }
        .controls { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-top: 24px; }
        select, input, button {
            background: #111827; color: var(--text-primary); border: 1px solid var(--card-border);
            border-radius: 8px; padding: 9px 12px; font-size: 14px;
        }
        button.primary { background: var(--accent-teal); border-color: transparent; cursor: pointer; font-weight: 600; }
        button.primary:hover { background: var(--accent-teal-light); color: #06251f; }
        button.danger { background: transparent; color: var(--status-cancelled); border-color: rgba(239,68,68,.35); cursor: pointer; font-size: 12px; padding: 5px 9px; }
        table { width: 100%; border-collapse: collapse; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; overflow: hidden; }
        th, td { text-align: left; padding: 11px 14px; font-size: 14px; border-bottom: 1px solid var(--card-border); }
        th { color: var(--text-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
        tr:last-child td { border-bottom: none; }
        .status { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status.confirmed { background: rgba(13,148,136,.15); color: var(--accent-teal-light); }
        .status.pending_payment { background: rgba(245,158,11,.15); color: var(--status-pending); }
        .status.completed { background: rgba(16,185,129,.15); color: var(--status-completed); }
        .status.cancelled, .status.no_show { background: rgba(239,68,68,.15); color: var(--status-cancelled); }
        .actions button { margin-right: 6px; }
        .empty { color: var(--text-secondary); padding: 24px; text-align: center; }
        .schedule-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .chip {
            display: inline-flex; align-items: center; gap: 8px; background: var(--card-bg);
            border: 1px solid var(--card-border); padding: 6px 12px; border-radius: 20px; font-size: 13px;
        }
        .chip button { background: none; border: none; color: var(--status-cancelled); cursor: pointer; font-size: 14px; padding: 0; }
    </style>
</head>
<body>
    <header>
        <div class="title">AURA <span>Agenda</span></div>
        <nav>
            <a href="/doctor">← Volver al panel</a>
        </nav>
    </header>
    <main>
        <h2>Próximas citas</h2>
        <table>
            <thead>
                <tr>
                    <th>Fecha y hora</th><th>Paciente</th><th>Profesional</th>
                    <th>Motivo</th><th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody id="appointments"><tr><td colspan="6" class="empty">Cargando…</td></tr></tbody>
        </table>

        <h2>Horarios de atención</h2>
        <div class="controls">
            <select id="professional"></select>
            <select id="day">
                <option value="1">Lunes</option><option value="2">Martes</option>
                <option value="3">Miércoles</option><option value="4">Jueves</option>
                <option value="5">Viernes</option><option value="6">Sábado</option>
                <option value="7">Domingo</option>
            </select>
            <input type="time" id="start" value="09:00">
            <input type="time" id="end" value="13:00">
            <button class="primary" onclick="addBlock()">Agregar bloque</button>
        </div>
        <div id="schedule" class="schedule-chips"></div>
    </main>

    <script>
        const csrf = '{{ csrf_token() }}';
        const DAYS = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        const STATUS_ES = {
            confirmed: 'Confirmada', pending_payment: 'Pago pendiente',
            completed: 'Completada', cancelled: 'Cancelada', no_show: 'No asistió'
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
            const tbody = document.getElementById('appointments');
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty">No hay citas agendadas.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(a => {
                const dt = new Date(a.scheduled_at);
                const when = dt.toLocaleString('es-CL', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
                const canAct = ['confirmed', 'pending_payment'].includes(a.status);
                const joinBtn = a.joinable ? `
                    <button class="primary" style="font-size:12px;padding:5px 9px;background:#7C3AED" onclick="joinVideo('${a.id}')">🎥 Unirse</button>` : '';
                const actions = (a.joinable ? joinBtn : '') + (canAct ? `
                    <button class="primary" style="font-size:12px;padding:5px 9px" onclick="setStatus('${a.id}','completed')">Completada</button>
                    <button class="danger" onclick="setStatus('${a.id}','no_show')">No asistió</button>
                    <button class="danger" onclick="setStatus('${a.id}','cancelled')">Cancelar</button>` : '');
                const kind = a.type === 'video' ? ' 🎥' : '';
                return `<tr>
                    <td>${when}${kind}</td>
                    <td>${a.patient_name ?? '—'}</td>
                    <td>${a.professional_name ?? '—'}</td>
                    <td>${a.reason ?? '—'}</td>
                    <td><span class="status ${a.status}">${STATUS_ES[a.status] ?? a.status}</span></td>
                    <td class="actions">${actions}</td>
                </tr>`;
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
            const pros = await api('/api/professionals');
            const select = document.getElementById('professional');
            select.innerHTML = pros.map(p => `<option value="${p.id}">${p.name} — ${p.specialty}</option>`).join('');
            select.onchange = loadSchedule;
            if (pros.length) loadSchedule();
        }

        async function loadSchedule() {
            const id = document.getElementById('professional').value;
            const blocks = await api(`/doctor/api/professionals/${id}/schedules`);
            document.getElementById('schedule').innerHTML = blocks.map(b => `
                <span class="chip">${DAYS[b.day_of_week]} ${b.start_time}–${b.end_time}
                    <button title="Eliminar" onclick="removeBlock(${b.id})">✕</button>
                </span>`).join('') || '<span class="empty">Sin horarios definidos.</span>';
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
        setInterval(loadAppointments, 30000);
    </script>
</body>
</html>
