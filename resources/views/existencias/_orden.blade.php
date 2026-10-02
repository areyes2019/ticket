{{-- Orden actual; forma parte del formulario de filtros para conservarlo al seguir buscando. --}}
<div id="existencias-orden">
    @isset($parametros['orden'])
        <input type="hidden" name="orden" value="{{ $parametros['orden'] }}">
    @endisset
    @isset($parametros['direccion'])
        <input type="hidden" name="direccion" value="{{ $parametros['direccion'] }}">
    @endisset
</div>
