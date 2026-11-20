<?php

/*
 * What the public landing page says the product does.
 *
 * The page at `/` is drawn entirely from this file, so it stays honest by
 * construction: a feature appears there when it is added here, and not before.
 *
 * The page follows the design system in docs/brand-book.md, and its layout is
 * the one supplied with it (resources/css/pharmaco/landing.css). Its one idea
 * is two columns, one book: every row is a pair, pharmacy on the left and
 * distributor on the right, and what both share sits apart from the pairs.
 *
 * WHEN A FEATURE SHIPS, ADD IT HERE:
 *   - to the `pharmacy` and/or `distributor` side of the row it belongs to
 *     (or a new row — a row always has both sides);
 *   - to `shared` when it is the same for both and is a fact about the books.
 *
 * WHATEVER IS NOT BUILT YET IS LABELLED "COMING SOON". Give that side of the
 * row `'status' => 'soon'` and the page puts the chip on it. Take the label
 * off when it ships. Nothing unbuilt is ever shown as available.
 *
 * Voice: plain and exact, in the owner's words. Say what the system does and
 * what it prevents. No exclamation marks.
 */

return [

    // Where "Request a demo" goes. While this is empty the page shows no demo
    // links at all, rather than buttons that lead nowhere.
    'contact_email' => env('LANDING_CONTACT_EMAIL'),

    'hero' => [
        'label' => 'Pharmacies and distributors, on one set of books',
        'title' => 'One set of books, closed against the drawer every day.',
        'lede' => 'Pharmaco runs the selling, stock and money of a pharmaceutical business in the browser. Every sale, delivery and collection posts to a double-entry ledger, and nothing posted can be edited or deleted afterwards.',

        'pharmacy' => [
            'title' => 'A busy counter, and a drawer that no longer matches what you believe.',
            'body' => 'Stock, expiry and cash drift away from the books, and nobody can say where the difference went.',
            'button' => 'See it for pharmacies',
            // A sample of the screen. The figures are examples and say so.
            'sample' => [
                'head' => 'Day closing',
                'rows' => [
                    ['Books say', 'Rs 48,200', false],
                    ['Drawer counted', 'Rs 48,200', false],
                    ['Difference', 'Rs 0', false],
                ],
                'foot' => 'Day locked',
                'alert' => false,
            ],
        ],
        'distributor' => [
            'title' => 'Profitable every day, and still running out of cash.',
            'body' => 'The profit is sitting in pharmacies\' shops as credit.',
            'button' => 'See it for distributors',
            'sample' => [
                'head' => 'Market credit',
                // The third value marks a figure as overdue: the only red on the page.
                'rows' => [
                    ['0 to 30 days', 'Rs 312,000', false],
                    ['31 to 60 days', 'Rs 96,500', false],
                    ['Over 60 days', 'Rs 41,000', true],
                ],
                'foot' => 'Overdue Rs 41,000',
                'alert' => true,
            ],
        ],
    ],

    // What the owner sees together.
    'four' => [
        'label' => 'The owner sees four things together, and can trust them',
        'items' => ['Cash', 'Stock', 'What customers owe', 'What is owed to companies'],
    ],

    'rows' => [
        [
            'title' => 'Money coming in',
            'pharmacy' => [
                'title' => 'A fast till',
                'points' => [
                    'Scan or search',
                    'Every item at its MRP, with a discount at the counter',
                    'Thermal or A4 receipts',
                    'Cash, JazzCash, Easypaisa or bank transfer',
                ],
            ],
            'distributor' => [
                'title' => 'Market credit',
                'points' => [
                    'A statement for every pharmacy',
                    'Ageing and overdue totals',
                    'Collections applied to the specific bills they pay',
                ],
            ],
        ],
        [
            'title' => 'Early warning',
            'pharmacy' => [
                'title' => 'Expiry and batch',
                'points' => [
                    'Near-expiry stock is flagged at the counter before it is sold',
                    'A valued list of what is running out',
                ],
            ],
            'distributor' => [
                'title' => 'Alerts',
                'points' => [
                    'When credit grows faster than sales',
                    'When collections lag',
                    'When cash runs thin',
                ],
            ],
        ],
        [
            'title' => 'Stock and purchasing',
            'pharmacy' => [
                'title' => 'Stock that holds up',
                'points' => [
                    'Stock cannot go below zero',
                    'A few lines are counted each day',
                    'Reorder levels produce a list of what to buy',
                ],
            ],
            'distributor' => [
                'title' => 'Purchasing',
                'points' => [
                    'Order forms sent as PDF',
                    'Deliveries received against them, with batch and expiry',
                    'Price lists imported from the company\'s own PDF',
                ],
            ],
        ],
        [
            'title' => 'Accounts that stay straight',
            'pharmacy' => [
                'title' => 'Returns and corrections',
                'points' => [
                    'Goods taken back against the original bill',
                    'Bill edits that keep their full history',
                ],
            ],
            'distributor' => [
                'title' => 'Company accounts',
                'points' => [
                    'A running account per supplier',
                    'What you owe is never one lump figure',
                ],
            ],
        ],
        [
            'title' => 'Who closes the day',
            'pharmacy' => [
                'title' => 'Daily closing',
                'points' => [
                    'The drawer is counted against the books every day',
                    'Each bill carries its own profit',
                ],
            ],
            'distributor' => [
                'title' => 'Control over staff',
                'points' => [
                    'Operators enter the day',
                    'The owner closes it',
                    'Every change is on record',
                ],
            ],
        ],
        [
            'title' => 'Down to the detail',
            'pharmacy' => [
                'title' => 'Loose selling',
                'points' => [
                    'Sell a whole pack',
                    'Or single tablets out of it',
                    'Priced to the piece',
                ],
            ],
            'distributor' => [
                'title' => 'What is next',
                'status' => 'soon',
                'points' => [
                    'Order booking by field staff',
                    'Trade terms: bonus and scheme quantities, per-customer prices',
                    'Credit limits',
                ],
            ],
        ],
    ],

    // What both sides share. Facts about the books, not about either trade.
    'shared' => [
        'label' => 'Pharmacy or distributor',
        'title' => 'What both sides share',
        'items' => [
            ['title' => 'Figures cannot drift', 'body' => 'Balances are worked out from the entries every time, never stored.'],
            ['title' => 'No quiet edits', 'body' => 'Posted records cannot be quietly changed, by anyone.'],
            ['title' => 'Every number opens', 'body' => 'Each figure opens to the entries behind it.'],
            ['title' => 'Spreadsheet reports', 'body' => 'Reports for sales, purchases and stock, downloadable as spreadsheets.'],
            ['title' => 'Several businesses, one login', 'body' => 'Each business is fully separate.'],
            ['title' => 'Nothing to install', 'body' => 'Pharmaco runs in the browser.'],
        ],
    ],

    // Shown only when contact_email is set.
    'demo' => [
        'pharmacy' => [
            'title' => 'Run a pharmacy?',
            'body' => 'See the till, the expiry flags and the daily closing on your own counter.',
            'button' => 'Request a pharmacy demo',
        ],
        'distributor' => [
            'title' => 'Run a distribution?',
            'body' => 'See market credit, alerts and company accounts on your own customers and suppliers.',
            'button' => 'Request a distributor demo',
        ],
    ],
];
