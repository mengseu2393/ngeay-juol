<?php

namespace App\Services;

/**
 * Room-number/price grid for bulk room creation — used by UnitResource's
 * "Generate rooms" action and the landlord setup wizard (LandlordSetup).
 */
class RoomGridService
{
    /**
     * Build the flat, editable room grid from the floor rows. Each entry carries its
     * computed room number, floor, the floor default (for the "differs" marker) and a
     * price. Merge semantics against $current (the existing grid): a room the user
     * manually overrode (price != its old floor default) keeps its price; every other
     * room takes the floor's current default — so changing a default and refreshing
     * propagates to untouched rooms without discarding deliberate overrides.
     *
     * @return list<array{number:string,floor:string,floor_default:float|null,price:float}>
     */
    public static function build(array $data, array $current = []): array
    {
        $prefix = $data['prefix'] ?? '';
        $numbering = $data['numbering'] ?? 'floor_seq';

        $prev = [];
        foreach ($current as $row) {
            if (! empty($row['number'])) {
                $prev[(string) $row['number']] = [
                    'price' => ($row['price'] === '' || ($row['price'] ?? null) === null) ? null : (float) $row['price'],
                    'default' => ($row['floor_default'] === '' || ($row['floor_default'] ?? null) === null) ? null : (float) $row['floor_default'],
                ];
            }
        }

        $grid = [];
        $seq = 0;
        foreach ($data['floors'] ?? [] as $floorRow) {
            $floor = (int) ($floorRow['floor'] ?? 0);
            $count = (int) ($floorRow['rooms'] ?? 0);
            $default = ($floorRow['default_price'] === '' || ($floorRow['default_price'] ?? null) === null)
                ? null
                : (float) $floorRow['default_price'];

            for ($i = 1; $i <= $count; $i++) {
                $seq++;
                $number = $numbering === 'floor_seq'
                    ? sprintf('%s%d%02d', $prefix, $floor, $i)
                    : sprintf('%s%d', $prefix, $seq);

                $price = $default ?? 0.0;
                if (isset($prev[$number]) && $prev[$number]['price'] !== null) {
                    $oldDefault = $prev[$number]['default'] ?? 0.0;
                    if ((float) $prev[$number]['price'] !== (float) $oldDefault) {
                        $price = (float) $prev[$number]['price']; // deliberate override → keep
                    }
                }

                $grid[] = [
                    'number' => $number,
                    'floor' => (string) $floor,
                    'floor_default' => $default,
                    'price' => $price,
                ];
            }
        }

        return $grid;
    }
}
