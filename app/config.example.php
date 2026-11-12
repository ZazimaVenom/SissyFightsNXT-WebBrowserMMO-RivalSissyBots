<?php
// The local installer creates app/config.php. Never publish database credentials.
return [
    'db_host' => '127.0.0.1', 'db_port' => 3306,
    'db_name' => 'sissy_fights_nxt', 'db_user' => 'root', 'db_pass' => '',
    'app_secret' => 'generated-by-installer',
    'turn_seconds' => 30, 'bot_turn_seconds' => 10, 'lobby_seconds' => 35,
    'max_rooms' => 180, 'bot_count' => 1000, 'tick_interval' => 2,
    'bot_chat_chance' => 0.12, 'max_turns' => 60,
];
