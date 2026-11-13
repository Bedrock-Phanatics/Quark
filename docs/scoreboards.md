# Scoreboard API

Each player has one lazily created sidebar in `quark\scoreboard`. Update it after the player spawns, for example in `PlayerJoinEvent`:

```php
$board = $player->getScoreboard();
$board->update(title: "§lArena", lines: [
    "§7" . $player->getName(),
    "",
    "Kills: 0",
    "Coins: 100",
    "",
    "§equark.example"
]);
```

Lines are a PHP list in top-to-bottom order. Row indexes are zero-based, from `0` to `14`. Empty and duplicate text are supported without changing the text returned by the API. The title defaults to `Scoreboard` if you call `setLines()` or `show()` first.

For routine updates, pass the lines you want displayed. You do not need to track row numbers or repeat the title; Quark compares the new lines with the previous ones and sends only changes:

```php
$board->update(lines: [
    "§7" . $player->getName(),
    "",
    "Kills: " . $kills,
    "Coins: " . $coins,
    "",
    "§equark.example"
]);

$board->update(title: "§lFinal round");
```

Omitted or `null` arguments keep their current content. `lines: []` clears the rows; `title: ""` sets an empty title. Calling `update()` without arguments retries pending changes without showing a hidden board. The existing positional `update($title, $lines)` call still works.

For a single fixed row, the lower-level methods are also available:

```php
$board->setLine(2, "Kills: " . $kills);
$board->setTitle("§lFinal round");
$board->removeLine(3);
$board->clear(); // Remove all rows, keeping the title.
```

`setLine()` changes one fixed row; `removeLine()` leaves other indexes unchanged. `setLines()` replaces every row with a new list. `setTitle()` and `setLines()` are shorthand for the corresponding named `update()` argument. `getLines()` returns the current row-index-to-text map, which can have gaps after removal; `getLine()` returns `null` for an absent row.

For a lobby, match timer, personal statistics, or leaderboard, render the current state into a list and call `update(lines: ...)` from your existing task or events. To give several players the same board, pass the same title and lines to each player's controller; personalized boards use the same API. Controllers belong to individual players and must not be shared.

```php
$board->hide();
$board->update(title: "Next match", lines: ["Waiting for players..."]);
$board->show();
```

Hiding retains the title and rows. Changes while hidden send no score packets; `show()` restores the latest content. The first update displays the board automatically. Repeating an accepted update or `show()` sends nothing.

Line updates compare against the last accepted state. They remove renamed/deleted rows before adding replacements, together in one score packet. Unchanged rows are not resent. Title changes recreate the objective and resend its rows because Bedrock requires it. There is no polling task, global player cache, immediate network flush, or additional dependency.

Titles are limited to 256 UTF-8 bytes; each line is limited to 1024 UTF-8 bytes. Newlines, carriage returns, NUL bytes, invalid UTF-8, non-string rows, non-list bulk input, and more than 15 rows throw `InvalidArgumentException` before changing content. Single-line formatting codes are allowed. The API handles sidebar text, not player-list or below-name entity scores; the displayed row scores determine ordering.

Calls queue packets immediately through the existing session. A disconnected or unspawned player, closed controller, or mutation from inside a scoreboard send hook throws `LogicException`. A rejected packet throws `RuntimeException`. Requested content remains available through the getters, and accepted packets are tracked individually: repeat the update or call `update()` to retry. Queued state is not an acknowledgement of what the client renders. Plugin send hooks that replace scoreboard packets take responsibility for the resulting client state.

Disconnect cleanup closes the controller and releases its content. Holding a controller does not keep its player alive. All mutations belong on the server thread. The API owns objective `quark:sidebar` and scoreboard IDs `-2` through `-16`, avoiding Bedrock's invalid ID `-1` ([identity definition](https://github.com/EndstoneMC/endstone/blob/main/src/bedrock/world/scores/scoreboard_id.h)). Raw packets and other sidebar libraries must not overwrite them or compete for the sidebar slot.

Client rendering should be checked on the Bedrock versions and resource packs your server supports. The protocol tests cover packet contents and ordering, not the client's visual behavior.

Run `php tools/benchmark-scoreboard.php` with Quark's native PHP runtime to measure 15-row unchanged, single-row, and full-row updates. It reports medians from seven rounds of 10,000 updates, packet counts, encoded bytes, and transient PHP memory against an unconditional two-packet full resend. Both paths encode real protocol packets; transport, compression, and client rendering are excluded. Replacing every row still pays validation and comparison costs, so this API does not promise lower CPU time for full replacements.
