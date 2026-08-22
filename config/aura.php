<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retención de la plataforma
    |--------------------------------------------------------------------------
    |
    | Comisión por defecto en puntos base (1250 = 12,5 %). Es entero a
    | propósito: el cálculo de dinero se hace en pesos enteros y con enteros,
    | de modo que la suma de lo retenido más lo dispersado siempre cuadra con
    | lo cobrado.
    |
    | Un prestador puede tener su propia tasa en `professionals.commission_bps`
    | —así se pueden premiar las buenas calificaciones sin tocar código—; si es
    | nula se aplica esta.
    |
    */

    'commission_bps' => (int) env('AURA_COMMISSION_BPS', 1250),

    /*
    |--------------------------------------------------------------------------
    | Recargo de plataforma al paciente
    |--------------------------------------------------------------------------
    |
    | Lo que se suma al precio de catalogo para llegar al importe que paga el
    | paciente. No es lo mismo que `commission_bps`, que es lo que la plataforma
    | RETIENE de lo recaudado: uno mira al paciente y el otro al prestador.
    |
    | Vivia como `_commissionRate = 0.15` en el cliente Flutter, declarado bajo
    | el comentario "Simulator Parameters". Es decir: el recargo real que pagaba
    | la gente era un parametro de simulador cableado en la app, y cambiar la
    | retencion del servidor no lo movia. 1500 = 15 %, el valor que la app venia
    | aplicando, para no alterar lo que se cobra hoy.
    |
    */

    'patient_surcharge_bps' => (int) env('AURA_PATIENT_SURCHARGE_BPS', 1500),

    /*
    |--------------------------------------------------------------------------
    | Traslados
    |--------------------------------------------------------------------------
    |
    | La ambulancia medicalizada es la unica variante con precio propio y no
    | tiene fila en `clinical_services`: existia solo como constante en el
    | formulario de la app. Queda aqui hasta que el catalogo sepa representar
    | variantes de un mismo servicio.
    |
    */

    'ambulance' => [
        'medicalized_price' => (int) env('AURA_AMBULANCE_MEDICALIZED_PRICE', 28500),
    ],

    'transport' => [
        'base_fee_basic' => (int) env('AURA_TRANSPORT_BASE_FEE_BASIC', 18500),
        'base_fee_medicalized' => (int) env('AURA_TRANSPORT_BASE_FEE_MEDICALIZED', 28500),
        'price_per_km' => (int) env('AURA_TRANSPORT_PRICE_PER_KM', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laboratorio
    |--------------------------------------------------------------------------
    */

    'lab' => [
        // Días hacia adelante que el paciente puede agendar una toma.
        'max_days_ahead' => (int) env('AURA_LAB_MAX_DAYS_AHEAD', 30),

        // Antelación mínima entre "ahora" y el inicio del cupo. Una toma de
        // muestras exige preparación (ayuno, insumos, ruta), así que no se
        // ofrece un bloque que empieza en diez minutos.
        'min_notice_minutes' => (int) env('AURA_LAB_MIN_NOTICE_MINUTES', 120),

        // Ventana previa al cupo en la que la solicitud programada pasa a ser
        // la atención "activa" de la app y aparece en seguimiento.
        'activation_window_minutes' => (int) env('AURA_LAB_ACTIVATION_WINDOW_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seguimiento en vivo (SSE)
    |--------------------------------------------------------------------------
    |
    | `/api/bookings/{id}/sse` mantiene la petición abierta hasta 50 segundos.
    | Con un servidor de un solo proceso —`php artisan serve` sin workers— eso
    | congela TODO el backend mientras dure: el portal no carga, el mensaje del
    | profesional no se guarda y la app agota su propio timeout. Poner esto en
    | false devuelve 204 al instante; la app ya no depende del stream porque
    | consulta el hilo y el estado por su cuenta.
    |
    | Solo tiene sentido dejarlo activado si el servidor atiende varias
    | peticiones a la vez (PHP_CLI_SERVER_WORKERS, php-fpm, FrankenPHP...).
    |
    */

    'sse' => [
        'enabled' => filter_var(env('AURA_SSE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Cuánto sostiene el proceso cada conexión antes de cerrarla para que
        // el cliente vuelva a abrirla.
        'max_seconds' => (int) env('AURA_SSE_MAX_SECONDS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento público / Medios persistentes (P1.2)
    |--------------------------------------------------------------------------
    |
    | 'public' para disco local o 's3' para Cloudflare R2 / AWS S3 / Backblaze B2.
    |
    */

    'media' => [
        'public_disk' => env('AURA_PUBLIC_DISK', 'public'),
    ],

];
