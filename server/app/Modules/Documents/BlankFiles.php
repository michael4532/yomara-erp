<?php

namespace App\Modules\Documents;

/** Fichiers bureautiques vierges et conversions pour l'éditeur en ligne. */
class BlankFiles
{
    public static function docx(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $z = new \ZipArchive;
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>');
        $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/><w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
        $z->close();
        $b = file_get_contents($tmp);
        @unlink($tmp);

        return $b;
    }

    public static function xlsx(): string
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('Feuille 1');

        return self::write($book);
    }

    public static function toXlsx(string $bytes, string $ext): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'conv').'.'.$ext;
        file_put_contents($tmp, $bytes);
        try {
            return self::write(\PhpOffice\PhpSpreadsheet\IOFactory::load($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    protected static function write(\PhpOffice\PhpSpreadsheet\Spreadsheet $book): string
    {
        $out = tempnam(sys_get_temp_dir(), 'xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($out);
        $b = file_get_contents($out);
        @unlink($out);

        return $b;
    }
}
