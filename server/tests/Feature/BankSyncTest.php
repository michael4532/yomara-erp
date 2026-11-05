<?php

namespace Tests\Feature;

use App\Modules\Tresorerie\Bank\StatementFormats;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lot d'intégration : relevés bancaires normalisés, synchronisation (Wave Business, open banking, e-mail), règles, encaissement Mobile Money direct. */
class BankSyncTest extends TestCase
{
    public const OFX = "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>XOF<BANKTRANLIST>\n"
        ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261015120000<TRNAMT>250000.00<FITID>OFX-1<NAME>VIR HOTEL IVOIRE<MEMO>Facture FAC-0001</STMTTRN>\n"
        ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261016<TRNAMT>-3500.00<FITID>OFX-2<NAME>FRAIS TENUE DE COMPTE</STMTTRN>\n"
        ."</BANKTRANLIST><LEDGERBAL><BALAMT>1246500.00<DTASOF>20261016</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";

    public const MT940 = ":20:STMT2610\n:25:CI0080011234567\n:28C:00045/001\n:60F:C261014XOF1000000,00\n"
        .":61:2610151015C125000,00NTRFNONREF//BK778899\n:86:VIREMENT RECU SOCIETE IVOIRE TELECOM\n"
        .":61:2610161016D45000,50NCHK0001234//BK778900\n:86:CHEQUE 0001234\n:62F:C261016XOF1079999,50\n-}";

    public const CAMT = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Acct><Ccy>EUR</Ccy></Acct>'
        .'<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="EUR">5120.40</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
        .'<Ntry><NtryRef>N1</NtryRef><Amt Ccy="EUR">980.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-10-20</Dt></BookgDt><AcctSvcrRef>CAMT-1</AcctSvcrRef>'
        .'<NtryDtls><TxDtls><RltdPties><Dbtr><Nm>DUPONT SARL</Nm></Dbtr></RltdPties><RmtInf><Ustrd>Facture 2026-118</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
        .'<Ntry><Amt Ccy="EUR">49.90</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-10-21</Dt></BookgDt><AcctSvcrRef>CAMT-2</AcctSvcrRef><AddtlNtryInf>PRLV ORANGE INTERNET</AddtlNtryInf></Ntry>'
        .'<Ntry><Amt Ccy="EUR">10.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>PDNG</Sts><BookgDt><Dt>2026-10-22</Dt></BookgDt></Ntry>'
        .'</Stmt></BkToCstmrStmt></Document>';

    public static function cfonb(): string
    {
        $rec = function (string $code, string $date, string $label, string $amount, string $ref = '', string $num = '') {
            $l = $code.'30001'.'    '.'00001'.'XOF'.'2'.' '.str_pad('12345678901', 11).'  '.$date.'  '.$date.str_pad($label, 31).'  '.str_pad($num, 7).'  '.str_pad($amount, 14, '0', STR_PAD_LEFT).str_pad($ref, 16);

            return str_pad($l, 120);
        };

        return implode("\n", [
            $rec('01', '141026', '', '100000{'),
            $rec('04', '151026', 'REMISE CHEQUES', '7500{', 'REM001', '0000001'),
            $rec('04', '161026', 'PRLV CIE ELECTRICITE', '1234J', '', '0000002'),
            $rec('07', '161026', '', '106266{'),
        ]);
    }

    public function test_standard_statement_formats(): void
    {
        $o = StatementFormats::parse(self::OFX);
        $this->assertEquals(['ofx', 2, '2026-10-15', 250000.0, 'OFX-1', -3500.0, 1246500.0], [$o['format'], count($o['lines']), $o['lines'][0]['date'], $o['lines'][0]['amount'], $o['lines'][0]['external_id'], $o['lines'][1]['amount'], $o['closing']]);
        $this->assertStringContainsString('HOTEL IVOIRE', $o['lines'][0]['label']);

        $m = StatementFormats::parse(self::MT940);
        $this->assertEquals(['mt940', 2, '2026-10-15', 125000.0, -45000.5, 'BK778899', 1000000.0, 1079999.5, 'XOF'], [$m['format'], count($m['lines']), $m['lines'][0]['date'], $m['lines'][0]['amount'],
            $m['lines'][1]['amount'], $m['lines'][0]['external_id'], $m['opening'], $m['closing'], $m['currency']]);
        $this->assertSame('VIREMENT RECU SOCIETE IVOIRE TELECOM', $m['lines'][0]['label']);

        $c = StatementFormats::parse(self::CAMT);
        $this->assertEquals(['camt', 2, 980.0, -49.9, 'DUPONT SARL — Facture 2026-118', 'CAMT-1', 5120.4], [$c['format'], count($c['lines']), $c['lines'][0]['amount'], $c['lines'][1]['amount'],
            $c['lines'][0]['label'], $c['lines'][0]['external_id'], $c['closing']]);

        $f = StatementFormats::parse(self::cfonb());
        $this->assertEquals(['cfonb', 2, '2026-10-15', 750.0, -123.41, 'REMISE CHEQUES', 'REM001'], [$f['format'], count($f['lines']), $f['lines'][0]['date'], $f['lines'][0]['amount'],
            $f['lines'][1]['amount'], $f['lines'][0]['label'], $f['lines'][0]['reference']]);
        $this->assertEquals([750000.0, -123.1], [StatementFormats::cfonbAmount('7500000{', 2), StatementFormats::cfonbAmount('123J', 1)]);
        $this->assertNull(StatementFormats::detect("Date;Libellé;Montant\n01/10/2026;Test;100"));
    }

