@php
    // Chats en manos de humano con mensajes del cliente sin respuesta (041, regla 62).
    // Consulta directa (no la relación del usuario): el mismo número de consultas en cada petición.
    $total = ! App\Models\WhatsappNumero::where('user_id', auth()->id())->exists()
        ? null
        : App\Models\WhatsappConversacion::where('user_id', auth()->id())
            ->where('estado', App\Enums\EstadoConversacion::Humano->value)
            ->where('pendientes_humano', '>', 0)
            ->count();
@endphp

@if ($total !== null)
    <a href="{{ route('whatsapp.index', ['carpeta' => 'humano']) }}" @class(['ventas-hoy', 'whatsapp-pendientes', 'ventas-hoy-cero' => $total === 0])
       title="WhatsApp: chats que requieren humano" aria-label="WhatsApp: {{ $total }} chats requieren humano">
        <x-icono nombre="chat-dots" />
        <span class="ventas-hoy-numero" aria-hidden="true">{{ $total > 99 ? '99+' : $total }}</span>
    </a>
@endif
