<?php

namespace App\Modules\ImportExport;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** Lecture / écriture Excel (.xlsx) et CSV, sans dépendance lourde (fonctionne en hébergement mutualisé). */
class Sheet
{
    /** Lit la première feuille : [en-têtes, lignes (tableaux indexés)]. Lignes vides ignorées. */
    public static function read(string $path, int $limit = 20000): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $path = self::normalizeCsv($path);
            $first = (string) fgets(fopen($path, 'r'));
            $delim = collect([';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $delim));
        } else {
            $reader = new XlsxReader();
        }
        $reader->open($path);
        $headers = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_string($v) ? trim($v) : $v), $row->toArray());
                if (! array_filter($values, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }
                if ($headers === null) {
                    $headers = array_map(fn ($v) => trim((string) $v), $values);

                    continue;
                }
                $rows[] = $values;
                if (count($rows) >= $limit) {
                    break;
                }
            }
            break; // première feuille seulement
        }
        $reader->close();

        return [$headers ?? [], $rows];
    }

    /** CSV en Windows-1252 (Excel français) → UTF-8, sans BOM. */
    protected static function normalizeCsv(string $path): string
    {
        $raw = file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $out = $path.'.utf8.csv';
        file_put_contents($out, $raw);

        return $out;
    }

    /**
     * Classeur .xlsx de plusieurs feuilles : [nom de feuille => [en-têtes, lignes, indices des lignes en gras (facultatif)]].
     * Une ligne vide ([]) sépare les blocs.
     */
    public static function writeBook(string $path, array $sheets): void
    {
        $w = new XlsxWriter();
        $w->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $sheet) {
            [$headers, $rows] = $sheet;
            $bold = array_flip($sheet[2] ?? []);
            if (! $first) {
                $w->addNewSheetAndMakeItCurrent();
            }
            $first = false;
            $w->getCurrentSheet()->setName(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string) $name), 0, 31));
            if ($headers) {
                $w->addRow(Row::fromValuesWithStyle($headers, new Style(fontBold: true, backgroundColor: 'FFC531')));
            }
            foreach (array_values($rows) as $i => $r) {
                $vals = array_map(fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : $v, array_values($r));
                $w->addRow(\App\Support\SafeCell::xlsxRow($vals, isset($bold[$i]) ? new Style(fontBold: true) : null));
            }
        }
        $w->close();
    }

    /** Écrit un fichier .xlsx ou .csv (séparateur « ; » pour Excel français). */
    public static function write(string $path, array $headers, iterable $rows): void
    {
        if (str_ends_with($path, '.csv')) {
            $w = new CsvWriter(new CsvWriterOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
            $w->openToFile($path);
            $w->addRow(Row::fromValues($headers));
        } else {
            $w = new XlsxWriter();
            $w->openToFile($path);
            $w->addRow(Row::fromValuesWithStyle($headers, new Style(fontBold: true, backgroundColor: 'FFC531')));
        }
        $csv = str_ends_with($path, '.csv');
        foreach ($rows as $r) {
            $vals = array_map(fn ($v) => is_bool($v) ? ($v ? 'oui' : 'non') : $v, array_values($r));
            $w->addRow($csv ? Row::fromValues(\App\Support\SafeCell::csvRow($vals)) : \App\Support\SafeCell::xlsxRow($vals));
        }
        $w->close();
    }
}