    public function test_statement_import_rules_and_connections(): void
    {
        [$company, , $token] = $this->makeCompany('Banque Test SARL');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/tresorerie/summary')->assertOk();
        $acc = collect($api()->getJson('/api/tresorerie/accounts')->json('data'))->keyBy('kind');
        $bank = $acc['bank']['id'];

        // Règle : frais bancaires reconnus automatiquement
        $rule = $api()->postJson('/api/tresorerie/bank/rules', ['name' => 'Frais bancaires', 'contains' => 'frais, agios', 'direction' => 'out', 'category' => 'bank_fees'])->assertCreated()->json('data');

        // Relevé OFX déposé : reconnu sans correspondance de colonnes
        $path = tempnam(sys_get_temp_dir(), 'ofx');
        file_put_contents($path, self::OFX);
        $a = $api()->post("/api/tresorerie/accounts/$bank/statement/analyze", ['file' => new UploadedFile($path, 'releve.ofx', null, null, true)])->assertOk()->json('data');
        $this->assertEquals(['ofx', 2], [$a['format'], $a['rows']]);
        $r = $api()->postJson("/api/tresorerie/accounts/$bank/statement/import", ['file' => $a['file'], 'filename' => 'releve.ofx'])->assertOk()->json('data');
        $this->assertEquals([2, 0, 1], [$r['imported'], $r['duplicates'], $r['rules']]);
        $this->assertSame(0, $api()->postJson("/api/tresorerie/accounts/$bank/statement/import", ['file' => $a['file'], 'filename' => 'releve.ofx'])->assertOk()->json('data.imported'));
        $this->assertTrue(DB::table('tr_operations')->where('company_id', $company->id)->where('category', 'bank_fees')->where('amount', -3500)->exists());
        $this->assertEquals(1, DB::table('tr_bank_rules')->where('id', $rule['id'])->value('hits'));
        $lines = collect($api()->getJson("/api/tresorerie/accounts/$bank/statement/lines?status=all")->json('data.lines'))->keyBy('amount');
        $this->assertSame('matched', $lines[-3500]['status'] ?? $lines['-3500']['status']);

        // Wave Business : opérations du jour, frais séparés, solde ; pas de doublon à la synchronisation suivante
        Http::fake([
            'api.wave.com/v1/transactions*' => Http::response(['page_info' => ['has_next_page' => false], 'items' => [
                ['timestamp' => now()->toIso8601String(), 'transaction_id' => 'T_AAA', 'amount' => '15000', 'fee' => '150', 'currency' => 'XOF', 'counterparty_name' => 'Awa Bamba', 'counterparty_mobile' => '+2250700000001', 'transaction_type' => 'merchant_payment'],
                ['timestamp' => now()->toIso8601String(), 'transaction_id' => 'T_BBB', 'amount' => '-5000', 'fee' => '0', 'currency' => 'XOF', 'counterparty_name' => 'Fournisseur', 'transaction_type' => 'b2b_payment'],
            ]]),
            'api.wave.com/v1/balance' => Http::response(['amount' => '987000', 'currency' => 'XOF']),
        ]);
        $momo = $acc['mobile_money']['id'];
        $w = $api()->postJson('/api/tresorerie/bank/connections', ['provider' => 'wave', 'account_id' => $momo, 'api_key' => 'wave_sn_prod_xxx', 'sync_from' => now()->toDateString()])->assertCreated()->json();
        $this->assertEquals(['active', 987000.0], [$w['data']['status'], $w['data']['last_balance']]);
        $this->assertSame(3, $w['sync']['imported']); // encaissement, frais, paiement fournisseur
        $this->assertSame(0, $api()->postJson("/api/tresorerie/bank/connections/{$w['data']['id']}/sync")->assertOk()->json('result.imported'));
        $this->assertTrue(DB::table('tr_statement_lines')->where('account_id', $momo)->where('label', 'like', 'Frais Wave%')->where('amount', -150)->exists());

        // Banque européenne (GoCardless) : lien d'autorisation, retour de la banque, import
        config(['erp.bank.gocardless_secret_id' => 'sid', 'erp.bank.gocardless_secret_key' => 'skey']);
        Cache::flush();
        Http::fake([
            'bankaccountdata.gocardless.com/api/v2/token/new/' => Http::response(['access' => 'acc-token', 'access_expires' => 86400]),
            'bankaccountdata.gocardless.com/api/v2/institutions/*' => Http::response([['id' => 'BNP_BNPAFRPP', 'name' => 'BNP Paribas', 'logo' => 'x', 'transaction_total_days' => '730']]),
            'bankaccountdata.gocardless.com/api/v2/agreements/enduser/' => Http::response(['id' => 'agr-1']),
            'bankaccountdata.gocardless.com/api/v2/requisitions/' => Http::response(['id' => 'req-1', 'link' => 'https://ob.gocardless.com/psd2/start/req-1']),
            'bankaccountdata.gocardless.com/api/v2/requisitions/req-1/' => Http::response(['id' => 'req-1', 'status' => 'LN', 'accounts' => ['acct-1']]),
            'bankaccountdata.gocardless.com/api/v2/accounts/acct-1/transactions/*' => Http::response(['transactions' => ['booked' => [
                ['transactionId' => 'GC-1', 'bookingDate' => now()->toDateString(), 'transactionAmount' => ['amount' => '1200.50', 'currency' => 'EUR'], 'debtorName' => 'Client Paris', 'remittanceInformationUnstructured' => 'Commande 42'],
            ], 'pending' => []]]),
            'bankaccountdata.gocardless.com/api/v2/accounts/acct-1/balances/' => Http::response(['balances' => [['balanceAmount' => ['amount' => '8400.00', 'currency' => 'EUR'], 'balanceType' => 'interimBooked']]]),
        ]);
        $this->assertSame('BNP Paribas', $api()->getJson('/api/tresorerie/bank/institutions?country=fr')->assertOk()->json('data.0.name'));
        $boa = $api()->postJson('/api/tresorerie/accounts', ['name' => 'BNP Paris', 'kind' => 'bank', 'methods' => []])->assertCreated()->json('data');
        $g = $api()->postJson('/api/tresorerie/bank/connections', ['provider' => 'gocardless', 'account_id' => $boa['id']])->assertCreated()->json('data');
        $this->assertSame('pending', $g['status']);
        $l = $api()->postJson("/api/tresorerie/bank/connections/{$g['id']}/link", ['institution_id' => 'BNP_BNPAFRPP', 'institution_name' => 'BNP Paribas'])->assertOk()->json();
        $this->assertStringStartsWith('https://ob.gocardless.com/', $l['link']);
        $this->get('/banque/retour/'.$g['id'])->assertOk()->assertSee('Banque connectée');
        $this->assertTrue(DB::table('tr_statement_lines')->where('account_id', $boa['id'])->where('external_id', 'GC-1')->where('amount', 1200.5)->exists());
        $con = collect($api()->getJson('/api/tresorerie/bank')->assertOk()->json('data.connections'))->keyBy('provider');
        $this->assertEquals(['active', 8400.0, 'BNP Paribas'], [$con['gocardless']['status'], $con['gocardless']['last_balance'], $con['gocardless']['institution']]);

        // Relevés reçus par e-mail : pièce jointe MT940 transférée par le service de réception
        $e = $api()->postJson('/api/tresorerie/bank/connections', ['provider' => 'email', 'account_id' => $bank, 'label' => 'Relevés SGCI'])->assertCreated()->json('data');
        $tok = basename($e['inbound_url']);
        $mt = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($mt, self::MT940);
        $this->flushHeaders();
        $in = $this->post('/api/bank-inbound/'.$tok, ['subject' => 'Relevé SGCI', 'attachment-1' => new UploadedFile($mt, 'releve.sta', null, null, true)], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertEquals([1, 2], [$in['files'], $in['imported']]);
        $this->post('/api/bank-inbound/'.str_repeat('a', 32), ['x' => 1], ['Accept' => 'application/json'])->assertNotFound();
        // Postmark : pièce jointe en base64
        $in2 = $this->postJson('/api/bank-inbound/'.$tok, ['Subject' => 'CAMT', 'Attachments' => [['Name' => 'camt.xml', 'Content' => base64_encode(self::CAMT)]]])->assertOk()->json('data');
        $this->assertSame(2, $in2['imported']);

        // Synchronisation planifiée : la connexion Wave arrivée à échéance est relancée
        DB::table('tr_bank_connections')->where('provider', 'wave')->update(['next_sync_at' => now()->subMinute()]);
        Http::fake(['api.wave.com/*' => Http::response(['page_info' => ['has_next_page' => false], 'items' => []])]);
        $this->artisan('erp:bank-sync')->assertSuccessful();
        $this->assertTrue(\Carbon\Carbon::parse(DB::table('tr_bank_connections')->where('provider', 'wave')->value('next_sync_at'))->isFuture());
    }

    public function test_mobile_money_direct_collection(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique MoMo');
        $api = fn () => $this->api($token);
        $mtn = $api()->postJson('/api/payments/gateways', ['driver' => 'mtn_momo', 'label' => 'MTN MoMo', 'mode' => 'live',
            'credentials' => ['subscription_key' => 'sub', 'api_user' => '0c7a5a5e-1111-2222-3333-444455556666', 'api_key' => 'key', 'target_environment' => 'mtnivorycoast']])->assertCreated()->json();
        $mtnId = $mtn['id'] ?? $mtn['data']['id'];
        $wave = $api()->postJson('/api/payments/gateways', ['driver' => 'wave', 'label' => 'Wave', 'credentials' => ['api_key' => 'wave_key']])->assertCreated()->json();
        $waveId = $wave['id'] ?? $wave['data']['id'];
        $gws = collect($api()->getJson('/api/payments/collect/gateways')->assertOk()->json('data'))->keyBy('driver');
        $this->assertEquals(['push', 'qr'], [$gws['mtn_momo']['mode'], $gws['wave']['mode']]);

        Http::fake([
            'proxy.momoapi.mtn.com/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'proxy.momoapi.mtn.com/collection/v1_0/requesttopay' => Http::response(null, 202),
            'proxy.momoapi.mtn.com/collection/v1_0/requesttopay/*' => Http::sequence()->push(['status' => 'PENDING'])->push(['status' => 'SUCCESSFUL', 'financialTransactionId' => '998877']),
            'api.wave.com/v1/checkout/sessions' => Http::response(['id' => 'cos-1', 'wave_launch_url' => 'https://pay.wave.com/c/cos-1']),
        ]);
        // Caisse : sans numéro, refus ; avec numéro, la demande part sur le téléphone du client
        $api()->postJson('/api/payments/collect', ['gateway_id' => $mtnId, 'amount' => 12500])->assertStatus(422);
        $tx = $api()->postJson('/api/payments/collect', ['gateway_id' => $mtnId, 'amount' => 12500, 'phone' => '07 07 12 34 56', 'label' => 'Ticket caisse 1'])->assertCreated()->json('data');
        $this->assertEquals(['push', 'pending', '2250707123456'], [$tx['mode'], $tx['status'], $tx['phone']]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'requesttopay') && $req['payer']['partyId'] === '2250707123456' && $req['amount'] === '12500' && $req->header('X-Target-Environment')[0] === 'mtnivorycoast');
        $this->assertSame('pending', $api()->getJson("/api/payments/collect/{$tx['id']}")->assertOk()->json('data.status'));
        $this->assertEquals(['paid', 'mobile_money'], [$api()->getJson("/api/payments/collect/{$tx['id']}")->json('data.status'), $api()->getJson("/api/payments/collect/{$tx['id']}")->json('data.method')]);

        // QR code Wave : lien de paiement à scanner
        $q = $api()->postJson('/api/payments/collect', ['gateway_id' => $waveId, 'amount' => 3000])->assertCreated()->json('data');
        $this->assertEquals(['qr', 'https://pay.wave.com/c/cos-1'], [$q['mode'], $q['url']]);
        $this->assertSame('cancelled', $api()->postJson("/api/payments/collect/{$q['id']}/cancel")->assertOk()->json('data.status'));

        // La page publique de paiement des factures ne propose pas le paiement direct (pas de numéro à saisir)
        $this->assertFalse(\App\Payments\InvoicePayments::gateways($company)->contains('driver', 'mtn_momo'));
        $this->assertSame('2250102030405', \App\Payments\Gateways\MtnMomo::msisdn('+225 01 02 03 04 05'));
    }
}
