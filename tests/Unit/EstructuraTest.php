<?php

it('tiene los recursos estáticos en public', function (string $archivo) {
    expect(file_exists(dirname(__DIR__, 2).'/public/'.$archivo))->toBeTrue();
})->with(['css/app.css', 'js/app.js', 'js/inicio.js', 'js/constancia-fiscal.js', 'vendor/axios.min.js']);

it('no incluye archivos de Vite ni carpetas de frontend separadas', function (string $ruta) {
    expect(file_exists(dirname(__DIR__, 2).'/'.$ruta))->toBeFalse();
})->with(['vite.config.js', 'package.json', 'resources/js', 'resources/css', 'frontend', 'backend']);

it('no usa @vite en las vistas', function () {
    $vistas = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($vistas as $vista) {
        expect(file_get_contents($vista->getPathname()))->not->toContain('@vite');
    }
});
