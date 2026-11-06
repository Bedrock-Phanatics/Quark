<?php

declare(strict_types=1);

namespace quark\form;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\form\element\BaseSelector;
use quark\form\element\Dropdown;
use quark\form\element\FormIcon;
use quark\form\element\Input;
use quark\form\element\Label;
use quark\form\element\MenuOption;
use quark\form\element\Slider;
use quark\form\element\StepSlider;
use quark\form\element\Toggle;
use quark\form\type\CustomForm;
use quark\form\type\CustomFormResponse;
use quark\form\type\MenuForm;
use quark\form\type\ModalForm;
use quark\player\Player;
use ReflectionClass;
use function json_decode;
use function json_encode;
use const INF;
use const JSON_THROW_ON_ERROR;
use const NAN;

final class FormsTest extends TestCase{
	private function player() : Player{
		return new ReflectionClass(FormsTestPlayer::class)->newInstanceWithoutConstructor();
	}

	public function testSliderConstructionAndSerialization() : void{
		$slider = new Slider("volume", "Volume", -5, 10, 0.5, 2.5);
		self::assertSame(-5.0, $slider->getMin());
		self::assertSame(10.0, $slider->getMax());
		self::assertSame(0.5, $slider->getStep());
		self::assertSame(2.5, $slider->getDefault());
		self::assertSame([
			"min" => -5.0, "max" => 10.0, "default" => 2.5, "step" => 0.5,
			"type" => "slider", "text" => "Volume"
		], $slider->jsonSerialize());
		self::assertSame(-5.0, (new Slider("volume", "Volume", -5, 10))->getDefault());
		self::assertSame(1.0, (new Slider("fixed", "Fixed", 1, 1))->getDefault());
		foreach([-5, 0, 2.5, 10] as $value){
			$slider->validateValue($value);
		}
	}

	/** @return iterable<string, array{float, float, float, ?float}> */
	public static function invalidSliderConfigurations() : iterable{
		yield "reversed range" => [10, 0, 1, null];
		yield "zero step" => [0, 10, 0, null];
		yield "negative step" => [0, 10, -1, null];
		yield "low default" => [0, 10, 1, -1];
		yield "high default" => [0, 10, 1, 11];
		foreach([INF, -INF, NAN] as $index => $value){
			yield "min $index" => [$value, 10, 1, null];
			yield "max $index" => [0, $value, 1, null];
			yield "step $index" => [0, 10, $value, null];
			yield "default $index" => [0, 10, 1, $value];
		}
	}

