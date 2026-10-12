<?php

declare(strict_types=1);

return [
    'routing' => [
        // EN: A workspace defines the first slug and a page defines the second URL segment.
        //
        // HR: Područje definira prvi slug, a stranica drugi segment URL-a.
        'root_path' => 'workspace',
    ],
    'defaults' => [
        'visibility' => 'restricted',
        'tree_visible' => true,
        'contents_visible' => false,
    ],
    'creation' => [
        // EN: Administrators may always create workspaces; other subjects are added here.
        //
        // HR: Administratori uvijek smiju kreirati područja; ovdje se dodaju ostali subjekti.
        'users' => [],
        'groups' => [],
    ],
    'shorts' => [
        // EN: Default values for each Workspace's public Shorts listing.
        //
        // HR: Zadane vrijednosti javnog popisa sažetaka unutar svakog područja.
        'depth' => 2,
        'limit' => 10,
        'order' => 'newest',
        'display_options_visible' => false,
    ],
    'backlinks' => [
        // EN: The periodic check repairs the derived index if an event was interrupted.
        //
        // HR: Periodična provjera popravlja izvedeni indeks ako je događaj bio prekinut.
        'refresh_seconds' => 3600,
    ],
    'menu' => [
        'auto_register_top' => true,
        'auto_register_settings' => true,
    ],
    'homepage' => [
        // EN: Global policy is configuration; personal selections remain in the database.
        //
        // HR: Globalna politika je konfiguracija; osobni odabiri ostaju u bazi.
        'public_target' => [
            'type' => 'default', 'node_id' => 0, 'workspace_id' => 0,
            'show_tree' => true, 'show_display_options' => true,
        ],
        'authenticated_target' => [
            'type' => 'default', 'node_id' => 0, 'workspace_id' => 0,
            'show_tree' => true, 'show_display_options' => true,
        ],
        'allow_user_selection' => true,
    ],
];
