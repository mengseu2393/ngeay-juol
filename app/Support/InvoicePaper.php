<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Paper-size catalogue for invoice documents.
 *
 * Knows the four printable sizes RentWise supports — two ISO pages (A4, A5) and
 * two thermal receipt widths (80 mm, 65 mm) — plus how each one maps onto a
 * dompdf paper spec. Thermal sizes have no fixed height: the page grows with the
 * number of line items and payments so a long receipt is never clipped.
 */
class InvoicePaper
{
    /** Points per millimetre (1mm = 2.83465pt). */
    private const PT_PER_MM = 2.83465;

    /** Every size we can render, in menu order. */
    public const SIZES = ['a4', 'a5', '80mm', '65mm'];

    /** Page width in millimetres, keyed by size. */
    private const WIDTH_MM = [
        'a4' => 210.0,
        'a5' => 148.0,
        '80mm' => 80.0,
        '65mm' => 65.0,
    ];

    /** Is this one of the narrow thermal receipt widths? */
    public static function isThermal(string $size): bool
    {
        return $size === '80mm' || $size === '65mm';
    }

    /** Page width in millimetres for the given size (0 for unknown sizes). */
    public static function widthMm(string $size): float
    {
        return self::WIDTH_MM[$size] ?? 0.0;
    }

    /** Human label for a size (translatable). */
    public static function label(string $size): string
    {
        return match ($size) {
            'a4' => __('A4'),
            'a5' => __('A5'),
            '80mm' => __('80 mm (receipt)'),
            '65mm' => __('65 mm (receipt)'),
            default => $size,
        };
    }

    /** [size => label] map for selects and action menus. */
    public static function options(): array
    {
        $options = [];

        foreach (self::SIZES as $size) {
            $options[$size] = self::label($size);
        }

        return $options;
    }

    /**
     * dompdf paper spec for a size: a named page for A4/A5, or an explicit
     * [x0, y0, x1, y1] point box for thermal widths. The thermal height is
     * estimated from the invoice's line + payment count so the receipt is tall
     * enough to hold everything (clamped to a sensible minimum).
     */
    public static function dompdfPaper(string $size, ?Invoice $invoice = null): string|array
    {
        if (! self::isThermal($size)) {
            return $size === 'a5' ? 'a5' : 'a4';
        }

        $rows = count($invoice?->lines ?? []) + count($invoice?->payments ?? []);
        $heightPt = max(400, 360 + $rows * 18);
        $widthPt = round(self::widthMm($size) * self::PT_PER_MM, 2);

        return [0, 0, $widthPt, $heightPt];
    }
}
