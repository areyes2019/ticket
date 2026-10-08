<?php

namespace App\Services\Etiquetas;

/**
 * Las seis medidas de una planilla de etiquetas sobre hoja carta (031), las
 * columnas elegidas (corrección 2: automático, 1, 2 o 3) y lo que se deriva
 * de ellas: columnas, renglones y márgenes centrados.
 *
 * Todo se cuenta en décimas de milímetro (la precisión de las medidas), así
 * las divisiones son enteras y no hay ruido de punto flotante. El espejo en
 * JavaScript es public/js/etiquetas-produccion.js; los dos recorren
 * tests/Fixtures/planillas-etiquetas.json.
 */
final class MedidasPlanilla
{
    /** Hoja carta, en décimas de milímetro. */
    public const HOJA_ANCHO = 2159;

    public const HOJA_ALTO = 2794;

    /**
     * Campo => [mínimo, máximo, fábrica], en décimas de milímetro. Los
     * márgenes de fábrica centran la rejilla de 3 × 8 de 60 × 30 mm (030).
     *
     * @var array<string, array{int, int, int}>
     */
    public const CAMPOS = [
        'ancho' => [100, self::HOJA_ANCHO, 600],
        'alto' => [100, self::HOJA_ALTO, 300],
        'separacion_horizontal' => [0, 500, 0],
        'separacion_vertical' => [0, 500, 0],
        'margen_superior' => [0, 1000, 197],
        'margen_izquierdo' => [0, 1000, 179],
    ];

    /** Columnas que se pueden elegir; "auto" (null) pone todas las que caben. */
    public const COLUMNAS = [1, 2, 3];

    public const COLUMNAS_AUTOMATICO = 'auto';

    /** @var array<string, string> */
    public const ETIQUETAS = [
        'ancho' => 'Ancho',
        'alto' => 'Alto',
        'separacion_horizontal' => 'Separación entre columnas',
        'separacion_vertical' => 'Separación entre renglones',
        'margen_superior' => 'Margen superior',
        'margen_izquierdo' => 'Margen izquierdo',
    ];

    /**
     * @param  array<string, int>  $decimas
     * @param  int|null  $columnasElegidas  1, 2 o 3; null es automático.
     */
    private function __construct(private readonly array $decimas, private readonly ?int $columnasElegidas = null) {}

    public static function fabrica(): self
    {
        return new self(array_map(fn (array $campo) => $campo[2], self::CAMPOS));
    }

    /**
     * @param  array<string, mixed>  $milimetros  Las seis medidas, ya válidas, y opcionalmente columnas.
     */
    public static function desdeMilimetros(array $milimetros): self
    {
        return new self(array_map(
            fn (string $campo) => self::aDecimas($milimetros[$campo]),
            array_combine(array_keys(self::CAMPOS), array_keys(self::CAMPOS))
        ), self::leerColumnas($milimetros['columnas'] ?? null) ?: null);
    }

    /**
     * Cada medida válida de $datos reemplaza la de $base; las ausentes o
     * fuera de rango se quedan como en $base.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function desdePeticion(array $datos, self $base): self
    {
        $decimas = $base->decimas;

        foreach (self::CAMPOS as $campo => [$minimo, $maximo]) {
            $valor = $datos[$campo] ?? null;

            if (! is_numeric($valor)) {
                continue;
            }

            $nuevo = self::aDecimas($valor);

            if ($nuevo >= $minimo && $nuevo <= $maximo) {
                $decimas[$campo] = $nuevo;
            }
        }

        $columnas = array_key_exists('columnas', $datos) ? self::leerColumnas($datos['columnas']) : false;

        return new self($decimas, $columnas === false ? $base->columnasElegidas : $columnas);
    }

    /**
     * Reglas de validación de las seis medidas, en milímetros, y de las
     * columnas (sin ellas, automático).
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas(): array
    {
        return [
            ...array_map(fn (array $campo) => [
                'required', 'numeric', 'decimal:0,1', 'between:'.self::aMilimetros($campo[0]).','.self::aMilimetros($campo[1]),
            ], self::CAMPOS),
            'columnas' => ['nullable', 'in:'.implode(',', [self::COLUMNAS_AUTOMATICO, ...self::COLUMNAS])],
        ];
    }

    public function milimetros(string $campo): string
    {
        return self::aMilimetros($this->decimas[$campo]);
    }

    /**
     * @return array<string, string> Las seis medidas en milímetros con un decimal.
     */
    public function toArray(): array
    {
        return array_map(fn (int $valor) => self::aMilimetros($valor), $this->decimas);
    }

