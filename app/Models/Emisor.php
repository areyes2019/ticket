<?php

namespace App\Models;

use App\Enums\RegimenFiscal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Emisor de toda la instalación (026): una sola fila. Lo imprimen cotización y
 * orden de compra; la factura solo toma de aquí los datos de contacto, porque
 * lo fiscal es la copia del timbrado.
 */
#[Fillable(['nombre', 'rfc', 'regimen_fiscal', 'domicilio', 'correo', 'telefono', 'sitio_web', 'whatsapp'])]
class Emisor extends Model
{
    protected $table = 'emisor';

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regimen_fiscal' => RegimenFiscal::class,
        ];
    }
}
