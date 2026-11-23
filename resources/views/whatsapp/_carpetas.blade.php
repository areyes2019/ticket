{{-- Carpetas de la bandeja de WhatsApp (041, regla 56). --}}
@php
    $iconos = ['humano' => 'person-raised-hand', 'ventas' => 'bag', 'facturacion' => 'receipt', 'cerradas' => 'archive', 'todas' => 'inbox'];
@endphp

<nav class="bandeja-carpetas" id="bandeja-carpetas" aria-label="Carpetas de WhatsApp">
    <ul class="bandeja-opciones">
        @foreach (App\Models\WhatsappConversacion::CARPETAS as $clave => $nombre)
            <li>
                <a href="{{ route('whatsapp.index', array_filter(['carpeta' => $clave === 'todas' ? null : $clave, 'q' => $texto ?: null])) }}"
                   @class(['bandeja-opcion', 'bandeja-opcion-activa' => $carpeta === $clave]) @if ($carpeta === $clave) aria-current="true" @endif>
                    <x-icono :nombre="$iconos[$clave]" />
                    <span class="bandeja-opcion-nombre">{{ $nombre }}</span>
                    <span @class(['bandeja-contador', 'wa-contador-humano' => $clave === 'humano']) @if ($contadores[$clave] === 0) hidden @endif>{{ $contadores[$clave] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
