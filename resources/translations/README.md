# Quark-Language

These files contain translation strings used in Quark.

## Contributing translations (non-English)
Quark-specific translation changes should be submitted as a pull request. For inherited upstream strings and language work, see the [PocketMine-MP Crowdin project](https://crowdin.com/project/pocketmine).

## For maintainers
### Adding new strings
> [!CAUTION]
> Only `eng.ini` should be modified directly.
>
> Do not modify any of the other languages manually. They are managed by Crowdin, and any changes you make to them will be overwritten.

To add new strings, add them ONLY to `eng.ini`.

- Vanilla strings should use the same keys as used by [Mojang](https://raw.githubusercontent.com/Mojang/bedrock-samples/refs/heads/main/resource_pack/texts/en_US.lang). However, Quark currently uses `{%paramName}` for parameters instead of `%1$s` `%2$s` etc, so you can't copy-paste them directly. Make sure to adapt these.
- Strings specifically for Quark can have any keys you like, but they must start with `quark.`

> [!TIP]
> You don't need to worry about translating newly added strings into other languages.
> Just make sure that `eng.ini` is correct, and translators will handle other languages via Crowdin.

Once you update `eng.ini`, run `composer update-codegen` to regenerate `KnownTranslationFactory` et al.
This will generate a function that you can use to create a parameterised `Translatable` instance for the string.

The bundled workflow may synchronize `eng.ini` when a Quark Crowdin integration is configured. PMMP's Crowdin project is an upstream reference and does not automatically publish Quark-specific strings.

### Pitfalls
- If you have issues with translation files being deleted, add a language mapping in the Crowdin config. Some issues arose with Chinese due to Chinese Simplified and Chinese Traditional both mapping to `zho`, requiring a mapping to `zho-cn` for simplified.
