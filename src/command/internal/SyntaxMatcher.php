<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\command\internal;

use quark\command\argument\Argument;
use quark\command\argument\ArgumentKind;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\LiteralArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\CommandSyntax;
use quark\command\utils\CommandStringHelper;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\permission\PermissionManager;
use function array_intersect;
use function array_slice;
use function count;
use function implode;
use function ksort;
use function max;
use function preg_match;
use function rtrim;
use function strcmp;
use function strtolower;
use function substr;
use function usort;
use const SORT_STRING;

/**
 * Registers and resolves the syntaxes of declarative commands. Resolving never calls a handler, and the first
 * handler call happens only after one syntax has matched and every argument parsed.
 *
 * @internal
 */
final class SyntaxMatcher{
	private const int SHAPE_INT = 1;
	private const int SHAPE_DECIMAL = 2;
	private const int SHAPE_RELATIVE = 4;
	private const int SHAPE_WORD = 8;
	private const int SHAPE_ANY = 15;

	private function __construct(){
		//NOOP
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public static function prepare(Command $command) : ?PreparedCommand{
		$name = $command->getName();
		$overridesExecute = self::overrides($command, "execute");
		$overridesRun = self::overrides($command, "run");
		$syntaxes = $command->getSyntaxes();

		if($overridesExecute){
			if($overridesRun || count($syntaxes) !== 0){
				throw new \LogicException("Command \"$name\" cannot override execute() and declare syntaxes or override run()");
			}
			return null;
		}
		if($overridesRun){
			//run() handles the plain command unless a syntax with no leading words was declared
			$hasPlainSyntax = false;
			foreach($syntaxes as $syntax){
				$hasPlainSyntax = $hasPlainSyntax || count($syntax->getLiterals()) === 0;
			}
			if(!$hasPlainSyntax){
				$syntaxes[] = new CommandSyntax();
			}
		}elseif(count($syntaxes) === 0){
			throw new \LogicException("Command \"$name\" must override run() or execute()");
		}

		$permissionManager = PermissionManager::getInstance();
		$root = new SyntaxNode();
		$softEnums = [];
		foreach($syntaxes as $syntax){
			$permission = $syntax->getPermission();
			if($permission !== null && $permissionManager->getPermission($permission) === null){
				throw new \InvalidArgumentException("Syntax \"" . $syntax->getUsage($name) . "\" uses non-existing permission \"$permission\"");
			}
			$handler = $syntax->getHandler();
			if($handler !== null){
				$syntax->setBinding(HandlerBinding::inspect($command, $syntax, $handler));
			}elseif(!$overridesRun){
				throw new \LogicException("Syntax \"" . $syntax->getUsage($name) . "\" needs a handler, or the command must override run()");
			}else{
				$syntax->setBinding(null);
			}

			foreach($syntax->getArguments() as $argument){
				$kind = $argument->getKind();
				if(($kind === ArgumentKind::ENUM && $argument->getEnum() === null) || ($kind === ArgumentKind::SOFT_ENUM && $argument->getSoftEnum() === null)){
					throw new \LogicException("Argument \"" . $argument->getName() . "\" of command \"$name\" needs an enum");
				}
				$softEnum = $argument->getSoftEnum();
				if($softEnum !== null){
					if(($softEnums[$softEnum->getName()] ?? $softEnum) !== $softEnum){
						throw new \InvalidArgumentException("Command \"$name\" uses two different soft enums named \"" . $softEnum->getName() . "\"");
					}
					$softEnums[$softEnum->getName()] = $softEnum;
				}
			}

			$node = $root;
			foreach($syntax->getLiterals() as $literal){
				$node = $node->children[$literal] ??= new SyntaxNode();
			}
			$node->syntaxes[] = $syntax;
		}

		$all = [];
		self::finish($command, $root, $all);
		return new PreparedCommand($root, $all, $softEnums);
	}

	/**
	 * @param CommandSyntax[] $all
	 * @phpstan-param list<CommandSyntax> $all
	 */
	private static function finish(Command $command, SyntaxNode $node, array &$all) : void{
		usort($node->syntaxes, static function(CommandSyntax $a, CommandSyntax $b) : int{
			$byLiterals = $b->getLiteralCount() <=> $a->getLiteralCount();
			if($byLiterals !== 0){
				return $byLiterals;
			}
			$byTokens = $a->getMinTokens() <=> $b->getMinTokens();
			return $byTokens !== 0 ? $byTokens : strcmp($a->getUsage(""), $b->getUsage(""));
		});
		$count = count($node->syntaxes);
		for($i = 0; $i < $count; ++$i){
			for($j = $i + 1; $j < $count; ++$j){
				if(self::overlaps($node->syntaxes[$i], $node->syntaxes[$j])){
					$label = $command->getName();
					throw new \LogicException("Syntaxes \"" . $node->syntaxes[$i]->getUsage($label) . "\" and \"" . $node->syntaxes[$j]->getUsage($label) . "\" are ambiguous");
				}
			}
			$all[] = $node->syntaxes[$i];
		}
		ksort($node->children, SORT_STRING);
		foreach($node->children as $child){
			self::finish($command, $child, $all);
		}
	}

	private static function overrides(Command $command, string $method) : bool{
		return (new \ReflectionMethod($command, $method))->getDeclaringClass()->getName() !== Command::class;
	}

	/**
	 * Whether some input could be accepted by both syntaxes, judged by the token shapes each argument allows.
	 */
	private static function overlaps(CommandSyntax $a, CommandSyntax $b) : bool{
		$slotsA = self::slots($a);
		$slotsB = self::slots($b);
		$limit = max(count($slotsA), count($slotsB)) + 1;
		for($tokens = 0; $tokens <= $limit; ++$tokens){
			if(!$a->acceptsTokenCount($tokens) || !$b->acceptsTokenCount($tokens)){
				continue;
			}
			for($i = 0; $i < $tokens; ++$i){
				$slotA = $slotsA[$i] ?? $slotsA[count($slotsA) - 1];
				$slotB = $slotsB[$i] ?? $slotsB[count($slotsB) - 1];
				if(!self::slotsOverlap($slotA, $slotB)){
					continue 2;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * @return Argument[]
	 * @phpstan-return list<Argument<mixed>>
	 */
	private static function slots(CommandSyntax $syntax) : array{
		$slots = [];
		foreach($syntax->getArguments() as $argument){
			for($i = 0, $width = $argument->getWidth(); $i < $width; ++$i){
				$slots[] = $argument;
			}
		}
		return $slots;
	}

	/**
	 * @phpstan-param Argument<mixed> $a
	 * @phpstan-param Argument<mixed> $b
	 */
	private static function slotsOverlap(Argument $a, Argument $b) : bool{
		//a literal reserves its word against open input, like leading words do
		if(($a instanceof LiteralArgument && $b->getChoices() === null) || ($b instanceof LiteralArgument && $a->getChoices() === null)){
			return false;
		}
		$choicesA = $a->getChoices();
		$choicesB = $b->getChoices();
		if($choicesA !== null && $choicesB !== null){
			return count(array_intersect($choicesA, $choicesB)) !== 0;
		}
		if($choicesA !== null){
			return self::anyChoiceFits($choicesA, self::shapeOf($b));
		}
		if($choicesB !== null){
			return self::anyChoiceFits($choicesB, self::shapeOf($a));
		}
		return (self::shapeOf($a) & self::shapeOf($b)) !== 0;
	}

	/**
	 * @param string[] $choices
	 * @phpstan-param list<string> $choices
	 */
	private static function anyChoiceFits(array $choices, int $shape) : bool{
		foreach($choices as $choice){
			if((self::classify($choice) & $shape) !== 0){
				return true;
			}
		}
		return false;
	}

	private static function classify(string $token) : int{
		if(preg_match('/\A[+-]?[0-9]+\z/', $token) === 1){
			return self::SHAPE_INT | self::SHAPE_DECIMAL;
		}
		if(preg_match('/\A[+-]?(?:[0-9]*\.[0-9]+|[0-9]+\.)(?:[eE][+-]?[0-9]+)?\z/', $token) === 1){
			return self::SHAPE_DECIMAL;
		}
		return $token[0] === "~" ? self::SHAPE_RELATIVE : self::SHAPE_WORD;
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 */
	private static function shapeOf(Argument $argument) : int{
		return match($argument->getKind()){
			ArgumentKind::INTEGER => self::SHAPE_INT,
			ArgumentKind::FLOAT => self::SHAPE_INT | self::SHAPE_DECIMAL,
			ArgumentKind::POSITION, ArgumentKind::VALUE => self::SHAPE_INT | self::SHAPE_DECIMAL | self::SHAPE_RELATIVE,
			ArgumentKind::PLAYER => self::SHAPE_WORD,
			default => $argument->allowsNumbers() ? self::SHAPE_ANY : self::SHAPE_WORD | self::SHAPE_RELATIVE
		};
	}

	/**
	 * Resolves the input to one syntax and parses its arguments. Returns null after replying that the sender may
	 * not use the syntax.
	 *
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 * @param string|null $commandLine the raw line the args came from, used to hand greedy arguments their exact text
	 *
	 * @throws CommandInputException
	 */
	public static function parse(Command $command, PreparedCommand $prepared, CommandSender $sender, string $commandLabel, array $args, ?string $commandLine) : ?CommandArgs{
		$count = count($args);
		$node = $prepared->root;
		$consumed = 0;
		foreach($args as $arg){
			//clients match enum values case-insensitively, literals are sent as enums
			$next = $node->children[strtolower($arg)] ?? null;
			if($next === null){
				break;
			}
			$node = $next;
			++$consumed;
		}
		$remaining = $count - $consumed;

		$candidates = [];
		foreach($node->syntaxes as $syntax){
			if($syntax->acceptsTokenCount($remaining)){
				$candidates[] = $syntax;
			}
		}

		$checkShape = count($candidates) > 1;
		$denied = null;
		$failure = null;
		foreach($candidates as $syntax){
			if($checkShape){
				$mismatch = self::findMismatch($syntax, $args, $consumed, $remaining);
				if($mismatch !== null){
					if($syntax->testPermissionSilent($sender) && self::isCloser($mismatch, $failure)){
						$failure = $mismatch;
					}
					continue;
				}
			}
			if(!$syntax->testPermissionSilent($sender)){
				$denied ??= $syntax;
				continue;
			}
			$result = self::parseValues($syntax, $sender, $commandLabel, $args, $consumed, $remaining, $commandLine);
			if($result instanceof CommandArgs){
				return $result;
			}
			if(self::isCloser($result, $failure)){
				$failure = $result;
			}
		}

		if($denied !== null){
			$command->testPermission($sender, $denied->getPermission());
			return null;
		}
		if($failure === null || $failure->argument instanceof LiteralArgument){
			throw new CommandInputException(null, self::collectUsages($command, $node, $sender, $commandLabel));
		}

		$argument = $failure->argument;
		$message = $failure->message ?? self::explainMismatch($argument, $args, $consumed + $failure->start, $sender);
		throw new CommandInputException(
			KnownTranslationFactory::quark_command_error_invalidArgument($argument->getName(), $message),
			[$failure->syntax->getUsage($commandLabel)]
		);
	}

	/**
	 * A typed argument explains more than a missing word, and among the same kind a failure further along is closer to what was meant.
	 */
	private static function isCloser(SyntaxFailure $candidate, ?SyntaxFailure $best) : bool{
		if($best === null){
			return true;
		}
		$candidateLiteral = $candidate->argument instanceof LiteralArgument;
		if($candidateLiteral !== ($best->argument instanceof LiteralArgument)){
			return !$candidateLiteral;
		}
		return $candidate->position > $best->position;
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private static function findMismatch(CommandSyntax $syntax, array $args, int $base, int $limit) : ?SyntaxFailure{
		$position = 0;
		foreach($syntax->getArguments() as $argument){
			if($position >= $limit || $argument->getKind() === ArgumentKind::TEXT){
				break;
			}
			$width = $argument->getWidth();
			for($slot = 0; $slot < $width && $position + $slot < $limit; ++$slot){
				if(!$argument->matches($args[$base + $position + $slot], $slot)){
					return new SyntaxFailure($syntax, $argument, $position, $position + $slot, null);
				}
			}
			$position += $width;
		}
		return null;
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private static function parseValues(CommandSyntax $syntax, CommandSender $sender, string $commandLabel, array $args, int $base, int $remaining, ?string $commandLine) : CommandArgs|SyntaxFailure{
		$values = [];
		$position = 0;
		foreach($syntax->getArguments() as $argument){
			if($position >= $remaining){
				break;
			}
			$kind = $argument->getKind();
			if($kind === ArgumentKind::LITERAL){
				if(!$argument->matches($args[$base + $position])){
					return new SyntaxFailure($syntax, $argument, $position, $position, null);
				}
				++$position;
				continue;
			}
			try{
				if($kind === ArgumentKind::TEXT){
					$values[$argument->getName()] = $argument->parse(self::rawText($args, $base + $position, $commandLine), $sender);
					break;
				}
				$values[$argument->getName()] = $argument->parseTokens($args, $base + $position, $sender);
			}catch(ArgumentParseException $e){
				return new SyntaxFailure($syntax, $argument, $position, $position, $e->getUserMessage());
			}
			$position += $argument->getWidth();
		}
		return new CommandArgs($commandLabel, $syntax, $values);
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private static function rawText(array $args, int $index, ?string $commandLine) : string{
		if($commandLine !== null){
			//the command label is the first token of the line
			$offset = CommandStringHelper::tokenOffset($commandLine, $index + 1);
			if($offset !== null){
				return rtrim(substr($commandLine, $offset));
			}
		}
		return implode(" ", array_slice($args, $index));
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private static function explainMismatch(Argument $argument, array $args, int $index, CommandSender $sender) : Translatable|string{
		try{
			$argument->parseTokens($args, $index, $sender);
		}catch(ArgumentParseException $e){
			return $e->getUserMessage();
		}
		return KnownTranslationFactory::quark_command_error_invalidValue($args[$index]);
	}

	/**
	 * Whether the sender can use any syntax that the given leading tokens could still lead to. With $moreArgs the
	 * tokens are only the start of what will be typed.
	 *
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	public static function canReachPermittedSyntax(PreparedCommand $prepared, CommandSender $sender, array $args, bool $moreArgs) : bool{
		$node = $prepared->root;
		$consumed = 0;
		foreach($args as $arg){
			$next = $node->children[strtolower($arg)] ?? null;
			if($next === null){
				break;
			}
			$node = $next;
			++$consumed;
		}
		$remaining = count($args) - $consumed;
		foreach($node->syntaxes as $syntax){
			if(
				($moreArgs ? $syntax->getMaxTokens() >= $remaining : $syntax->acceptsTokenCount($remaining)) &&
				$syntax->testPermissionSilent($sender) &&
				self::findMismatch($syntax, $args, $consumed, $remaining) === null
			){
				return true;
			}
		}
		//unknown trailing arguments can still name deeper words
		return $moreArgs && $remaining === 0 && self::hasPermittedBelow($node, $sender);
	}

	private static function hasPermittedBelow(SyntaxNode $node, CommandSender $sender) : bool{
		foreach($node->children as $child){
			foreach($child->syntaxes as $syntax){
				if($syntax->testPermissionSilent($sender)){
					return true;
				}
			}
			if(self::hasPermittedBelow($child, $sender)){
				return true;
			}
		}
		return false;
	}

	/**
	 * @phpstan-return list<Translatable|string>
	 */
	private static function collectUsages(Command $command, SyntaxNode $node, CommandSender $sender, string $commandLabel) : array{
		$usages = [];
		self::appendUsages($node, $sender, $commandLabel, $usages);
		return count($usages) !== 0 ? $usages : [$command->getUsage()];
	}

	/**
	 * @param string[] $usages
	 * @phpstan-param list<string> $usages
	 */
	private static function appendUsages(SyntaxNode $node, CommandSender $sender, string $commandLabel, array &$usages) : void{
		foreach($node->syntaxes as $syntax){
			if($syntax->testPermissionSilent($sender)){
				$usages[] = $syntax->getUsage($commandLabel);
			}
		}
		foreach($node->children as $child){
			self::appendUsages($child, $sender, $commandLabel, $usages);
		}
	}
}
