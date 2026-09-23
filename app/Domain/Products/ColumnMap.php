<?php

namespace App\Domain\Products;

use App\Enums\ProductField;

/** Which extracted column feeds which product field. */
final class ColumnMap
{
    /** @param array<int, ProductField> $fields keyed by column index */
    public function __construct(private array $fields = []) {}

    /** @param array<int|string, string|null> $raw */
    public static function fromArray(array $raw): self
    {
        $fields = [];

        foreach ($raw as $index => $value) {
            $field = $value === null || $value === '' ? null : ProductField::tryFrom((string) $value);

            if ($field !== null) {
                $fields[(int) $index] = $field;
            }
        }

        return new self($fields);
    }

    /** @return array<int, string> */
    public function toArray(): array
    {
        return array_map(fn (ProductField $f) => $f->value, $this->fields);
    }

    public function set(int $column, ?ProductField $field): void
    {
        if ($field === null) {
            unset($this->fields[$column]);

            return;
        }

        // A field can only come from one column; assigning it elsewhere moves it.
        foreach ($this->fields as $index => $existing) {
            if ($existing === $field && $index !== $column) {
                unset($this->fields[$index]);
            }
        }

        $this->fields[$column] = $field;
    }

    public function fieldFor(int $column): ?ProductField
    {
        return $this->fields[$column] ?? null;
    }

    public function columnFor(ProductField $field): ?int
    {
        $column = array_search($field, $this->fields, true);

        return $column === false ? null : (int) $column;
    }

    public function has(ProductField $field): bool
    {
        return $this->columnFor($field) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return array<ProductField> */
    public function missingRequired(): array
    {
        return array_values(array_filter(
            ProductField::cases(),
            fn (ProductField $f) => $f->isRequired() && ! $this->has($f),
        ));
    }
}
