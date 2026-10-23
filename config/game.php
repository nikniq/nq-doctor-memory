<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Game Presentation
    |--------------------------------------------------------------------------
    |
    | Text and theme values for the memory game. Each one can be overridden
    | from .env so the game can be re-skinned without touching the views.
    |
    */

    'title' => env('GAME_TITLE', 'Doctor Memory Game'),

    'win_message' => env('GAME_WIN_MESSAGE', 'Congratulations! You found all pairs!'),

    'font_family' => env('GAME_FONT_FAMILY', 'Arial'),

    'font_size' => (int) env('GAME_FONT_SIZE', 16),

    'colors' => [
        'background' => env('GAME_BACKGROUND_COLOR', '#1e3a5f'),
        'card_back' => env('GAME_CARD_BACK_COLOR', '#3b82f6'),
        'card_front' => env('GAME_CARD_FRONT_COLOR', '#ffffff'),
        'text' => env('GAME_TEXT_COLOR', '#1e293b'),
        'button' => env('GAME_BUTTON_COLOR', '#10b981'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cards
    |--------------------------------------------------------------------------
    |
    | One entry per pair. The board shows every card twice, shuffled. The
    | fact is shown briefly when the pair is matched.
    |
    */

    'cards' => [
        ['id' => 1, 'icon' => '🩺', 'name' => 'Stethoscope', 'fact' => 'René Laennec invented the stethoscope in 1816, starting with a rolled-up tube of paper.'],
        ['id' => 2, 'icon' => '💊', 'name' => 'Pills', 'fact' => 'Aspirin was first made in stable form by chemist Felix Hoffmann at Bayer in 1897.'],
        ['id' => 3, 'icon' => '💉', 'name' => 'Syringe', 'fact' => 'The hypodermic syringe was developed in the 1850s by Alexander Wood and Charles Pravaz.'],
        ['id' => 4, 'icon' => '🏥', 'name' => 'Hospital', 'fact' => 'The word hospital comes from the Latin hospes, meaning host or guest.'],
        ['id' => 5, 'icon' => '⚕️', 'name' => 'Medical', 'fact' => 'This is the Rod of Asclepius, named after the Greek god of medicine.'],
        ['id' => 6, 'icon' => '🚑', 'name' => 'Ambulance', 'fact' => 'Napoleon\'s surgeon Dominique-Jean Larrey created horse-drawn \'flying ambulances\' in the 1790s.'],
        ['id' => 7, 'icon' => '🩹', 'name' => 'Bandage', 'fact' => 'Earle Dickson invented the Band-Aid around 1920 for his wife, who often cut herself cooking.'],
        ['id' => 8, 'icon' => '🔬', 'name' => 'Microscope', 'fact' => 'Antonie van Leeuwenhoek was the first person to see bacteria, through a lens he made himself.'],
        ['id' => 9, 'icon' => '🧬', 'name' => 'DNA', 'fact' => 'The double-helix shape of DNA was described in 1953, building on Rosalind Franklin\'s X-ray images.'],
        ['id' => 10, 'icon' => '🦷', 'name' => 'Tooth', 'fact' => 'Tooth enamel is the hardest substance in the human body.'],
        ['id' => 11, 'icon' => '🧪', 'name' => 'Test Tube', 'fact' => 'Louise Brown, the world\'s first IVF or \'test-tube\' baby, was born in 1978.'],
        ['id' => 12, 'icon' => '🫀', 'name' => 'Heart', 'fact' => 'An adult heart beats roughly 100,000 times a day.'],
        ['id' => 13, 'icon' => '🩻', 'name' => 'X-ray', 'fact' => 'Wilhelm Röntgen discovered X-rays in 1895 and won the first Nobel Prize in Physics for it.'],
        ['id' => 14, 'icon' => '🧠', 'name' => 'Brain', 'fact' => 'The brain is about 2% of body weight but uses roughly 20% of the body\'s energy.'],
        ['id' => 15, 'icon' => '🫁', 'name' => 'Lungs', 'fact' => 'The right lung has three lobes and the left has two, leaving room for the heart.'],
        ['id' => 16, 'icon' => '🦴', 'name' => 'Bone', 'fact' => 'Adults have 206 bones. Babies start with around 300, and many fuse as they grow.'],
        ['id' => 17, 'icon' => '🩸', 'name' => 'Blood', 'fact' => 'Karl Landsteiner discovered the A, B and O blood groups in 1901.'],
        ['id' => 18, 'icon' => '🌡️', 'name' => 'Thermometer', 'fact' => 'Normal body temperature is about 37°C, and it shifts a little over the day.'],
        ['id' => 19, 'icon' => '🦠', 'name' => 'Microbe', 'fact' => 'Alexander Fleming discovered penicillin in 1928 when mould killed bacteria in a dish.'],
        ['id' => 20, 'icon' => '😷', 'name' => 'Mask', 'fact' => 'Surgeons began wearing masks in operating rooms in the late 1890s.'],
        ['id' => 21, 'icon' => '🧫', 'name' => 'Petri Dish', 'fact' => 'The Petri dish is named after Julius Petri, who worked in Robert Koch\'s lab in the 1880s.'],
        ['id' => 22, 'icon' => '👁️', 'name' => 'Eye', 'fact' => 'The cornea has no blood vessels. It takes oxygen straight from the air.'],
        ['id' => 23, 'icon' => '🧑‍⚕️', 'name' => 'Doctor', 'fact' => 'Hippocrates, born around 460 BC, is often called the father of Western medicine.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grid Sizes
    |--------------------------------------------------------------------------
    |
    | Each grid is its own board with its own leaderboard. Keys are
    | "columns x rows" and are stored with games and scores, so renaming one
    | needs a data migration. 'columns_narrow' is the column count on phones.
    | 'pairs' must equal columns * rows / 2 and may not exceed the cards.
    |
    */

    'default_difficulty' => '4x4',

    'difficulties' => [
        '4x3' => ['label' => '4 × 3', 'pairs' => 6, 'columns' => 4, 'columns_narrow' => 3],
        '4x4' => ['label' => '4 × 4', 'pairs' => 8, 'columns' => 4, 'columns_narrow' => 4],
        '5x4' => ['label' => '5 × 4', 'pairs' => 10, 'columns' => 5, 'columns_narrow' => 4],
        '6x4' => ['label' => '6 × 4', 'pairs' => 12, 'columns' => 6, 'columns_narrow' => 4],
        '6x5' => ['label' => '6 × 5', 'pairs' => 15, 'columns' => 6, 'columns_narrow' => 5],
        '6x6' => ['label' => '6 × 6', 'pairs' => 18, 'columns' => 6, 'columns_narrow' => 6],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leaderboard
    |--------------------------------------------------------------------------
    */

    'leaderboard_size' => 10,

    /*
    | Player rankings need this many finished solo games before a player is
    | ranked overall, so one lucky game cannot top the table.
    */

    'rankings_min_games' => 3,

];
