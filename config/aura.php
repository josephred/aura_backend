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

];
