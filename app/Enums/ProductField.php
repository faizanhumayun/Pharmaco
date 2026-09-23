<?php

namespace App\Enums;

use App\Domain\Products\HeaderMatcher;

/**
 * The fields a price-list column can be mapped onto.
 *
 * Header wording varies by company, so each field carries the labels it answers
 * to. The importer never guesses from position alone — an unmatched column is
 * left unmapped for a person to assign.
 */
enum ProductField: string
{
    case Code = 'code';
    case BrandName = 'brand_name';
    case GenericName = 'generic_name';
    case Strength = 'strength';
    case DosageForm = 'dosage_form';
    case PackSize = 'pack_size';
    case PackType = 'pack_type';
    case Mrp = 'mrp';
    case TradePrice = 'trade_price';
    case PurchaseRate = 'purchase_rate';
    case CaseSize = 'case_size';

    public function label(): string
    {
        return match ($this) {
            self::Code => 'Product code',
            self::BrandName => 'Brand name',
            self::GenericName => 'Generic name',
            self::Strength => 'Strength',
            self::DosageForm => 'Dosage form',
            self::PackSize => 'Pack size',
            self::PackType => 'Pack type',
            self::Mrp => 'MRP',
            self::TradePrice => 'Trade price',
            self::PurchaseRate => 'Our rate',
            self::CaseSize => 'Case size',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Code => "The company's own item number",
            self::BrandName => 'The name on the box',
            self::GenericName => 'The molecule',
            self::Strength => 'e.g. 500mg, 2g/5ml',
            self::DosageForm => 'Tablet, syrup, injection …',
            self::PackSize => 'e.g. 2x10, 120ml',
            self::PackType => 'Blister, bottle, vial …',
            self::Mrp => 'What the patient pays',
            self::TradePrice => 'What the pharmacy pays',
            self::PurchaseRate => 'What this business pays the company',
            self::CaseSize => 'Packs to a carton',
        };
    }

    /** Without a brand name there is no product, so this is the one hard requirement. */
    public function isRequired(): bool
    {
        return $this === self::BrandName;
    }

    public function isMoney(): bool
    {
        return in_array($this, [self::Mrp, self::TradePrice, self::PurchaseRate], true);
    }

    public function isInteger(): bool
    {
        return $this === self::CaseSize;
    }

    /**
     * Header labels this field answers to, lowercased and stripped of
     * punctuation before comparison. Order matters only in that the longest
     * match wins, which {@see HeaderMatcher} handles.
     *
     * @return array<string>
     */
    public function synonyms(): array
    {
        return match ($this) {
            self::Code => ['code', 'item code', 'product code', 'sku', 'item no', 'item', 'art no', 'article no', 'sr code'],
            self::BrandName => ['brand', 'brand name', 'product', 'product name', 'description', 'item name', 'trade name', 'name', 'particulars', 'products'],
            self::GenericName => ['generic', 'generic name', 'molecule', 'composition', 'formula', 'salt', 'ingredients', 'active ingredient'],
            self::Strength => ['strength', 'potency', 'dose', 'dosage', 'mg', 'strength mg'],
            self::DosageForm => ['form', 'dosage form', 'type', 'presentation', 'formulation'],
            self::PackSize => ['pack size', 'packing', 'pack', 'packsize', 'pack qty', 'unit pack', 'size', 'qty per pack', 'contents'],
            self::PackType => ['pack type', 'packing type', 'container', 'pack form'],
            self::Mrp => ['mrp', 'm r p', 'retail price', 'rp', 'max retail price', 'maximum retail price', 'public price', 'mrp rs'],
            self::TradePrice => ['tp', 't p', 'trade price', 'trade', 'tradeprice', 'trade rate', 'tp rs', 'pharmacy price'],
            self::PurchaseRate => ['rate', 'rates', 'our rate', 'net rate', 'purchase rate', 'distributor price', 'dp', 'd p', 'cost', 'cost price', 'invoice rate', 'supply price', 'landed rate'],
            self::CaseSize => ['case size', 'carton size', 'case', 'carton', 'ctn size', 'master carton', 'outer', 'box size', 'pcs per carton', 'units per carton'],
        };
    }

    /** @return array<string, string> value => label, for select menus. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
