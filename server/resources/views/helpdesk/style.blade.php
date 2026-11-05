<style>
  .search { display:flex; gap:8px; } .search input { flex:1; } .search button { width:auto; padding:12px 18px; }
  .sec { font-size:13px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin:18px 0 8px; }
  .art { display:block; padding:12px 0; border-bottom:1px solid #EEF1F6; color:inherit; } .art:last-child { border:0; } .art b { color:var(--night); } .art:hover b { color:var(--blue); }
  .body h3 { font-size:17px; margin:18px 0 6px; } .body p { line-height:1.6; margin:8px 0; } .body ul { padding-left:20px; line-height:1.6; } .body .step { padding-left:4px; }
  .msg { padding:12px 14px; border-radius:14px; margin-bottom:10px; max-width:88%; white-space:pre-line; line-height:1.5; }
  .msg.customer { background:#E8F0FE; margin-left:auto; } .msg.agent { background:#fff; border:1px solid var(--line); } .msg small { display:block; color:var(--muted); font-size:12px; margin-bottom:4px; white-space:normal; }
  .stars { display:flex; gap:6px; flex-direction:row-reverse; justify-content:flex-end; } .stars input { display:none; }
  .stars label { font-size:34px; color:#D5DBE6; cursor:pointer; margin:0; } .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color:#FFB400; }
  select { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; }
  .two { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; } @media (max-width:560px) { .two { grid-template-columns:1fr; } }
</style>
