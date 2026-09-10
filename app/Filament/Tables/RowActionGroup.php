<?php

namespace App\Filament\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;

/**
 * The canonical row-action wrapper: one gray ellipsis-menu ActionGroup.
 *
 * Every table in both panels wraps its row actions in a single ActionGroup
 * with the same icon/label/color (see CLAUDE.md, "Filament admin panel") —
 * this is the one place those three modifiers live, so the trigger can never
 * drift between resources, relation managers and widgets.
 *
 * The return type is still ActionGroup, so a call site that needs extra
 * modifiers on the group itself chains them onto the result as usual.
 */
class RowActionGroup
{
    /**
     * @param  array<int, Action|ActionGroup>  $actions
     */
    public static function make(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->icon('heroicon-m-ellipsis-vertical')
            ->label(null)
            ->color('gray');
    }
}
