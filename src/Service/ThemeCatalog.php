<?php

namespace App\Service;

final class ThemeCatalog
{
    public const string DEFAULT = 'tempo';

    public const array THEMES = [
        'signature' => [
            'label' => 'Rouge Signature',
            'colors' => [
                'sidebar' => '#201a20', 'accent' => '#b9243b', 'accent-hover' => '#921c31',
                'canvas' => '#f8f7f8', 'ink' => '#292027', 'muted' => '#665965', 'line' => '#e9e1e6',
                'brand-mark' => '#f7bec8', 'accent-soft' => '#fbedef', 'accent-tint' => '#f5d8df',
            ],
        ],
        'atlantic' => [
            'label' => 'Bleu Atlantique',
            'colors' => [
                'sidebar' => '#182c43', 'accent' => '#245eb0', 'accent-hover' => '#194785',
                'canvas' => '#f4f7fb', 'ink' => '#1a2c43', 'muted' => '#52657c', 'line' => '#dce6f1',
                'brand-mark' => '#bedaff', 'accent-soft' => '#edf4fe', 'accent-tint' => '#d8e8fd',
            ],
        ],
        'iris' => [
            'label' => 'Iris',
            'colors' => [
                'sidebar' => '#2c2345', 'accent' => '#6743a9', 'accent-hover' => '#503185',
                'canvas' => '#f8f6fb', 'ink' => '#302641', 'muted' => '#625772', 'line' => '#e8e1ef',
                'brand-mark' => '#dcc7fa', 'accent-soft' => '#f3eefb', 'accent-tint' => '#e8dcf8',
            ],
        ],
        'terracotta' => [
            'label' => 'Terre cuite',
            'colors' => [
                'sidebar' => '#342721', 'accent' => '#a54832', 'accent-hover' => '#853724',
                'canvas' => '#faf7f5', 'ink' => '#382923', 'muted' => '#6c5b52', 'line' => '#eee2da',
                'brand-mark' => '#f5c9b5', 'accent-soft' => '#faf0eb', 'accent-tint' => '#f2ded2',
            ],
        ],
        'lagoon' => [
            'label' => 'Lagon',
            'colors' => [
                'sidebar' => '#15343b', 'accent' => '#08727e', 'accent-hover' => '#07545e',
                'canvas' => '#f3f8f9', 'ink' => '#173237', 'muted' => '#4d6870', 'line' => '#d9e8eb',
                'brand-mark' => '#ade5e8', 'accent-soft' => '#e7f5f6', 'accent-tint' => '#d1edf0',
            ],
        ],
        'gold' => [
            'label' => 'Encre et or',
            'colors' => [
                'sidebar' => '#242628', 'accent' => '#82600b', 'accent-hover' => '#654807',
                'canvas' => '#f8f7f3', 'ink' => '#312d24', 'muted' => '#665e4c', 'line' => '#e9e3d5',
                'brand-mark' => '#f4d992', 'accent-soft' => '#f9f3e2', 'accent-tint' => '#efe3bd',
            ],
        ],
        self::DEFAULT => [
            'label' => 'Vert Tempo',
            'colors' => [
                'sidebar' => '#152e29', 'accent' => '#0e6f57', 'accent-hover' => '#095341',
                'canvas' => '#f5f7f6', 'ink' => '#162521', 'muted' => '#5b6b65', 'line' => '#e1e8e4',
                'brand-mark' => '#a9e6c8', 'accent-soft' => '#eaf5ee', 'accent-tint' => '#d2ecdf',
            ],
        ],
    ];
}
