<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Cotizacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Las listas de consulta del mostrador (034): cotizaciones, facturas y
 * catálogo. La pantalla pinta la primera página (o las que ya se habían
 * cargado, ?paginas=n, al volver de un detalle) y las tarjetas sirven las
 * siguientes, con la misma consulta.
 */
trait PaginaTarjetasMostrador
{
    /**
     * Sin texto, las listas de documentos muestran solo lo de estos días (el
     * plazo de caducidad de la cotización, 011): casi exactamente lo vivo.
     */
    public const DIAS_RECIENTES = Cotizacion::DIAS_CADUCIDAD;

    public const DOCUMENTOS_POR_PAGINA = 20;

    public const CATALOGO_POR_PAGINA = 24;

    /**
     * Tope de páginas que se vuelven a pedir juntas al regresar a una lista.
     */
    public const MAX_PAGINAS = 10;

    /**
     * @return array{elementos: LengthAwarePaginator, siguiente: string|null, q: string}
     */
    protected function paginaCotizaciones(Request $request): array
    {
        $q = $this->textoBuscado($request);
        $consulta = $request->user()->cotizaciones()->with('cliente')
            ->when($q !== '', fn ($cotizaciones) => $cotizaciones->filtrar(['texto' => $q, 'folio' => Cotizacion::folioBuscado($q)]))
            ->when($q === '', fn ($cotizaciones) => $cotizaciones->where('created_at', '>=', $this->inicioRecientes()))
            ->latest()
            ->orderByDesc('id');

        return $this->pagina($request, $consulta, self::DOCUMENTOS_POR_PAGINA, $q, 'mostrador.tarjetas.cotizaciones');
    }

    /**
     * @return array{elementos: LengthAwarePaginator, siguiente: string|null, q: string}
     */
    protected function paginaFacturas(Request $request): array
    {
        $q = $this->textoBuscado($request);
        $consulta = $request->user()->facturas()->with('cliente')
            ->when($q !== '', fn ($facturas) => $facturas->buscarTexto($q))
            ->when($q === '', fn ($facturas) => $facturas->where('created_at', '>=', $this->inicioRecientes()))
            ->latest()
            ->orderByDesc('id');

        return $this->pagina($request, $consulta, self::DOCUMENTOS_POR_PAGINA, $q, 'mostrador.tarjetas.facturas');
    }

    /**
     * En el orden de la lista de artículos del escritorio: por id, de menor a
     * mayor (el orden de alta o de importación).
     *
     * @return array{elementos: LengthAwarePaginator, siguiente: string|null, q: string}
     */
    protected function paginaCatalogo(Request $request): array
    {
        $q = $this->textoBuscado($request);
        $consulta = $request->user()->articulos()
            ->buscarTexto($q)
            ->orderBy('id');

        return $this->pagina($request, $consulta, self::CATALOGO_POR_PAGINA, $q, 'mostrador.tarjetas.catalogo');
    }

    private function textoBuscado(Request $request): string
    {
        return $request->string('q')->trim()->limit(100, '')->toString();
    }

    /**
     * Inicio del día, en la zona del negocio, de hace DIAS_RECIENTES días.
     */
    private function inicioRecientes(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.zona_negocio'))->startOfDay()->subDays(self::DIAS_RECIENTES)->utc();
    }

    /**
     * La página pedida (?page) o, con ?paginas=n, las páginas 1 a n juntas.
     * La URL de la que sigue la arma el servidor (data-siguiente), siempre
     * hacia las tarjetas aunque la página la pinte la pantalla completa: el
     * navegador no conoce la paginación.
     *
     * @param  Builder<*>|Relation<*, *, *>  $consulta
     * @return array{elementos: LengthAwarePaginator, siguiente: string|null, q: string}
     */
    private function pagina(Request $request, Builder|Relation $consulta, int $porPagina, string $q, string $rutaTarjetas): array
    {
        $paginas = min(max($request->integer('paginas', 1), 1), self::MAX_PAGINAS);
        $pagina = $paginas > 1 ? 1 : max($request->integer('page', 1), 1);
        $elementos = $consulta->paginate($porPagina * $paginas, ['*'], 'page', $pagina);
        $siguiente = $elementos->hasMorePages()
            ? route($rutaTarjetas, array_filter(['q' => $q, 'page' => $paginas > 1 ? $paginas + 1 : $pagina + 1]))
            : null;

        return ['elementos' => $elementos, 'siguiente' => $siguiente, 'q' => $q];
    }
}
