<?php

declare(strict_types=1);

namespace ColinHDev\ActualAntiXRay\updater;

use ColinHDev\ActualAntiXRay\ActualAntiXRay;
use ColinHDev\ActualAntiXRay\ResourceManager;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginException;
use function basename;
use function copy;
use function date;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function register_shutdown_function;
use function rename;
use function rmdir;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;
use function version_compare;
use const FILE_APPEND;
use const PHP_EOL;

/**
 * Checks the GitHub releases of this plugin on startup, downloads the newest .phar into the plugin's data folder and
 * installs it when the server stops, at which point the currently running .phar deletes itself. A plugin loaded from a
 * source folder (DevTools) is turned into a .phar the same way: the new file is written next to the folder, and the
 * folder is then deleted.
 *
 * The swap is deliberately delayed until the very end of the process: as soon as the running .phar or source folder is
 * gone, no class of this plugin can be loaded from it anymore.
 */
final class Updater{

	private const STAGING_FOLDER = "updates";
	private const INSTALL_LOG = "update.log";

	/** Absolute path of the .phar this plugin runs from, or null if it runs from a source folder. */
	private ?string $pharPath;
	/** Absolute path of the source folder this plugin runs from (DevTools), or null if it runs from a .phar. */
	private ?string $folderPath;
	private string $stagingFolder;

	/** Path of a downloaded and verified .phar waiting to be installed when the server stops. */
	private ?string $stagedUpdate = null;
	private string $stagedVersion = "";

	public function __construct(private ActualAntiXRay $plugin, string $pluginFile){
		$this->pharPath = self::resolvePharPath($pluginFile);
		$this->folderPath = $this->pharPath === null ? self::resolveFolderPath($pluginFile) : null;
		$this->stagingFolder = $this->plugin->getDataFolder() . self::STAGING_FOLDER . "/";
	}

	/**
	 * Turns the path PocketMine hands to a plugin ("phar://C:/server/plugins/Plugin.phar/") into the real path of the
	 * .phar file. Returns null when the plugin runs from a source folder instead.
	 */
	private static function resolvePharPath(string $pluginFile) : ?string{
		if(!str_starts_with($pluginFile, "phar://")){
			return null;
		}
		$path = rtrim(substr($pluginFile, strlen("phar://")), "/\\");
		return is_file($path) ? $path : null;
	}

	/** Path of the source folder a plugin loaded by DevTools runs from, without the trailing separator. */
	private static function resolveFolderPath(string $pluginFile) : ?string{
		$path = rtrim($pluginFile, "/\\");
		return is_dir($path) ? $path : null;
	}

	/** Where an update ends up: the running .phar, or a .phar next to the source folder that replaces it. */
	private function getInstallTarget() : ?string{
		if($this->pharPath !== null){
			return $this->pharPath;
		}
		if($this->folderPath !== null){
			return dirname($this->folderPath) . "/" . $this->plugin->getDescription()->getName() . ".phar";
		}
		return null;
	}

	/**
	 * Reads the plugin.yml out of a .phar file, or returns null if the file is not a valid .phar holding a plugin.
	 * Opening the archive verifies its signature, so this doubles as a check that a download is not corrupted.
	 */
	public static function describePhar(string $path) : ?PluginDescription{
		try{
			$phar = new \Phar($path);
			if(!isset($phar["plugin.yml"])){
				return null;
			}
			return new PluginDescription($phar["plugin.yml"]->getContent());
		}catch(\UnexpectedValueException | \PharException | PluginException $e){
			// Not a .phar, a broken one, or one without a usable plugin.yml.
			return null;
		}
	}

	/** Version of the update waiting to be installed when the server stops, if there is one. */
	public function getStagedVersion() : ?string{
		return $this->stagedUpdate !== null ? $this->stagedVersion : null;
	}

	public function start() : void{
		$this->reportLastInstall();

		$resourceManager = ResourceManager::getInstance();
		if(!$resourceManager->isAutoUpdateEnabled()){
			// Anything left in the staging folder stays untouched: it is only installed while updating is switched on.
			return;
		}
		$this->collectStagedUpdate();
		if($this->stagedUpdate !== null){
			$this->plugin->getLogger()->notice("Version " . $this->stagedVersion . " has already been downloaded and will be installed when the server stops.");
			return;
		}
		$this->plugin->getServer()->getAsyncPool()->submitTask(
			new UpdateCheckTask(
				$this->plugin,
				$resourceManager->getAutoUpdateRepository(),
				$this->plugin->getDescription()->getName(),
				$this->plugin->getDescription()->getVersion(),
				$resourceManager->getAutoUpdateToken(),
				$resourceManager->allowAutoUpdatePreReleases(),
				$resourceManager->isAutoUpdateDownloadEnabled() && $this->getInstallTarget() !== null,
				$this->stagingFolder
			)
		);
	}

