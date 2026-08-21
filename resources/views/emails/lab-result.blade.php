@component('mail::message')
# Sus resultados están listos

Hola {{ $patientName }},

Se encuentra disponible su informe **{{ $result->title }}** correspondiente a su toma de muestras a domicilio.

@if($examRequired)
**Exámenes solicitados:** {{ $examRequired }}
@endif

@if($result->notes)
**Observaciones del laboratorio:** {{ $result->notes }}
@endif

@if(!empty($downloadUrl))
@component('mail::button', ['url' => $downloadUrl])
Ver y Descargar Informe
@endcomponent
@endif

También puede consultarlo cuando desee desde la sección **Mis Exámenes** en la aplicación de Aura Salud.

Estos resultados son un documento clínico y no reemplazan la interpretación de un profesional. Si tiene dudas sobre lo que indican, agende una consulta para revisarlos con un médico.

Gracias por confiar en nosotros,<br>
Equipo de {{ config('app.name') }}
@endcomponent
