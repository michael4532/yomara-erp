<?php

namespace App\Modules\Questionnaires;

/** Réponses refusées : erreurs par question. */
class QuestionnaireErrors extends \DomainException
{
    public function __construct(public array $errors)
    {
        parent::__construct(count($errors) === 1 ? reset($errors) : 'Certaines réponses sont manquantes ou invalides.');
    }
}