    /**
     * 1, 2 o 3, o null si es automático.
     */
    public function columnasElegidas(): ?int
    {
        return $this->columnasElegidas;
    }

    /**
     * Las columnas como van en la dirección y en el select: 1, 2, 3 o "auto".
     */
    public function columnasParaDireccion(): string
    {
        return (string) ($this->columnasElegidas ?? self::COLUMNAS_AUTOMATICO);
    }

    /**
     * Las que se pintan: las elegidas si caben, o todas las que caben.
     */
    public function columnas(): int
    {
        return $this->limitarColumnas($this->columnasQueCaben());
    }

    public function columnasQueCaben(): int
    {
        return self::caben(self::HOJA_ANCHO - $this->decimas['margen_izquierdo'], $this->decimas['ancho'], $this->decimas['separacion_horizontal']);
    }

    /**
     * Se eligieron más columnas de las que caben (y cabe al menos una).
     */
    public function columnasRecortadas(): bool
    {
        $caben = $this->columnasQueCaben();

        return $this->columnasElegidas !== null && $caben > 0 && $this->columnasElegidas > $caben;
    }

    public function renglones(): int
    {
        return self::caben(self::HOJA_ALTO - $this->decimas['margen_superior'], $this->decimas['alto'], $this->decimas['separacion_vertical']);
    }

    public function porHoja(): int
    {
        return $this->columnas() * $this->renglones();
    }

    public function cabe(): bool
    {
        return $this->porHoja() > 0;
    }

    /**
     * Cuánto se escala la letra de la etiqueta con su alto (031, corrección
     * 1): el alto menos los 4 mm de orilla, contra los 26 mm de la etiqueta
     * de 30 mm de 030, que es la escala 1.
     */
    public function escalaLetra(): float
    {
        return max(0, $this->decimas['alto'] - 40) / 260;
    }

    /**
     * Los márgenes que dejan en medio de la hoja el bloque de columnas y
     * renglones que se ve ahora. Si hoy no cabe nada, centra lo que cabría
     * con margen 0.
     */
    public function centrada(): self
    {
        $d = $this->decimas;

        $columnas = $this->columnas() ?: $this->limitarColumnas(self::caben(self::HOJA_ANCHO, $d['ancho'], $d['separacion_horizontal']));
        $renglones = $this->renglones() ?: self::caben(self::HOJA_ALTO, $d['alto'], $d['separacion_vertical']);

        $d['margen_izquierdo'] = self::margenCentrado(self::HOJA_ANCHO, $columnas, $d['ancho'], $d['separacion_horizontal']);
        $d['margen_superior'] = self::margenCentrado(self::HOJA_ALTO, $renglones, $d['alto'], $d['separacion_vertical']);

        return new self($d, $this->columnasElegidas);
    }

    private function limitarColumnas(int $caben): int
    {
        return $this->columnasElegidas === null ? $caben : min($this->columnasElegidas, $caben);
    }

    /**
     * 1, 2 o 3; null para automático ("auto" o vacío); false si no es válido.
     */
    private static function leerColumnas(mixed $valor): int|false|null
    {
        if ($valor === null || $valor === '' || $valor === self::COLUMNAS_AUTOMATICO) {
            return null;
        }

        $columnas = filter_var($valor, FILTER_VALIDATE_INT);

        return in_array($columnas, self::COLUMNAS, true) ? $columnas : false;
    }

    private static function caben(int $espacio, int $tamano, int $separacion): int
    {
        return max(0, intdiv($espacio + $separacion, $tamano + $separacion));
    }

    private static function margenCentrado(int $hoja, int $cuantas, int $tamano, int $separacion): int
    {
        if ($cuantas === 0) {
            return 0;
        }

        $bloque = $cuantas * $tamano + ($cuantas - 1) * $separacion;

        return min(self::CAMPOS['margen_superior'][1], max(0, intdiv($hoja - $bloque, 2)));
    }

    private static function aDecimas(float|int|string $milimetros): int
    {
        return (int) round((float) $milimetros * 10);
    }

    private static function aMilimetros(int $decimas): string
    {
        return number_format($decimas / 10, 1, '.', '');
    }
}
