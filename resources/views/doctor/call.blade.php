<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Videoconsulta — AURA Salud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #0B0F19; color: #F8FAFC; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 20px; border-bottom: 1px solid rgba(255,255,255,.06); flex-shrink: 0;
        }
        .title { font-size: 15px; font-weight: 700; }
        .title span { color: #2DD4BF; }
        .patient { font-size: 13px; color: #94A3B8; }
        .stage { position: relative; flex: 1; background: #05070D; }
        #remoteVideo { width: 100%; height: 100%; object-fit: contain; }
        #localVideo {
            position: absolute; bottom: 18px; right: 18px; width: 200px; aspect-ratio: 4/3;
            object-fit: cover; border-radius: 12px; border: 1px solid rgba(255,255,255,.15);
            background: #0B0F19; transform: scaleX(-1);
        }
        #status {
            position: absolute; inset: 0; display: flex; flex-direction: column; gap: 14px;
            align-items: center; justify-content: center; background: rgba(11,15,25,.85);
            font-size: 15px; color: #94A3B8; text-align: center; padding: 20px;
        }
        #status.hidden { display: none; }
        .spinner {
            width: 34px; height: 34px; border-radius: 50%;
            border: 3px solid rgba(45,212,191,.25); border-top-color: #2DD4BF;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .controls {
            display: flex; justify-content: center; gap: 14px; padding: 14px; flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,.06);
        }
        .controls button {
            width: 52px; height: 52px; border-radius: 50%; border: none; cursor: pointer;
            font-size: 20px; background: rgba(255,255,255,.08); color: #F8FAFC;
        }
        .controls button.off { background: #475569; }
        .controls button.hang { background: #EF4444; }
        .controls button:hover { filter: brightness(1.2); }
        #recall {
            background: #0D9488; border: none; color: white; font-weight: 700;
            padding: 11px 22px; border-radius: 10px; cursor: pointer; font-size: 14px;
        }
    </style>
</head>
<body>
    <header>
        <div class="title">AURA <span>Videoconsulta</span></div>
        <div class="patient">Paciente: {{ $patientName }} · {{ $appointment->scheduled_at->format('H:i') }} hrs</div>
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
