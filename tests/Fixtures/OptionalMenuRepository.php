<?php

/**
 * EN: Marks the optional Menu repository in standalone Workspace cleanup tests.
 * The real class, when installed, always takes precedence.
 *
 * HR: Označava opcionalni Menu repozitorij u samostalnim testovima čišćenja područja.
 * Stvarna klasa, kada je instalirana, uvijek ima prednost.
 */

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleMenu\Service;

if (!class_exists(MenuConfigRepository::class)) {
    final class MenuConfigRepository
    {
    }
}
