<?php

namespace App\Modules\Rapports;

/** Données d'une source inaccessibles à l'utilisateur (module inactif ou droit manquant). */
class AccessException extends \DomainException {}
