import 'local_db.dart';

/// Certification FNE (DGI) d'un document, synchronisée depuis le serveur.
Map<String, dynamic>? fneCertFor(String? documentId) {
  if (documentId == null || !LocalDb.hasEntity('fne_certifications')) return null;
  for (final c in LocalDb.all('fne_certifications')) {
    if (c['document_id'] == documentId) return c;
  }
  return null;
}

const fneStatusLabels = {'pending': 'FNE en attente', 'certified': 'Certifiée FNE', 'failed': 'FNE refusée'};

const fneTemplateLabels = {'B2C': 'Particulier (B2C)', 'B2B': 'Entreprise (B2B)', 'B2G': 'Administration (B2G)', 'B2F': 'Étranger (B2F)'};

const fnePaymentLabels = {
  'cash': 'Espèces',
  'card': 'Carte bancaire',
  'check': 'Chèque',
  'mobile-money': 'Mobile money',
  'transfer': 'Virement',
  'deferred': 'À terme (crédit)',
};
