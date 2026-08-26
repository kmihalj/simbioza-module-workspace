<?php

declare(strict_types=1);

return [
    'routing' => [
        // HR: Područje definira prvi slug, a stranica drugi segment URL-a.
        // EN: A workspace defines the first slug and a page defines the second URL segment.
        'root_path' => 'workspace',
    ],
    'defaults' => [
        'visibility' => 'restricted',
        'tree_visible' => true,
        'contents_visible' => false,
    ],
    'creation' => [
        // HR: Administratori uvijek smiju kreirati područja; ovdje se dodaju ostali subjekti.
        // EN: Administrators may always create workspaces; other subjects are added here.
        'users' => [],
        'groups' => [],
    ],
    'shorts' => [
        // HR: Zadane vrijednosti javnog popisa sažetaka unutar svakog područja.
        // EN: Default values for each Workspace's public Shorts listing.
        'depth' => 2,
        'limit' => 10,
        'order' => 'newest',
        'display_options_visible' => false,
    ],
    'backlinks' => [
        // HR: Periodična provjera popravlja izvedeni indeks ako je događaj bio prekinut.
        // EN: The periodic check repairs the derived index if an event was interrupted.
        'refresh_seconds' => 3600,
    ],
    'menu' => [
        'auto_register_top' => true,
        'auto_register_settings' => true,
    ],
];
