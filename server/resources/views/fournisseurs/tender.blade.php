@extends('rdv.layout')
@section('title', $t->title)
@section('content')
  @include('fournisseurs.nav')
  <div class="card">
    <div class="muted">{{ $t->number }} · publié le {{ $t->published_at?->format('d/m/Y') }}</div>
    <h2 style="font-size:21px;margin:4px 0 8px">{{ $t->title }}</h2>
    @if($t->description)<p style="white-space:pre-line">{{ $t->description }}</p>@endif
    <div class="row"><span>Date limite des offres</span><b>{{ $t->deadline->format('d/m/Y à H:i') }}</b></div>
    @if($t->delivery_place)<div class="row"><span>Lieu de livraison</span><b>{{ $t->delivery_place }}</b></div>@endif
    @if($bid)<div class="row"><span>Votre offre</span><b>{{ number_format($bid->total, 0, ',', ' ') }} {{ $t->currency }} HT · déposée le {{ $bid->submitted_at->format('d/m/Y H:i') }}{{ $bid->revisions ? ' (révisée '.$bid->revisions.' fois)' : '' }}</b></div>@endif
    @if($t->status === 'awarded')<div class="flash {{ $bid?->status === 'awarded' ? 'ok' : 'bad' }}" style="margin-top:10px">{{ $bid?->status === 'awarded' ? 'Votre offre est retenue.' : 'Appel d\'offres attribué à un autre fournisseur.' }}</div>@endif
  </div>
  @php($open = $t->isOpen())
  <form class="card" method="post" action="{{ url('/fournisseur/'.$token.'/ao/'.$t->id) }}">
    @csrf
    <h2>{{ $open ? ($bid ? 'Modifier votre offre' : 'Votre offre') : 'Articles demandés' }}</h2>
    <table>
      <tr><th>Article</th><th class="r">Quantité</th>@if($open || $bid)<th class="r">Prix unitaire HT ({{ $t->currency }})</th>@endif</tr>
      @foreach($t->lines as $l)
        @php($mine = $bid ? collect($bid->lines)->firstWhere('line_id', $l['id']) : null)
        <tr>
          <td>{{ $l['description'] }}@if($open)<input name="note[{{ $l['id'] }}]" placeholder="Remarque (marque, délai…)" value="{{ old('note.'.$l['id'], $mine['note'] ?? '') }}" style="margin-top:6px;padding:7px;font-size:13px">@elseif($mine['note'] ?? null)<div class="muted">{{ $mine['note'] }}</div>@endif</td>
          <td class="r">{{ rtrim(rtrim(number_format($l['quantity'], 2, ',', ' '), '0'), ',') }} {{ $l['unit'] }}</td>
          @if($open)
            <td class="r" style="width:170px"><input class="price" name="price[{{ $l['id'] }}]" inputmode="decimal" placeholder="non proposé" value="{{ old('price.'.$l['id'], isset($mine['unit_price']) ? rtrim(rtrim(number_format($mine['unit_price'], 2, '.', ''), '0'), '.') : '') }}"></td>
          @elseif($bid)
            <td class="r">{{ isset($mine['unit_price']) ? number_format($mine['unit_price'], 0, ',', ' ') : '—' }}</td>
          @endif
        </tr>
      @endforeach
    </table>
    @if($open)
      <div class="two">
        <div><label for="delivery_days">Délai de livraison (jours)</label><input id="delivery_days" name="delivery_days" type="number" min="0" value="{{ old('delivery_days', $bid?->delivery_days) }}"></div>
        <div><label for="valid_until">Offre valable jusqu'au</label><input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $bid?->valid_until?->format('Y-m-d')) }}"></div>
      </div>
      <label for="comment">Commentaire</label>
      <textarea id="comment" name="comment" rows="3">{{ old('comment', $bid?->comment) }}</textarea>
      <p class="muted">Laissez vide le prix d'un article que vous ne proposez pas. Vous pouvez modifier votre offre jusqu'à la date limite.</p>
      <button class="btn">{{ $bid ? 'Mettre à jour mon offre' : 'Envoyer mon offre' }}</button>
    @endif
  </form>
@endsection
