<?php

namespace App\Modules\TableauxExcel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Classeur Excel interactif : feuille « Tableau de bord » (filtres en listes déroulantes, indicateurs, graphiques,
 * classements), feuille « Données » (tableau structuré filtrable), feuille « Calculs » (tables des graphiques),
 * feuille « Mode d'emploi ». Toutes les valeurs du tableau de bord sont des formules SOMME.SI.ENS sur les données :
 * changer un filtre recalcule indicateurs, graphiques et classements, dans Excel comme dans LibreOffice.
 */
class XlsxDashboard
{
    public const ALL = '(Tous)';

    public const THEMES = [
        'ocean' => ['name' => 'Océan', 'primary' => '1F6FEB', 'deep' => '0B1B3A', 'tint' => 'EAF2FE', 'soft' => 'F5F8FD', 'chart' => ['1F6FEB', '12A150', 'FFB020', '7C4DFF', 'EF5DA8', '00B8D9', '9AA8BF']],
        'emeraude' => ['name' => 'Émeraude', 'primary' => '0F9D58', 'deep' => '0B3D2E', 'tint' => 'E5F5EC', 'soft' => 'F4FAF6', 'chart' => ['0F9D58', '1F6FEB', 'F4B400', '00897B', 'DB4437', '7CB342', 'A0AEC0']],
        'ambre' => ['name' => 'Ambre', 'primary' => 'E8710A', 'deep' => '4A2500', 'tint' => 'FDEFE1', 'soft' => 'FEF8F2', 'chart' => ['E8710A', '0B1B3A', 'F6C343', 'C2410C', '1F6FEB', '16A34A', 'A8A29E']],
        'violet' => ['name' => 'Améthyste', 'primary' => '6A4BD8', 'deep' => '2A1B5E', 'tint' => 'EFEBFC', 'soft' => 'F8F6FE', 'chart' => ['6A4BD8', 'EF5DA8', '00B8D9', 'FFB020', '12A150', '3F3D99', 'A0A3BD']],
        'corail' => ['name' => 'Corail', 'primary' => 'E5484D', 'deep' => '3B0D11', 'tint' => 'FDECEC', 'soft' => 'FEF6F6', 'chart' => ['E5484D', '0B1B3A', 'F59E0B', '0EA5E9', '10B981', '8B5CF6', 'A1A1AA']],
        'nuit' => ['name' => 'Nuit', 'dark' => true, 'primary' => '4C8DFF', 'deep' => '07122A', 'tint' => '13274F', 'soft' => '0B1B3A', 'chart' => ['4C8DFF', '34D399', 'FBBF24', 'A78BFA', 'F472B6', '22D3EE', '94A3B8']],
    ];

    protected Spreadsheet $ss;

    protected Worksheet $dash;

    protected Worksheet $data;

    protected Worksheet $calc;

    protected array $t;

    protected array $spec;

    /** @var array<string, string> lettre de colonne des données */
    protected array $col = [];

    protected int $last;

    /** @var array<string, string> cellule critère par colonne filtrée */
    protected array $crit = [];

    protected ?string $yearShown = null;

    protected ?string $yearPrev = null;

    protected ?string $yearPrevKpi = null;

    protected int $calcRow = 1;

    protected int $chartNo = 0;

    /** @var array<string, float|string> valeurs pré-calculées en PHP (cache du moteur de calcul) */
    protected array $prime = [];

    /** @var array<string, string> valeur par défaut des filtres */
    protected array $defaults = [];

    public function build(array $spec, string $path): void
    {
        $this->spec = $spec;
        $this->t = self::THEMES[$spec['theme'] ?? 'ocean'] ?? self::THEMES['ocean'];
        $this->ss = new Spreadsheet;
        $this->ss->getProperties()->setCreator('Yomara ERP')->setTitle($spec['title'])->setSubject($spec['subtitle'] ?? '')->setCompany($spec['company'] ?? '');
        $this->ss->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $this->dash = $this->ss->getActiveSheet()->setTitle('Tableau de bord');
        $this->data = $this->ss->createSheet()->setTitle('Données');
        $this->calc = $this->ss->createSheet()->setTitle('Calculs');
        $lists = $this->ss->createSheet()->setTitle('Listes');
        $guide = $this->ss->createSheet()->setTitle('Mode d\'emploi');

        $this->writeData();
        $this->writeLists($lists);
        $this->layoutDashboard();
        $this->writeGuide($guide);

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $this->calc->getTabColor()->setRGB('A0AEC0');
        $this->data->getTabColor()->setRGB($this->t['chart'][1]);
        $this->dash->getTabColor()->setRGB($this->t['primary']);
        $this->ss->setActiveSheetIndex(0);
        $this->dash->setSelectedCell('C5');

        $w = new Xlsx($this->ss);
        $w->setIncludeCharts(true);
        $calc = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($this->ss);
        foreach ($this->prime as $ref => $v) {
            $calc->saveValueToCache($ref, $v);
        }
        FastConditional::install();
        try {
            $w->save($path);
        } finally {
            FastConditional::uninstall();
            $this->ss->disconnectWorksheets();
        }
    }

    // ------------------------------------------------------------------ Données

