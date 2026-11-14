import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { applyFr } from './sheet-fr.js';
import './office.css';

window.__fsLocale = (() => {
  let cache;
  return (en) => (cache ??= applyFr(en));
})();

const C = window.OFFICE || {};
// Barre d'outils du tableur, sans le bouton monétaire (symbole ¥ en dur) : la devise se règle par « Format »
const SHEET_TOOLBAR = ['undo', 'redo', 'format-painter', 'clear-format', '|', 'percentage-format', 'number-decrease', 'number-increase', 'format', '|', 'font', '|', 'font-size', '|',
  'bold', 'italic', 'strike-through', 'underline', '|', 'font-color', 'background', 'border', 'merge-cell', '|', 'horizontal-align', 'vertical-align', 'text-wrap', 'text-rotation', '|',
  'freeze', 'conditionFormat', 'filter', 'link', 'image', 'comment', 'quick-formula', 'dataVerification', 'splitColumn', 'search'];

const KIND = { doc: { label: 'Document', letter: 'W', color: '#2B579A' }, sheet: { label: 'Classeur', letter: 'X', color: '#217346' }, slides: { label: 'Présentation', letter: 'P', color: '#C43E1C' } };

async function loadFile() {
  const r = await fetch(C.content, { credentials: 'omit', cache: 'no-store' });
  if (!r.ok) throw new Error(r.status === 410 ? 'Session expirée : rouvrez le document depuis l\'application.' : 'Le document n\'a pas pu être chargé.');
  const ext = ['xls', 'ods'].includes(C.ext) ? 'xlsx' : C.ext;
  const base = C.name.replace(/\.[^.]+$/, '');
  const types = { docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', csv: 'text/csv', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation' };
  return new File([await r.arrayBuffer()], `${base}.${ext}`, { type: types[ext] || 'application/octet-stream' });
}

function closeEditor() {
  try { if (window.parent && window.parent !== window) return window.parent.postMessage({ type: 'yomara-office', event: 'close' }, '*'); } catch (e) {}
  try { if (window.flutter_inappwebview) return window.flutter_inappwebview.callHandler('office', 'close'); } catch (e) {}
  if (history.length > 1) history.back(); else window.close();
}

function notifyParent(event, data) {
  try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: 'yomara-office', event, ...data }, '*'); } catch (e) {}
  try { window.flutter_inappwebview && window.flutter_inappwebview.callHandler('office', event, data); } catch (e) {}
}

// ---------------------------------------------------------------- Copie de secours locale
// Chaque modification est aussi gardée sur l'appareil (IndexedDB) jusqu'à son enregistrement sur le serveur :
// après une fermeture imprévue, une coupure réseau ou un plantage, l'éditeur propose de la restaurer.
const RKEY = `${C.id || C.name}:${C.uid || ''}`;
function idb() {
  return new Promise((ok, ko) => {
    const r = indexedDB.open('yomara-office', 1);
    r.onupgradeneeded = () => r.result.createObjectStore('recovery');
    r.onsuccess = () => ok(r.result);
    r.onerror = () => ko(r.error);
  });
}
async function rec(op, value) {
  try {
    const db = await idb();
    return await new Promise((ok, ko) => {
      const tx = db.transaction('recovery', op === 'get' ? 'readonly' : 'readwrite');
      const st = tx.objectStore('recovery');
      const q = op === 'get' ? st.get(RKEY) : op === 'put' ? st.put(value, RKEY) : st.delete(RKEY);
      q.onsuccess = () => ok(q.result);
      q.onerror = () => ko(q.error);
    });
  } catch (e) { return null; }
}

const time = (d) => d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

// ---------------------------------------------------------------- Tableur
const SheetEditor = React.memo(function SheetEditor({ file, readOnly, onDirty, api, onError }) {
  const [mod, setMod] = useState(null);
  const ref = useRef(null);
  const [sheets, setSheets] = useState([{ name: 'Feuille 1' }]);
  const [key, setKey] = useState(0);
  const ready = useRef(false);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [{ Workbook }, fx] = await Promise.all([import('@fortune-sheet/react'), import('@corbe30/fortune-excel'), import('@fortune-sheet/react/dist/index.css')]);
        if (!alive) return;
        setMod({ Workbook, fx });
        await fx.transformExcelToFortune(file, setSheets, setKey, ref);
        setTimeout(() => { ready.current = true; }, 800);
      } catch (e) { onError(e); }
    })();
    return () => { alive = false; };
  }, []);

  api.current = {
    export: async () => {
      const blob = await mod.fx.transformFortuneToExcel(ref, C.ext === 'csv' ? 'csv' : 'xlsx', false);
      return blob;
    },
  };

  if (!mod) return <Loading text="Ouverture du classeur…" />;
  const { Workbook } = mod;
  return (
    <div className="sheet-host">
      <Workbook key={key} ref={ref} data={sheets} lang="fr" allowEdit={!readOnly} showFormulaBar currency="F CFA" defaultFontSize={11} toolbarItems={SHEET_TOOLBAR}
        onChange={() => { if (ready.current && !readOnly) onDirty(); }} />
    </div>
  );
});

