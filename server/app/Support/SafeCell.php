<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Exports tableur sans « injection de formules » : un texte saisi par un visiteur (« =HYPERLINK(…) », « +cmd », « @SOMME »)
 * ne doit jamais devenir une formule dans Excel / LibreOffice quand l'équipe ouvre l'export.
 */
class SafeCell
{
    /** Valeur CSV : préfixe « ' » devant un texte commençant par = + - @ tabulation ou retour (sauf un nombre comme « -12,5 »). */
    public static function csv(mixed $v): mixed
    {
        if (! is_string($v) || $v === '' || ! in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $v;
        }

        return is_numeric(str_replace([',', ' '], ['.', ''], $v)) ? $v : "'".$v;
    }

    public static function csvRow(array $vals): array
    {
        return array_map([self::class, 'csv'], array_values($vals));
    }

    /** Ligne .xlsx dont les textes restent des textes (openspout transforme sinon « =… » en formule). */
    public static function xlsxRow(array $vals, ?Style $style = null): Row
    {
        return new Row(array_map(fn ($v) => is_string($v) && $v !== '' ? new StringCell($v, $style) : Cell::fromValue($v, $style), array_values($vals)));
    }
}
