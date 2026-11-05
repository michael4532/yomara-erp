@extends('portail.layout')
@section('title', 'Forfaits & livrables')
@php($h = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','))
@section('content')
  <h1>Forfaits & livrables</h1>
  @if($packs->isEmpty() && $deliverables->isEmpty())
    <div class="card"><p class="muted">Aucun forfait ni livrable pour le moment.</p></div>
  @endif
  @foreach($packs as $p)
    @php($used = $p->consumed())
    @php($pct = $p->hours > 0 ? min(100, round(100 * $used / $p->hours)) : 0)
    <div class="card">
      <div class="row"><h2 class="sp" style="margin:0">{{ $p->name }}</h2><span class="pill {{ $p->status === 'active' ? 'info' : ($p->status === 'exhausted' ? 'wait' : 'bad') }}">{{ ['active' => 'En cours', 'exhausted' => 'Consommé', 'expired' => 'Expiré'][$p->status] ?? $p->status }}</span></div>
      <div class="muted">{{ $p->number }} · depuis le {{ $p->start_date->format('d/m/Y') }}@if($p->expiry_date) · valable jusqu'au {{ $p->expiry_date->format('d/m/Y') }}@endif</div>
      <div style="height:12px;background:#EEF1F6;border-radius:8px;margin:12px 0 6px;overflow:hidden"><div style="height:100%;width:{{ $pct }}%;background:{{ $pct >= 100 ? 'var(--bad)' : ($pct >= $p->alert_percent ? '#E8A317' : 'var(--ok)') }}"></div></div>
      <div class="row" style="border:0"><span><b>{{ $h($used) }} h</b> utilisées sur {{ $h($p->hours) }} h</span><b>{{ $used <= $p->hours ? 'Reste '.$h($p->hours - $used).' h' : 'Dépassement '.$h($used - $p->hours).' h' }}</b></div>
      @if(isset($entries[$p->id]))
        <table style="margin-top:8px">
          <tr><th>Date</th><th>Intervention</th><th class="r">Durée</th></tr>
          @foreach($entries[$p->id]->take(30) as $e)
            <tr><td>{{ $e->date->format('d/m/Y') }}</td><td>{{ $e->description }}<div class="muted">{{ $e->project_name ? $e->project_name.' · ' : '' }}{{ $e->user_name }}</div></td><td class="r">{{ $h($e->hours) }} h</td></tr>
          @endforeach
        </table>
      @endif
    </div>
  @endforeach
  @if($deliverables->isNotEmpty())
    <div class="card">
      <h2>Livrables</h2>
      <div class="list">
        @foreach($deliverables as $d)
          <a class="item" href="{{ $d->publicUrl() }}">
            <div class="sp"><strong>{{ $d->title }}</strong><div class="muted">{{ $d->number }} · v{{ $d->version_no }} · envoyé le {{ $d->sent_at?->format('d/m/Y') }}</div></div>
            <span class="pill {{ $d->status === 'approved' ? 'ok' : ($d->status === 'sent' ? 'wait' : 'info') }}">{{ ['sent' => 'À valider', 'approved' => 'Validé', 'changes' => 'Modifications demandées', 'draft' => 'Nouvelle version en préparation'][$d->status] ?? $d->status }}</span>
          </a>
        @endforeach
      </div>
    </div>
  @endif
  @if(($reports ?? collect())->isNotEmpty())
    <div class="card">
      <h2>Rapports d'activité</h2>
      <div class="list">
        @foreach($reports as $r)
          <a class="item" href="{{ $r->publicUrl() }}" target="_blank">
            <div class="sp"><strong>{{ ucfirst($r->label()) }}</strong><div class="muted">{{ $h($r->hours) }} h · {{ $r->deliverables }} livrable(s)</div></div>
            <span class="pill info">PDF</span>
          </a>
        @endforeach
      </div>
    </div>
  @endif
@endsection
