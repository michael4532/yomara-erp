<?php

namespace App\Modules\Migration\Sources;

/**
 * Source de migration : renvoie des enregistrements au format commun.
 *
 * partner  : id, name, type (customer|supplier|both), code, email, phone, address, city, tax_id, notes
 * product  : id, name, sku, barcode, category, type (product|service), unit, sale_price, purchase_price, tax_rate, stock, active
 * document : id, number, direction (sale|purchase), type (invoice|credit_note|quote|order), partner_id, partner_name, date, due_date,
 *            status (draft|validated|cancelled), currency, reference, total, paid, payments[{date, amount, method}],
 *            lines[{description, product_id, quantity, unit_price, tax_rate, discount}]
 */
abstract class Source
{
    /** @var string[] avertissements remontés à l'utilisateur */
    public array $warnings = [];

    abstract public function key(): string;

    /** Vérifie la connexion et renvoie ['name' => …, 'counts' => [entité => nombre|null], 'samples' => […]]. */
    abstract public function probe(): array;

    /** @return iterable<array> */
    abstract public function partners(): iterable;

    /** @return iterable<array> */
    abstract public function products(): iterable;

    /** @return iterable<array> documents de ventes (sale) ou d'achats (purchase) */
    abstract public function documents(string $direction): iterable;

    protected function warn(string $m): void
    {
        if (count($this->warnings) < 200) {
            $this->warnings[] = $m;
        }
    }

    protected static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    protected static function date(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        if (is_numeric($v) && (int) $v > 100000000) {
            return date('Y-m-d', (int) $v);
        }
        $s = substr((string) $v, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    protected static function clean(?string $v): ?string
    {
        $v = $v === null ? null : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $v)), ENT_QUOTES | ENT_HTML5));

        return $v === '' ? null : $v;
    }
}
