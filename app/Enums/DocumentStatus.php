<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Posted => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        };
    }
}
