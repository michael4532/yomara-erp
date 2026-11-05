<?php

namespace App\Modules\Documents;

/** Texte indexé pour la recherche : PDF, Word (docx, odt), Excel (xlsx, ods), texte et CSV. */
class TextExtractor
{
    public const MAX = 200000;

    public static function extract(string $absPath, string $ext): ?string
    {
        try {
            $text = match (strtolower($ext)) {
                'pdf' => static::pdf($absPath),
                'docx' => static::zipXml($absPath, ['word/document.xml']),
                'xlsx' => static::zipXml($absPath, ['xl/sharedStrings.xml'], '#^xl/worksheets/sheet\d+\.xml$#'),
                'pptx' => static::zipXml($absPath, null, '#^ppt/slides/slide\d+\.xml$#'),
                'odt', 'ods', 'odp' => static::zipXml($absPath, ['content.xml']),
                'txt', 'csv', 'md', 'json', 'xml', 'html', 'htm' => static::plain($absPath),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
        if ($text === null) {
            return null;
        }
        $text = str_replace('Powered by TCPDF (www.tcpdf.org)', '', $text);
        $text = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\R{3,}/u', "\n\n", $text) ?? '') ?? '');

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    protected static function pdf(string $path): ?string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class) || filesize($path) > 30 * 1024 * 1024) {
            return null;
        }
        $cfg = new \Smalot\PdfParser\Config;
        $cfg->setRetainImageContent(false);
        $cfg->setDecodeMemoryLimit(64 * 1024 * 1024);

        return (new \Smalot\PdfParser\Parser([], $cfg))->parseFile($path)->getText();
    }

    protected static function zipXml(string $path, ?array $entries, ?string $pattern = null): ?string
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }
        $z = new \ZipArchive;
        if ($z->open($path) !== true) {
            return null;
        }
        $names = $entries ?? [];
        if ($pattern) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                if (preg_match($pattern, $z->getNameIndex($i))) {
                    $names[] = $z->getNameIndex($i);
                }
            }
            natsort($names);
        }
        $out = '';
        foreach ($names as $n) {
            $xml = $z->getFromName($n);
            if ($xml !== false) {
                $xml = preg_replace('#</(w:p|text:p|a:p|si|text:h|row)>#', "\n", $xml);
                $xml = preg_replace('#</c>#', ' ', $xml);
                $xml = preg_replace('#<(w:tab|text:tab)[^>]*/>#', ' ', $xml);
                $out .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8')."\n";
            }
        }
        $z->close();

        return $out;
    }

    protected static function plain(string $path): ?string
    {
        $s = file_get_contents($path, false, null, 0, self::MAX * 2);
        if ($s === false) {
            return null;
        }
        if (! mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }

        return strip_tags($s);
    }
}
