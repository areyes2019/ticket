<?php

namespace App\Models;

use App\Enums\EstadoComplementoPago;
use App\Enums\FormaPago;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CFDI de pago de una factura PPD (uno por factura). Es independiente de los
 * pagos de cotización, que no tienen documento fiscal. No guarda XML ni PDF.
 */
#[Fillable([
    'fecha_pago',
    'monto',
    'forma_pago',
])]
class ComplementoPago extends Model
{
    protected $table = 'complementos_pago';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'pendiente',
    ];

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * Identificador propio ante facturapi.io, fijo en todos los reintentos
     * (ver Factura::referenciaExterna()).
     */
    public function referenciaExterna(): string
    {
        return 'complemento-'.$this->id.'-'.$this->created_at->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $respuesta
     */
    public function aplicarRespuestaTimbrado(array $respuesta): void
    {
        $this->forceFill([
            'estado' => EstadoComplementoPago::Timbrado,
            'facturapi_invoice_id' => $respuesta['id'] ?? null,
            'uuid_fiscal' => $respuesta['uuid'] ?? null,
            'sello_cfdi' => data_get($respuesta, 'stamp.signature'),
            'sello_sat' => data_get($respuesta, 'stamp.sat_signature'),
            'cadena_original_sat' => data_get($respuesta, 'stamp.complement_string'),
            'fecha_timbrado' => Factura::fecha(data_get($respuesta, 'stamp.date')),
            'error_timbrado' => null,
        ])->save();
    }

    public function registrarError(string $mensaje): void
    {
        $this->forceFill([
            'estado' => EstadoComplementoPago::Error,
            'error_timbrado' => $mensaje,
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'forma_pago' => FormaPago::class,
            'estado' => EstadoComplementoPago::class,
            'fecha_timbrado' => 'immutable_datetime',
        ];
    }
}
