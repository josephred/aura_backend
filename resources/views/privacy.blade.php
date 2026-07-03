<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Políticas de Privacidad - AURA Salud</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #0D9488;
            --brand-dark: #115E59;
            --brand-light: #F0FDFA;
            --text-main: #1E293B;
            --text-muted: #64748B;
            --bg-color: #F8FAFC;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            line-height: 1.6;
            padding: 40px 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 50px 40px;
            border-radius: 24px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05), 0 20px 25px -5px rgb(0 0 0 / 0.02);
            border: 1px solid #F1F5F9;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 2px solid var(--brand-light);
            padding-bottom: 30px;
        }

        .logo {
            font-size: 32px;
            font-weight: 700;
            color: var(--brand-primary);
            margin-bottom: 10px;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .logo::before {
            content: "";
            display: inline-block;
            width: 14px;
            height: 14px;
            background-color: var(--brand-primary);
            border-radius: 50px;
            box-shadow: 0 0 12px var(--brand-primary);
        }

        h1 {
            font-size: 26px;
            font-weight: 700;
            color: var(--brand-dark);
            margin-bottom: 6px;
        }

        .last-update {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
        }

        h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--brand-dark);
            margin-top: 32px;
            margin-bottom: 12px;
            border-left: 4px solid var(--brand-primary);
            padding-left: 12px;
        }

        p {
            font-size: 15px;
            color: var(--text-main);
            margin-bottom: 16px;
            text-align: justify;
        }

        ul {
            margin-bottom: 16px;
            padding-left: 24px;
        }

        li {
            font-size: 15px;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .highlight {
            background-color: var(--brand-light);
            color: var(--brand-dark);
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: 500;
        }

        .footer {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid #E2E8F0;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
        }

        a {
            color: var(--brand-primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        a:hover {
            color: var(--brand-dark);
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            body {
                padding: 20px 10px;
            }
            .container {
                padding: 30px 20px;
                border-radius: 16px;
            }
            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo">AURA</div>
        <h1>Políticas de Privacidad</h1>
        <div class="last-update">Última actualización: 3 de julio de 2026</div>
    </div>

    <p>En <strong>AURA Salud</strong>, operado por <strong>Aura Salud S.A.</strong> (en adelante, "la Empresa"), nos tomamos muy en serio la privacidad y seguridad de sus datos personales. Esta Política de Privacidad describe cómo recopilamos, utilizamos, almacenamos y protegemos su información cuando utiliza nuestra aplicación móvil y los servicios asociados.</p>

    <p>Al descargar, instalar o utilizar la Aplicación, usted acepta las prácticas descritas en esta política.</p>

    <h2>1. Información que Recopilamos</h2>
    <p>Para proporcionarle servicios clínicos y de salud a domicilio de alta calidad, recopilamos la siguiente información:</p>
    <ul>
        <li><strong>Datos de Registro:</strong> Nombre completo, dirección de correo electrónico, contraseña cifrada y número de teléfono móvil.</li>
        <li><strong>Información de Identidad Social (Opcional):</strong> Si decide iniciar sesión mediante <span class="highlight">Google</span> o <span class="highlight">Facebook</span>, recopilamos su ID único de proveedor, dirección de correo electrónico y nombre público suministrados por el proveedor de autenticación.</li>
        <li><strong>Información de Salud y Clínica:</strong> Descripción de síntomas, antecedentes médicos generales, recetas médicas (incluyendo archivos de imagen o documentos adjuntos) y datos de dependientes (familiares asociados para los cuales solicita atención).</li>
        <li><strong>Datos de Ubicación:</strong> Coordenadas de geolocalización o direcciones ingresadas manualmente para poder coordinar y despachar al especialista clínico o ambulancia hacia su domicilio.</li>
        <li><strong>Información de Pago:</strong> Procesamos los cobros a través de pasarelas de pago integradas (Mercado Pago). Nosotros <strong>no</strong> almacenamos los números completos de sus tarjetas de crédito o débito en nuestros servidores; estos se procesan de forma segura a través de los servidores cifrados de la pasarela.</li>
        <li><strong>Tokens de Dispositivo:</strong> Registramos el identificador único de notificaciones push de su dispositivo (FCM Token) para enviarle actualizaciones en tiempo real sobre el estado de su médico y mensajes de chat.</li>
    </ul>

    <h2>2. Uso de la Información</h2>
    <p>Utilizamos la información recopilada exclusivamente para los siguientes fines:</p>
    <ul>
        <li>Gestionar y agendar sus solicitudes de atención de salud a domicilio.</li>
        <li>Permitir la comunicación por chat en tiempo real entre el paciente y el médico asignado.</li>
        <li>Procesar los pagos de las prestaciones clínicas.</li>
        <li>Brindar soporte técnico y resolver inconvenientes con el servicio.</li>
        <li>Enviar notificaciones sobre el estado de su trayecto, confirmaciones de reserva y alertas del sistema.</li>
        <li>Cumplir con las normativas legales de salud vigentes en relación con el resguardo de información de fichas clínicas.</li>
    </ul>

    <h2>3. Almacenamiento y Protección de Datos</h2>
    <p><strong>Cifrado Local:</strong> Toda la información médica, direcciones, chats e historial de atención almacenada de forma temporal en su dispositivo se encuentra bajo un sistema de base de datos cifrada localmente mediante <span class="highlight">SQLCipher (AES-256)</span>. La clave de cifrado se genera aleatoriamente y se guarda de forma segura en el llavero de hardware del dispositivo (KeyStore en Android / Keychain en iOS).</p>
    <p><strong>Seguridad de Servidor:</strong> Toda la comunicación entre la aplicación móvil y nuestros servidores se realiza mediante protocolos seguros cifrados <strong>HTTPS/TLS</strong>. El token de acceso a la cuenta del usuario se guarda en almacenamiento encriptado seguro en el dispositivo móvil.</p>

    <h2>4. Compartición de Datos con Terceros</h2>
    <p>No vendemos, comercializamos ni transferimos su información personal a terceros, excepto en los siguientes casos necesarios para la operación:</p>
    <ul>
        <li><strong>Especialistas Médicos y Clínicos:</strong> El profesional asignado a su atención tendrá acceso a su nombre, dirección, síntomas y recetas asociadas para poder prestarle la atención de salud de manera adecuada.</li>
        <li><strong>Pasarelas de Pago:</strong> Proveedores como Mercado Pago para completar transacciones financieras.</li>
        <li><strong>Servicios de Notificación:</strong> Google Firebase Cloud Messaging para la entrega de alertas Push en su dispositivo.</li>
    </ul>

    <h2>5. Sus Derechos de Control (Derechos ARCO)</h2>
    <p>Usted tiene derecho a acceder a los datos personales que mantenemos, rectificar o actualizar cualquier información incorrecta, oponerse al procesamiento de ciertas solicitudes o solicitar la eliminación total de su cuenta y sus datos personales de nuestros registros activos cuando lo estime conveniente.</p>
    <p>Para ejercer cualquiera de estos derechos, o si tiene dudas sobre esta Política de Privacidad, puede contactarnos enviando un correo a: <a href="mailto:soporte@aurasalud.app">soporte@aurasalud.app</a>.</p>

    <h2>6. Modificaciones a esta Política</h2>
    <p>Nos reservamos el derecho de actualizar esta política de privacidad en cualquier momento. Le notificaremos cualquier cambio sustancial mediante la publicación de la nueva política dentro de la Aplicación o mediante avisos en nuestro sitio web.</p>

    <div class="footer">
        &copy; 2026 Aura Salud S.A. Todos los derechos reservados.
    </div>
</div>

</body>
</html>
