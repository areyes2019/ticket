<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje de una conversación (041): del cliente (entrante), enviado por un
 * agente o un usuario (saliente), o interno (uso de herramienta, aviso del
 * sistema). wamid es único: un aviso repetido de Meta no se guarda dos veces
 * (adición 3).
 */
#[Fillable([
    'wamid',
    'direccion',
    'autor',
    'user_id',
    'tipo',
    'texto',
    'archivo_ruta',
    'archivo_nombre',
    'archivo_mime',
    'datos',
    'estado_envio',
    'error_envio',
    'respondido',
    'enviado_en',
])]
class WhatsappMensaje extends Model
{
    protected $table = 'whatsapp_mensajes';

    public const ENTRANTE = 'entrante';

    public const SALIENTE = 'saliente';

    public const INTERNO = 'interno';

    /**
     * Tipos que se le pasan al agente; los demás reciben el aviso de la regla 18.
     */
    public const TIPOS_LEGIBLES = ['texto', 'imagen', 'documento'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'respondido' => false,
    ];

    /**
     * @return BelongsTo<WhatsappConversacion, $this>
     */
    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversacion::class, 'whatsapp_conversacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esDelCliente(): bool
    {
        return $this->direccion === self::ENTRANTE;
    }

    public function esHerramienta(): bool
    {
        return $this->tipo === 'herramienta';
    }

    public function tieneArchivo(): bool
    {
        return $this->archivo_ruta !== null;
    }

    public function esImagen(): bool
    {
        return $this->tieneArchivo() && str_starts_with((string) $this->archivo_mime, 'image/');
    }

    public function autorTexto(): string
    {
        return match ($this->autor) {
            'cliente' => 'Cliente',
            'ventas' => 'Agente de ventas',
            'facturacion' => 'Agente de facturación',
            'usuario' => $this->user?->name ?? 'Usuario',
            default => 'Sistema',
        };
    }

    public function estadoEnvioTexto(): ?string
    {
        return match ($this->estado_envio) {
            'enviado' => 'Enviado',
            'entregado' => 'Entregado',
            'leido' => 'Leído',
            'fallido' => 'No entregado'.($this->error_envio ? ': '.$this->error_envio : ''),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'respondido' => 'boolean',
            'enviado_en' => 'immutable_datetime',
        ];
    }
}