	/**
	 * Logs and removes the report the shutdown handler of the previous run left behind, since no logger is available
	 * anymore at the time an update is installed.
	 */
	private function reportLastInstall() : void{
		$logPath = $this->plugin->getDataFolder() . self::INSTALL_LOG;
		if(!is_file($logPath)){
			return;
		}
		$contents = file_get_contents($logPath);
		if(is_string($contents) && $contents !== ""){
			foreach(explode(PHP_EOL, rtrim($contents, PHP_EOL)) as $line){
				$this->plugin->getLogger()->notice($line);
			}
		}
		@unlink($logPath);
	}

	/**
	 * Picks up a .phar that was downloaded during an earlier run but never installed, e.g. because the server crashed
	 * or because the old file could not be deleted. Everything else in the staging folder is thrown away.
	 */
	private function collectStagedUpdate() : void{
		if(!is_dir($this->stagingFolder)){
			return;
		}
		$files = glob($this->stagingFolder . "*.phar");
		if(!is_array($files)){
			return;
		}
		$name = $this->plugin->getDescription()->getName();
		$currentVersion = $this->plugin->getDescription()->getVersion();
		foreach($files as $file){
			$description = self::describePhar($file);
			if(
				$description === null ||
				$description->getName() !== $name ||
				!version_compare($description->getVersion(), $currentVersion, ">") ||
				($this->stagedUpdate !== null && !version_compare($description->getVersion(), $this->stagedVersion, ">"))
			){
				@unlink($file);
				continue;
			}
			if($this->stagedUpdate !== null){
				@unlink($this->stagedUpdate);
			}
			$this->stagedUpdate = $file;
			$this->stagedVersion = $description->getVersion();
		}
	}

	/**
	 * @param array<string, string> $result
	 *
	 * @see UpdateCheckTask::onCompletion()
	 */
	public function handleCheckResult(array $result) : void{
		$logger = $this->plugin->getLogger();
		$version = $result["version"] ?? "?";
		switch($result["status"] ?? UpdateCheckTask::STATUS_ERROR){
			case UpdateCheckTask::STATUS_UP_TO_DATE:
				$logger->debug("No update available, running the latest version.");
				break;

			case UpdateCheckTask::STATUS_AVAILABLE:
				$logger->notice("Version " . $version . " is available: " . ($result["url"] ?? ""));
				if(isset($result["message"])){
					$logger->notice("It cannot be installed automatically: " . $result["message"] . ".");
				}elseif($this->getInstallTarget() === null){
					$logger->notice("This plugin runs from neither a .phar file nor a folder, so it cannot install the update itself.");
				}
				break;

			case UpdateCheckTask::STATUS_DOWNLOADED:
				$path = $result["path"] ?? "";
				if($path === "" || !is_file($path)){
					$logger->warning("The downloaded update disappeared before it could be installed.");
					break;
				}
				$this->stagedUpdate = $path;
				$this->stagedVersion = $version;
				$logger->notice(
					$this->folderPath !== null ?
						"Version " . $version . " has been downloaded and will replace this source folder with " . basename($this->getInstallTarget() ?? "") . " when the server stops." :
						"Version " . $version . " has been downloaded and will replace this file when the server stops."
				);
				break;

			case UpdateCheckTask::STATUS_ERROR:
			default:
				$logger->warning("Update check failed: " . ($result["message"] ?? "unknown error"));
				break;
		}
	}

