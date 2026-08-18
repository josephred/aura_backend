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

];