	#[DataProvider("invalidSliderConfigurations")]
	public function testInvalidSliderConfiguration(float $min, float $max, float $step, ?float $default) : void{
		$this->expectException(InvalidArgumentException::class);
		new Slider("value", "Value", $min, $max, $step, $default);
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidSliderValues() : iterable{
		foreach([-1, 11, INF, -INF, NAN, "5", true, null, []] as $index => $value){
			yield (string) $index => [$value];
		}
	}

	#[DataProvider("invalidSliderValues")]
	public function testInvalidSliderResponse(mixed $value) : void{
		$slider = new Slider("value", "Value", 0, 10);
		$this->expectException(FormValidationException::class);
		$slider->validateValue($value);
	}

	public function testCustomFormResponseRoundTrip() : void{
		$player = $this->player();
		$response = null;
		$form = new CustomForm("Settings", [
			"label" => new Label("help", "Help"),
			"input" => new Input("name", "Name", "Hint", "Guest"),
			"toggle" => new Toggle("enabled", "Enabled", true),
			"dropdown" => new Dropdown("mode", "Mode", [3 => "First", 9 => "Second"], 1),
			"slider" => new Slider("volume", "Volume", 0, 10),
			"steps" => new StepSlider("speed", "Speed", ["Slow", "Fast"])
		], static function(Player $sender, CustomFormResponse $data) use ($player, &$response) : void{
			self::assertSame($player, $sender);
			$response = $data;
		});
		$serialized = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($serialized);
		self::assertSame("custom_form", $serialized["type"]);
		self::assertSame("Settings", $serialized["title"]);
		self::assertIsArray($serialized["content"]);
		self::assertCount(6, $serialized["content"]);
		self::assertIsArray($serialized["content"][3]);
		self::assertSame(["First", "Second"], $serialized["content"][3]["options"]);
		self::assertSame($form->getElement(1), $form->getElementByName("name"));
		$form->handleResponse($player, [null, "Alice", false, 1, 5, 0]);
		self::assertInstanceOf(CustomFormResponse::class, $response);
		self::assertSame("Alice", $response->getString("name"));
		self::assertFalse($response->getBool("enabled"));
		self::assertSame(1, $response->getInt("mode"));
		self::assertSame(5.0, $response->getFloat("volume"));
		self::assertNull($response->getValue("help"));
		self::assertSame(["help" => null, "name" => "Alice", "enabled" => false, "mode" => 1, "volume" => 5, "speed" => 0], $response->getAll());
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidCustomResponses() : iterable{
		yield "wrong type" => [false];
		yield "too short" => [[null]];
		yield "too long" => [[null, true, "extra"]];
		yield "sparse" => [[0 => null, 2 => true]];
		yield "reordered" => [[1 => true, 0 => null]];
		yield "named" => [["label" => null, "toggle" => true]];
		yield "invalid label" => [["hostile", true]];
		yield "invalid toggle" => [[null, 1]];
	}

	#[DataProvider("invalidCustomResponses")]
	public function testInvalidCustomResponseDoesNotRunCallback(mixed $data) : void{
		$form = new CustomForm("Settings", [new Label("label", "Label"), new Toggle("toggle", "Toggle")], static function(Player $player, CustomFormResponse $response) : void{
			self::fail("Invalid data reached the callback");
		});
		$this->expectException(FormValidationException::class);
		$form->handleResponse($this->player(), $data);
	}

	public function testDuplicateElementNamesAreRejected() : void{
		$this->expectException(InvalidArgumentException::class);
		new CustomForm("Settings", [new Input("same", "First"), new Toggle("same", "Second")], static function(Player $player, CustomFormResponse $response) : void{});
	}

	public function testEmptyCustomFormAndCloseCallback() : void{
		$submits = 0;
		$closes = 0;
		$form = new CustomForm("Empty", [], static function(Player $player, CustomFormResponse $response) use (&$submits) : void{
			self::assertSame([], $response->getAll());
			++$submits;
		}, static function(Player $player) use (&$closes) : void{ ++$closes; });
		$player = $this->player();
		$form->handleResponse($player, []);
		$form->handleResponse($player, null);
		self::assertSame(1, $submits);
		self::assertSame(1, $closes);
	}

	public function testModalChoicesAndCancellationAreDistinct() : void{
		$choices = [];
		$closes = 0;
		$form = new ModalForm("Confirm", "Continue?", static function(Player $player, bool $choice) use (&$choices) : void{ $choices[] = $choice; }, onClose: static function(Player $player) use (&$closes) : void{ ++$closes; });
		$player = $this->player();
		$form->handleResponse($player, true);
		$form->handleResponse($player, false);
		$form->handleResponse($player, null);
		self::assertSame([true, false], $choices);
		self::assertSame(1, $closes);
		(new ModalForm("Confirm", "Continue?", static function(Player $player, bool $choice) : void{ self::fail("Cancellation submitted a choice"); }))->handleResponse($player, null);
	}

	public function testMenuSerializationAndCallbacks() : void{
		$selected = null;
		$closes = 0;
		$option = new MenuOption("Play", new FormIcon("textures/items/compass", FormIcon::IMAGE_TYPE_PATH));
		$form = new MenuForm("Menu", "Choose", [5 => $option], static function(Player $player, int $index) use (&$selected) : void{ $selected = $index; }, static function(Player $player) use (&$closes) : void{ ++$closes; });
		self::assertSame($option, $form->getOption(0));
		self::assertNull($form->getOption(1));
		self::assertSame([
			"content" => "Choose", "buttons" => [["text" => "Play", "image" => ["type" => "path", "data" => "textures/items/compass"]]], "type" => "form", "title" => "Menu"
		], json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
		$player = $this->player();
		$form->handleResponse($player, 0);
		$form->handleResponse($player, null);
		self::assertSame(0, $selected);
		self::assertSame(1, $closes);
	}

	/** @return iterable<string, array{Form, mixed}> */
	public static function invalidSimpleResponses() : iterable{
		$menu = new MenuForm("Menu", "Choose", [new MenuOption("Play")], static function(Player $player, int $index) : void{ self::fail("Invalid menu selection submitted"); });
		$modal = new ModalForm("Confirm", "Continue?", static function(Player $player, bool $choice) : void{ self::fail("Invalid modal choice submitted"); });
		foreach([-1, 1, "0", 0.0, true, []] as $index => $value){
			yield "menu $index" => [$menu, $value];
		}
		foreach([0, 1, "true", []] as $index => $value){
			yield "modal $index" => [$modal, $value];
		}
	}

	#[DataProvider("invalidSimpleResponses")]
	public function testInvalidSimpleResponse(Form $form, mixed $data) : void{
		$this->expectException(FormValidationException::class);
		$form->handleResponse($this->player(), $data);
	}

	/** @return iterable<string, array{class-string<BaseSelector>, array<mixed>, int}> */
	public static function invalidSelectorConfigurations() : iterable{
		foreach([Dropdown::class, StepSlider::class] as $class){
			yield "$class empty" => [$class, [], 0];
			yield "$class negative" => [$class, ["First"], -1];
			yield "$class overflow" => [$class, ["First"], 1];
			yield "$class invalid option" => [$class, ["First", false], 0];
		}
	}

	/** @param class-string<BaseSelector> $class
	 * @param array<mixed> $options
	 */
	#[DataProvider("invalidSelectorConfigurations")]
	public function testInvalidSelectorConfiguration(string $class, array $options, int $default) : void{
		$this->expectException($options === ["First", false] ? \TypeError::class : InvalidArgumentException::class);
		if($class === Dropdown::class){
			// @phpstan-ignore argument.type (Test invalid option types)
			new Dropdown("choice", "Choice", $options, $default);
		}else{
			// @phpstan-ignore argument.type (Test invalid option types)
			new StepSlider("choice", "Choice", $options, $default);
		}
	}

	public function testInvalidIconType() : void{
		$this->expectException(InvalidArgumentException::class);
		new FormIcon("icon.png", "invalid");
	}

	/** @return iterable<string, array{string, mixed}> */
	public static function invalidTypedAccessors() : iterable{
		yield "int" => ["getInt", "1"];
		yield "string" => ["getString", 1];
		yield "float" => ["getFloat", "1.5"];
		yield "bool" => ["getBool", 1];
		yield "null" => ["getInt", null];
	}

	#[DataProvider("invalidTypedAccessors")]
	public function testTypedAccessorRejectsWrongType(string $method, mixed $value) : void{
		$response = new CustomFormResponse(["value" => $value]);
		$this->expectException(InvalidArgumentException::class);
		match($method){
			"getInt" => $response->getInt("value"),
			"getString" => $response->getString("value"),
			"getFloat" => $response->getFloat("value"),
			"getBool" => $response->getBool("value"),
			default => self::fail("Unknown accessor $method")
		};
	}

	public function testMissingResponseValue() : void{
		$this->expectException(InvalidArgumentException::class);
		(new CustomFormResponse([]))->getValue("missing");
	}
}

final class FormsTestPlayer extends Player{
	public function __destruct(){}
}
