@extends('rdv.layout')
@section('title', 'Billet '.$ticket->code)
@section('footer', 'Billetterie en ligne')
@section('content')
<style>.big{background:#fff;border:1px solid var(--line);border-radius:20px;padding:22px;text-align:center;max-width:420px;margin:0 auto}.big svg{width:260px;height:260px}.code{font-family:monospace;font-size:24px;font-weight:800;letter-spacing:3px}.kind{display:inline-block;padding:5px 14px;border-radius:20px;background:{{ $type?->color ?: '#1F6FEB' }};color:#fff;font-weight:800;margin-bottom:10px}</style>
  <div class="big">
    <div class="kind">{{ $type?->name ?? (\App\Modules\Billetterie\Models\TicketType::KINDS[$ticket->kind] ?? 'Billet') }}</div>
    <h2 style="margin:4px 0">{{ $event->name }}</h2>
    <div class="muted">{{ $ticket->valid_from && $ticket->valid_to ? ($ticket->valid_from->eq($ticket->valid_to) ? 'Le '.$ticket->valid_from->format('d/m/Y') : 'Du '.$ticket->valid_from->format('d/m/Y').' au '.$ticket->valid_to->format('d/m/Y')) : '' }}{{ $event->venue ? ' · '.$event->venue : '' }}</div>
    @if($ticket->status === 'valid')
      <div style="margin:14px 0">{!! \App\Modules\Billetterie\TicketService::qrSvg($ticket->token, 7) !!}</div>
    @else
      <div class="flash bad" style="margin:14px 0">Billet annulé</div>
    @endif
    <div class="code">{{ $ticket->code }}</div>
    <h3 style="margin:10px 0 0">{{ $ticket->holder_name }}</h3>
    @if($ticket->company_name)<div class="muted">{{ $ticket->company_name }}</div>@endif
  </div>
@endsection
