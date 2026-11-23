<?php

namespace App\Models;

use App\Enums\RegimenFiscal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Emisor de toda la instalación (026): una sola fila. Lo imprimen cotización y
 * orden de compra; la factura solo toma de aquí los datos de contacto, porque
 * lo fiscal es la copia del timbrado. También guarda el horario de atención
 * del negocio, que usan los agentes de WhatsApp (046, regla 6).
 */
#[Fillable(['nombre', 'rfc', 'regimen_fiscal', 'domicilio', 'correo', 'telefono', 'sitio_web', 'whatsapp', 'horario'])]
class Emisor extends Model
{
    protected $table = 'emisor';

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

    /**
     * La fila única o una instancia vacía sin guardar: nunca null.
     */
    public static function actual(): self
    {
        return self::query()->oldest('id')->first() ?? new self;
    }

    public function estaCompleto(): bool
    {
        return filled($this->nombre) && filled($this->rfc) && $this->regimen_fiscal !== null;
    }

    /**
     * @return array<string, array{0: string, 1: string}|null>
     */
    public function horarioSemanal(): array
    {
        return [...self::HORARIO_POR_DEFECTO, ...($this->horario ?? [])];
    }

    /**
     * 041, regla 31: el horario se evalúa en la zona del negocio.
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
     * "lunes de 9:00 a.m. a 6:00 p.m., sábado de 9:00 a.m. a 2:00 p.m.":
     * en 12 horas, que es como lo lee el cliente (y como lo repite la IA).
     */
    public function horarioTexto(): string
    {
        $partes = [];

        foreach ($this->horarioSemanal() as $dia => $tramo) {
            if ($tramo !== null) {
                $partes[] = mb_strtolower(self::DIAS[$dia]).' de '.self::hora12($tramo[0]).' a '.self::hora12($tramo[1]);
            }
        }

        return $partes === [] ? 'por confirmar' : implode(', ', $partes);
    }

    /**
     * "19:00" → "7:00 p.m.", "09:30" → "9:30 a.m.", "12:00" → "12:00 p.m.".
     */
    public static function hora12(string $hora): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return sprintf('%d:%02d %s', $h % 12 ?: 12, $m, $h < 12 ? 'a.m.' : 'p.m.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regimen_fiscal' => RegimenFiscal::class,
            'horario' => 'array',
        ];
    }
}
