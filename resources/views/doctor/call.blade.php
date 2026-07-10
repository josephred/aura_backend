<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Videoconsulta — AURA Salud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        :root {
            --bg: #0B0F19;
            --chrome-bg: #0B0F19;
            --stage-bg: #05070D;
            --border: rgba(255, 255, 255, 0.06);
            --preview-border: rgba(255, 255, 255, 0.15);
            --overlay-bg: rgba(11, 15, 25, 0.85);
            --control-bg: rgba(255, 255, 255, 0.08);
            --control-off: #475569;
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --accent: #0D9488;
            --accent-light: #2DD4BF;
            --spinner-track: rgba(45, 212, 191, 0.25);
            --on-accent: #FFFFFF;
            --danger: #EF4444;
        }
        :root[data-theme="light"] {
            --bg: #EEF2F6;
            --chrome-bg: #FFFFFF;
            --stage-bg: #0B0F19;
            --border: #E2E8F0;
            --preview-border: rgba(15, 23, 42, 0.15);
            --overlay-bg: rgba(238, 242, 246, 0.92);
            --control-bg: #E2E8F0;
            --control-off: #94A3B8;
            --text-primary: #0F172A;
            --text-secondary: #475569;
            --accent-light: #0F766E;
            --spinner-track: rgba(13, 148, 136, 0.2);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: var(--bg); color: var(--text-primary); height: 100vh; display: flex; flex-direction: column; overflow: hidden; transition: background 0.3s ease, color 0.3s ease; }
        header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 20px; border-bottom: 1px solid var(--border); flex-shrink: 0;
            background: var(--chrome-bg);
        }
        .title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        .title span { color: var(--accent-light); }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .patient { font-size: 13px; color: var(--text-secondary); }
        .theme-toggle {
            width: 38px; height: 38px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--control-bg);
            color: var(--text-primary); font-size: 16px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;
        }
        .theme-toggle:hover { border-color: var(--accent); }
        .stage { position: relative; flex: 1; background: var(--stage-bg); }
        #remoteVideo { width: 100%; height: 100%; object-fit: contain; }
        #localVideo {
            position: absolute; bottom: 18px; right: 18px; width: 200px; aspect-ratio: 4/3;
            object-fit: cover; border-radius: 12px; border: 1px solid var(--preview-border);
            background: #0B0F19; transform: scaleX(-1);
        }
        #status {
            position: absolute; inset: 0; display: flex; flex-direction: column; gap: 14px;
            align-items: center; justify-content: center; background: var(--overlay-bg);
            font-size: 15px; color: var(--text-secondary); text-align: center; padding: 20px;
        }
        #status.hidden { display: none; }
        .spinner {
            width: 34px; height: 34px; border-radius: 50%;
            border: 3px solid var(--spinner-track); border-top-color: var(--accent-light);
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .controls {
            display: flex; justify-content: center; gap: 14px; padding: 14px; flex-shrink: 0;
            border-top: 1px solid var(--border); background: var(--chrome-bg);
        }
        .controls button {
            width: 52px; height: 52px; border-radius: 50%; border: none; cursor: pointer;
            font-size: 20px; background: var(--control-bg); color: var(--text-primary);
        }
        .controls button.off { background: var(--control-off); color: #FFFFFF; }
        .controls button.hang { background: var(--danger); color: #FFFFFF; }
        .controls button:hover { filter: brightness(1.2); }
        #recall {
            background: var(--accent); border: none; color: var(--on-accent); font-weight: 700;
            padding: 11px 22px; border-radius: 10px; cursor: pointer; font-size: 14px;
        }
    </style>
</head>
<body>
    <header>
        <div class="title">AURA <span>Videoconsulta</span></div>
        <div class="header-right">
            <div class="patient">Paciente: {{ $patientName }} · {{ $appointment->scheduled_at->format('H:i') }} hrs</div>
            <button type="button" class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Cambiar tema">☀️</button>
        </div>
    </header>
    <div class="stage">
        <video id="remoteVideo" autoplay playsinline></video>
        <video id="localVideo" autoplay playsinline muted></video>
        <div id="status">
            <div class="spinner" id="statusSpinner"></div>
            <div id="statusText">Preparando cámara…</div>
            <button id="recall" style="display:none" onclick="startOffer()">Volver a llamar</button>
        </div>
    </div>
    <div class="controls">
        <button id="micBtn" onclick="toggleMic()" title="Silenciar micrófono">🎙️</button>
        <button id="camBtn" onclick="toggleCam()" title="Apagar cámara">🎥</button>
        <button class="hang" onclick="hangUp()" title="Terminar llamada">📞</button>
    </div>

    <script>
        const APPOINTMENT_ID = @json($appointment->id);
        const CSRF = '{{ csrf_token() }}';
        const API = `/doctor/api/appointments/${APPOINTMENT_ID}`;

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

        let pc = null;
        let localStream = null;
        let lastSignalId = 0;
        let queuedCandidates = [];
        let pollTimer = null;
        let ended = false;

        const statusBox = document.getElementById('status');
        const statusText = document.getElementById('statusText');
        const statusSpinner = document.getElementById('statusSpinner');
        const recallBtn = document.getElementById('recall');

        function setStatus(text, { spinner = true, recall = false, hidden = false } = {}) {
            statusBox.classList.toggle('hidden', hidden);
            statusText.textContent = text;
            statusSpinner.style.display = spinner ? 'block' : 'none';
            recallBtn.style.display = recall ? 'block' : 'none';
        }

        async function api(path, options = {}) {
            const res = await fetch(path, {
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                ...options,
            });
            return res.json();
        }

        function sendSignal(type, payload = null) {
            return api(`${API}/video-signals`, {
                method: 'POST',
                body: JSON.stringify({ type, payload }),
            });
        }

        async function init() {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                });
            } catch (e) {
                setStatus('No se pudo acceder a la cámara o micrófono. Revisa los permisos del navegador.', { spinner: false });
                return;
            }
            document.getElementById('localVideo').srcObject = localStream;

            const config = await api(`${API}/webrtc-config`);
            window.iceServers = config.ice_servers ?? [];

            await startOffer();
            pollTimer = setInterval(poll, 900);
        }

        async function startOffer() {
            ended = false;
            queuedCandidates = [];
            if (pc) { try { pc.close(); } catch (e) {} }

            pc = new RTCPeerConnection({ iceServers: window.iceServers });
            localStream.getTracks().forEach(t => pc.addTrack(t, localStream));

            pc.onicecandidate = (e) => {
                if (e.candidate) {
                    sendSignal('candidate', {
                        candidate: e.candidate.candidate,
                        sdpMid: e.candidate.sdpMid,
                        sdpMLineIndex: e.candidate.sdpMLineIndex,
                    });
                }
            };
            pc.ontrack = (e) => {
                if (e.streams.length) {
                    document.getElementById('remoteVideo').srcObject = e.streams[0];
                    setStatus('', { hidden: true });
                }
            };
            pc.onconnectionstatechange = () => {
                if (ended) return;
                if (pc.connectionState === 'connected') {
                    setStatus('', { hidden: true });
                } else if (['disconnected', 'failed'].includes(pc.connectionState)) {
                    setStatus('Conexión perdida. Esperando reconexión…');
                }
            };

            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            // Posting an offer clears previous signals server-side: fresh session
            await sendSignal('offer', { sdp: offer.sdp });
            setStatus('Llamando al paciente…');
        }

        async function poll() {
            if (ended) return;
            let data;
            try {
                data = await api(`${API}/video-signals?after=${lastSignalId}`);
            } catch (e) { return; }

            for (const s of (data.signals ?? [])) {
                lastSignalId = Math.max(lastSignalId, s.id);

                if (s.type === 'ready') {
                    // Patient (re)joined: start a fresh session
                    await startOffer();
                } else if (s.type === 'answer' && pc && pc.signalingState === 'have-local-offer') {
                    await pc.setRemoteDescription({ type: 'answer', sdp: s.payload.sdp });
                    for (const c of queuedCandidates) { try { await pc.addIceCandidate(c); } catch (e) {} }
                    queuedCandidates = [];
                    setStatus('Conectando video…');
                } else if (s.type === 'candidate' && s.payload) {
                    const candidate = new RTCIceCandidate(s.payload);
                    if (pc && pc.remoteDescription) {
                        try { await pc.addIceCandidate(candidate); } catch (e) {}
                    } else {
                        queuedCandidates.push(candidate);
                    }
                } else if (s.type === 'hangup') {
                    ended = true;
                    document.getElementById('remoteVideo').srcObject = null;
                    setStatus('El paciente finalizó la llamada.', { spinner: false, recall: true });
                }
            }
        }

        function toggleMic() {
            const track = localStream?.getAudioTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            document.getElementById('micBtn').classList.toggle('off', !track.enabled);
        }

        function toggleCam() {
            const track = localStream?.getVideoTracks()[0];
            if (!track) return;
            track.enabled = !track.enabled;
            document.getElementById('camBtn').classList.toggle('off', !track.enabled);
        }

        async function hangUp() {
            ended = true;
            try { await sendSignal('hangup'); } catch (e) {}
            if (pollTimer) clearInterval(pollTimer);
            if (pc) { try { pc.close(); } catch (e) {} }
            localStream?.getTracks().forEach(t => t.stop());
            window.location.href = '/doctor/agenda';
        }

        window.addEventListener('pagehide', () => {
            if (ended) return;
            fetch(`${API}/video-signals`, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ type: 'hangup' }),
            });
        });

        init();
    </script>
</body>
</html>
