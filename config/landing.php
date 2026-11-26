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

    /*
     * The product itself, in pictures. These are screens of the running
     * application (public/images/landing), with the business's name changed.
     * The first one answers the owner's question: cash, stock, what customers
     * owe and what is owed to companies, together.
     *
     * To add one: save the picture beside the others and add a line here.
     */
    'screens' => [
        'label' => 'The real screens',
        'title' => 'Cash, stock, what customers owe and what is owed to companies, on one screen.',
        'body' => 'This is the application as a pharmacy uses it, not a drawing of it.',
        'items' => [
            [
                'tab' => 'Dashboard',
                'image' => 'images/landing/dashboard.png',
                'caption' => 'Where the business stands today: net position, sales, market credit, what is due, near-expiry stock and the stock count.',
            ],
            [
                'tab' => 'The till',
                'image' => 'images/landing/pos.png',
                'caption' => 'Scan or search, see what is in stock, and take cash, a wallet, a bank transfer or credit.',
            ],
            [
                'tab' => 'Daily closing',
                'image' => 'images/landing/closing.png',
                'caption' => 'The books say what should be in the drawer. The day closes only when every check is ticked.',
            ],
            [
                'tab' => 'Near expiry',
                'image' => 'images/landing/expiring.png',
                'caption' => 'Every batch that is running out of time, valued at cost, before it becomes a loss.',
            ],
        ],
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
                    'Each company\'s rack counted batch by batch, with every expiry on the sheet',
                    'A company\'s rack audited each month, with a report of what went missing',
                    'Stock found short is booked as a loss, stock found over adds to stock value',
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
                    'Open any bill on the statement to see its items, batches and rates',
                    'What each supplier\'s goods have sold for at the counter',
                    'Pay a supplier from its own page, in full or in part',
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
            ['title' => 'Clear on any screen', 'body' => 'Strong colours make text, lines and colours easy to read on a dull or washed-out counter screen, set for each computer.'],
            ['title' => 'Works without internet', 'body' => 'A shop can run on its own computer and keep selling offline. The owner reads an up-to-date copy online, and support can pause a shop to fix its books and hand them straight back. If the shop\'s computer is lost or stolen, its books come back from the online copy.'],
        ],
    ],

    /*
     * What it costs. The list prices and the discounts are kept apart so an
     * offer can end by changing one number: the page works the discounted
     * price out itself. Prices are whole rupees, per business.
     *
     * 'setup_free' => true shows the one-time setup fee crossed out and
     * "Free for now"; set it to false when the fee starts being charged.
     */
    'pricing' => [
        'label' => 'Pricing',
        'title' => 'One price for the whole business',
        'body' => 'Launch prices, per business rather than per user. The setup fee covers moving your old records in and entering your opening position.',
        'monthly_discount' => 40,
        'yearly_discount' => 50,
        'setup_free' => true,
        'pharmacy' => ['monthly' => 4000, 'setup' => 15000],
        'distributor' => ['monthly' => 10000, 'setup' => 25000],
    ],

    // The foot of the page. 'powered_by' is who built and runs the product.
    'footer' => [
        'powered_by' => 'Faizan Humayun',
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