	/**
	 * Installs a downloaded update. This is called when the plugin is disabled, but the actual work is deferred to the
	 * very end of the process: deleting the running .phar (or source folder) makes every class it still holds
	 * unloadable.
	 */
	public function installStagedUpdate() : void{
		$staged = $this->stagedUpdate;
		$target = $this->getInstallTarget();
		if($staged === null || $target === null || !is_file($staged)){
			return;
		}
		$this->stagedUpdate = null;

		$version = $this->stagedVersion;
		$folder = $this->folderPath;
		$name = $this->plugin->getDescription()->getName();
		$logPath = $this->plugin->getDataFolder() . self::INSTALL_LOG;
		$this->plugin->getLogger()->notice(
			$folder !== null ?
				"Installing version " . $version . " as " . basename($target) . ", this source folder will be deleted." :
				"Installing version " . $version . " as " . basename($target) . ", this file will delete itself."
		);

		register_shutdown_function(static function() use ($staged, $target, $folder, $name, $logPath, $version) : void{
			$message = $folder !== null ?
				self::replaceFolder($staged, $folder, $target, $name, $version) :
				self::swapPhar($staged, $target, $version);
			@file_put_contents($logPath, "[" . date("H:i:s") . "] " . $message . PHP_EOL, FILE_APPEND);
		});
	}

	/**
	 * Installs the update as a .phar next to the source folder the plugin was loaded from (DevTools) and deletes that
	 * folder, so that the next start loads the .phar instead. The folder is only deleted once the .phar is in place,
	 * because a server that finds both would load the plugin twice.
	 */
	private static function replaceFolder(string $staged, string $folder, string $target, string $name, string $version) : string{
		if(!is_file($staged)){
			return "The update for version " . $version . " went missing before it could be installed.";
		}
		$temporary = $target . ".new";
		@unlink($temporary);
		if(!@copy($staged, $temporary)){
			return "Could not copy the update to " . $temporary . ", keeping " . basename($folder) . "/.";
		}
		// rename() replaces an existing file, so an older .phar of this plugin lying around is overwritten.
		if(!@rename($temporary, $target)){
			@unlink($temporary);
			return "Could not write " . basename($target) . ", keeping " . basename($folder) . "/.";
		}
		if(!self::deleteFolder($folder, $name)){
			return "Installed version " . $version . " as " . basename($target) . ", but " . $folder . " could not be deleted:" .
				" delete it by hand, the server would otherwise load this plugin twice.";
		}
		@unlink($staged);
		return "Updated to version " . $version . ": " . basename($folder) . "/ has been replaced by " . basename($target) . ".";
	}

	/**
	 * Deletes a plugin's source folder, but only once its plugin.yml confirms that it really is the folder of $name —
	 * a wrong path here would delete something that was never ours.
	 */
	private static function deleteFolder(string $folder, string $name) : bool{
		$manifest = $folder . "/plugin.yml";
		if(!is_file($manifest)){
			return false;
		}
		$contents = file_get_contents($manifest);
		if(!is_string($contents)){
			return false;
		}
		try{
			if((new PluginDescription($contents))->getName() !== $name){
				return false;
			}
		}catch(PluginException $e){
			return false;
		}

		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($entries as $entry){
			if(!is_string($entry)){
				continue;
			}
			// is_dir() follows symlinks, so links are removed as the files they are instead of being descended into.
			if(is_dir($entry) && !is_link($entry)){
				@rmdir($entry);
			}else{
				@unlink($entry);
			}
		}
		return @rmdir($folder);
	}

	/**
	 * Replaces the .phar at $target with the one at $staged and returns what happened, to be logged on the next start.
	 * The current version is only deleted once a copy of the update sits next to it, so a failure at any point leaves a
	 * working plugin behind.
	 */
	private static function swapPhar(string $staged, string $target, string $version) : string{
		if(!is_file($staged)){
			return "The update for version " . $version . " went missing before it could be installed.";
		}
		// Copying next to the target keeps the download as a fallback and turns the final step into a rename inside a
		// single directory, which cannot end up half-done.
		$temporary = $target . ".new";
		@unlink($temporary);
		if(!@copy($staged, $temporary)){
			return "Could not copy the update to " . $temporary . ", keeping the current version.";
		}
		if(!@unlink($target)){
			@unlink($temporary);
			return "Could not delete " . basename($target) . " to replace it, the update will be retried on the next stop.";
		}
		if(!@rename($temporary, $target)){
			if(!@copy($temporary, $target)){
				return "Deleted " . basename($target) . " but failed to put version " . $version . " in its place! Install " . $staged . " manually.";
			}
			@unlink($temporary);
		}
		@unlink($staged);
		return "Updated to version " . $version . ".";
	}
}
