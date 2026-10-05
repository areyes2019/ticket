{{-- Enlaces del mensaje de éxito (flash exito_enlaces, [texto => url]): por
     ejemplo, a la venta y a la orden de trabajo que nacieron con el primer
     pago de una cotización (029). --}}
@if (session('exito_enlaces'))
    <span class="enlaces-exito">
        @foreach (session('exito_enlaces') as $texto => $url)
            <a href="{{ $url }}">{{ $texto }}</a>
        @endforeach
    </span>
@endif
