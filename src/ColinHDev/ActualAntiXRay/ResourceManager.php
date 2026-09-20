<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay;

use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use function is_array;
use function is_bool;
use function is_string;

class ResourceManager{
	use SingletonTrait;

	private bool $default;
	/** @var array<string, true> */
	private array $worlds = [];

	private bool $autoUpdateEnabled;
	private bool $autoUpdateDownload;
	private string $autoUpdateRepository;
	private bool $autoUpdatePreReleases;
	private string $autoUpdateToken;

	public function __construct(){
		ActualAntiXRay::getInstance()->saveResource("config.yml");
		$config = new Config(ActualAntiXRay::getInstance()->getDataFolder() . "config.yml", Config::YAML);
		$mode = $config->get("mode", "blacklist");
		if($mode === "blacklist"){
			$this->default = true;
		}else{
			$this->default = false;
		}
		$worlds = $config->get("worlds", []);
		if(is_array($worlds)){
			foreach($worlds as $worldName){
				if(is_string($worldName)){
					$this->worlds[$worldName] = true;
				}
			}
		}

		// Configs written before the auto-updater existed do not have this section, so every value falls back to a
		// default instead of being required.
		$autoUpdate = $config->get("auto-update", []);
		if(!is_array($autoUpdate)){
			$autoUpdate = [];
		}
		$this->autoUpdateEnabled = self::readBool($autoUpdate, "enabled", true);
		$this->autoUpdateDownload = self::readBool($autoUpdate, "download", true);
		$this->autoUpdateRepository = self::readString($autoUpdate, "repository", "ColinHDevNew/ActualAntiXRay-PM5");
		$this->autoUpdatePreReleases = self::readBool($autoUpdate, "pre-releases", false);
		$this->autoUpdateToken = self::readString($autoUpdate, "token", "");
	}

	/**
	 * @param array<mixed, mixed> $values
	 */
	private static function readBool(array $values, string $key, bool $default) : bool{
		$value = $values[$key] ?? null;
		return is_bool($value) ? $value : $default;
	}

	/**
	 * @param array<mixed, mixed> $values
	 */
	private static function readString(array $values, string $key, string $default) : string{
		$value = $values[$key] ?? null;
		return is_string($value) ? $value : $default;
	}

	public function isEnabledForWorld(string $worldName) : bool{
		return
			($this->default && !isset($this->worlds[$worldName])) ||
			(!$this->default && isset($this->worlds[$worldName]));
	}

	public function isAutoUpdateEnabled() : bool{
		return $this->autoUpdateEnabled;
	}

	public function isAutoUpdateDownloadEnabled() : bool{
		return $this->autoUpdateDownload;
	}

	public function getAutoUpdateRepository() : string{
		return $this->autoUpdateRepository;
	}

	public function allowAutoUpdatePreReleases() : bool{
		return $this->autoUpdatePreReleases;
	}

	public function getAutoUpdateToken() : string{
		return $this->autoUpdateToken;
	}
}
