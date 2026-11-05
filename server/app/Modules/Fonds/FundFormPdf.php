<?php

namespace App\Modules\Fonds;

/** Pied de page légal sur chaque page (coordonnées, RCCM, CC…) et numéro de page. */
class FundFormPdf extends \TCPDF
{
    public array $footerLines = [];

    public string $footerColor = '#0B4C8C';

    public function Footer()
    {
        $this->SetY(-($this->footerLines ? 18 : 10));
        if ($this->footerLines) {
            $this->SetLineStyle(['width' => 0.4, 'color' => FundForm::rgb($this->footerColor)]);
            $this->Line(14, $this->GetY(), 196, $this->GetY());
            $this->Ln(1);
            $this->SetFont('helvetica', '', 6.8);
            $this->SetTextColor(70, 78, 90);
            foreach ($this->footerLines as $l) {
                $this->Cell(0, 3.4, $l, 0, 1, 'C');
            }
        }
        $this->SetFont('helvetica', '', 6.5);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 3, 'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }
}
