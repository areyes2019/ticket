<?php

namespace App\Models;

use App\Enums\AgenteWhatsapp;
use App\Enums\EstadoConversacion;
use App\Services\Whatsapp\Telefono;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una conversación por teléfono y número (041, regla 75). Ninguna columna es
 * asignable en masa: las escriben ReceptorWebhook, OrquestadorTurno y las
 * acciones de la bandeja.
 */
class WhatsappConversacion extends Model
{
    protected $table = 'whatsapp_conversaciones';

    /**
     * Regla 64: la ventana para mensajes libres.
     */
    public const HORAS_VENTANA = 24;

    /**
     * Regla 66: con menos de esto, un chat en manos de humano se marca en rojo.
     */
    public const HORAS_POR_VENCER = 2;

    public const CARPETAS = [
        'humano' => 'Requieren humano',
        'ventas' => 'Ventas',
        'facturacion' => 'Facturación',
        'cerradas' => 'Cerradas',
        'todas' => 'Todas',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'agente',
        'agente_actual' => 'ventas',
        'aviso_humano_enviado' => false,
        'sin_limite' => false,
        'pendientes_humano' => 0,
    ];

    /**
     * @return BelongsTo<WhatsappNumero, $this>
     */
    public function numero(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumero::class, 'whatsapp_numero_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return HasMany<WhatsappMensaje, $this>
     */
    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsappMensaje::class)->orderBy('id');
    }

    /**
     * @return HasMany<Cotizacion, $this>
     */
    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    public function ventanaCierraEl(): ?CarbonImmutable
    {
        return $this->ultimo_mensaje_cliente_en?->addHours(self::HORAS_VENTANA);
    }

    /**
     * Única definición de la ventana de 24 horas (adición 2).
     */
    public function ventanaAbierta(): bool
    {
        $cierre = $this->ventanaCierraEl();

        return $cierre !== null && CarbonImmutable::now()->lessThan($cierre);
    }

    public function ventanaPorVencer(): bool
    {
        return $this->ventanaAbierta() && CarbonImmutable::now()->diffInMinutes($this->ventanaCierraEl()) < self::HORAS_POR_VENCER * 60;
    }

    /**
     * "quedan 3 h", "quedan 25 min" o null con la ventana cerrada.
     */
    public function ventanaRestanteTexto(): ?string
    {
        if (! $this->ventanaAbierta()) {
            return null;
        }

        $minutos = (int) CarbonImmutable::now()->diffInMinutes($this->ventanaCierraEl());

        return $minutos >= 60 ? 'quedan '.intdiv($minutos, 60).' h' : 'quedan '.max($minutos, 1).' min';
    }

    public function enManosDeHumano(): bool
    {
        return $this->estado === EstadoConversacion::Humano;
    }

    public function nombreVisible(): string
    {
        return $this->cliente?->nombre_contacto
            ?: $this->cliente?->razon_social
            ?: $this->nombre_perfil
            ?: Telefono::legible($this->wa_id);
    }

    public function telefonoLegible(): string
    {
        return Telefono::legible($this->wa_id);
    }

    /**
     * @param  Builder<WhatsappConversacion>  $consulta
     */
    #[Scope]
    protected function enCarpeta(Builder $consulta, string $carpeta): void
    {
        match ($carpeta) {
            'humano' => $consulta->where('estado', EstadoConversacion::Humano->value),
            'ventas' => $consulta->where('estado', EstadoConversacion::Agente->value)->where('agente_actual', AgenteWhatsapp::Ventas->value),
            'facturacion' => $consulta->where('estado', EstadoConversacion::Agente->value)->where('agente_actual', AgenteWhatsapp::Facturacion->value),
            'cerradas' => $consulta->where('estado', EstadoConversacion::Cerrada->value),
            default => null,
        };
    }

    /**
     * Nombre, teléfono o texto de los mensajes (regla 56).
     *
     * @param  Builder<WhatsappConversacion>  $consulta
     */
    #[Scope]
    protected function buscar(Builder $consulta, ?string $texto): void
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return;
        }

        $digitos = preg_replace('/\D/', '', $texto);

        $consulta->where(function (Builder $o) use ($texto, $digitos) {
            $o->where('nombre_perfil', 'like', "%{$texto}%")
                ->orWhereHas('cliente', fn (Builder $c) => $c->where('razon_social', 'like', "%{$texto}%")->orWhere('nombre_contacto', 'like', "%{$texto}%"))
                ->orWhereHas('mensajes', fn (Builder $m) => $m->where('texto', 'like', "%{$texto}%"));

            if (strlen((string) $digitos) >= 4) {
                $o->orWhere('wa_id', 'like', "%{$digitos}%");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoConversacion::class,
            'agente_actual' => AgenteWhatsapp::class,
            'aviso_humano_enviado' => 'boolean',
            'sin_limite' => 'boolean',
            'aviso_audio_el' => 'immutable_date',
            'ventas_validadas' => 'array',
            'ultimo_mensaje_cliente_en' => 'immutable_datetime',
            'ultimo_mensaje_en' => 'immutable_datetime',
            'pendientes_humano' => 'integer',
        ];
    }
}
