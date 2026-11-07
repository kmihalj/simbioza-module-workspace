<?php

/**
 * EN: Marks the optional Menu catalog for standalone Workspace static analysis.
 * The real class, when installed, always takes precedence.
 *
 * HR: Označava opcionalni Menu katalog za samostalnu statičku analizu Workspacea.
 * Stvarna klasa, kada je instalirana, uvijek ima prednost.
 */

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleMenu\Service;

if (!class_exists(MenuNavigationCatalog::class)) {
    final class MenuNavigationCatalog
    {
    }
}
