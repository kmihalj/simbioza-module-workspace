<?php

/**
 * EN: Marks the optional Menu catalog when it is absent from the standalone test dependencies.
 *
 * HR: Označava opcionalni Menu katalog kada nije dio samostalnih testnih ovisnosti.
 */

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleMenu\Service;

if (!class_exists(MenuNavigationCatalog::class)) {
    final class MenuNavigationCatalog
    {
    }
}
