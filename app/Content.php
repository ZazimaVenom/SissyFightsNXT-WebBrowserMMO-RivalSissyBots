<?php
declare(strict_types=1);

/** Stable content IDs shared by installer, API and authoritative game engine. */
final class GameContent
{
    public const AVATAR_COUNT = 32;
    public const ARENA_COUNT = 10;
    public const BOT_ROSTER_VERSION = '2';
    public const ARENA_NAMES = [
        0=>'The Original Playground',
        1=>'The Glitter Gutter',
        2=>'Dumpster Divas',
        3=>'Dial-Up Detention',
        4=>'Mall Rat Mayhem',
        5=>'Lollypop Lockdown',
        6=>'Trailer Trash Palace',
        7=>'Toilet Paper Kingdom',
        8=>'Skatepark Meltdown',
        9=>'Food Court Fiasco',
        10=>'The Petty Pool',
    ];

    /** The same permanent bot always receives the same look, across fresh installs and upgrades. */
    public static function botAvatar(int $botNumber): int
    {
        return (($botNumber - 1) * 13 + 7) % self::AVATAR_COUNT;
    }

    public static function arena(int $id): array
    {
        if ($id < 1 || $id > self::ARENA_COUNT) {
            return ['arena_id'=>0, 'arena_name'=>self::ARENA_NAMES[0], 'arena_image'=>'assets/arena.png'];
        }
        return ['arena_id'=>$id, 'arena_name'=>self::ARENA_NAMES[$id], 'arena_image'=>'assets/arenas/'.str_pad((string)$id, 2, '0', STR_PAD_LEFT).'.png'];
    }
}