// ---------------------------------------------------------------- Traitement de texte
const DOC_TEXTS = {
  bold: 'Gras', fontFamily: 'Police', ai: 'Rédaction assistée', fontSize: 'Taille', italic: 'Italique', underline: 'Souligné', highlight: 'Surlignage',
  strikethrough: 'Barré', color: 'Couleur du texte', search: 'Rechercher', link: 'Lien', image: 'Image', tableOfContents: 'Table des matières',
  table: 'Insérer un tableau', tableActions: 'Options du tableau', addRowBefore: 'Insérer une ligne au-dessus', addRowAfter: 'Insérer une ligne en dessous',
  addColumnBefore: 'Insérer une colonne à gauche', addColumnAfter: 'Insérer une colonne à droite', deleteRow: 'Supprimer la ligne', deleteColumn: 'Supprimer la colonne',
  deleteTable: 'Supprimer le tableau', removeBorders: 'Retirer les bordures', mergeCells: 'Fusionner les cellules', splitCell: 'Fractionner la cellule',
  fixTables: 'Réparer les tableaux', textAlign: 'Alignement', bulletList: 'Liste à puces', numberedList: 'Liste numérotée', indentLeft: 'Diminuer le retrait',
  indentRight: 'Augmenter le retrait', directionLtr: 'De gauche à droite', directionRtl: 'De droite à gauche', zoom: 'Zoom', undo: 'Annuler', redo: 'Rétablir',
  trackChanges: 'Suivi des modifications', trackChangesAccept: 'Accepter les modifications sélectionnées', trackChangesReject: 'Refuser les modifications sélectionnées',
  trackChangesOriginal: 'Afficher l\'original', trackChangesFinal: 'Afficher la version finale', clearFormatting: 'Effacer la mise en forme', copyFormat: 'Reproduire la mise en forme',
  lineHeight: 'Interligne', formatText: 'Style', ruler: 'Afficher ou masquer la règle', formattingMarks: 'Afficher ou masquer les marques de mise en forme',
  pageBreak: 'Insérer un saut de page', documentEditingMode: 'Modification', documentSuggestingMode: 'Suggestions', documentViewingMode: 'Lecture',
  documentEditingModeDescription: 'Modifier directement le document', documentSuggestingModeDescription: 'Les modifications deviennent des suggestions',
  documentViewingModeDescription: 'Voir la version propre du document', linkedStyles: 'Styles',
};

const DocEditor = React.memo(function DocEditor({ file, readOnly, onDirty, api, onError }) {
  const host = useRef(null);
  const sd = useRef(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [{ SuperDoc }] = await Promise.all([import('@harbour-enterprises/superdoc'), import('@harbour-enterprises/superdoc/style.css')]);
        if (!alive) return;
        let loaded = false;
        sd.current = new SuperDoc({
          selector: '#doc-editor',
          toolbar: readOnly ? undefined : '#doc-toolbar',
          toolbarTexts: DOC_TEXTS,
          documentMode: readOnly ? 'viewing' : 'editing',
          documents: [{ id: 'doc', type: 'docx', data: file }],
          user: C.user,
          pagination: true,
          rulers: true,
          onReady: () => { setReady(true); setTimeout(() => { loaded = true; }, 1200); },
          onEditorUpdate: () => { if (loaded && !readOnly) onDirty(); },
          onException: (e) => console.warn(e),
          telemetry: { enabled: false }, // aucune donnée envoyée à l'éditeur de la bibliothèque
        });
      } catch (e) { onError(e); }
    })();
    return () => { alive = false; try { sd.current?.destroy?.(); } catch (e) {} };
  }, []);

  api.current = { export: () => sd.current.export({ isFinalDoc: false, triggerDownload: false, commentsType: 'external' }) };

  return (
    <div className="doc-host">
      {!readOnly && <div id="doc-toolbar" className="doc-toolbar" />}
      <div className="doc-scroll"><div id="doc-editor" ref={host} /></div>
      {!ready && <Loading text="Ouverture du document…" overlay />}
    </div>
  );
});

function Loading({ text, overlay }) {
  return <div className={overlay ? 'loading overlay' : 'loading'}><div className="spinner" /><span>{text}</span></div>;
}

