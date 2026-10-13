<?php
declare(strict_types=1);

namespace CakeWire;

use CakeWire\View\ComponentTemplate;

/**
 * Pointe vers un template PHP sous `templates/CakeWire/` (syntaxe proche de Livewire).
 *
 * @param string $template Nom du fichier sans `.php` (`show-posts`, `dossier1/ficher`, `livewire.show-posts`).
 */
function view(string $template): ComponentTemplate
{
    return new ComponentTemplate($template);
}
