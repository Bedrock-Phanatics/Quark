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

namespace quark\form\type;

use quark\form\element\FormIcon;
use quark\player\Player;

/**
 * Serializes custom form data with an optional server-settings icon.
 * {@link Player::sendForm()} displays a normal custom form, the core does not register settings-menu forms.
 */
class ServerSettingsForm extends CustomForm{
	private ?FormIcon $icon;

	public function __construct(string $title, array $elements, ?FormIcon $icon, \Closure $onSubmit, ?\Closure $onClose = null){
		parent::__construct($title, $elements, $onSubmit, $onClose);
		$this->icon = $icon;
	}

	public function hasIcon() : bool{
		return $this->icon !== null;
	}

	public function getIcon() : ?FormIcon{
		return $this->icon;
	}

	protected function serializeFormData() : array{
		$data = parent::serializeFormData();

		if($this->hasIcon()){
			$data["icon"] = $this->icon;
		}

		return $data;
	}
}