// ---------------------------------------------------------------- Application
function App() {
  const kind = KIND[C.kind] || KIND.doc;
  const [file, setFile] = useState(null);
  const [error, setError] = useState(null);
  const [mode, setMode] = useState(C.mode);
  const [lockedBy, setLockedBy] = useState(C.lockedBy);
  const [status, setStatus] = useState({ state: 'saved', at: null, version: C.version });
  const [people, setPeople] = useState([]);
  const api = useRef(null);
  const dirty = useRef(false);
  const saving = useRef(false);
  const timer = useRef(null);
  const firstDirty = useRef(null);
  const snapTimer = useRef(null);
  const editSeq = useRef(0);
  const [recovery, setRecovery] = useState(null);
  const [gen, setGen] = useState(0);
  const readOnly = mode !== 'edit';
  const onError = useCallback((e) => setError((C.kind === 'sheet' ? 'Ce classeur' : 'Ce document') + ' n\'a pas pu être ouvert : ' + e.message), []);

  useEffect(() => {
    loadFile().then(setFile).catch((e) => setError(e.message));
    if (C.mode === 'edit') rec('get').then((r) => { if (r && r.blob) setRecovery(r); });
  }, []);

  // Copie de secours : 2 s après la dernière modification (au plus tard 10 s)
  const snapshot = useCallback(async () => {
    clearTimeout(snapTimer.current);
    snapTimer.current = null;
    if (readOnly || !api.current || !dirty.current) return;
    try {
      const seq = editSeq.current;
      const blob = await api.current.export();
      if (seq === editSeq.current || dirty.current) await rec('put', { blob, base: C.version, at: Date.now(), name: C.name, seq });
    } catch (e) {}
  }, [readOnly]);
  useEffect(() => { document.title = `${C.name} · Yomara Office`; }, []);

  const save = useCallback(async () => {
    if (readOnly || saving.current || !dirty.current || !api.current) return;
    saving.current = true;
    dirty.current = false;
    firstDirty.current = null;
    const seq = editSeq.current;
    setStatus((s) => ({ ...s, state: 'saving' }));
    try {
      const blob = await api.current.export();
      const fd = new FormData();
      fd.append('file', blob, C.name.replace(/\.(xls|ods)$/i, '.xlsx'));
      const r = await fetch(C.save, { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j.message || 'Enregistrement impossible.');
      setStatus({ state: 'saved', at: new Date(), version: j.data.version });
      C.version = j.data.version;
      if (seq === editSeq.current && !dirty.current) { clearTimeout(snapTimer.current); snapTimer.current = null; rec('del'); }
      notifyParent('saved', { version: j.data.version });
    } catch (e) {
      dirty.current = true;
      setStatus((s) => ({ ...s, state: 'error', message: e.message }));
    } finally {
      saving.current = false;
      if (dirty.current) { clearTimeout(timer.current); timer.current = setTimeout(save, 5000); }
    }
  }, [readOnly]);

  const onDirty = useCallback(() => {
    dirty.current = true;
    editSeq.current++;
    if (!snapTimer.current) snapTimer.current = setTimeout(snapshot, 10000);
    else { clearTimeout(snapTimer.current); snapTimer.current = setTimeout(snapshot, 2000); }
    setStatus((s) => (s.state === 'saving' || s.state === 'dirty' ? s : { ...s, state: 'dirty' }));
    // Enregistrement 3 s après la dernière modification, et au plus tard 15 s après la première
    clearTimeout(timer.current);
    firstDirty.current ??= Date.now();
    timer.current = setTimeout(save, Date.now() - firstDirty.current > 15000 ? 0 : 3000);
  }, [save, snapshot]);

  // Présence et verrou de modification
  useEffect(() => {
    let stop = false;
    const tick = async () => {
      try {
        const r = await fetch(C.ping, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ editing: mode === 'edit' }) });
        if (r.ok) {
          const d = (await r.json()).data;
          setPeople(d.people);
          if (mode === 'edit' && !d.lock.mine) { setMode('view'); setLockedBy(d.lock.name); }
          if (mode === 'view') setLockedBy(d.lock.mine ? null : d.lock.name);
        }
      } catch (e) {}
      if (!stop) setTimeout(tick, 15000);
    };
    tick();
    return () => { stop = true; };
  }, [mode]);

  // Ctrl+S, et rien de perdu en fermant l'onglet
  useEffect(() => {
    const k = (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); clearTimeout(timer.current); save(); } };
    const b = (e) => { if (dirty.current || saving.current) { e.preventDefault(); e.returnValue = ''; } };
    // Application mise en arrière-plan ou fenêtre fermée : copie de secours puis enregistrement immédiat
    const h = () => { if (document.visibilityState === 'hidden' && dirty.current) { snapshot(); clearTimeout(timer.current); save(); } };
    window.addEventListener('keydown', k, true);
    window.addEventListener('beforeunload', b);
    document.addEventListener('visibilitychange', h);
    window.addEventListener('pagehide', h);
    return () => {
      window.removeEventListener('keydown', k, true); window.removeEventListener('beforeunload', b);
      document.removeEventListener('visibilitychange', h); window.removeEventListener('pagehide', h);
    };
  }, [save, snapshot]);

  const restore = () => {
    const r = recovery;
    setRecovery(null);
    const ext = ['xls', 'ods'].includes(C.ext) ? 'xlsx' : C.ext;
    setFile(new File([r.blob], `${C.name.replace(/\.[^.]+$/, '')}.${ext}`, { type: r.blob.type || 'application/octet-stream' }));
    setGen((g) => g + 1);
    // la copie restaurée part au serveur comme une nouvelle version, dès que l'éditeur l'a ouverte
    setTimeout(() => { dirty.current = true; editSeq.current++; clearTimeout(timer.current); save(); }, 2500);
  };
  const dismiss = () => { setRecovery(null); rec('del'); };
  const when = (t) => new Date(t).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

  const download = async () => {
    const blob = api.current ? await api.current.export() : file;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = C.name.replace(/\.(xls|ods)$/i, '.xlsx');
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  };

  const close = async () => {
    if (dirty.current) { clearTimeout(timer.current); await save(); }
    closeEditor();
  };

  const statusText = {
    saved: status.at ? `Enregistré à ${time(status.at)} · version ${status.version}` : `Version ${status.version}`,
    dirty: 'Modifications en cours…',
    saving: 'Enregistrement…',
    error: status.message || 'Enregistrement impossible',
  }[status.state];

  const others = people.filter((p) => !p.me);
  return (
    <div className="app" style={{ '--brand': kind.color }}>
      <header className="bar">
        <div className="badge" aria-hidden>{kind.letter}</div>
        <div className="title">
          <div className="name" title={C.name}>{C.name}</div>
          <div className={`status ${readOnly ? 'readonly' : status.state}`}>
            {readOnly ? (lockedBy ? `Lecture seule : ${lockedBy} modifie ce fichier` : (C.kind === 'slides' ? 'Présentation en lecture' : 'Lecture seule')) : statusText}
          </div>
        </div>
        <div className="people">
          {others.slice(0, 4).map((p, i) => <span key={i} className="avatar" title={`${p.name} a ce fichier ouvert`}>{p.name.slice(0, 1).toUpperCase()}</span>)}
          {others.length > 4 && <span className="avatar more">+{others.length - 4}</span>}
        </div>
        {!readOnly && (
          <button className="btn primary" onClick={() => { clearTimeout(timer.current); dirty.current = true; save(); }} disabled={status.state === 'saving'} title="Ctrl+S">
            Enregistrer
          </button>
        )}
        <button className="btn" onClick={download} disabled={!file}>Télécharger</button>
        <button className="btn icon" onClick={close} title="Fermer" aria-label="Fermer">✕</button>
      </header>
      {recovery && !readOnly && (
        <div className="recover" role="alert">
          <span>
            <strong>Modifications non enregistrées retrouvées</strong> ({when(recovery.at)}).
            {recovery.base !== C.version ? ' Le fichier a été modifié depuis : la restauration créera une nouvelle version, l\'actuelle reste dans l\'historique.' : ' Restaurez-les pour reprendre là où vous en étiez.'}
          </span>
          <button className="btn primary" onClick={restore}>Restaurer</button>
          <button className="btn" onClick={dismiss}>Ignorer</button>
        </div>
      )}
      <main className="body">
        {error ? (
          <div className="message"><strong>{error}</strong></div>
        ) : !file ? (
          <Loading text={`Ouverture du ${kind.label.toLowerCase()}…`} />
        ) : C.kind === 'sheet' ? (
          <SheetEditor key={gen} file={file} readOnly={readOnly} onDirty={onDirty} api={api} onError={onError} />
        ) : C.kind === 'doc' ? (
          <DocEditor key={gen} file={file} readOnly={readOnly} onDirty={onDirty} api={api} onError={onError} />
        ) : (
          <div className="message">
            <strong>L'aperçu des présentations arrive bientôt.</strong>
            <span>En attendant, téléchargez le fichier pour l'ouvrir.</span>
          </div>
        )}
      </main>
    </div>
  );
}

createRoot(document.getElementById('root')).render(<App />);
