<?php

namespace App\Enums;

/** What the pack itself is — how the strips or bottles are put up. */
enum PackType: string
{
    case Blister = 'blister';
    case Strip = 'strip';
    case Bottle = 'bottle';
    case Vial = 'vial';
    case Ampoule = 'ampoule';
    case Tube = 'tube';
    case Jar = 'jar';
    case Sachet = 'sachet';
    case Box = 'box';
    case Can = 'can';
    case Pen = 'pen';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string> */
    public function synonyms(): array
    {
        return match ($this) {
            self::Blister => ['blister', 'blisters', 'alu alu', 'alu-alu', 'bl'],
            self::Strip => ['strip', 'strips', 'str'],
            self::Bottle => ['bottle', 'bot', 'btl', 'bottles', 'pet bottle'],
            self::Vial => ['vial', 'vials', 'vl'],
            self::Ampoule => ['ampoule', 'ampoules', 'amp', 'amps', 'ampule'],
            self::Tube => ['tube', 'tubes'],
            self::Jar => ['jar', 'jars'],
            self::Sachet => ['sachet', 'sachets', 'pouch'],
            self::Box => ['box', 'boxes', 'carton', 'ctn'],
            self::Can => ['can', 'tin', 'tins'],
            self::Pen => ['pen', 'prefilled pen', 'pre-filled pen', 'cartridge'],
            self::Other => [],
        };
    }

    public static function match(?string $text): ?self
    {
        $needle = strtolower(trim((string) $text));

        if ($needle === '') {
            return null;
        }

        $needle = preg_replace('/\s+/', ' ', str_replace(['.', '_'], ' ', $needle)) ?? $needle;

        foreach (self::cases() as $case) {
            foreach ($case->synonyms() as $synonym) {
                if ($needle === $synonym) {
                    return $case;
                }
            }
        }

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
