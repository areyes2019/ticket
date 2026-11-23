<?php

namespace App\Models;

use App\Enums\AgenteWhatsapp;
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
    'instrucciones_ventas',
    'instrucciones_facturacion',
    'horario',
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

    public const DIAS = ['lun' => 'Lunes', 'mar' => 'Martes', 'mie' => 'Miércoles', 'jue' => 'Jueves', 'vie' => 'Viernes', 'sab' => 'Sábado', 'dom' => 'Domingo'];

    public const HORARIO_POR_DEFECTO = [
        'lun' => ['09:00', '18:00'],
        'mar' => ['09:00', '18:00'],
        'mie' => ['09:00', '18:00'],
        'jue' => ['09:00', '18:00'],
        'vie' => ['09:00', '18:00'],
        'sab' => ['09:00', '14:00'],
        'dom' => null,
    ];

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

    public function instrucciones(AgenteWhatsapp $agente): ?string
    {
        return match ($agente) {
            AgenteWhatsapp::Ventas => $this->instrucciones_ventas,
            AgenteWhatsapp::Facturacion => $this->instrucciones_facturacion,
        };
    }

    /**
     * @return array<string, array{0: string, 1: string}|null>
     */
    public function horarioSemanal(): array
    {
        return [...self::HORARIO_POR_DEFECTO, ...($this->horario ?? [])];
    }

    /**
     * Regla 31: el horario se evalúa en la zona del negocio.
     */
    public function enHorario(?CarbonInterface $momento = null): bool
    {
        $momento = ($momento ?? now())->copy()->setTimezone(config('app.zona_negocio'));
        $dia = array_keys(self::DIAS)[$momento->dayOfWeekIso - 1];
        $tramo = $this->horarioSemanal()[$dia] ?? null;

        if ($tramo === null) {
            return false;
        }

        $hora = $momento->format('H:i');

        return $hora >= $tramo[0] && $hora < $tramo[1];
    }

    /**
     * "lunes a viernes de 09:00 a 18:00, sábado de 09:00 a 14:00".
     */
    public function horarioTexto(): string
    {
        $partes = [];

        foreach ($this->horarioSemanal() as $dia => $tramo) {
            if ($tramo !== null) {
                $partes[] = mb_strtolower(self::DIAS[$dia]).' de '.$tramo[0].' a '.$tramo[1];
            }
        }

        return $partes === [] ? 'por confirmar' : implode(', ', $partes);
    }

    /**
     * Regla 30: el aviso que recibe el cliente al pasar a humano.
     */
    public function avisoHumano(?CarbonInterface $momento = null): string
    {
        if ($this->enHorario($momento)) {
            return $this->aviso_humano ?: self::AVISO_HUMANO;
        }

        return str_replace('{horario}', $this->horarioTexto(), $this->aviso_humano_fuera_horario ?: self::AVISO_HUMANO_FUERA_HORARIO);
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
            'horario' => 'array',
            'tope_mensual_usd' => 'decimal:2',
            'limite_hora' => 'integer',
            'limite_dia' => 'integer',
        ];
    }
}
