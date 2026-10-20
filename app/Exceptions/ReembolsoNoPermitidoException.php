<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El reembolso (038) no se hizo. El mensaje va tal cual al usuario: dice qué
 * falló y, si ya se pidió la cancelación de la factura, también eso.
 */
class ReembolsoNoPermitidoException extends RuntimeException {}
