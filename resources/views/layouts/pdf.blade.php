{{-- Plantilla base de los PDF (026): cotización, factura y orden de compra.
     Parámetros: $titulo, $folio, $notaPie, $logo (data URI o null), $logoMedidas.
     Secciones: meta, marca-agua, emisor, contraparte, conceptos, totales, extras.
     Dompdf no lee public/css/app.css: los valores se escriben aquí. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $tituloArchivo ?? $titulo.' '.$folio }}</title>
    <style>
        @page { margin: 1.3cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #2c3e50; }
        table { border-collapse: collapse; }
        .suave { color: #7f8c8d; }
        .derecha { text-align: right; }
        .numero { text-align: right; white-space: nowrap; }

        .encabezado { width: 100%; border-bottom: 2px solid #2c3e50; margin-bottom: 8pt; }
        .encabezado td { vertical-align: top; padding-bottom: 6pt; }
        .encabezado .logo { width: 55mm; height: 30mm; }
        .titulo-documento { font-size: 18pt; font-weight: bold; color: #2c3e50; }
        .folio { font-size: 13pt; color: #2c3e50; margin-bottom: 3pt; }

        .partes { width: 100%; margin-bottom: 8pt; }
        .partes td { vertical-align: top; width: 50%; padding: 2pt 8pt 2pt 0; }
        .partes td.contraparte { border-left: 1px solid #95a5a6; padding-left: 8pt; }
        .rotulo { font-size: 7pt; color: #7f8c8d; text-transform: uppercase; margin-bottom: 2pt; }
        .nombre { font-size: 10pt; font-weight: bold; }
        .vigente { color: #27ae60; font-weight: bold; }
        .cancelada { color: #c0392b; font-weight: bold; }

        table.conceptos { width: 100%; }
        table.conceptos th, table.conceptos td { border: 1px solid #95a5a6; padding: 3pt; vertical-align: top; }
        table.conceptos th { background: #f5f5f5; text-align: left; font-size: 7.5pt; }
        table.conceptos th.numero { text-align: right; }

        table.totales { width: 38%; margin-top: 8pt; margin-left: 62%; }
        table.totales td { border: 1px solid #95a5a6; padding: 3pt 5pt; white-space: nowrap; }
        table.totales tr.total td { background: #f5f5f5; font-weight: bold; font-size: 10pt; }

        .seccion { margin-top: 10pt; }
        .titulo-seccion { font-size: 7pt; color: #2c3e50; font-weight: bold; text-transform: uppercase; margin: 4pt 0 2pt; }
        .mono-box { font-family: "DejaVu Sans Mono", monospace; border: 1px solid #95a5a6; padding: 3pt; word-wrap: break-word; }

        .pie { margin-top: 12pt; padding-top: 4pt; border-top: 1px solid #95a5a6; font-size: 7pt; color: #7f8c8d; text-align: center; }
        .marca-agua { position: fixed; top: 38%; left: 8%; font-size: 64pt; color: #fadbd8; transform: rotate(-30deg); z-index: -1; }
    </style>
</head>
<body>
    @yield('marca-agua')

    <table class="encabezado">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="Sello Pronto" style="width: {{ $logoMedidas['ancho_mm'] }}mm; height: {{ $logoMedidas['alto_mm'] }}mm;">
                @endif
            </td>
            <td class="derecha">
                <div class="titulo-documento">{{ $titulo }}</div>
                <div class="folio">{{ $folio }}</div>
                @yield('meta')
            </td>
        </tr>
    </table>

    <table class="partes">
        <tr>
            <td>@yield('emisor')</td>
            <td class="contraparte">@yield('contraparte')</td>
        </tr>
    </table>

    @yield('conceptos')

    @yield('totales')

    @yield('extras')

    <p class="pie">{{ $notaPie }}</p>
</body>
</html>
