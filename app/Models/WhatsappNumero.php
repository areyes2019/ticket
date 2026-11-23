<?php

namespace App\Models;

use App\Enums\AgenteWhatsapp;
use App\Services\Whatsapp\TextoIndicaciones;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El número de WhatsApp Cloud conectado (041, reglas 4 y 5) y los ajustes de
 * sus agentes (regla 81). El token y la clave secreta de la app van cifrados
 * y nunca se vuelven a mostrar completos.
 */
#[Fillable([
    'phone_number_id',
    'waba_id',
    'token',
    'app_secret',
    'verify_token',
    'ventas_activo',
    'facturacion_activo',
    'indicaciones_ventas',
    'indicaciones_facturacion',
    'aviso_humano',
    'aviso_humano_fuera_horario',
    'tope_mensual_usd',
    'limite_hora',
    'limite_dia',
])]
#[Hidden(['token', 'app_secret', 'verify_token'])]
class WhatsappNumero extends Model
{
    protected $table = 'whatsapp_numeros';

    public const AVISO_HUMANO = 'Te comunico con un asesor, en breve te responde.';

    public const AVISO_HUMANO_FUERA_HORARIO = 'Te comunico con un asesor. Nuestro horario es {horario}; te respondemos en cuanto abramos.';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ventas_activo' => false,
        'facturacion_activo' => false,
        'tope_mensual_usd' => '50.00',
        'limite_hora' => 20,
        'limite_dia' => 100,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WhatsappConversacion, $this>
     */
    public function conversaciones(): HasMany
    {
        return $this->hasMany(WhatsappConversacion::class);
    }

    /**
     * @return HasMany<WhatsappPlantilla, $this>
     */
    public function plantillas(): HasMany
    {
        return $this->hasMany(WhatsappPlantilla::class);
    }

    public function agenteActivo(AgenteWhatsapp $agente): bool
    {
        return match ($agente) {
            AgenteWhatsapp::Ventas => $this->ventas_activo,
            AgenteWhatsapp::Facturacion => $this->facturacion_activo,
        };
    }

    /**
     * La caja de indicaciones del agente con todas sus claves (045, regla 22).
     *
     * @return array<string, mixed>
     */
    public function indicaciones(AgenteWhatsapp $agente): array
    {
        return TextoIndicaciones::normalizar($agente, $this->{$agente->columnaIndicaciones()} ?? []);
    }

    /**
     * El texto de las indicaciones que recibe el agente (045, regla 20).
     */
    public function instrucciones(AgenteWhatsapp $agente): ?string
    {
        return TextoIndicaciones::armar($agente, $this->indicaciones($agente));
    }

    /**
     * Regla 30: el aviso que recibe el cliente al pasar a humano. El horario
     * es del negocio (046, regla 6).
     */
    public function avisoHumano(?CarbonInterface $momento = null): string
    {
        $negocio = Emisor::actual();

        if ($negocio->enHorario($momento)) {
            return $this->aviso_humano ?: self::AVISO_HUMANO;
        }

        return str_replace('{horario}', $negocio->horarioTexto(), $this->aviso_humano_fuera_horario ?: self::AVISO_HUMANO_FUERA_HORARIO);
    }

    /**
     * "…a1b2": lo único que se vuelve a mostrar de un secreto (regla 5).
     */
    public static function enmascarar(?string $secreto): string
    {
        return $secreto === null || $secreto === '' ? '' : '…'.substr($secreto, -4);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'app_secret' => 'encrypted',
            'ventas_activo' => 'boolean',
            'facturacion_activo' => 'boolean',
            'indicaciones_ventas' => 'array',
            'indicaciones_facturacion' => 'array',
            'tope_mensual_usd' => 'decimal:2',
            'limite_hora' => 'integer',
            'limite_dia' => 'integer',
        ];
    }
}
