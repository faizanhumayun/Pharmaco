<?php

namespace App\Enums;

/**
 * How the medicine is presented.
 *
 * Price lists write the same form a dozen ways — "Tab.", "TABS", "Tablets" —
 * so the enum owns the vocabulary and {@see match()} is the only place that
 * has to know about it.
 */
enum DosageForm: string
{
    case Tablet = 'tablet';
    case Capsule = 'capsule';
    case Syrup = 'syrup';
    case Suspension = 'suspension';
    case Injection = 'injection';
    case Infusion = 'infusion';
    case Drops = 'drops';
    case Cream = 'cream';
    case Ointment = 'ointment';
    case Gel = 'gel';
    case Lotion = 'lotion';
    case Inhaler = 'inhaler';
    case Spray = 'spray';
    case Sachet = 'sachet';
    case Powder = 'powder';
    case Suppository = 'suppository';
    case Solution = 'solution';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string> */
    public function synonyms(): array
    {
        return match ($this) {
            self::Tablet => ['tab', 'tabs', 'tablet', 'tablets', 'tb', 'f.c tablet', 'fc tablet', 'caplet', 'caplets'],
            self::Capsule => ['cap', 'caps', 'capsule', 'capsules', 'softgel', 'soft gel'],
            self::Syrup => ['syr', 'syrup', 'syp', 'elixir', 'linctus'],
            self::Suspension => ['susp', 'suspension', 'dry susp', 'dry suspension'],
            self::Injection => ['inj', 'injection', 'ampoule', 'amp', 'vial', 'im', 'iv'],
            self::Infusion => ['infusion', 'iv infusion', 'drip'],
            self::Drops => ['drop', 'drops', 'eye drops', 'ear drops', 'nasal drops', 'oral drops', 'gtt'],
            self::Cream => ['cream', 'crm'],
            self::Ointment => ['oint', 'ointment', 'ont'],
            self::Gel => ['gel'],
            self::Lotion => ['lotion', 'shampoo'],
            self::Inhaler => ['inhaler', 'inh', 'mdi', 'rotacap', 'respule', 'nebuliser', 'nebulizer'],
            self::Spray => ['spray', 'nasal spray', 'aerosol'],
            self::Sachet => ['sachet', 'sachets', 'sach'],
            self::Powder => ['powder', 'granules', 'pwd'],
            self::Suppository => ['supp', 'suppository', 'suppositories'],
            self::Solution => ['solution', 'soln', 'oral solution'],
            self::Other => [],
        };
    }

    /**
     * Best guess at the form named by a free-text cell.
     *
     * Returns null rather than Other when nothing matches, so the review screen
     * can show the cell as unrecognised instead of quietly filing it away.
     */
    public static function match(?string $text): ?self
    {
        $needle = strtolower(trim((string) $text));

        if ($needle === '') {
            return null;
        }

        $needle = rtrim(str_replace(['.', '_'], ' ', $needle));
        $needle = preg_replace('/\s+/', ' ', $needle) ?? $needle;

        foreach (self::cases() as $case) {
            foreach ($case->synonyms() as $synonym) {
                if ($needle === $synonym) {
                    return $case;
                }
            }
        }

        // A form is often buried in a longer description: "Amoxil 250mg Cap 2x10".
        foreach (self::cases() as $case) {
            foreach ($case->synonyms() as $synonym) {
                if (preg_match('/\b'.preg_quote($synonym, '/').'\b/', $needle)) {
                    return $case;
                }
            }
        }

        return null;
    }
}