    protected function writeData(): void
    {
        $s = $this->data;
        $cols = $this->spec['columns'];
        $keys = array_keys($cols);
        foreach ($keys as $i => $k) {
            $this->col[$k] = Coordinate::stringFromColumnIndex($i + 1);
            $s->setCellValue($this->col[$k].'1', $cols[$k]['label']);
        }
        $rows = $this->spec['rows'];
        $r = 2;
        foreach ($rows as $row) {
            foreach ($keys as $k) {
                $v = $row[$k] ?? null;
                $type = $cols[$k]['type'] ?? 'text';
                $cell = $this->col[$k].$r;
                if ($type === 'text') {
                    $s->setCellValueExplicit($cell, (string) ($v ?? ''), DataType::TYPE_STRING);
                } elseif ($type === 'date') {
                    if ($v) {
                        $s->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime($v)));
                    }
                } elseif ($v !== null) {
                    $s->setCellValue($cell, (float) $v);
                }
            }
            $r++;
        }
        $this->last = max(2, $r - 1);
        if (! $rows) {
            // Une ligne vide pour que les plages et le tableau restent valides
            foreach ($keys as $k) {
                if (($cols[$k]['type'] ?? 'text') === 'text') {
                    $s->setCellValueExplicit($this->col[$k].'2', '', DataType::TYPE_STRING);
                }
            }
        }
        $lastCol = $this->col[end($keys)];
        foreach ($keys as $k) {
            $c = $this->col[$k];
            $type = $cols[$k]['type'] ?? 'text';
            $s->getColumnDimension($c)->setWidth($cols[$k]['width'] ?? ($type === 'text' ? 18 : 14));
            $fmt = match ($type) {
                'money' => $this->moneyFormat(), 'number' => '#,##0.##', 'int' => '#,##0', 'date' => 'dd/mm/yyyy', 'pct' => '0.0%', default => null,
            };
            if ($fmt) {
                $s->getStyle("{$c}2:{$c}{$this->last}")->getNumberFormat()->setFormatCode($fmt);
            }
        }
        $table = new Table("A1:{$lastCol}{$this->last}", 'Donnees');
        $style = new TableStyle;
        $style->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true);
        $table->setStyle($style);
        $s->addTable($table);
        $s->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $s->freezePane('A2');
        $s->getRowDimension(1)->setRowHeight(22);
    }

    protected function range(string $key): string
    {
        $c = $this->col[$key];

        return "'Données'!\${$c}\$2:\${$c}\${$this->last}";
    }

    protected function moneyFormat(): string
    {
        $cur = $this->spec['currency'] ?? 'XOF';

        return in_array($cur, ['XOF', 'XAF'], true) ? '#,##0" F";-#,##0" F";"–"' : '#,##0.00" '.$cur.'";-#,##0.00" '.$cur.'";"–"';
    }

    protected function fmt(string $f): string
    {
        return match ($f) {
            'money' => $this->moneyFormat(), 'pct' => '0.0%', 'int' => '#,##0;-#,##0;"–"', default => '#,##0.##;-#,##0.##;"–"',
        };
    }

    // ------------------------------------------------------------------ Listes et filtres

    protected function uniqueValues(string $key, ?array $order = null): array
    {
        $vals = [];
        foreach ($this->spec['rows'] as $r) {
            $v = (string) ($r[$key] ?? '');
            if ($v !== '') {
                $vals[$v] = true;
            }
        }
        $vals = array_keys($vals);
        if ($order) {
            return array_values(array_merge(array_intersect($order, $vals), array_diff($vals, $order)));
        }
        sort($vals, SORT_NATURAL | SORT_FLAG_CASE);

        return $vals;
    }

    protected array $listRanges = [];

    protected function writeLists(Worksheet $s): void
    {
        foreach ($this->spec['filters'] ?? [] as $i => $f) {
            $c = Coordinate::stringFromColumnIndex($i + 1);
            $vals = $f['values'] ?? $this->uniqueValues($f['col']);
            if (($f['sort'] ?? null) === 'desc') {
                rsort($vals, SORT_NATURAL);
            }
            $all = [self::ALL, ...$vals];
            foreach ($all as $j => $v) {
                $s->setCellValueExplicit($c.($j + 1), (string) $v, DataType::TYPE_STRING);
            }
            $this->listRanges[$i] = "'Listes'!\${$c}\$1:\${$c}\$".count($all);
        }
    }

    // ------------------------------------------------------------------ Tableau de bord

    protected function style(string $range): \PhpOffice\PhpSpreadsheet\Style\Style
    {
        return $this->dash->getStyle($range);
    }

    protected function fill(string $range, string $rgb): void
    {
        $this->style($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    protected function dark(): bool
    {
        return ! empty($this->t['dark']);
    }

    protected function text(): string
    {
        return $this->dark() ? 'F1F5FF' : '15233F';
    }

    protected function muted(): string
    {
        return $this->dark() ? '9FB0D4' : '6B7A94';
    }

    protected function layoutDashboard(): void
    {
        $d = $this->dash;
        $d->setShowGridlines(false);
        $d->getSheetView()->setZoomScale(90);
        $d->getColumnDimension('A')->setWidth(2.5);
        foreach (range('B', 'Q') as $c) {
            $d->getColumnDimension($c)->setWidth(in_array($c, ['E', 'I', 'M'], true) ? 2.5 : 12.5);
        }
        $d->getColumnDimension('Q')->setWidth(2.5);
        $bg = $this->dark() ? $this->t['soft'] : 'FFFFFF';
        $this->fill('A1:Q140', $bg);
        $this->style('A1:Q140')->getFont()->getColor()->setRGB($this->text());

        // Bandeau
        $this->fill('A1:Q3', $this->dark() ? $this->t['deep'] : $this->t['primary']);
        $d->getRowDimension(1)->setRowHeight(8);
        $d->getRowDimension(2)->setRowHeight(32);
        $d->getRowDimension(3)->setRowHeight(20);
        $d->mergeCells('B2:L2');
        $d->setCellValue('B2', $this->spec['title']);
        $this->style('B2')->getFont()->setSize(20)->setBold(true)->getColor()->setRGB('FFFFFF');
        $this->style('B2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $d->mergeCells('B3:L3');
        $d->setCellValue('B3', $this->spec['subtitle'] ?? '');
        $this->style('B3')->getFont()->setSize(10)->getColor()->setRGB('DCE6FA');
        $d->mergeCells('M2:P2');
        $d->setCellValue('M2', $this->spec['company'] ?? '');
        $this->style('M2')->getFont()->setSize(12)->setBold(true)->getColor()->setRGB('FFFFFF');
        $this->style('M2:M3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        $d->mergeCells('M3:P3');
        $d->setCellValue('M3', 'Généré le '.now()->format('d/m/Y à H:i').' · Yomara ERP');
        $this->style('M3')->getFont()->setSize(9)->getColor()->setRGB('DCE6FA');
        $d->getRowDimension(4)->setRowHeight(10);

        $this->filters();
        $this->kpis();
        $row = $this->charts(11);
        $row = $this->tables($row);
        $d->getStyle('A'.($row + 1))->getFont()->getColor()->setRGB($this->muted());
        $d->setCellValue('B'.($row + 1), 'Astuce : changez les filtres en haut de la page (listes déroulantes) ; indicateurs, graphiques et classements se recalculent. Les données détaillées sont dans l\'onglet « Données ».');
        $this->style('B'.($row + 1))->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB($this->muted());

        // Impression : paysage, une page en largeur
        $d->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $d->getPageSetup()->setPrintArea('A1:Q'.($row + 1));
        $d->getPageMargins()->setLeft(.3)->setRight(.3)->setTop(.4)->setBottom(.4);

        if (! empty($this->spec['protect'])) {
            $d->getProtection()->setSheet(true)->setSort(true)->setAutoFilter(true)->setFormatColumns(false);
        }
    }

    protected function filters(): void
    {
        $d = $this->dash;
        $d->getRowDimension(5)->setRowHeight(24);
        $d->getRowDimension(6)->setRowHeight(12);
        $slots = [['B', 'C', 'D'], ['F', 'G', 'H'], ['J', 'K', 'L'], ['N', 'O', 'P']];
        $this->calc->setCellValue('A1', 'Critères des filtres');
        $this->calc->getStyle('A1')->getFont()->setBold(true);
        $r = 2;
        foreach ($this->spec['filters'] ?? [] as $i => $f) {
            if ($i > 3) {
                break;
            }
            [$l, $a, $b] = $slots[$i];
            $d->setCellValue("{$l}5", $f['label']);
            $this->style("{$l}5")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($this->muted());
            $this->style("{$l}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $d->mergeCells("{$a}5:{$b}5");
            $d->setCellValueExplicit("{$a}5", (string) ($f['default'] ?? self::ALL), DataType::TYPE_STRING);
            $st = $this->style("{$a}5:{$b}5");
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->t['tint']);
            $st->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($this->t['primary']);
            $st->getFont()->setBold(true)->getColor()->setRGB($this->dark() ? 'FFFFFF' : $this->t['primary']);
            $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $st->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
            $dv = $d->getCell("{$a}5")->getDataValidation();
            $dv->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(false)
                ->setShowInputMessage(true)->setShowErrorMessage(true)->setShowDropDown(true)
                ->setPromptTitle($f['label'])->setPrompt('Choisissez une valeur dans la liste (« '.self::ALL.' » pour tout afficher).')
                ->setErrorTitle('Valeur inconnue')->setError('Choisissez une valeur de la liste.')->setFormula1($this->listRanges[$i]);
            $ref = "'Tableau de bord'!\${$a}\$5";
            $this->defaults[$f['col']] = (string) ($f['default'] ?? self::ALL);
            $this->calc->setCellValue("A{$r}", $f['label']);
            $this->calc->setCellValue("B{$r}", "=IF({$ref}=\"".self::ALL."\",\"*\",{$ref}&\"\")");
            $this->crit[$f['col']] = "Calculs!\$B\${$r}";
            $r++;
            if (($this->spec['year'] ?? null) === $f['col']) {
                $years = $this->uniqueValues($f['col']);
                $max = $years ? max(array_map('intval', $years)) : (int) date('Y');
                $this->calc->setCellValue("A{$r}", 'Année affichée');
                $this->calc->setCellValue("B{$r}", "=IF({$ref}=\"".self::ALL."\",\"{$max}\",{$ref}&\"\")");
                $this->yearShown = "Calculs!\$B\${$r}";
                $r++;
                $this->calc->setCellValue("A{$r}", 'Année précédente');
                $this->calc->setCellValue("B{$r}", "=TEXT(VALUE({$this->yearShown})-1,\"0\")");
                $this->yearPrev = "Calculs!\$B\${$r}";
                $r++;
                $this->calc->setCellValue("A{$r}", 'Comparaison (indicateurs)');
                $this->calc->setCellValue("B{$r}", "=IF({$ref}=\"".self::ALL."\",\"§\",TEXT(VALUE({$ref})-1,\"0\"))");
                $this->yearPrevKpi = "Calculs!\$B\${$r}";
                $r++;
            }
        }
        $this->calc->getColumnDimension('A')->setWidth(28);
        $this->calc->getColumnDimension('B')->setWidth(18);
        $this->calcRow = $r + 1;
    }

    /**
     * Formule SOMME.SI.ENS / NB.SI.ENS avec les filtres actifs (sauf ceux de $skip), les conditions $where et la période $year.
     * $where : [colonne => valeur | [valeurs…]] (plusieurs valeurs = somme de plusieurs termes).
     */
    protected function agg(?string $sumCol, array $where = [], array $skip = [], ?string $yearCrit = null): string
    {
        $base = [];
        foreach ($this->crit as $col => $ref) {
            if (in_array($col, $skip, true) || array_key_exists($col, $where)) {
                continue;
            }
            if ($yearCrit && $col === ($this->spec['year'] ?? null)) {
                $base[] = [$this->range($col), $yearCrit];

                continue;
            }
            $base[] = [$this->range($col), $ref];
        }
        if ($yearCrit && ! isset($this->crit[$this->spec['year'] ?? ''])) {
            $base[] = [$this->range($this->spec['year']), $yearCrit];
        }
        $variants = [[]];
        foreach ($where as $col => $vals) {
            $next = [];
            foreach ((array) $vals as $v) {
                foreach ($variants as $vv) {
                    $next[] = [...$vv, [$this->range($col), is_string($v) && str_starts_with($v, '=') ? substr($v, 1) : '"'.str_replace('"', '""', (string) $v).'"']];
                }
            }
            $variants = $next;
        }
        $terms = [];
        foreach ($variants as $v) {
            $pairs = [...$base, ...$v];
            if ($sumCol) {
                $terms[] = 'SUMIFS('.$this->range($sumCol).($pairs ? ','.implode(',', array_map(fn ($p) => $p[0].','.$p[1], $pairs)) : ','.$this->range($sumCol).',"<>"').')';
            } else {
                $first = array_key_first($this->col);
                $terms[] = $pairs ? 'COUNTIFS('.implode(',', array_map(fn ($p) => $p[0].','.$p[1], $pairs)).')' : 'COUNTA('.$this->range($first).')';
            }
        }

        return implode('+', $terms);
    }

    // ------------------------------------------------------------------ Évaluation en PHP (valeurs par défaut des filtres)

    protected function shownYear(): string
    {
        $y = $this->spec['year'] ?? null;
        $d = $y ? ($this->defaults[$y] ?? self::ALL) : self::ALL;
        if ($d !== self::ALL) {
            return $d;
        }
        $years = $y ? $this->uniqueValues($y) : [];

        return (string) ($years ? max(array_map('intval', $years)) : (int) date('Y'));
    }

    /** Conditions équivalentes à agg() : [colonne => valeurs admises | null (tout texte)]. $year : null, 'current', 'previous', 'kpi_previous'. */
    protected function conds(array $where, array $skip, ?string $year): ?array
    {
        $out = [];
        $ycol = $this->spec['year'] ?? null;
        foreach ($this->crit as $col => $_) {
            if (in_array($col, $skip, true) || array_key_exists($col, $where)) {
                continue;
            }
            if ($year && $col === $ycol) {
                continue;
            }
            $d = $this->defaults[$col] ?? self::ALL;
            $out[$col] = $d === self::ALL ? null : [mb_strtolower($d)];
        }
        if ($year && $ycol) {
            $shown = $this->shownYear();
            $v = match ($year) {
                'current' => $shown,
                'previous' => (string) ((int) $shown - 1),
                default => ($this->defaults[$ycol] ?? self::ALL) === self::ALL ? null : (string) ((int) $this->defaults[$ycol] - 1),
            };
            if ($v === null) {
                return null; // aucune ligne
            }
            $out[$ycol] = [$v];
        }
        foreach ($where as $col => $vals) {
            $out[$col] = array_map(fn ($x) => mb_strtolower((string) $x), (array) $vals);
        }

        return $out;
    }

    protected function rowMatches(array $r, array $conds): bool
    {
        foreach ($conds as $col => $vals) {
            $v = $r[$col] ?? null;
            if ($vals === null) {
                if ($v === null || $v === '') {
                    return false;
                }
            } elseif (! in_array(mb_strtolower((string) $v), $vals, true)) {
                return false;
            }
        }

        return true;
    }

    protected function evalAgg(?string $sumCol, ?array $conds): float
    {
        if ($conds === null) {
            return 0.0;
        }
        $t = 0.0;
        foreach ($this->spec['rows'] as $r) {
            if ($this->rowMatches($r, $conds)) {
                $t += $sumCol ? (float) ($r[$sumCol] ?? 0) : 1;
            }
        }

        return $t;
    }

    /** Somme par valeur de $by (une seule lecture des données). */
    protected function evalGrouped(?string $sumCol, ?array $conds, string $by): array
    {
        $out = [];
        if ($conds === null) {
            return $out;
        }
        unset($conds[$by]);
        foreach ($this->spec['rows'] as $r) {
            if ($this->rowMatches($r, $conds)) {
                $k = mb_strtolower((string) ($r[$by] ?? ''));
                $out[$k] = ($out[$k] ?? 0) + ($sumCol ? (float) ($r[$sumCol] ?? 0) : 1);
            }
        }

        return $out;
    }

    protected function kpiValue(array $k, bool $previous = false): float
    {
        $y = $previous ? 'kpi_previous' : null;
        if (isset($k['static'])) {
            return (float) $k['static'];
        }
        if (isset($k['ratio'])) {
            [$a, $b] = $k['ratio'];
            $den = $this->kpiValue($this->spec['kpis'][$b], $previous);

            return $den == 0 ? 0.0 : $this->kpiValue($this->spec['kpis'][$a], $previous) / $den;
        }
        if (isset($k['diff'])) {
            [$a, $b] = $k['diff'];

            return $this->kpiValue($this->spec['kpis'][$a], $previous) - $this->kpiValue($this->spec['kpis'][$b], $previous);
        }
        $total = 0.0;
        foreach ($this->expand($k['where'] ?? []) as $w) {
            $total += $this->evalAgg($k['sum'] ?? null, $this->conds($w, [], $y));
        }
        if (isset($k['minus'])) {
            foreach ($this->expand($k['minus']) as $w) {
                $total -= $this->evalAgg($k['sum'] ?? null, $this->conds($w, [], $y));
            }
        }

        return $total;
    }

    /** Conditions à valeurs multiples : une variante par combinaison (comme la somme de termes de agg()). */
    protected function expand(array $where): array
    {
        $variants = [[]];
        foreach ($where as $col => $vals) {
            $next = [];
            foreach ((array) $vals as $v) {
                foreach ($variants as $vv) {
                    $next[] = $vv + [$col => $v];
                }
            }
            $variants = $next;
        }

        return $variants;
    }

    protected function kpiFormula(array $k, bool $previous = false): string
    {
        $yc = $previous ? $this->yearPrevKpi : null;
        if (isset($k['static'])) {
            return (string) (float) $k['static'];
        }
        if (isset($k['ratio'])) {
            [$a, $b] = $k['ratio'];
            $num = $this->kpiFormula($this->spec['kpis'][$a], $previous);
            $den = $this->kpiFormula($this->spec['kpis'][$b], $previous);

            return "IFERROR(({$num})/({$den}),0)";
        }
        if (isset($k['diff'])) {
            [$a, $b] = $k['diff'];

            return '('.$this->kpiFormula($this->spec['kpis'][$a], $previous).')-('.$this->kpiFormula($this->spec['kpis'][$b], $previous).')';
        }
        if (isset($k['minus'])) {
            // total moins une partie : $k['sum'] avec where, moins $k['minus'] (where)
            return '('.$this->agg($k['sum'], $k['where'] ?? [], [], $yc).')-('.$this->agg($k['sum'], $k['minus'], [], $yc).')';
        }

        return $this->agg($k['sum'] ?? null, $k['where'] ?? [], [], $yc);
    }

    protected function kpis(): void
    {
        $d = $this->dash;
        $slots = [['B', 'D'], ['F', 'H'], ['J', 'L'], ['N', 'P']];
        $d->getRowDimension(7)->setRowHeight(20);
        $d->getRowDimension(8)->setRowHeight(34);
        $d->getRowDimension(9)->setRowHeight(18);
        $d->getRowDimension(10)->setRowHeight(16);
        foreach (array_slice($this->spec['kpis'] ?? [], 0, 4) as $i => $k) {
            if (! empty($k['hidden'])) {
                continue;
            }
            [$a, $b] = $slots[$i];
            $first = $i === 0;
            $bg = $first ? $this->t['primary'] : ($this->dark() ? $this->t['tint'] : $this->t['soft']);
            $fg = $first ? 'FFFFFF' : ($this->dark() ? 'FFFFFF' : $this->t['deep']);
            $sub = $first ? 'E3ECFD' : $this->muted();
            foreach ([7, 8, 9] as $r) {
                $d->mergeCells("{$a}{$r}:{$b}{$r}");
            }
            $box = $this->style("{$a}7:{$b}9");
            $box->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
            if (! $first) {
                $box->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($this->dark() ? '23396B' : 'E3E8F1');
                $this->style("{$a}7:{$b}7")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB($this->t['chart'][$i % 7]);
            }
            $d->setCellValue("{$a}7", mb_strtoupper($k['label']));
            $this->style("{$a}7")->getFont()->setBold(true)->setSize(8.5)->getColor()->setRGB($sub);
            $this->style("{$a}7:{$a}9")->getAlignment()->setIndent(1)->setVertical(Alignment::VERTICAL_CENTER);
            $d->setCellValue("{$a}8", '='.$this->kpiFormula($k));
            $this->prime["Tableau de bord!{$a}8"] = $this->kpiValue($k);
            $this->style("{$a}8")->getFont()->setBold(true)->setSize(19)->getColor()->setRGB($fg);
            $this->style("{$a}8")->getNumberFormat()->setFormatCode($this->fmt($k['format'] ?? 'money'));
            $this->style("{$a}8")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            if (! empty($k['compare']) && $this->yearPrevKpi) {
                // Valeur de l'année précédente dans « Calculs » (pré-calculée), puis évolution en texte (FIXED : séparateur décimal local)
                $pr = $this->calcRow++;
                $this->calc->setCellValue("A{$pr}", $k['label'].' — année précédente');
                $this->calc->setCellValue("B{$pr}", "=IF({$this->yearPrevKpi}=\"§\",0,".$this->kpiFormula($k, true).')');
                $this->prime["Calculs!B{$pr}"] = $this->kpiValue($k, true);
                $pc = "Calculs!\$B\${$pr}";
                $yp = $this->yearPrevKpi;
                $note = str_replace('"', '""', $k['note'] ?? 'Choisissez une année pour comparer');
                if (($k['format'] ?? 'money') === 'pct') {
                    $f = "=IF({$yp}=\"§\",\"{$note}\",IF({$a}8>={$pc},\"+\",\"\")&FIXED(({$a}8-{$pc})*100,1)&\" pt vs \"&{$yp})";
                } else {
                    $f = "=IF({$yp}=\"§\",\"{$note}\",IF({$pc}=0,\"Pas de données en \"&{$yp},IF({$a}8>={$pc},\"▲ +\",\"▼ \")&FIXED(({$a}8/{$pc}-1)*100,1)&\" % vs \"&{$yp}))";
                }
                $d->setCellValue("{$a}9", $f);
                if (! $first) {
                    $good = ($k['lower_is_better'] ?? false) ? '▼' : '▲';
                    $bad = ($k['lower_is_better'] ?? false) ? '▲' : '▼';
                    $cs = [];
                    foreach ([[$good, '12A150'], [$bad, 'D93025']] as [$ch, $rgb]) {
                        $c = new Conditional;
                        $c->setConditionType(Conditional::CONDITION_CONTAINSTEXT)->setOperatorType(Conditional::OPERATOR_BEGINSWITH)->setText($ch);
                        $c->addCondition("LEFT({$a}9,1)=\"{$ch}\"");
                        $c->getStyle()->getFont()->setBold(true)->getColor()->setRGB($rgb);
                        $cs[] = $c;
                    }
                    $this->style("{$a}9")->setConditionalStyles($cs);
                }
            } else {
                $d->setCellValue("{$a}9", (string) ($k['note'] ?? ''));
            }
            $this->style("{$a}9")->getFont()->setSize(9)->getColor()->setRGB($sub);
        }
    }

    // ------------------------------------------------------------------ Tables de calcul et graphiques

    /** Écrit une table de calcul et renvoie [plage catégories, [plages séries], [cellules noms des séries], nombre de lignes]. */
    protected function helper(array $c): array
    {
        $s = $this->calc;
        $r0 = $this->calcRow;
        $s->setCellValue("A{$r0}", $c['title']);
        $s->getStyle("A{$r0}")->getFont()->setBold(true)->getColor()->setRGB($this->t['primary']);
        $by = $c['by'];
        $cats = $c['categories'] ?? $this->uniqueValues($by, $c['order'] ?? null);
        $skip = [$by, ...($c['ignore'] ?? [])];
        $series = $c['series'];
        $h = $r0 + 1;
        $s->setCellValue("A{$h}", $this->spec['columns'][$by]['label'] ?? $by);
        $names = [];
        foreach ($series as $j => $se) {
            $col = Coordinate::stringFromColumnIndex($j + 2);
            if (($se['year'] ?? null) === 'current' && $this->yearShown) {
                $s->setCellValue("{$col}{$h}", '='.($se['label'] ?? '"Année "').'&'.$this->yearShown);
            } elseif (($se['year'] ?? null) === 'previous' && $this->yearPrev) {
                $s->setCellValue("{$col}{$h}", '='.($se['label'] ?? '"Année "').'&'.$this->yearPrev);
            } else {
                $s->setCellValue("{$col}{$h}", $se['label']);
            }
            $names[] = "Calculs!\${$col}\${$h}";
        }
        $s->getStyle("A{$h}:".Coordinate::stringFromColumnIndex(count($series) + 1).$h)->getFont()->setBold(true);

        $grouped = [];
        foreach ($series as $j => $se) {
            $grouped[$j] = $this->seriesValues($se, $by, $skip);
        }
        $top = $c['top'] ?? null;
        if ($top) {
            // Classement : table complète puis les N premiers (GRANDE.VALEUR + INDEX/EQUIV, égalités départagées)
            // Candidats du classement : les mieux placés sur l'ensemble des données (limite le volume de formules)
            $cats = $this->candidates($by, $series[0], $cats, 60);
            $raw = $h + 1;
            $key = Coordinate::stringFromColumnIndex(count($series) + 3);
            foreach ($cats as $i => $cat) {
                $r = $raw + $i;
                $s->setCellValueExplicit("A{$r}", (string) $cat, DataType::TYPE_STRING);
                foreach ($series as $j => $se) {
                    $col = Coordinate::stringFromColumnIndex($j + 2);
                    $s->setCellValue("{$col}{$r}", '='.$this->seriesFormula($se, $by, "A{$r}", $skip));
                    $this->prime["Calculs!{$col}{$r}"] = (float) ($grouped[$j][mb_strtolower((string) $cat)] ?? 0);
                }
                $s->setCellValue("{$key}{$r}", "=B{$r}+ROW()/1000000000");
            }
            $rawEnd = $raw + max(1, count($cats)) - 1;
            $rk = $rawEnd + 2;
            $s->setCellValue("A{$rk}", 'Classement — '.$c['title']);
            $s->getStyle("A{$rk}")->getFont()->setItalic(true);
            $rk++;
            for ($i = 1; $i <= $top; $i++) {
                $r = $rk + $i - 1;
                $m = "MATCH(LARGE(\${$key}\${$raw}:\${$key}\${$rawEnd},{$i}),\${$key}\${$raw}:\${$key}\${$rawEnd},0)";
                $s->setCellValue("A{$r}", "=IFERROR(IF(INDEX(\$B\${$raw}:\$B\${$rawEnd},{$m})<=0,\"\",INDEX(\$A\${$raw}:\$A\${$rawEnd},{$m})),\"\")");
                foreach ($series as $j => $se) {
                    $col = Coordinate::stringFromColumnIndex($j + 2);
                    $s->setCellValue("{$col}{$r}", "=IF(\$A{$r}=\"\",0,IFERROR(INDEX({$col}\${$raw}:{$col}\${$rawEnd},{$m}),0))");
                }
            }
            $first = $rk;
            $n = $top;
            $this->calcRow = $rk + $top + 2;
        } else {
            $first = $h + 1;
            foreach ($cats as $i => $cat) {
                $r = $first + $i;
                $s->setCellValueExplicit("A{$r}", (string) $cat, DataType::TYPE_STRING);
                foreach ($series as $j => $se) {
                    $col = Coordinate::stringFromColumnIndex($j + 2);
                    $f = $this->seriesFormula($se, $by, "A{$r}", $skip);
                    $v = (float) ($grouped[$j][mb_strtolower((string) $cat)] ?? 0);
                    if (($se['year'] ?? null) === 'current' && ! empty($c['gap_future'])) {
                        // Mois à venir de l'année en cours : point absent plutôt qu'une chute à zéro
                        $cy = now()->year;
                        $f = "IF(AND({$this->yearShown}=\"{$cy}\",".($i + 1).'>'.now()->month.'),NA(),'.$f.')';
                        if ($this->shownYear() === (string) $cy && $i + 1 > now()->month) {
                            $v = '#N/A';
                        }
                    }
                    $s->setCellValue("{$col}{$r}", '='.$f);
                    $this->prime["Calculs!{$col}{$r}"] = $v;
                }
            }
            $n = max(1, count($cats));
            $this->calcRow = $first + $n + 2;
        }
        $end = $first + $n - 1;
        $fmt = $this->fmt($c['format'] ?? 'money');
        $s->getStyle('B'.$first.':'.Coordinate::stringFromColumnIndex(count($series) + 1).$end)->getNumberFormat()->setFormatCode($fmt);
        $ranges = [];
        foreach ($series as $j => $se) {
            $col = Coordinate::stringFromColumnIndex($j + 2);
            $ranges[] = "Calculs!\${$col}\${$first}:\${$col}\${$end}";
        }

        $max = 0.0;
        foreach ($grouped as $g) {
            foreach ($g as $v) {
                $max = max($max, abs((float) $v));
            }
        }

        return ["Calculs!\$A\${$first}:\$A\${$end}", $ranges, $names, $n, $first, $max];
    }

    protected function candidates(string $by, array $se, array $cats, int $max): array
    {
        if (count($cats) <= $max) {
            return $cats;
        }
        $tot = [];
        $where = $se['where'] ?? [];
        foreach ($this->spec['rows'] as $r) {
            foreach ($where as $col => $vals) {
                if (! in_array((string) ($r[$col] ?? ''), array_map('strval', (array) $vals), true)) {
                    continue 2;
                }
            }
            $k = (string) ($r[$by] ?? '');
            $tot[$k] = ($tot[$k] ?? 0) + (isset($se['sum']) ? (float) ($r[$se['sum']] ?? 0) : 1);
        }
        arsort($tot);

        return array_slice(array_keys($tot), 0, $max);
    }

    protected function seriesValues(array $se, string $by, array $skip): array
    {
        $year = $se['year'] ?? null;
        if ($year) {
            $skip = [...$skip, $this->spec['year']];
        }
        $out = [];
        foreach ($this->expand($se['where'] ?? []) as $w) {
            foreach ($this->evalGrouped($se['sum'] ?? null, $this->conds($w, $skip, $year), $by) as $k => $v) {
                $out[$k] = ($out[$k] ?? 0) + $v;
            }
        }

        return $out;
    }

    protected function seriesFormula(array $se, string $by, string $catCell, array $skip): string
    {
        $where = $se['where'] ?? [];
        $where[$by] = '='.$catCell;
        $yc = match ($se['year'] ?? null) { 'current' => $this->yearShown, 'previous' => $this->yearPrev, default => null };
        if ($yc) {
            $skip = [...$skip, $this->spec['year']];
        }

        return $this->agg($se['sum'] ?? null, $where, $skip, $yc);
    }

    protected function charts(int $row): int
    {
        $charts = $this->spec['charts'] ?? [];
        $height = 17;
        $half = null;
        foreach ($charts as $c) {
            [$cats, $vals, $names, $n, , $max] = $this->helper($c);
            $c['max'] = $max;
            $wide = ! empty($c['wide']);
            if ($wide && $half !== null) {
                $row += $height + 1;
                $half = null;
            }
            if ($wide) {
                $from = "B{$row}";
                $to = 'Q'.($row + $height);
                $row += $height + 1;
            } elseif ($half === null) {
                $from = "B{$row}";
                $to = 'I'.($row + $height);
                $half = $row;
            } else {
                $from = "J{$half}";
                $to = 'Q'.($half + $height);
                $row = $half + $height + 1;
                $half = null;
            }
            $this->addChart($c, $cats, $vals, $names, $n, $from, $to);
        }
        if ($half !== null) {
            $row = $half + $height + 1;
        }

        return $row + 1;
    }

    protected function addChart(array $c, string $cats, array $vals, array $names, int $n, string $from, string $to): void
    {
        $type = $c['type'] ?? 'column';
        $colors = $this->t['chart'];
        $labels = [];
        $values = [];
        foreach ($vals as $j => $v) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $names[$j], null, 1);
            $dv = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $v, null, $n);
            if ($type === 'doughnut' || $type === 'pie') {
                $dv->setFillColor(array_slice(array_merge($colors, $colors, $colors), 0, max(1, $n)));
            } elseif ($type === 'line') {
                $rgb = $j === 0 ? $colors[0] : ($this->dark() ? '64748B' : 'A0AEC0');
                $dv->setLineColorProperties($rgb);
                $dv->setLineWidth($j === 0 ? 2.75 : 1.75);
                if ($j > 0) {
                    $dv->setLineStyleProperties(null, null, 'dash');
                }
                $dv->setPointMarker($j === 0 ? 'circle' : 'none');
                $dv->setPointSize(6);
                $dv->getMarkerFillColor()->setColorProperties($colors[0]);
                $dv->getMarkerBorderColor()->setColorProperties('FFFFFF');
            } else {
                $dv->setFillColor($j === 0 ? $colors[0] : $colors[$j % 7]);
            }
            $values[] = $dv;
        }
        $catv = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $cats, null, $n)];
        $plotType = match ($type) { 'line' => DataSeries::TYPE_LINECHART, 'doughnut' => DataSeries::TYPE_DONUTCHART, 'pie' => DataSeries::TYPE_PIECHART, default => DataSeries::TYPE_BARCHART };
        $grouping = match ($type) { 'line' => DataSeries::GROUPING_STANDARD, 'stacked' => DataSeries::GROUPING_STACKED, 'doughnut', 'pie' => null, default => DataSeries::GROUPING_CLUSTERED };
        $series = new DataSeries($plotType, $grouping, range(0, count($values) - 1), $labels, $catv, $values);
        if ($type === 'bar') {
            $series->setPlotDirection(DataSeries::DIRECTION_BAR);
        } elseif (in_array($type, ['column', 'stacked'], true)) {
            $series->setPlotDirection(DataSeries::DIRECTION_COL);
        }
        if ($type === 'line') {
            $series->setSmoothLine(true);
        }
        $layout = new Layout;
        if (in_array($type, ['doughnut', 'pie'], true)) {
            $layout->setShowPercent(true)->setShowVal(false)->setShowCatName(false)->setShowSerName(false)->setShowLeaderLines(false);
        }
        $plot = new PlotArea($layout, [$series]);
        $legend = (count($values) > 1 || in_array($type, ['doughnut', 'pie'], true))
            ? new Legend(in_array($type, ['doughnut', 'pie'], true) ? Legend::POSITION_RIGHT : Legend::POSITION_TOP, null, false) : null;
        $rt = new \PhpOffice\PhpSpreadsheet\RichText\RichText;
        $run = $rt->createTextRun($c['title']);
        $run->getFont()->setBold(true)->setSize(12)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF'.($this->dark() ? 'FFFFFF' : $this->t['deep'])));
        $title = new Title($rt);
        $chart = new Chart('graphique'.(++$this->chartNo), $title, $legend, $plot, true, DataSeries::EMPTY_AS_GAP);
        $chart->setTopLeftPosition($from);
        $chart->setBottomRightPosition($to);
        $chart->setRoundedCorners(false);
        $chart->getBorderLines()->setLineColorProperties($this->dark() ? '23396B' : 'E3E8F1');
        $textColor = $this->dark() ? 'C9D6F2' : '5B6B85';
        $axisText = function () use ($textColor) {
            $t = new \PhpOffice\PhpSpreadsheet\Chart\AxisText;
            $t->getFillColorObject()->setColorProperties($textColor);
            $t->getFont()->setSize(8);

            return $t;
        };
        if (! in_array($type, ['doughnut', 'pie'], true)) {
            $valueAxis = $type === 'bar' ? $chart->getChartAxisY() : $chart->getChartAxisY();
            $max = (float) ($c['max'] ?? 0);
            $fmt = ($c['format'] ?? 'money') === 'int' || ($c['format'] ?? '') === 'number' ? '#,##0'
                : ($max >= 1e7 ? '#,##0,," M"' : ($max >= 1e6 ? '#,##0.0,," M"' : ($max >= 1e4 ? '#,##0," k"' : '#,##0')));
            $valueAxis->setAxisNumberProperties($fmt);
            $grid = new \PhpOffice\PhpSpreadsheet\Chart\GridLines;
            $grid->setLineColorProperties($this->dark() ? '1E3460' : 'EDF1F7');
            $valueAxis->setMajorGridlines($grid);
            $chart->getChartAxisY()->setAxisText($axisText());
            $chart->getChartAxisX()->setAxisText($axisText());
        }
        $legend?->setLegendText($axisText());
        if ($this->dark()) {
            $chart->getFillColor()->setColorProperties($this->t['tint']);
            $chart->getPlotArea()?->setNoFill(true);
        }
        $this->dash->addChart($chart);
    }

    protected function tables(int $row): int
    {
        $tables = $this->spec['tables'] ?? [];
        $slots = [['B', 'C', 'E', 'F', 'G', 'H'], ['J', 'K', 'M', 'N', 'O', 'P']];
        $d = $this->dash;
        $start = $row;
        $maxEnd = $row;
        foreach (array_values($tables) as $ti => $t) {
            if ($ti > 0 && $ti % 2 === 0) {
                $start = $maxEnd + 2;
            }
            [$rk, $n1, $n2, $vc, $pc, $bc] = $slots[$ti % 2];
            $t['series'] = [['label' => $t['value_label'] ?? 'Montant', 'sum' => $t['sum'] ?? null, 'where' => $t['where'] ?? []]];
            [, , , $n, $first] = $this->helper($t);
            $r = $start;
            $d->mergeCells("{$rk}{$r}:{$bc}{$r}");
            $d->setCellValue("{$rk}{$r}", $t['title']);
            $this->style("{$rk}{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($this->dark() ? 'FFFFFF' : $this->t['deep']);
            $d->getRowDimension($r)->setRowHeight(22);
            $r++;
            $head = $this->style("{$rk}{$r}:{$bc}{$r}");
            $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->dark() ? $this->t['tint'] : $this->t['tint']);
            $head->getFont()->setBold(true)->setSize(8.5)->getColor()->setRGB($this->dark() ? 'DCE6FA' : $this->t['primary']);
            $d->setCellValue("{$rk}{$r}", '#');
            $d->mergeCells("{$n1}{$r}:{$n2}{$r}");
            $d->setCellValue("{$n1}{$r}", mb_strtoupper($this->spec['columns'][$t['by']]['label'] ?? ''));
            $d->setCellValue("{$vc}{$r}", mb_strtoupper($t['value_label'] ?? 'Montant'));
            $d->setCellValue("{$pc}{$r}", 'PART');
            $d->setCellValue("{$bc}{$r}", '');
            $this->style("{$vc}{$r}:{$pc}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
            $total = "SUM(Calculs!\$B\${$first}:\$B\$".($first + $n - 1).')';
            $tr = $this->calcRow++;
            $this->calc->setCellValue("A{$tr}", 'Total — '.$t['title']);
            $this->calc->setCellValue("B{$tr}", '='.$this->agg($t['sum'] ?? null, $t['where'] ?? []));
            $tot = 0.0;
            foreach ($this->expand($t['where'] ?? []) as $w) {
                $tot += $this->evalAgg($t['sum'] ?? null, $this->conds($w, [], null));
            }
            $this->prime["Calculs!B{$tr}"] = $tot;
            $totalAll = "Calculs!\$B\${$tr}";
            $this->calcRow++;
            for ($i = 0; $i < $n; $i++, $r++) {
                $src = $first + $i;
                $d->setCellValue("{$rk}{$r}", "=IF(Calculs!\$A\${$src}=\"\",\"\",".($i + 1).')');
                $d->mergeCells("{$n1}{$r}:{$n2}{$r}");
                $d->setCellValue("{$n1}{$r}", "=Calculs!\$A\${$src}");
                $d->setCellValue("{$vc}{$r}", "=IF(Calculs!\$A\${$src}=\"\",\"\",Calculs!\$B\${$src})");
                $d->setCellValue("{$pc}{$r}", "=IF(Calculs!\$A\${$src}=\"\",\"\",IFERROR(Calculs!\$B\${$src}/({$totalAll}),0))");
                $d->setCellValue("{$bc}{$r}", "=IF(Calculs!\$A\${$src}=\"\",\"\",REPT(\"█\",ROUND(10*IFERROR(Calculs!\$B\${$src}/MAX(Calculs!\$B\${$first}:\$B\$".($first + $n - 1).'),0),0)))');
                $this->style("{$vc}{$r}")->getNumberFormat()->setFormatCode($this->fmt($t['format'] ?? 'money'));
                $this->style("{$pc}{$r}")->getNumberFormat()->setFormatCode('0.0%');
                $this->style("{$bc}{$r}")->getFont()->setSize(8)->getColor()->setRGB($this->t['chart'][$ti % 7]);
                $this->style("{$rk}{$r}")->getFont()->setBold(true)->getColor()->setRGB($this->muted());
                $this->style("{$rk}{$r}:{$bc}{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB($this->dark() ? '23396B' : 'E3E8F1');
                $this->style("{$rk}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            unset($total);
            $maxEnd = max($maxEnd, $r);
        }

        return $maxEnd + 1;
    }

    // ------------------------------------------------------------------ Mode d'emploi

    protected function writeGuide(Worksheet $g): void
    {
        $g->setShowGridlines(false);
        $g->getColumnDimension('A')->setWidth(3);
        $g->getColumnDimension('B')->setWidth(110);
        $lines = [
            ['h', 'Mode d\'emploi — '.$this->spec['title']],
            ['p', 'Ce classeur a été généré par Yomara ERP à partir de vos données. Il fonctionne dans Microsoft Excel (2010 et plus récent), LibreOffice Calc et Google Sheets.'],
            ['s', 'Filtrer le tableau de bord'],
            ['p', 'En haut de l\'onglet « Tableau de bord », cliquez sur une case de filtre (fond coloré) puis choisissez une valeur dans la liste. « '.self::ALL.' » retire le filtre.'],
            ['p', 'Les indicateurs, les graphiques et les classements se recalculent aussitôt. Les graphiques par mois ignorent le filtre « Mois » pour montrer toute l\'année.'],
            ['s', 'Explorer les données'],
            ['p', 'L\'onglet « Données » contient chaque ligne utilisée, dans un tableau Excel structuré : utilisez les flèches des en-têtes pour trier et filtrer.'],
            ['p', 'Pour aller plus loin : Insertion › Tableau croisé dynamique sur le tableau « Donnees », puis ajoutez des segments (Insertion › Segment) pour des filtres visuels.'],
            ['s', 'Calculs'],
            ['p', 'L\'onglet « Calculs » contient les tables qui alimentent les graphiques (formules SOMME.SI.ENS sur les données). Vous pouvez les réutiliser dans vos propres graphiques.'],
            ['s', 'Actualiser'],
            ['p', 'Pour des chiffres à jour, générez à nouveau le classeur depuis Yomara ERP (Pilotage › Tableaux de bord Excel), ou programmez son envoi automatique par e-mail.'],
        ];
        foreach ($this->spec['guide'] ?? [] as $l) {
            $lines[] = ['p', $l];
        }
        $r = 2;
        foreach ($lines as [$kind, $text]) {
            if ($kind === 's') {
                $r++;
            }
            $g->setCellValue("B{$r}", $text);
            $st = $g->getStyle("B{$r}");
            $st->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            if ($kind === 'h') {
                $st->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($this->t['primary']);
                $g->getRowDimension($r)->setRowHeight(28);
            } elseif ($kind === 's') {
                $st->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($this->t['deep']);
                $g->getRowDimension($r)->setRowHeight(20);
            } else {
                $g->getRowDimension($r)->setRowHeight(30);
            }
            $r++;
        }
    }
}
