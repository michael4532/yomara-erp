@extends('portail.layout')
@section('title', 'Relevé de compte')
@section('content')
  <div class="row"><div class="sp"><h1>Relevé de compte</h1><p class="muted">{{ $partner->name }} · au {{ now()->format('d/m/Y') }}</p></div><button class="btn ghost sm noprint" onclick="window.print()">Imprimer / PDF</button></div>
  <div class="card">
    @if($rows->isEmpty())
      <p class="muted">Aucune opération.</p>
    @else
    <div class="tscroll"><table>
      <tr><th>Date</th><th>Opération</th><th class="r">Débit</th><th class="r">Crédit</th><th class="r">Solde</th></tr>
      @foreach($rows as $r)
        <tr>
          <td>{{ $r['date']->format('d/m/Y') }}</td>
          <td>@if($r['id'])<a href="{{ url('/portail/'.$token.'/document/'.$r['id']) }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
          <td class="r">{{ $r['debit'] > 0 ? $fmt($r['debit']) : '' }}</td>
          <td class="r">{{ $r['credit'] > 0 ? $fmt($r['credit']) : '' }}</td>
          <td class="r"><strong>{{ $fmt($r['balance']) }}</strong></td>
        </tr>
      @endforeach
    </table></div>
    <div class="row" style="margin-top:16px"><span class="sp"></span><strong>Solde dû : {{ $fmt(max(0, $balance)) }}</strong>@if($balance < 0)<span class="pill ok">Crédit en votre faveur : {{ $fmt(-$balance) }}</span>@endif</div>
    @endif
  </div>
  @if($print)<script>window.addEventListener('load', () => window.print());</script>@endif
@endsection
