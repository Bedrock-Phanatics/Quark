# Scoreboard API

Each player owns one sidebar. Use it on the server thread after the player spawns.

```php
$board = $player->getScoreboard();
$board->update(title: "Arena", lines: [
    "kills" => "Kills: 0",
    "coins" => "Coins: 100",
    "",
    "example.com"
]);

$board->update(["kills" => "Kills: $kills"]);
$board->update(title: "Final round");
```

Layout order determines placement. Named updates keep other rows unchanged and send only changed text. Names must already exist in the layout.

```php
$board->setLine("coins", "Coins: $coins");
$text = $board->getLine("coins");
$board->removeLine("coins");
$board->update(["coins" => "Coins: $coins"]); // Restore the original position.

$board->hide();
$board->show();
$board->clear(); // Remove rows and names, keeping the title.

$board->setLines(["status" => "Waiting for players..."]);
```

`setLines()` replaces the layout and its names. Hidden boards retain content; updates stay hidden until `show()`.

Limits: 15 rows, 256-byte titles, 1024-byte row text, and nonempty names up to 64 bytes. Text must be single-line UTF-8. Blank and duplicate rows are supported.

Invalid input throws `InvalidArgumentException`. Rejected sends throw `RuntimeException`; call `update()` to retry.
