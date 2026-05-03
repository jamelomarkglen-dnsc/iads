<?php

function program_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'PHDEM' => [
            'label' => 'Doctor of Philosophy in Educational Management (PHDEM)',
            'aliases' => [
                'PhD in Educational Management',
                'Doctor of Philosophy in Educational Management',
            ],
        ],
        'PHD-ELST' => [
            'label' => 'Doctor of Philosophy in English Language Studies and Teaching (PhD ELST)',
            'aliases' => [
                'PhD in English Language Studies and Teaching',
                'Doctor of Philosophy in English Language Studies and Teaching',
                'PhD ELST',
            ],
        ],
        'PHD-SCIED' => [
            'label' => 'Doctor of Philosophy in Science Education (PhD SciEd)',
            'aliases' => [
                'PhD in Science Education',
                'Doctor of Philosophy in Science Education',
                'PhD SciEd',
            ],
        ],
        'MAEM' => [
            'label' => 'Master of Arts in Educational Management (MAEM)',
            'aliases' => [
                'Master of Arts in Educational Management',
            ],
        ],
        'MAED-ELST' => [
            'label' => 'Master of Education Major in English Language Studies and Teaching (MAED-ELST)',
            'aliases' => [
                'Master of Education Major in English Language Studies and Teaching',
            ],
        ],
        'MST-GENSCI' => [
            'label' => 'Master in Science Teaching Major in General Science (MST-GENSCI)',
            'aliases' => [
                'Master in Science Teaching Major in General Science',
            ],
        ],
        'MST-MATH' => [
            'label' => 'Master in Science Teaching Major in Mathematics (MST-MATH)',
            'aliases' => [
                'Master in Science Teaching Major in Mathematics',
            ],
        ],
        'MFM-AT' => [
            'label' => 'Master in Fisheries Management Major in Aquaculture Technology (MFM-AT)',
            'aliases' => [
                'Master in Fisheries Management Major in Aquaculture Technology',
            ],
        ],
        'MFM-FP' => [
            'label' => 'Master in Fisheries Management Major in Fish Processing (MFM-FP)',
            'aliases' => [
                'Master in Fisheries Management Major in Fish Processing',
            ],
        ],
        'MSMB' => [
            'label' => 'Master of Science in Marine Biodiversity (MSMB)',
            'aliases' => [
                'MS in Marine Biodiversity & Fisheries Management',
                'Master of Science in Marine Biodiversity',
            ],
        ],
        'MIT' => [
            'label' => 'Master in Information Technology (MIT)',
            'aliases' => [
                'Master in Information Technology',
            ],
        ],
    ];

    return $catalog;
}

function program_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = [];
    foreach (program_catalog() as $code => $definition) {
        $options[$code] = $definition['label'];
    }

    return $options;
}

function program_normalize_key(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return strtolower(preg_replace('/\s+/', ' ', $value));
}

function normalize_program_code(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $needle = program_normalize_key($value);
    foreach (program_catalog() as $code => $definition) {
        $candidates = array_merge([$code, $definition['label']], $definition['aliases']);
        foreach ($candidates as $candidate) {
            if ($needle === program_normalize_key((string)$candidate)) {
                return $code;
            }
        }
    }

    return '';
}

function program_display_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $code = normalize_program_code($value);
    $options = program_options();
    if (isset($options[$code])) {
        return $options[$code];
    }

    return $value;
}

function program_values_match(?string $left, ?string $right): bool
{
    $left = trim((string)$left);
    $right = trim((string)$right);
    if ($left === '' || $right === '') {
        return false;
    }

    $leftCode = normalize_program_code($left);
    $rightCode = normalize_program_code($right);
    if ($leftCode !== '' && $rightCode !== '') {
        return strcasecmp($leftCode, $rightCode) === 0;
    }

    return strcasecmp($left, $right) === 0;
}

function program_match_terms(?string $value): array
{
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }

    $code = normalize_program_code($value);
    $options = program_options();
    if ($code === '' || !isset($options[$code])) {
        return [$value];
    }

    $definition = program_catalog()[$code];
    $terms = [$code, $definition['label']];
    foreach ($definition['aliases'] as $alias) {
        $terms[] = $alias;
    }

    $unique = [];
    $seen = [];
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term === '') {
            continue;
        }
        $key = program_normalize_key($term);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $term;
    }

    return $unique;
}

function program_sql_in_clause(string $column, ?string $value, array &$params, string &$types): string
{
    $terms = program_match_terms($value);
    if (empty($terms)) {
        return '';
    }

    $placeholders = implode(', ', array_fill(0, count($terms), '?'));
    foreach ($terms as $term) {
        $params[] = $term;
        $types .= 's';
    }

    return "{$column} IN ({$placeholders})";
}
