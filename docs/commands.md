# Commands

Declare a command's syntaxes and the server parses, permission-checks and dispatches them, then calls your handler. Clients get the same definitions as typed autocomplete.

## Simple command

```php
use quark\command\argument\IntegerArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;

final class PayCommand extends Command{
    public function __construct(private Economy $economy){
        parent::__construct("pay", "Pay another player");
        $this->setPermission("economy.pay");
        $this->registerArguments([
            new PlayerArgument("target"),
            new IntegerArgument("amount", min: 1),
            new StringArgument("note", optional: true, greedy: true),
        ]);
    }

    protected function run(CommandSender $sender, CommandArgs $args) : void{
        $this->economy->pay($sender, $args->player("target"), $args->int("amount"), $args->string("note", ""));
    }
}

// Inside PluginBase::onEnable()
$this->getServer()->getCommandMap()->register("economy", new PayCommand($this->economy));
```

`/pay Steve abc` replies with the invalid value and `Usage: /pay <target> <amount> [note...]` without calling `run()`.

## Syntaxes and handlers

A command can declare several syntaxes (overloads). `syntax("a b")` starts one with leading words, `literal()` adds a fixed word further in, and `handler()` gives it its own function. Handlers take the sender followed by one typed parameter per argument, literals skipped, or the sender and a `CommandArgs`.

```php
public function __construct(){
    parent::__construct("effect", "Manage effects");
    $this->setPermission("effects.use");

    $this->syntax()
        ->registerArgument(new PlayerArgument("target"))
        ->literal("clear")
        ->handler($this->clear(...));

    $this->syntax()
        ->registerArguments([
            new PlayerArgument("target"),
            new EnumArgument("effect", EffectType::class),
            new IntegerArgument("seconds", min: 1, optional: true),
        ])
        ->handler($this->give(...));

    $this->syntax("admin purge")->permission("effects.admin")->handler(fn(CommandSender $sender) => $this->purge());
}

private function clear(CommandSender $sender, Player $target) : void{ /* ... */ }

private function give(CommandSender $sender, Player $target, EffectType $effect, ?int $seconds = null) : void{ /* ... */ }
```

- Handler signatures are checked when the command is registered: parameter count, types and defaults for optional arguments. Typing the sender as `Player` makes the syntax player-only, and the console gets a reply instead of a call.
- Syntaxes can share leading words as long as no input could match two of them. `register()` throws on ambiguous definitions, judged by what each argument can accept: numbers, words, fixed enum values and relative values are told apart, free text and players overlap with any word.
- Leading words and literals match in any case and can't match a quoted token containing spaces. The longest declared leading words win, even if a shorter syntax could take the word as a value.
- Required arguments come first, and a greedy string can only be last. `run()` handles syntaxes that have no handler, `CommandArgs::syntax()` tells overloads apart.
- Resolution never calls a handler. Overloads are chosen from cheap shape checks, then the arguments are parsed once, and only a fully valid syntax runs.
- A denied permission or an invalid value ends the command. Errors name the argument that failed and list only the syntaxes the sender may use.

## Arguments

| Argument | Accepts | Autocomplete |
|---|---|---|
| `StringArgument` | one word, or with `greedy: true` the rest of the line exactly as typed, with quotes, escapes and spacing | string, or raw text |
| `IntegerArgument` | a decimal integer within `min` and `max` | int |
| `FloatArgument` | a finite decimal number within `min` and `max` | float |
| `BooleanArgument` | `true` or `false` | enum |
| `EnumArgument` | a word of a `CommandEnum`, a list of words or a native PHP enum class, returning the mapped value | enum |
| `SoftEnumArgument` | a word of a `SoftEnum`, returned as stored | soft enum |
| `PlayerArgument` | an online player, optionally by name prefix with `partial: true` or `@s` with `self: true` | target |
| `PositionArgument` | three tokens `x y z`, each absolute or relative like `~` or `~-2.5` | position |
| `RotationArgument` | two tokens `yaw pitch`, absolute or relative | two values |
| `RelativeFloatArgument` | one absolute or relative number | value |
| `DurationArgument` | a number with a unit `ms`, `s`, `m`, `h` or `d`, returned in seconds | string |
| `MappedArgument` | one word turned into an object by your closure, for lookups that are not a fixed set | string, or enum with suggestions |
| `LiteralArgument` | a fixed word, what `literal()` adds | enum |

The client's int is 32 bits, so it may refuse larger values even though the server accepts them. Selectors such as `@p` are rejected. Relative values resolve with `RelativePosition::resolve($base)` or `RelativeFloat::resolve($base)`, validate the result before using it.

