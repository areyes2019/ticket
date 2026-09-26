<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo SAT c_ClaveProdServ (solo lectura; lo llena catalogos-sat:actualizar).
 */
class SatClaveProdServ extends Model
{
    /**
     * @var string
     */
    protected $table = 'sat_claves_prod_serv';

    /**
     * @var string
     */
    protected $primaryKey = 'clave';

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;
}
