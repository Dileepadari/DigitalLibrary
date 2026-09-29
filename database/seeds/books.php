<?php

declare(strict_types=1);

/**
 * Public domain books, so a fresh clone is not an empty shelf.
 *
 * Every one of these is out of copyright and freely available from Project
 * Gutenberg or the Internet Archive; `source_url` points at where it came from.
 * No files are attached: uploads arrive in M3, and these are catalogue records.
 *
 * Each one does carry a cover, in `covers/`. They are typeset here rather than
 * scanned: a seeded shelf with no pictures reads as a broken feature, and a
 * scanned jacket would drag someone else's licence into a public-domain set.
 */
return [
    [
        'title'       => 'Pride and Prejudice',
        'authors'     => 'Jane Austen',
        'year'        => 1813,
        'pages'       => 432,
        'categories'  => ['/stories/novels/'],
        'tags'        => ['Fiction', 'Romance', 'Classic'],
        'description' => 'Elizabeth Bennet meets Mr Darcy, dislikes him thoroughly, and spends the rest of '
            . 'the novel finding out how much of that was her own pride talking.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/1342',
        'cover'       => 'pride-and-prejudice.jpg',
    ],
    [
        'title'       => 'Frankenstein',
        'subtitle'    => 'or, The Modern Prometheus',
        'authors'     => 'Mary Shelley',
        'year'        => 1818,
        'pages'       => 280,
        'categories'  => ['/stories/novels/'],
        'tags'        => ['Fiction', 'Science fiction', 'Classic'],
        'description' => 'A student assembles a living creature and abandons it. The creature, articulate and '
            . 'entirely alone, comes looking for its maker.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/84',
        'cover'       => 'frankenstein.jpg',
    ],
    [
        'title'       => 'The Time Machine',
        'authors'     => 'H. G. Wells',
        'year'        => 1895,
        'pages'       => 118,
        'categories'  => ['/stories/novels/'],
        'tags'        => ['Fiction', 'Science fiction', 'Classic'],
        'description' => 'A traveller goes forward to the year 802,701 and finds humanity split into two '
            . 'species, neither of them an improvement.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/35',
        'cover'       => 'the-time-machine.jpg',
    ],
    [
        'title'       => 'Alice\'s Adventures in Wonderland',
        'authors'     => 'Lewis Carroll',
        'year'        => 1865,
        'pages'       => 200,
        'categories'  => ['/kids/picture-books/', '/stories/novels/'],
        'tags'        => ['Fiction', 'Fantasy', 'Classic'],
        'description' => 'Alice follows a hurried rabbit down a hole and negotiates with everyone she meets, '
            . 'none of whom are reasonable.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/11',
        'cover'       => 'alices-adventures-in-wonderland.jpg',
    ],
    [
        'title'       => 'Grimms\' Fairy Tales',
        'authors'     => 'Jacob Grimm, Wilhelm Grimm',
        'year'        => 1812,
        'pages'       => 320,
        'categories'  => ['/stories/folk-tales/', '/kids/picture-books/'],
        'tags'        => ['Fiction', 'Classic'],
        'description' => 'Two hundred tales collected across German-speaking Europe, most of them older and '
            . 'sharper than the versions that reached childhood.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/2591',
        'cover'       => 'grimms-fairy-tales.jpg',
    ],
    [
        'title'       => 'The Adventures of Sherlock Holmes',
        'authors'     => 'Arthur Conan Doyle',
        'year'        => 1892,
        'pages'       => 307,
        'categories'  => ['/stories/short-stories/'],
        'tags'        => ['Fiction', 'Thriller', 'Classic'],
        'description' => 'Twelve cases, narrated by Dr Watson, in which the answer was visible on page one to '
            . 'exactly one person.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/1661',
        'cover'       => 'the-adventures-of-sherlock-holmes.jpg',
    ],
    [
        'title'       => 'On the Origin of Species',
        'authors'     => 'Charles Darwin',
        'year'        => 1859,
        'pages'       => 502,
        'categories'  => ['/academics/reference/'],
        'tags'        => ['Non-fiction', 'Science', 'Classic'],
        'description' => 'The argument for descent with modification, built patiently out of pigeons, barnacles '
            . 'and twenty years of not publishing.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/1228',
        'cover'       => 'on-the-origin-of-species.jpg',
    ],
    [
        'title'        => 'The Elements of Euclid',
        'authors'      => 'Euclid',
        'year'         => 1570,
        'pages'        => 528,
        'categories'   => ['/academics/reference/', '/academics/school/'],
        'tags'         => ['Non-fiction', 'Mathematics', 'Classic'],
        'description'  => 'Thirteen books of geometry from a handful of definitions, still the model for what '
            . 'a proof looks like.',
        'source_url'   => 'https://www.gutenberg.org/ebooks/21076',
        'cover'       => 'the-elements-of-euclid.jpg',
        'content_type' => 'academic_paper',
    ],
    [
        'title'       => 'Meditations',
        'authors'     => 'Marcus Aurelius',
        'year'        => 180,
        'pages'       => 254,
        'categories'  => ['/academics/reference/', '/religion/'],
        'tags'        => ['Non-fiction', 'Philosophy', 'Self help'],
        'description' => 'A Roman emperor\'s notes to himself, written on campaign and never meant to be read '
            . 'by anyone else.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/2680',
        'cover'       => 'meditations.jpg',
    ],
    [
        'title'       => 'The Republic',
        'authors'     => 'Plato',
        'year'        => 1892,
        'pages'       => 416,
        'categories'  => ['/academics/reference/'],
        'tags'        => ['Non-fiction', 'Philosophy', 'Classic'],
        'description' => 'Socrates builds an ideal city in order to work out what justice is, and takes a long '
            . 'time doing it. Jowett\'s translation.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/1497',
        'cover'       => 'the-republic.jpg',
    ],
    [
        'title'       => 'Gitanjali',
        'subtitle'    => 'Song Offerings',
        'authors'     => 'Rabindranath Tagore',
        'year'        => 1912,
        'pages'       => 104,
        'categories'  => ['/stories/short-stories/', '/religion/'],
        'tags'        => ['Poetry', 'Classic'],
        'description' => 'The poet\'s own English versions of his Bengali songs, and the book the Nobel Prize '
            . 'followed a year later.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/7164',
        'cover'       => 'gitanjali.jpg',
    ],
    [
        'title'       => 'The Autobiography of Benjamin Franklin',
        'authors'     => 'Benjamin Franklin',
        'year'        => 1791,
        'pages'       => 240,
        'categories'  => ['/academics/reference/'],
        'tags'        => ['Non-fiction', 'Biography', 'Self help'],
        'description' => 'A printer\'s account of making himself useful, written for his son and finished by '
            . 'nobody in particular.',
        'source_url'  => 'https://www.gutenberg.org/ebooks/20203',
        'cover'       => 'the-autobiography-of-benjamin-franklin.jpg',
    ],
    [
        'title'        => 'Indian Constitution: Bare Act Reading Notes',
        'authors'      => 'Community contributors',
        'year'         => 2024,
        'pages'        => 96,
        'categories'   => ['/academics/competitive-exams/upsc/'],
        'tags'         => ['Non-fiction', 'UPSC'],
        'description'  => 'A worked reading of the Constitution\'s parts and schedules, written by members of '
            . 'this library. Placeholder seed content, replace it with your own.',
        'source_url'   => null,
        'cover'       => 'indian-constitution-bare-act-reading-notes.jpg',
        'content_type' => 'notes',
        'licence'      => 'own_work',
    ],
];
