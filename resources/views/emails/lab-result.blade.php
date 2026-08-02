@component('mail::message')
# Sus resultados están listos

Hola {{ $patientName }},

Adjuntamos el informe **{{ $result->title }}** correspondiente a su toma de muestras a domicilio.

@if($examRequired)
**Exámenes solicitados:** {{ $examRequired }}
@endif

@if($result->notes)
**Observaciones del laboratorio:** {{ $result->notes }}
@endif

También puede descargarlo cuando quiera desde **Mis Exámenes**, en la aplicación de Aura Salud.

Estos resultados son un documento clínico y no reemplazan la interpretación de un profesional. Si tiene dudas sobre lo que indican, agende una consulta para revisarlos con un médico.

Gracias por confiar en nosotros,<br>
Equipo de {{ config('app.name') }}
@endcomponent
