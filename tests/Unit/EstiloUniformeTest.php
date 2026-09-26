<?php

/*
 * Revisión automática del estilo uniforme (specs/estilo-uniforme.md, sección 7).
 */

function raizProyecto(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, string> ruta relativa => contenido
 */
function vistasDelProyecto(bool $incluirComponentes = true): array
{
    $raiz = raizProyecto();
    $archivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz.'/resources/views', FilesystemIterator::SKIP_DOTS)
    );

    $vistas = [];

    foreach ($archivos as $archivo) {
        $ruta = str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));

        if (! $incluirComponentes && str_starts_with($ruta, 'resources/views/components/')) {
            continue;
        }

        $vistas[$ruta] = file_get_contents($archivo->getPathname());
    }

    return $vistas;
}

/**
 * Devuelve "archivo:línea → texto" por cada coincidencia de la expresión.
 *
 * @param  array<string, string>  $archivos
 * @return list<string>
 */
function buscarEn(array $archivos, string $patron): array
{
    $problemas = [];

    foreach ($archivos as $ruta => $contenido) {
        foreach (preg_split('/\R/', $contenido) as $numero => $linea) {
            if (preg_match($patron, $linea, $coincidencia)) {
                $problemas[] = $ruta.':'.($numero + 1).' → '.$coincidencia[0];
            }
        }
    }

    return $problemas;
}

function cssPropio(): string
{
    return file_get_contents(raizProyecto().'/public/css/app.css');
}

it('no redondea esquinas en app.css', function () {
    preg_match_all('/border-radius\s*:\s*([^;]+);/', cssPropio(), $coincidencias);

    $invalidos = array_filter($coincidencias[1], fn ($valor) => ! in_array(trim($valor), ['0', 'var(--radio)'], true));

    expect($invalidos)->toBeEmpty('Hay border-radius con redondeo en public/css/app.css: '.implode(', ', $invalidos));
});

it('define las esquinas rectas en los valores de diseño', function () {
    expect(cssPropio())->toMatch('/:root\s*\{[^}]*--radio:\s*0;/');
});

it('solo escribe colores dentro de los valores de diseño', function () {
    $sinRoot = preg_replace('/:root\s*\{[^}]*\}/', '', cssPropio(), 1);

    preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/', $sinRoot, $coincidencias);

    expect($coincidencias[0])->toBeEmpty('Usa variables de :root en lugar de: '.implode(', ', $coincidencias[0]));
});

it('no redondea esquinas en las vistas', function () {
    $problemas = [
        ...buscarEn(vistasDelProyecto(), '/class="[^"]*\brounded(-[a-z0-9]+)?\b[^"]*"/'),
        ...buscarEn(vistasDelProyecto(), '/style="[^"]*border-radius[^"]*"/'),
    ];

    expect($problemas)->toBeEmpty("Esquinas redondeadas en las vistas:\n".implode("\n", $problemas));
});

it('solo usa Bootstrap Icons', function () {
    $patrones = [
        'Font Awesome' => '/\b(fa|fas|far|fab|fal|fad)\s|\bfa-[a-z]|font-?awesome/i',
        'Material Icons' => '/material-(icons|symbols)/i',
        'Heroicons' => '/heroicon/i',
        'Feather' => '/feather|data-feather/i',
        'Ionicons' => '/ionicon|<ion-icon/i',
        'SVG en línea' => '/<svg\b/i',
        'emoji' => '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B55}]/u',
    ];

    $problemas = [];

    foreach ($patrones as $libreria => $patron) {
        foreach (buscarEn(vistasDelProyecto(), $patron) as $problema) {
            $problemas[] = "[{$libreria}] {$problema}";
        }
    }

    expect($problemas)->toBeEmpty("Iconos que no son de Bootstrap Icons:\n".implode("\n", $problemas));
});

it('usa los componentes en lugar de piezas hechas a mano', function () {
    $vistas = vistasDelProyecto(incluirComponentes: false);

    $problemas = [
        ...buscarEn($vistas, '/<(button|select|textarea)\b/i'),
        ...buscarEn($vistas, '/<input\b(?![^>]*type="hidden")[^>]*>/i'),
        ...buscarEn($vistas, '/<i\s[^>]*class="[^"]*\bbi\b/i'),
        ...buscarEn($vistas, '/class="[^"]*\b(tarjeta|boton|alerta|campo|casilla|contrasena)(-[a-z-]+)?\b[^"]*"/'),
    ];

    expect($problemas)->toBeEmpty("Usa <x-boton>, <x-card>, <x-alerta>, <x-campo> o <x-icono>:\n".implode("\n", $problemas));
});

it('tiene Bootstrap Icons dentro del proyecto', function (string $archivo) {
    expect(file_exists(raizProyecto().'/public/vendor/bootstrap-icons/'.$archivo))->toBeTrue();
})->with(['bootstrap-icons.min.css', 'fonts/bootstrap-icons.woff2', 'fonts/bootstrap-icons.woff']);

it('no carga iconos desde internet', function () {
    $problemas = buscarEn(vistasDelProyecto(), '/<link[^>]+href="https?:\/\/[^"]*icons[^"]*"/i');

    expect($problemas)->toBeEmpty(implode("\n", $problemas));
});
