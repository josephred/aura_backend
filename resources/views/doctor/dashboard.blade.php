<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AURA Salud — Portal Médico</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
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
            --primary-glow: rgba(13, 148, 136, 0.15);
            --secondary-glow: rgba(45, 212, 191, 0.15);
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.06);
            --card-border-hover: rgba(13, 148, 136, 0.4);
            --text-primary: #F8FAFC;
            --text-secondary: #94A3B8;
            --accent-teal: #0D9488;
            --accent-teal-glow: #0F766E;
            --accent-teal-light: #2DD4BF;
            --status-pending: #F59E0B;
            --status-accepted: #3B82F6;
            --status-en-camino: #8B5CF6;
            --status-atencion: #EC4899;
            --status-completed: #10B981;
            --status-cancelled: #EF4444;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
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
            right: 10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--secondary-glow) 0%, transparent 70%);
            animation: pulse-glow 12s infinite alternate-reverse;
        }

        @keyframes pulse-glow {
            0% { transform: scale(1) translate(0, 0); opacity: 0.5; }
            100% { transform: scale(1.2) translate(30px, -30px); opacity: 0.8; }
        }

        /* Layout */
        header {
            height: 80px;
            border-bottom: 1px solid var(--card-border);
            backdrop-filter: blur(20px);
            background-color: rgba(11, 15, 25, 0.7);
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
            fill: white;
            width: 22px;
            height: 22px;
        }

        .logo-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #FFF, #CBD5E1);
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
            gap: 12px;
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
            color: white;
        }

        main {
            flex: 1;
            padding: 32px 40px;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 24px;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
        }

        /* Stats Section */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
        }

        .stat-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-primary);
        }

        .stat-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Main Workspace Grid */
        .workspace {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 24px;
            height: calc(100vh - 270px);
            min-height: 550px;
        }

        /* Left Side: Feed List */
        .feed-container {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .feed-header {
            padding: 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .feed-title {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: var(--status-pending);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--status-pending);
            animation: pulse 1.5s infinite alternate;
        }

        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 0.6; }
            100% { transform: scale(1.2); opacity: 1; box-shadow: 0 0 14px var(--status-pending); }
        }

        .feed-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Booking Cards */
        .booking-card {
            background-color: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .booking-card:hover {
            background-color: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateX(4px);
        }

        .booking-card.active {
            border-color: var(--accent-teal);
            background-color: rgba(13, 148, 136, 0.03);
            box-shadow: 0 0 15px rgba(13, 148, 136, 0.1);
        }

        .booking-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: transparent;
        }

        .booking-card.pending::before { background-color: var(--status-pending); }
        .booking-card.accepted::before { background-color: var(--status-accepted); }
        .booking-card.en_camino::before { background-color: var(--status-en-camino); }
        .booking-card.en_atencion::before { background-color: var(--status-atencion); }
        .booking-card.completed::before { background-color: var(--status-completed); }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .service-badge {
            font-size: 11px;
            font-weight: 700;
            color: var(--accent-teal-light);
            background-color: rgba(13, 148, 136, 0.12);
            padding: 3px 8px;
            border-radius: 6px;
        }

        .status-badge {
            font-size: 9px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.pending { background-color: rgba(245, 158, 11, 0.12); color: var(--status-pending); }
        .status-badge.accepted { background-color: rgba(59, 130, 246, 0.12); color: var(--status-accepted); }
        .status-badge.en_camino { background-color: rgba(139, 92, 246, 0.12); color: var(--status-en-camino); }
        .status-badge.en_atencion { background-color: rgba(236, 72, 153, 0.12); color: var(--status-atencion); }
        .status-badge.completed { background-color: rgba(16, 185, 129, 0.12); color: var(--status-completed); }
        .status-badge.cancelled { background-color: rgba(239, 68, 68, 0.12); color: var(--status-cancelled); }

        .card-patient {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .card-address {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-time {
            font-size: 11px;
            color: var(--accent-teal-light);
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Right Side: Detail Workspace */
        .detail-container {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .empty-detail {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            color: var(--text-secondary);
            padding: 40px;
            text-align: center;
        }

        .empty-detail svg {
            width: 64px;
            height: 64px;
            stroke: var(--text-secondary);
            opacity: 0.3;
        }

        .detail-content {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 380px;
            overflow: hidden;
        }

        /* Left Column of Detail: Workflow & Maps */
        .workspace-left {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            overflow-y: auto;
            border-right: 1px solid var(--card-border);
        }

        .patient-banner {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0.005));
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .banner-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .banner-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--accent-teal-light);
            font-weight: 700;
        }

        .banner-name {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-primary);
        }

        .banner-details {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .banner-price {
            text-align: right;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .price-label {
            font-size: 11px;
            color: var(--text-secondary);
        }

        .price-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--status-completed);
        }

        /* Timeline / Workflow status steps */
        .workflow-section {
            background-color: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 20px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .timeline {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 24px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            top: 14px;
            left: 20px;
            right: 20px;
            height: 3px;
            background-color: var(--card-border);
            z-index: 1;
        }

        .timeline-progress {
            position: absolute;
            top: 14px;
            left: 20px;
            height: 3px;
            background: linear-gradient(to right, var(--accent-teal), var(--accent-teal-light));
            z-index: 2;
            width: 0%;
            transition: width 0.4s ease;
        }

        .timeline-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            z-index: 3;
            width: 80px;
            text-align: center;
        }

        .step-bubble {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--bg-color);
            border: 2px solid var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }

        .timeline-step.active .step-bubble {
            border-color: var(--accent-teal-light);
            background-color: var(--accent-teal);
            color: white;
            box-shadow: 0 0 10px rgba(13, 148, 136, 0.4);
        }

        .timeline-step.completed .step-bubble {
            border-color: var(--status-completed);
            background-color: var(--status-completed);
            color: white;
        }

        .step-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .timeline-step.active .step-label {
            color: var(--text-primary);
        }

        /* Action Buttons Area */
        .actions-area {
            display: flex;
            gap: 12px;
        }

        .btn {
            flex: 1;
            height: 48px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-teal-light));
            color: white;
            box-shadow: 0 4px 15px rgba(13, 148, 136, 0.25);
        }

        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.4);
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.25);
        }

        .btn-success:hover {
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.08);
        }

        .btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Map / Tracking Simulator */
        .map-section {
            background-color: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 20px;
            flex: 1;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .map-canvas-simulator {
            flex: 1;
            background-color: #121824;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Map visual grid effects */
        .map-grid {
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 0),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 0),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 0);
            background-size: 20px 20px, 40px 40px, 40px 40px;
            z-index: 1;
        }

        /* Route drawing */
        .map-route-line {
            position: absolute;
            width: 70%;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            z-index: 2;
        }

        .map-route-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(to right, var(--accent-teal), var(--accent-teal-light));
            border-radius: 2px;
            box-shadow: 0 0 10px var(--accent-teal-light);
            transition: width 1s linear;
        }

        .map-pin {
            position: absolute;
            z-index: 3;
            transform: translate(-50%, -50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .map-pin-origin {
            left: 15%;
            top: 50%;
        }

        .map-pin-destination {
            left: 85%;
            top: 50%;
        }

        .map-pin-doctor {
            left: 15%;
            top: 50%;
            z-index: 4;
            transition: left 1s linear;
        }

        .pin-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
        }

        .map-pin-origin .pin-dot { background-color: var(--status-accepted); }
        .map-pin-destination .pin-dot { background-color: var(--status-atencion); }
        .map-pin-doctor .pin-dot { 
            background-color: var(--accent-teal-light); 
            box-shadow: 0 0 15px var(--accent-teal-light);
            width: 18px;
            height: 18px;
            animation: pulse-doctor 1s infinite alternate;
        }

        @keyframes pulse-doctor {
            0% { transform: scale(0.9); }
            100% { transform: scale(1.1); box-shadow: 0 0 20px var(--accent-teal-light); }
        }

        .pin-label {
            font-size: 9px;
            font-weight: 700;
            background-color: rgba(11, 15, 25, 0.85);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid var(--card-border);
            white-space: nowrap;
        }

        .map-info-text {
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
        }

        /* Right Column of Detail: Chat Widget */
        .workspace-right {
            display: flex;
            flex-direction: column;
            background-color: rgba(255, 255, 255, 0.005);
            overflow: hidden;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-header-title {
            font-size: 13px;
            font-weight: 700;
        }

        .chat-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chat-bubble {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 12px;
            line-height: 1.4;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .chat-bubble.provider {
            background: linear-gradient(135deg, var(--accent-teal-glow), var(--accent-teal));
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .chat-bubble.patient {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
            border: 1px solid var(--card-border);
        }

        .chat-bubble.system {
            background-color: rgba(255, 255, 255, 0.02);
            color: var(--text-secondary);
            align-self: center;
            text-align: center;
            font-size: 10px;
            border-radius: 10px;
            border: 1px dashed var(--card-border);
            padding: 6px 12px;
            max-width: 90%;
        }

        .chat-time {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.5);
            align-self: flex-end;
        }

        .chat-input-area {
            padding: 16px;
            border-top: 1px solid var(--card-border);
            display: flex;
            gap: 8px;
        }

        .chat-input {
            flex: 1;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 0 14px;
            color: white;
            font-size: 12px;
            outline: none;
            transition: border-color 0.25s ease;
        }

        .chat-input:focus {
            border-color: var(--accent-teal);
        }

        .chat-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background-color: var(--accent-teal);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.25s ease;
        }

        .chat-send-btn:hover {
            background-color: var(--accent-teal-light);
        }

        .chat-send-btn svg {
            fill: white;
            width: 16px;
            height: 16px;
        }
    </style>
</head>
<body>

    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <header>
        <div class="logo-section">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
            </div>
            <div class="logo-title">AURA Salud</div>
            <div class="logo-badge">Portal Médico</div>
        </div>
        <div class="doctor-profile">
            <a href="/doctor/agenda" style="color:#2DD4BF;text-decoration:none;font-weight:600;font-size:14px;margin-right:20px;">📅 Agenda de citas</a>
            <div class="profile-info">
                <div class="profile-name">Dr. Sebastián Leyton</div>
                <div class="profile-role">Médico General de Guardia</div>
            </div>
            <div class="profile-avatar">SL</div>
        </div>
    </header>

    <main>
        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Solicitudes Pendientes</span>
                    <span class="stat-value" id="stats-pending">0</span>
                </div>
                <div class="stat-icon-wrapper" style="background-color: rgba(245, 158, 11, 0.12); color: var(--status-pending);">
                    🔔
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Trayectos Activos</span>
                    <span class="stat-value" id="stats-traveling">0</span>
                </div>
                <div class="stat-icon-wrapper" style="background-color: rgba(139, 92, 246, 0.12); color: var(--status-en-camino);">
                    🚗
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">En Consulta</span>
                    <span class="stat-value" id="stats-active">0</span>
                </div>
                <div class="stat-icon-wrapper" style="background-color: rgba(236, 72, 153, 0.12); color: var(--status-atencion);">
                    🩺
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Atenciones Completadas</span>
                    <span class="stat-value" id="stats-completed">0</span>
                </div>
                <div class="stat-icon-wrapper" style="background-color: rgba(16, 185, 129, 0.12); color: var(--status-completed);">
                    ✅
                </div>
            </div>
        </div>

        <!-- Main Workspace -->
        <div class="workspace">
            <!-- Left Side Feed -->
            <div class="feed-container">
                <div class="feed-header">
                    <div class="feed-title">
                        <div class="pulse-dot"></div>
                        Bandeja de Solicitudes
                    </div>
                </div>
                <div class="feed-scroll" id="booking-list">
                    <!-- Dynamic List Items -->
                </div>
            </div>

            <!-- Right Side Workspace -->
            <div class="detail-container">
                <div class="empty-detail" id="empty-detail-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                    <p>Selecciona una solicitud médica de la bandeja de entrada para iniciar la atención clínica o revisar los detalles.</p>
                </div>

                <div class="detail-content" id="detail-workspace-state" style="display: none;">
                    <!-- Left Column of Workspace: Progress & Action -->
                    <div class="workspace-left">
                        <div class="patient-banner">
                            <div class="banner-info">
                                <span class="banner-label" id="patient-service-title">Atención Médica</span>
                                <span class="banner-name" id="patient-display-name">Paciente</span>
                                <div class="banner-details">
                                    <span id="patient-address">📍 Dirección</span>
                                    <span id="patient-symptoms">📝 Síntomas</span>
                                </div>
                            </div>
                            <div class="banner-price">
                                <span class="price-label">Valor Final</span>
                                <span class="price-value" id="patient-price">$0</span>
                            </div>
                        </div>

                        <!-- Timeline -->
                        <div class="workflow-section">
                            <div class="section-title">
                                📊 Estado del Flujo Clínico
                            </div>
                            <div class="timeline">
                                <div class="timeline-progress" id="timeline-progress-bar"></div>
                                <div class="timeline-step" id="step-0">
                                    <div class="step-bubble">0</div>
                                    <span class="step-label">Solicitado</span>
                                </div>
                                <div class="timeline-step" id="step-1">
                                    <div class="step-bubble">1</div>
                                    <span class="step-label">Asignado</span>
                                </div>
                                <div class="timeline-step" id="step-2">
                                    <div class="step-bubble">2</div>
                                    <span class="step-label">En Camino</span>
                                </div>
                                <div class="timeline-step" id="step-3">
                                    <div class="step-bubble">3</div>
                                    <span class="step-label">En Atención</span>
                                </div>
                                <div class="timeline-step" id="step-4">
                                    <div class="step-bubble">4</div>
                                    <span class="step-label">Completado</span>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="actions-area" id="actions-button-wrapper">
                                <!-- Triggered Dynamically -->
                            </div>
                        </div>

                        <!-- Travel / Map Simulation -->
                        <div class="map-section" id="map-section-wrapper" style="display: none;">
                            <div class="section-title">
                                🚗 Seguimiento en Ruta
                            </div>
                            <div class="map-canvas-simulator">
                                <div class="map-grid"></div>
                                <div class="map-route-line">
                                    <div class="map-route-fill" id="map-route-fill-bar"></div>
                                </div>
                                <div class="map-pin map-pin-origin">
                                    <div class="pin-dot"></div>
                                    <span class="pin-label">Clínica</span>
                                </div>
                                <div class="map-pin map-pin-doctor" id="map-doctor-pin">
                                    <div class="pin-dot"></div>
                                    <span class="pin-label">Móvil Médico</span>
                                </div>
                                <div class="map-pin map-pin-destination">
                                    <div class="pin-dot"></div>
                                    <span class="pin-label">Paciente</span>
                                </div>
                            </div>
                            <div class="map-info-text">
                                <span>Distancia restante: <strong id="map-dist-text">4.2 km</strong></span>
                                <span>Tiempo de arribo estimado: <strong id="map-time-text">12 min</strong></span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column of Workspace: Chat Widget -->
                    <div class="workspace-right">
                        <div class="chat-header">
                            💬 Canal de Comunicación Directa
                        </div>
                        <div class="chat-scroll" id="chat-messages-box">
                            <!-- Dynamic chat bubbles -->
                        </div>
                        <div class="chat-input-area">
                            <input type="text" class="chat-input" id="chat-text-input" placeholder="Escribe un mensaje al paciente..." onkeypress="handleChatKeyPress(event)">
                            <button class="chat-send-btn" onclick="sendChatMessage()">
                                <svg viewBox="0 0 24 24">
                                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        let bookings = [];
        let selectedBookingId = null;
        let chatPollTimer = null;
        let mapSimInterval = null;

        // Auto Refresh bookings every 4 seconds
        setInterval(fetchBookings, 4000);
        fetchBookings();

        function fetchBookings() {
            fetch('/doctor/api/bookings')
                .then(res => res.json())
                .then(data => {
                    bookings = data;
                    renderStats();
                    renderBookingList();
                    if (selectedBookingId) {
                        const updated = bookings.find(b => b.id === selectedBookingId);
                        if (updated) {
                            updateDetailWorkspace(updated);
                        }
                    }
                })
                .catch(err => console.error("Error fetching bookings:", err));
        }

        function renderStats() {
            let pending = 0;
            let traveling = 0;
            let active = 0;
            let completed = 0;

            bookings.forEach(b => {
                if (b.status === 'pending') pending++;
                else if (b.status === 'accepted') pending++; // assigned is considered accepted
                else if (b.status === 'en_camino') traveling++;
                else if (b.status === 'en_atencion') active++;
                else if (b.status === 'completed') completed++;
            });

            document.getElementById('stats-pending').textContent = pending;
            document.getElementById('stats-traveling').textContent = traveling;
            document.getElementById('stats-active').textContent = active;
            document.getElementById('stats-completed').textContent = completed;
        }

        function renderBookingList() {
            const listContainer = document.getElementById('booking-list');
            
            if (bookings.length === 0) {
                listContainer.innerHTML = '<div style="color: var(--text-secondary); text-align: center; padding: 20px; font-size: 13px;">No hay solicitudes activas en este momento.</div>';
                return;
            }

            let html = '';
            bookings.forEach(b => {
                const isActive = b.id === selectedBookingId ? 'active' : '';
                const serviceName = b.service ? b.service.title : 'Atención Médica';
                
                let displayName = b.user ? b.user.name : 'Paciente';
                if (b.patient_type === 'dependent' && b.dependent) {
                    displayName = b.dependent.name + ` (${translateRelationship(b.dependent.relationship)})`;
                }

                const shortAddress = b.address_text || 'Dirección no especificada';
                const createdTime = b.start_time || 'Ahora';

                html += `
                    <div class="booking-card ${b.status} ${isActive}" onclick="selectBooking('${b.id}')">
                        <div class="card-top">
                            <span class="service-badge">${serviceName}</span>
                            <span class="status-badge ${b.status}">${translateStatus(b.status)}</span>
                        </div>
                        <div class="card-patient">${displayName}</div>
                        <div class="card-address">📍 ${shortAddress}</div>
                        <div class="card-time">⏰ Solicitud: ${createdTime}</div>
                    </div>
                `;
            });
            listContainer.innerHTML = html;
        }

        function selectBooking(id) {
            selectedBookingId = id;
            
            // Highlight selected card visually immediately
            renderBookingList();

            const booking = bookings.find(b => b.id === id);
            if (!booking) return;

            document.getElementById('empty-detail-state').style.display = 'none';
            document.getElementById('detail-workspace-state').style.display = 'grid';

            updateDetailWorkspace(booking);

            // Fetch and set chat
            clearInterval(chatPollTimer);
            fetchChatMessages();
            chatPollTimer = setInterval(fetchChatMessages, 2000);
        }

        function updateDetailWorkspace(b) {
            const serviceName = b.service ? b.service.title : 'Atención Médica';
            let displayName = b.user ? b.user.name : 'Paciente';
            if (b.patient_type === 'dependent' && b.dependent) {
                displayName = b.dependent.name + ` (${translateRelationship(b.dependent.relationship)})`;
            }

            document.getElementById('patient-service-title').textContent = serviceName;
            document.getElementById('patient-display-name').textContent = displayName;
            document.getElementById('patient-address').textContent = '📍 ' + b.address_text;
            document.getElementById('patient-symptoms').textContent = '📝 Sintomas: ' + (b.symptoms_description || 'Sin comentarios adicionales');
            document.getElementById('patient-price').textContent = '$' + b.final_price.toLocaleString('es-CL');

            // Timeline steps update
            const steps = [0, 1, 2, 3, 4];
            let currentStepIndex = 0; // pending

            if (b.status === 'accepted') currentStepIndex = 1;
            else if (b.status === 'en_camino') currentStepIndex = 2;
            else if (b.status === 'en_atencion') currentStepIndex = 3;
            else if (b.status === 'completed') currentStepIndex = 4;

            steps.forEach(s => {
                const el = document.getElementById(`step-${s}`);
                if (!el) return;
                el.classList.remove('active', 'completed');
                
                if (s === currentStepIndex) {
                    el.classList.add('active');
                } else if (s < currentStepIndex) {
                    el.classList.add('completed');
                }
            });

            // Timeline progress bar
            const progressBar = document.getElementById('timeline-progress-bar');
            if (currentStepIndex === 0) progressBar.style.width = '0%';
            else if (currentStepIndex === 1) progressBar.style.width = '25%';
            else if (currentStepIndex === 2) progressBar.style.width = '50%';
            else if (currentStepIndex === 3) progressBar.style.width = '75%';
            else if (currentStepIndex === 4) progressBar.style.width = '100%';

            // Action Buttons
            const btnWrapper = document.getElementById('actions-button-wrapper');
            let btnHtml = '';

            if (b.status === 'pending') {
                btnHtml = `
                    <button class="btn btn-primary" onclick="changeBookingStatus('${b.id}', 'accepted')">
                        🤝 Aceptar Atención Clínica
                    </button>
                `;
            } else if (b.status === 'accepted') {
                btnHtml = `
                    <button class="btn btn-primary" onclick="changeBookingStatus('${b.id}', 'en_camino')">
                        🚗 Iniciar Ruta de Viaje
                    </button>
                `;
            } else if (b.status === 'en_camino') {
                btnHtml = `
                    <button class="btn btn-primary" onclick="changeBookingStatus('${b.id}', 'en_atencion')">
                        🩺 Llegar e Iniciar Consulta
                    </button>
                `;
            } else if (b.status === 'en_atencion') {
                btnHtml = `
                    <button class="btn btn-success" onclick="changeBookingStatus('${b.id}', 'completed')">
                        ✅ Finalizar Consulta Médica
                    </button>
                `;
            } else if (b.status === 'completed') {
                btnHtml = `
                    <button class="btn btn-secondary" disabled>
                        Atención Finalizada
                    </button>
                `;
            } else if (b.status === 'cancelled') {
                btnHtml = `
                    <button class="btn btn-secondary" style="color: var(--status-cancelled); border-color: var(--status-cancelled);" disabled>
                        Atención Cancelada por el Paciente
                    </button>
                `;
            }

            btnWrapper.innerHTML = btnHtml;

            // Map Simulation setup
            const mapSection = document.getElementById('map-section-wrapper');
            if (b.status === 'en_camino') {
                mapSection.style.display = 'flex';
                startMapSimulation();
            } else {
                mapSection.style.display = 'none';
                clearInterval(mapSimInterval);
            }
        }

        function changeBookingStatus(id, newStatus) {
            fetch(`/doctor/api/bookings/${id}/status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ status: newStatus })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchBookings();
                }
            })
            .catch(err => console.error("Error updating status:", err));
        }

        // Live Chat Fetching
        function fetchChatMessages() {
            if (!selectedBookingId) return;

            fetch(`/doctor/api/bookings/${selectedBookingId}/messages`)
                .then(res => res.json())
                .then(messages => {
                    const chatBox = document.getElementById('chat-messages-box');
                    let html = '';
                    messages.forEach(msg => {
                        html += `
                            <div class="chat-bubble ${msg.sender}">
                                <span>${msg.text}</span>
                                <span class="chat-time">${msg.timestamp}</span>
                            </div>
                        `;
                    });
                    
                    const isAtBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 50;
                    chatBox.innerHTML = html;
                    
                    if (isAtBottom) {
                        chatBox.scrollTop = chatBox.scrollHeight;
                    }
                })
                .catch(err => console.error("Error fetching messages:", err));
        }

        function sendChatMessage() {
            const input = document.getElementById('chat-text-input');
            const text = input.value.trim();
            if (!text || !selectedBookingId) return;

            input.value = '';

            fetch(`/doctor/api/bookings/${selectedBookingId}/messages`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ text: text })
            })
            .then(res => res.json())
            .then(msg => {
                fetchChatMessages();
            })
            .catch(err => console.error("Error sending message:", err));
        }

        function handleChatKeyPress(e) {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        }

        // Map Simulation loop
        function startMapSimulation() {
            clearInterval(mapSimInterval);
            const routeFill = document.getElementById('map-route-fill-bar');
            const doctorPin = document.getElementById('map-doctor-pin');
            const distText = document.getElementById('map-dist-text');
            const timeText = document.getElementById('map-time-text');

            let progress = 15; // starts at 15% (origin pin)
            
            mapSimInterval = setInterval(() => {
                progress += 5;
                if (progress > 85) progress = 85; // end at destination pin

                routeFill.style.width = ((progress - 15) / 70 * 100) + '%';
                doctorPin.style.left = progress + '%';

                const distRemaining = ((85 - progress) / 70 * 4.2).toFixed(1);
                const timeRemaining = Math.ceil((85 - progress) / 70 * 12);

                distText.textContent = distRemaining + ' km';
                timeText.textContent = timeRemaining + ' min';

                if (progress === 85) {
                    clearInterval(mapSimInterval);
                    distText.textContent = 'Llegado';
                    timeText.textContent = '0 min';
                }
            }, 3000);
        }

        // Helper translation functions
        function translateStatus(s) {
            const m = {
                'pending': 'Pendiente',
                'accepted': 'Asignado',
                'en_camino': 'En Camino',
                'en_atencion': 'En Consulta',
                'completed': 'Completado',
                'cancelled': 'Cancelado'
            };
            return m[s] || s;
        }

        function translateRelationship(r) {
            const m = {
                'spouse': 'Cónyuge',
                'child': 'Hijo/a',
                'parent': 'Padre/Madre',
                'sibling': 'Hermano/a',
                'other': 'Familiar'
            };
            return m[r] || r;
        }
    </script>
</body>
</html>
