<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;

/**
 * Copia oculta al correo del negocio (`negocio.copia_correos`) en cada
 * documento que sale por correo. Vacío: no hay copia. Si ya va entre los
 * destinatarios, no se duplica. Se revisa `$this->to` y no `hasTo()`, que
 * vuelve a llamar a `envelope()` y no terminaría.
 */
trait CopiaAlNegocio
{
    /**
     * @return list<Address>
     */
    protected function copiaAlNegocio(): array
    {
        $correo = trim((string) config('negocio.copia_correos'));

        $yaVa = collect($this->to)->contains(fn (array $destinatario) => strcasecmp($destinatario['address'], $correo) === 0);

        if ($correo === '' || $yaVa) {
            return [];
        }

        return [new Address($correo)];
    }
}