Read values with `$args->string()`, `int()`, `float()`, `bool()` or `player()`, or `get()` for others. The second parameter is the value to use when an optional argument is missing, and `has()` tells whether it was given. A missing value without a default or a value of another type throws `LogicException`.

Numbers are parsed strictly: overflowing integers, `nan`, `inf`, hex and exponents past the float range are rejected.

## Enums

```php
enum Gamemode : string{ case Survival = "survival"; case Creative = "creative"; }

$modes = CommandEnum::fromCases(Gamemode::class);                  // native enum, values lowercased
$sizes = CommandEnum::fromMap("Size", ["small" => 1, "big" => 100]); // words mapped to anything but null
$flags = CommandEnum::fromValues("Flag", ["on", "off"]);

new EnumArgument("mode", $modes);   // parses to Gamemode::Creative
new EnumArgument("mode", Gamemode::class); // shortcut for a native enum
```

Share one `CommandEnum` between commands and clients see one enum. Distinct enums that share a name are told apart automatically.

Soft enums are dynamic lists. Changes reach clients as small update packets, not a new command list:

```php
$this->warps = new SoftEnum("warps", ["spawn"]);
$this->syntax("go")->registerArgument(new SoftEnumArgument("name", $this->warps))->handler($this->go(...));

$this->warps->add("home");
$this->warps->remove("spawn");
$this->warps->set($names);
```

Values are words without spaces, up to 128 characters, at most 4096 per enum. Changes in a tick are merged and sent once, and only to players who were given the enum. An enum is tracked while a registered command uses it, so unregistering the command or disabling its plugin stops all updates. One name belongs to one `SoftEnum` object in a command map.

## Custom arguments

```php
/**
 * @phpstan-extends Argument<World>
 */
final class WorldArgument extends Argument{
    public function parse(string $input, CommandSender $sender) : World{
        return $sender->getServer()->getWorldManager()->getWorldByName($input) ??
            throw new ArgumentParseException("Unknown world \"$input\"");
    }

    public function getValueType() : string{
        return World::class;
    }
}
```

`parse()` must not have side effects or return `null`. Throw `ArgumentParseException` for bad input; other exceptions propagate as bugs. `getValueType()` lets handler signatures be checked. When several syntaxes share a position, also override `matches()` with a cheap check that accepts everything `parse()` does, and `allowsNumbers()` if numbers are never valid there. A wider argument overrides `getWidth()` and `parseTokens()`.

Autocomplete shows a plain word unless you override `getKind()` and return an enum from `getEnum()` or `getSoftEnum()`. The client refuses anything the kind doesn't allow, so it must cover everything `parse()` accepts.

## Permissions

The sender needs one of the command's permissions, and a syntax's own permission as well, checked before its arguments are parsed. Syntaxes the sender can't use are left out of usage replies and autocomplete, and a permission change refreshes the command list once per tick.

## Server aliases

Aliases in the `[aliases]` section of `config/server.toml` run their targets with each target's own permission checks. An alias is hidden from senders who can't use anything it reaches, and shows one raw text parameter in autocomplete. Greedy arguments of an alias target get the expanded tokens joined by spaces.

## Registration and plugins

Declare everything in the constructor. `register()` throws on an invalid definition and leaves the map unchanged, and changing a definition while its command is registered throws.

Declarative commands that implement `PluginOwned` are unregistered when their plugin is disabled, along with their soft enum tracking. Create and register them again from `onEnable()`. Other commands stay registered, and a declarative command that doesn't implement `PluginOwned` keeps running after its plugin is disabled.

## Legacy commands

Commands that override `execute()` keep working and show one raw text parameter in autocomplete. A command can't both override `execute()` and declare syntaxes or `run()`. Built-in commands no longer use this.

Calling `execute()` directly skips the permission checks. Use `Server::dispatchCommand()` or `SimpleCommandMap::dispatchParsed()`.

## Limitations

- A syntax whose leading words are a prefix of another's can't take those words as values, the longer one wins.
- `run()`, `syntax()`, `getSyntaxes()`, `registerArgument()`, `registerArguments()`, `getSoftEnums()`, `dispatchLine()`, `setPrepared()`, `getPreparedSyntaxes()` and `canReachPermittedSyntax()` conflict with existing subclasses that declare incompatible methods with those names.
- `execute()` is no longer abstract, so a subclass can't redeclare it as abstract.
- Clients only know int and float number types, so they don't validate `~` forms of numbers the way the server does.
