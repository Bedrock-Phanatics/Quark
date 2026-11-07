# Commands

Declare a command's arguments and the server parses and permission-checks them, then calls your handler. Clients get the same definitions as autocomplete.

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

## Subcommands

For small commands, declare subcommands with `syntax()` and handle them in `run()`. Words separated by spaces nest.

```php
public function __construct(){
    parent::__construct("warp", "Manage warps");
    $this->setPermission("warps.use");
    $this->syntax("set")->permission("warps.manage")->registerArgument(new StringArgument("name"));
    $this->syntax("admin clear")->permission("warps.admin");
}

protected function run(CommandSender $sender, CommandArgs $args) : void{
    match($args->subcommand()){
        "set" => $this->set($sender, $args->string("name")),
        "admin clear" => $this->clear(),
        default => $this->list($sender), // /warp
    };
}
```

For bigger commands, give each subcommand its own class. A command with only subcommand classes doesn't need `run()`.

```php
final class WarpSetCommand extends Subcommand{
    public function __construct(private Warps $warps){
        parent::__construct("set");
        $this->setPermission("warps.manage");
        $this->registerArgument(new StringArgument("name"));
    }

    protected function run(CommandSender $sender, CommandArgs $args) : void{
        $this->warps->set($sender, $args->string("name"));
    }
}

// Inside the command's constructor
$this->registerSubcommand(new WarpSetCommand($warps));
```

- Subcommand words are lowercase, `[a-z0-9_-]+`. The longest match wins, even if a shorter syntax could take the word as a value.
- Each subcommand has one syntax. Use optional arguments for different lengths.
- Required arguments come first, and a greedy string can only be last.
- A denied permission or an invalid value ends the command.

## Arguments

| Argument | Accepts | Autocomplete |
|---|---|---|
| `StringArgument` | one word, or all remaining words joined by single spaces with `greedy: true` | string, or raw text when greedy |
| `IntegerArgument` | a decimal integer within `min` and `max` | int |
| `FloatArgument` | a finite decimal number within `min` and `max` | float |
| `BooleanArgument` | `true` or `false` | enum |
| `EnumArgument` | one of the declared lowercase values | enum |
| `PlayerArgument` | the exact name of an online player, case-insensitive | player names, or string with `suggest: false` |

The client's int is 32 bits, so it may refuse larger values even though the server accepts them. Selectors such as `@p` are rejected.

Read values with `$args->string()`, `int()`, `float()`, `bool()` or `player()`, or `get()` for custom arguments. The second parameter is the value to use when an optional argument is missing, and `has()` tells whether it was given. A missing value without a default or a value of another type throws `LogicException`.

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
}
```

`parse()` must not have side effects or return `null`. Throw `ArgumentParseException` for bad input; other exceptions propagate as bugs.

Autocomplete shows a plain word unless you override `getKind()`, and `getEnumValues()` for `ArgumentKind::ENUM`. The client refuses anything the kind doesn't allow, so it must cover everything `parse()` accepts.

## Permissions

The sender needs one of the command's permissions, and a subcommand's permission as well. Both are checked on every execution. Subcommands the sender can't use are left out of usage replies and autocomplete.

## Server aliases

Aliases in the `[aliases]` section of `config/server.toml` run their targets with each target's own permission checks. An alias is hidden from senders who can't use anything it reaches, and shows one raw text parameter in autocomplete.

## Registration and plugins

Declare everything in the constructor. `register()` throws on an invalid definition and leaves the map unchanged, and changing a definition while its command is registered throws.

Declarative commands that implement `PluginOwned` are unregistered when their plugin is disabled. Create and register them again from `onEnable()`. Other commands stay registered, and a declarative command that doesn't implement `PluginOwned` keeps running after its plugin is disabled.

## Legacy commands

Commands that override `execute()` keep working and show one raw text parameter in autocomplete. A command can't both override `execute()` and declare syntaxes or `run()`.

Calling `execute()` directly skips the permission checks. Use `Server::dispatchCommand()` or `SimpleCommandMap::dispatchParsed()`.

## Limitations

- Subcommand words can't come after arguments.
- `run()`, `syntax()`, `getSyntaxes()`, `registerArgument()`, `registerArguments()`, `registerSubcommand()`, `getSubcommands()`, `setPreparedSyntaxes()`, `getPreparedSyntaxes()` and `canReachPermittedSyntax()` conflict with existing subclasses that declare incompatible methods with those names. PHP method names are case-insensitive, so this includes `registerSubCommand()`.
- `execute()` is no longer abstract, so a subclass can't redeclare it as abstract.
