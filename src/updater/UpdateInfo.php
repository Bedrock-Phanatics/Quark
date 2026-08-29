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

namespace quark\updater;

/**
 * Model class for JsonMapper to represent the information returned from the updater API.
 * @link https://github.com/Bedrock-Phanatics/Quark/releases
 */
final class UpdateInfo{
	/** @required */
	public string $php_version;
	/** @required */
	public string $base_version;
	/** @required */
	public bool $is_dev;
	/** @required */
	public string $channel;
	/** @required */
	public string $git_commit;
	/** @required */
	public string $mcpe_version;
	/** @required */
	public int $build;
	/** @required */
	public int $date;
	/** @required */
	public string $details_url;
	/** @required */
	public string $download_url;
	/** @required */
	public string $source_url;
}
